<?php
/**
 * ============================================================
 * แผนการปลูกจริง (Grow Plan)
 *
 * เมื่อเกษตรกรนำพืชที่ AI แนะนำไปปลูกจริง ระบบสร้าง "แผนตามระยะ" ให้:
 *   - ระยะ + จำนวนวัน มาจากข้อมูลมาตรฐานของพืช (ตาราง crop_stages ผู้ดูแลแก้ได้) ไม่ให้ AI กำหนดเอง
 *     เพราะโมเดลขนาดเล็กกำหนดตัวเลขเหล่านี้ไม่เสถียร
 *   - AI (qwen3) เขียน "งานที่ควรทำ" และ "ข้อควรระวัง" ของแต่ละระยะ ให้เข้ากับสภาพแปลง (ดิน น้ำ ฤดูกาล)
 *   - เกษตรกรติ๊กความคืบหน้า บันทึกการเก็บเกี่ยว -> ผลผลิตไหลไป crop_history / รายงาน / ผลตอบกลับ
 * ถ้า AI ไม่พร้อม ยังสร้างแผนได้ โดยใช้คำแนะนำมาตรฐานของแต่ละระยะแทน
 * ============================================================
 */

const GROW_STATE_TH = ['done' => 'เสร็จแล้ว', 'skipped' => 'ข้ามขั้นตอนนี้', 'current' => 'กำลังอยู่ในระยะนี้', 'overdue' => 'เลยกำหนด', 'upcoming' => 'ยังไม่ถึง'];

/** เหตุผลที่เกษตรกรเลือกได้เมื่อ "ไม่ได้ทำตามขั้นตอนนี้" (ต้องเลือกเสมอ) */
const GROW_SKIP_REASONS = [
    'no_material'  => 'ไม่มีวัสดุ/อุปกรณ์/ปุ๋ยตามที่แนะนำ',
    'weather'      => 'สภาพอากาศไม่เอื้ออำนวย (ฝนตกหนัก/แล้ง)',
    'cost'         => 'ค่าใช้จ่ายสูงเกินไป',
    'time_labor'   => 'ไม่มีเวลาหรือแรงงาน',
    'not_suitable' => 'ไม่เหมาะกับสภาพแปลงจริง',
    'other_method' => 'ใช้วิธีอื่นแทน',
    'other'        => 'อื่นๆ',
];
/** เหตุผลประเภทที่ต้องพิมพ์อธิบายเพิ่มด้วย (ไม่งั้นไม่รู้ว่าทำอะไรแทน/เกิดอะไรขึ้น) */
const GROW_SKIP_NEED_DETAIL = ['other_method', 'other'];
const GROW_STATUS_TH = ['active' => 'กำลังปลูก', 'harvested' => 'เก็บเกี่ยวแล้ว', 'cancelled' => 'ยกเลิก'];

/* =========================================================
 * ข้อมูลระยะของพืช
 * ========================================================= */

/** ระยะกลาง (ใช้เมื่อผู้ดูแลยังไม่กำหนดระยะของพืชนั้น) */
function growGenericStages(): array
{
    $rows = [
        ['เตรียมดินและเพาะ/หยอดเมล็ด', 1, 3, 'เตรียมแปลงและเริ่มปลูก เมล็ดหรือท่อนพันธุ์เริ่มงอก'],
        ['ระยะงอกและต้นอ่อน', 4, 14, 'ต้นอ่อนตั้งตัว ถอนแยกต้นและซ่อมต้นที่ไม่งอก กำจัดวัชพืช'],
        ['ระยะเจริญเติบโตทางลำต้น', 15, 45, 'ต้นเจริญเติบโตเร็ว ให้น้ำและธาตุอาหารสม่ำเสมอ'],
        ['ระยะออกดอกและสร้างผลผลิต', 46, 75, 'พืชออกดอกและติดผล/เมล็ด เฝ้าระวังโรคและแมลง'],
        ['ระยะแก่และเก็บเกี่ยว', 76, 100, 'ผลผลิตแก่เต็มที่ เก็บเกี่ยวในช่วงที่เหมาะสม'],
    ];
    $out = [];
    foreach ($rows as $i => [$n, $a, $b, $d]) {
        $out[] = ['stage_order' => $i + 1, 'stage_name' => $n, 'day_from' => $a, 'day_to' => $b, 'description' => $d];
    }
    return $out;
}

/** ระยะของพืชทุกชนิด (โหลดครั้งเดียวต่อคำขอ) คืนเป็น [crop_id => [stages...]] */
function growAllCropStages(PDO $pdo, bool $refresh = false): array
{
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = [];
        foreach ($pdo->query('SELECT crop_id, stage_order, stage_name, day_from, day_to, description FROM crop_stages ORDER BY crop_id, stage_order')->fetchAll() as $r) {
            $cache[(int) $r['crop_id']][] = $r;
        }
    }
    return $cache;
}

/**
 * ระยะของพืชหนึ่งชนิด
 * @return array ['stages'=>[...], 'generic'=>bool]
 */
function growCropStages(PDO $pdo, int $cropId, bool $refresh = false): array
{
    $all = growAllCropStages($pdo, $refresh);
    if (!empty($all[$cropId])) {
        return ['stages' => $all[$cropId], 'generic' => false];
    }
    return ['stages' => growGenericStages(), 'generic' => true];
}

/** แปลงข้อความจากช่อง "ระยะการปลูก" ของ Admin (บรรทัดละ 1 ระยะ: ชื่อ | 1-3 | คำอธิบาย) */
function growParseStagesText(string $text): array
{
    $stages = [];
    $errors = [];
    $prevFrom = 0;
    foreach (preg_split('/\R/u', $text) as $i => $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $ln = $i + 1;
        $parts = array_map('trim', explode('|', $line, 3));
        if (count($parts) < 2 || $parts[0] === '') {
            $errors[] = "บรรทัดที่ $ln: รูปแบบต้องเป็น ชื่อระยะ | วันที่เริ่ม-วันที่สิ้นสุด | คำอธิบาย";
            continue;
        }
        if (!preg_match('/^(\d{1,4})\s*[-–—]\s*(\d{1,4})$/u', $parts[1], $m) && !preg_match('/^(\d{1,4})$/', $parts[1], $m)) {
            $errors[] = "บรรทัดที่ $ln: ช่วงวันที่ต้องเป็นตัวเลข เช่น 1-3";
            continue;
        }
        $from = (int) $m[1];
        $to = isset($m[2]) ? (int) $m[2] : $from;
        if ($from < 1 || $to < $from || $to > 1500) {
            $errors[] = "บรรทัดที่ $ln: ช่วงวันที่ไม่ถูกต้อง (เริ่มตั้งแต่วันที่ 1 และวันสิ้นสุดต้องไม่น้อยกว่าวันเริ่ม)";
            continue;
        }
        if ($from <= $prevFrom) {
            $errors[] = "บรรทัดที่ $ln: ต้องเรียงตามลำดับเวลา (วันที่เริ่มต้องมากกว่าระยะก่อนหน้า)";
            continue;
        }
        $prevFrom = $from;
        $stages[] = ['stage_order' => count($stages) + 1, 'stage_name' => mb_substr($parts[0], 0, 150), 'day_from' => $from, 'day_to' => $to, 'description' => $parts[2] ?? ''];
    }
    if (count($stages) > 20) {
        $errors[] = 'กำหนดได้ไม่เกิน 20 ระยะ';
    }
    return ['stages' => $stages, 'errors' => $errors];
}

function growStagesToText(array $stages): string
{
    $lines = [];
    foreach ($stages as $s) {
        $lines[] = $s['stage_name'] . ' | ' . $s['day_from'] . '-' . $s['day_to'] . ($s['description'] !== '' && $s['description'] !== null ? ' | ' . $s['description'] : '');
    }
    return implode("\n", $lines);
}

/** แทนที่ระยะทั้งหมดของพืช (ใช้ในหน้า Admin) */
function growSaveCropStages(PDO $pdo, int $cropId, array $stages): void
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM crop_stages WHERE crop_id = ?')->execute([$cropId]);
        $ins = $pdo->prepare('INSERT INTO crop_stages (crop_id, stage_order, stage_name, day_from, day_to, description) VALUES (?,?,?,?,?,?)');
        foreach ($stages as $s) {
            $ins->execute([$cropId, $s['stage_order'], $s['stage_name'], $s['day_from'], $s['day_to'], $s['description'] ?: null]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    growAllCropStages($pdo, true);
}

/* =========================================================
 * วันที่ / สถานะ / ความคืบหน้า
 * ========================================================= */

/** ฤดูกาลโดยประมาณของไทย (ใช้เป็นข้อมูลประกอบให้ AI) */
function growSeasonOf(string $date): string
{
    $m = (int) date('n', strtotime($date));
    if ($m === 11 || $m === 12 || $m === 1) return 'ฤดูหนาว';
    if ($m >= 2 && $m <= 4) return 'ฤดูร้อน';
    return 'ฤดูฝน';
}

/** วันที่ของ "วันที่ n" ในแผน (วันปลูก = วันที่ 1) */
function growDate(string $start, int $dayNo): string
{
    return (new DateTime($start))->modify('+' . ($dayNo - 1) . ' days')->format('Y-m-d');
}

/** วันนี้เป็นวันที่เท่าไรของแผน (วันปลูก = 1, ก่อนวันปลูก <= 0) */
function growDayNumber(string $start, ?string $today = null): int
{
    $d = (new DateTime($start))->diff(new DateTime($today ?? date('Y-m-d')));
    return ((int) $d->format('%r%a')) + 1;
}

function growStageState(array $stage, int $todayDay): string
{
    if (!empty($stage['is_done'])) return 'done';
    if (!empty($stage['is_skipped'])) return 'skipped';   // ข้ามแล้ว (มีเหตุผล) ถือว่าจัดการแล้ว ไม่ค้างเป็น "เลยกำหนด"
    if ($todayDay > (int) $stage['day_to']) return 'overdue';
    if ($todayDay >= (int) $stage['day_from']) return 'current';
    return 'upcoming';
}

/**
 * ความคืบหน้าของแผน
 * @return array total_days, day, time_pct, done_count, skipped_count, stage_count, states[], focus (index|null), overdue_count, harvest_date, days_left
 */
function growPlanProgress(array $stages, string $start, ?string $today = null): array
{
    $total = 0;
    foreach ($stages as $s) {
        $total = max($total, (int) $s['day_to']);
    }
    $day = growDayNumber($start, $today);
    $states = [];
    $done = 0;
    $skipped = 0;
    $overdue = 0;
    foreach ($stages as $i => $s) {
        $states[$i] = growStageState($s, $day);
        $done += $states[$i] === 'done' ? 1 : 0;
        $skipped += $states[$i] === 'skipped' ? 1 : 0;
        $overdue += $states[$i] === 'overdue' ? 1 : 0;
    }
    // ระยะที่ควรโฟกัส: ระยะแรกที่ยังไม่เสร็จและถึงเวลาแล้ว (เลยกำหนด/กำลังอยู่) ไม่งั้นระยะถัดไปที่ยังไม่ถึง
    $focus = null;
    foreach ($states as $i => $st) {
        if ($st === 'overdue' || $st === 'current') { $focus = $i; break; }
    }
    if ($focus === null) {
        foreach ($states as $i => $st) {
            if ($st === 'upcoming') { $focus = $i; break; }
        }
    }
    return [
        'total_days'    => $total,
        'day'           => $day,
        'time_pct'      => $total > 0 ? max(0, min(100, round($day / $total * 100))) : 0,
        'done_count'    => $done,
        'skipped_count' => $skipped,
        'stage_count'   => count($stages),
        'states'        => $states,
        'focus'         => $focus,
        'overdue_count' => $overdue,
        'harvest_date'  => $total > 0 ? growDate($start, $total) : null,
        'days_left'     => $total - $day,
    ];
}

/** วันที่แบบไทยสั้น ๆ ของช่วงระยะ เช่น "5-7 ต.ค. 2569" */
function growRangeThai(string $start, int $from, int $to): string
{
    $a = growDate($start, $from);
    $b = growDate($start, $to);
    if ($a === $b) {
        return formatDateThai($a);
    }
    return formatDateThai($a) . ' – ' . formatDateThai($b);
}

/* =========================================================
 * AI: เขียนงานที่ควรทำในแต่ละระยะ
 * ========================================================= */

/** ข้อมูลประกอบการเขียนแผน (พืช แปลง ดิน น้ำ ฤดูกาล) */
function growLoadContext(PDO $pdo, int $plotId, int $cropId, string $startDate): ?array
{
    $p = $pdo->prepare('SELECT p.plot_id, p.plot_name, p.plot_size, f.farm_name, f.has_water_source, f.water_source_types FROM plots p JOIN farms f ON f.farm_id = p.farm_id WHERE p.plot_id = ?');
    $p->execute([$plotId]);
    $plot = $p->fetch();
    $c = $pdo->prepare('SELECT * FROM crops WHERE crop_id = ?');
    $c->execute([$cropId]);
    $crop = $c->fetch();
    if (!$plot || !$crop) {
        return null;
    }
    $s = $pdo->prepare('SELECT * FROM soil_data WHERE plot_id = ? ORDER BY record_date DESC, soil_id DESC LIMIT 1');
    $s->execute([$plotId]);
    return ['plot' => $plot, 'crop' => $crop, 'soil' => $s->fetch() ?: null, 'start' => $startDate, 'season' => growSeasonOf($startDate)];
}

function growPlanSchema(): array
{
    return [
        'type'       => 'object',
        'properties' => [
            'overview' => ['type' => 'string'],
            'risks'    => ['type' => 'string'],
            'stages'   => ['type' => 'array', 'items' => [
                'type'       => 'object',
                'properties' => ['order' => ['type' => 'integer'], 'tasks' => ['type' => 'string'], 'watch' => ['type' => 'string']],
                'required'   => ['order', 'tasks', 'watch'],
            ]],
        ],
        'required'   => ['overview', 'risks', 'stages'],
    ];
}

function growBuildPrompt(array $ctx, array $stages): string
{
    $crop = $ctx['crop'];
    $plot = $ctx['plot'];
    $soil = $ctx['soil'];
    $soilTxt = $soil
        ? "pH {$soil['ph_value']}, N " . ($soil['nitrogen'] ?? 'ไม่ระบุ') . ', P ' . ($soil['phosphorus'] ?? 'ไม่ระบุ') . ', K ' . ($soil['potassium'] ?? 'ไม่ระบุ') . ' mg/kg' . ($soil['soil_type'] ? ", ดิน{$soil['soil_type']}" : '')
        : 'ยังไม่มีข้อมูลดิน';
    $phNote = '';
    if ($soil && $crop['ph_min'] !== null && $crop['ph_max'] !== null) {
        $ph = (float) $soil['ph_value'];
        if ($ph < (float) $crop['ph_min'] || $ph > (float) $crop['ph_max']) {
            $phNote = ' (pH ของดินอยู่นอกช่วงเหมาะสมของพืชนี้ ' . number_format((float) $crop['ph_min'], 1) . '-' . number_format((float) $crop['ph_max'], 1) . ' ต้องปรับปรุงดินก่อนปลูกในระยะแรก)';
        }
    }
    $water = $plot['has_water_source'] ? 'มีแหล่งน้ำ (' . ($plot['water_source_types'] ?: 'ไม่ระบุประเภท') . ')' : 'ไม่มีแหล่งน้ำ ต้องพึ่งน้ำฝน';
    $area = $plot['plot_size'] ? $plot['plot_size'] . ' ไร่' : 'ไม่ระบุ';
    $formula = ($crop['fertilizer_formula'] ?? '') !== '' && $crop['fertilizer_formula'] !== '-' ? $crop['fertilizer_formula'] : 'ไม่ระบุ (ไม่ต้องใช้ปุ๋ยเคมี/เป็นพืชปุ๋ยสด)';
    $lines = '';
    foreach ($stages as $s) {
        $lines .= $s['stage_order'] . '. ' . $s['stage_name'] . ' (วันที่ ' . $s['day_from'] . '-' . $s['day_to'] . ')' . ($s['description'] ? ': ' . $s['description'] : '') . "\n";
    }
    $rag = ragQueryContext('การปลูก' . $crop['crop_name'] . ' ระยะการปลูก การดูแล การใส่ปุ๋ย การให้น้ำ');
    $ragBlock = $rag !== '' ? "\nข้อมูลอ้างอิงจากคลังความรู้ (ใช้ประกอบถ้าเกี่ยวข้อง):\n{$rag}\n" : '';
    $n = count($stages);

    return <<<PROMPT
คุณเป็นผู้เชี่ยวชาญด้านการเกษตร ช่วยวางแผนการปลูก{$crop['crop_name']}จริงให้เกษตรกร โดยเขียน "งานที่ควรทำ" และ "ข้อควรระวัง" ของแต่ละระยะให้เข้ากับสภาพแปลงนี้

ข้อมูลแปลง: {$plot['farm_name']} - {$plot['plot_name']} พื้นที่ {$area}
สภาพดินล่าสุด: {$soilTxt}{$phNote}
แหล่งน้ำ: {$water}
วันที่เริ่มปลูก: {$ctx['start']} ({$ctx['season']})
สูตรปุ๋ยที่ใช้ทั่วไปของพืชนี้: {$formula}

ระยะการปลูกมาตรฐาน (ระบบกำหนดไว้แล้ว ห้ามเปลี่ยนชื่อ ลำดับ หรือจำนวนวัน):
{$lines}{$ragBlock}
ให้เขียน JSON ประกอบด้วย:
- overview: ภาพรวมแผน 2-3 ประโยค รวมสิ่งที่ต้องเตรียมก่อนเริ่มปลูกตามสภาพดินและแหล่งน้ำของแปลงนี้
- risks: ความเสี่ยง/ข้อควรระวังสำคัญของพืชนี้ในแปลงนี้ตามฤดูกาล 2-3 ประโยค
- stages: อาร์เรย์ {$n} รายการ เรียงตาม order 1 ถึง {$n} แต่ละรายการมี order, tasks (งานที่ควรทำในระยะนั้น 2-3 ประโยค ระบุช่วงเวลาที่ควรทำ เช่น การให้น้ำ การใส่ปุ๋ย การกำจัดวัชพืช), watch (สิ่งที่ต้องระวังในระยะนั้น 1-2 ประโยค เช่น โรค แมลง การขาดน้ำ)

กฎสำคัญ: ห้ามระบุอัตราการใช้ปุ๋ยหรือสารเคมีกำจัดศัตรูพืชเป็นตัวเลข ให้ระบุสูตร/ชนิดและช่วงเวลา แล้วแนะนำให้ปรึกษาเจ้าหน้าที่เกษตรหรืออ่านฉลากผลิตภัณฑ์สำหรับปริมาณ
PROMPT;
}

/**
 * ให้ AI เขียนแผน
 * @return array ['success'=>true,'overview','risks','stages'=>[order=>['tasks','watch']],'model'] หรือ ['success'=>false,'error']
 */
function growGenerateAi(array $ctx, array $stages): array
{
    $r = aiGenerateJSON(growBuildPrompt($ctx, $stages), ['maxOutputTokens' => 1800, 'temperature' => 0.3, 'jsonSchema' => growPlanSchema()], 'plan');
    if (!$r['success']) {
        return $r;
    }
    $d = $r['data'];
    $txt = fn($v) => trim(is_array($v) ? implode(' ', array_map('strval', $v)) : (string) $v);
    $byOrder = [];
    foreach (($d['stages'] ?? []) as $i => $s) {
        if (!is_array($s)) {
            continue;
        }
        $o = (int) ($s['order'] ?? ($i + 1));
        if ($o < 1 || $o > count($stages) || isset($byOrder[$o])) {
            continue; // ลำดับนอกช่วง/ซ้ำ ไม่เอา (กัน AI ตอบเลขมั่ว)
        }
        $byOrder[$o] = ['tasks' => $txt($s['tasks'] ?? ''), 'watch' => $txt($s['watch'] ?? '')];
    }
    $overview = $txt($d['overview'] ?? '');
    $risks = $txt($d['risks'] ?? '');
    if ($overview === '' && !array_filter($byOrder, fn($x) => $x['tasks'] !== '')) {
        return ['success' => false, 'error' => 'AI ไม่ได้ตอบแผนการปลูกกลับมา'];
    }
    return ['success' => true, 'overview' => $overview, 'risks' => $risks, 'stages' => $byOrder, 'model' => $r['model'] ?? OLLAMA_TEXT_MODEL];
}

/* =========================================================
 * วงจรชีวิตของแผน
 * ========================================================= */

/** แผนที่กำลังปลูกอยู่ของแปลง (null ถ้าไม่มี) */
function growActivePlanForPlot(PDO $pdo, int $plotId): ?array
{
    $st = $pdo->prepare("SELECT g.plan_id, g.start_date, c.crop_name FROM grow_plans g JOIN crops c ON c.crop_id = g.crop_id WHERE g.plot_id = ? AND g.status = 'active' ORDER BY g.plan_id DESC LIMIT 1");
    $st->execute([$plotId]);
    return $st->fetch() ?: null;
}

/** ตรวจ recommend_id/crop_id ว่าเป็นของผู้ใช้และพืชอยู่ในผลจัดอันดับ (กันแก้ค่าจากฟอร์ม) */
function growCheckRecommendation(PDO $pdo, int $uid, int $recommendId, int $cropId, ?string &$error): ?array
{
    $st = $pdo->prepare('SELECT r.*, p.plot_id AS pid FROM ai_recommendations r JOIN plots p ON p.plot_id = r.plot_id JOIN farms f ON f.farm_id = p.farm_id WHERE r.recommend_id = ? AND f.user_id = ?');
    $st->execute([$recommendId, $uid]);
    $rec = $st->fetch();
    if (!$rec) {
        $error = 'ไม่พบคำแนะนำนี้';
        return null;
    }
    $detail = !empty($rec['score_detail']) ? json_decode($rec['score_detail'], true) : null;
    $ok = false;
    foreach (($detail['ranking'] ?? []) as $item) {
        if ((int) $item['crop_id'] === $cropId) {
            $ok = true;
        }
    }
    if (!$ok) {
        $c = $pdo->prepare('SELECT crop_id FROM crops WHERE crop_id = ? AND crop_name = ?');
        $c->execute([$cropId, $rec['recommended_crop']]);
        $ok = (bool) $c->fetch();
    }
    if (!$ok) {
        $error = 'พืชนี้ไม่อยู่ในผลจัดอันดับของคำแนะนำนี้';
        return null;
    }
    return $rec;
}

/**
 * สร้างแผนการปลูกจริง (คืน plan_id หรือ null พร้อมเหตุผลใน $error)
 * $aiWarning จะมีข้อความเมื่อ AI ไม่พร้อม (แผนยังถูกสร้างด้วยคำแนะนำมาตรฐาน)
 */
function growCreatePlan(PDO $pdo, int $uid, int $plotId, int $cropId, string $startDate, ?int $recommendId, ?string &$error, ?string &$aiWarning = null): ?int
{
    $own = $pdo->prepare('SELECT p.plot_id FROM plots p JOIN farms f ON f.farm_id = p.farm_id WHERE p.plot_id = ? AND f.user_id = ?');
    $own->execute([$plotId, $uid]);
    if (!$own->fetch()) {
        $error = 'ไม่พบแปลงเพาะปลูกนี้';
        return null;
    }
    $crop = $pdo->prepare('SELECT crop_id, crop_name FROM crops WHERE crop_id = ?');
    $crop->execute([$cropId]);
    $cropRow = $crop->fetch();
    if (!$cropRow) {
        $error = 'ไม่พบพืชที่เลือก';
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $startDate);
    if (!$d || $d->format('Y-m-d') !== $startDate) {
        $error = 'วันที่เริ่มปลูกไม่ถูกต้อง';
        return null;
    }
    $diff = (int) (new DateTime(date('Y-m-d')))->diff($d)->format('%r%a');
    if ($diff < -365 || $diff > 180) {
        $error = 'วันที่เริ่มปลูกต้องอยู่ในช่วง 1 ปีก่อน ถึง 6 เดือนข้างหน้า';
        return null;
    }
    if ($recommendId) {
        $rec = growCheckRecommendation($pdo, $uid, $recommendId, $cropId, $error);
        if (!$rec || (int) $rec['plot_id'] !== $plotId) {
            $error = $error ?: 'คำแนะนำนี้ไม่ตรงกับแปลงที่เลือก';
            return null;
        }
    }
    if ($active = growActivePlanForPlot($pdo, $plotId)) {
        $error = 'แปลงนี้มีแผนปลูก' . $active['crop_name'] . 'ที่กำลังดำเนินการอยู่ กรุณาบันทึกการเก็บเกี่ยวหรือยกเลิกแผนนั้นก่อน';
        return null;
    }

    $cs = growCropStages($pdo, $cropId);
    $stages = $cs['stages'];

    // ให้ AI เขียนแผน (ทำก่อนเปิด transaction เพราะอาจใช้เวลานาน) ถ้าไม่พร้อมใช้คำแนะนำมาตรฐาน
    $ctx = growLoadContext($pdo, $plotId, $cropId, $startDate);
    $ai = growGenerateAi($ctx, $stages);
    $aiWarning = $ai['success'] ? null : $ai['error'];

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO crop_history (plot_id, crop_id, planting_date, notes) VALUES (?,?,?,?)')
            ->execute([$plotId, $cropId, $startDate, 'สร้างจากแผนการปลูกจริง' . ($recommendId ? ' (คำแนะนำ AI #' . $recommendId . ')' : '')]);
        $historyId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO grow_plans (user_id, plot_id, crop_id, recommend_id, history_id, start_date, overview, risks, explained_by) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$uid, $plotId, $cropId, $recommendId ?: null, $historyId, $startDate,
                $ai['success'] ? ($ai['overview'] ?: null) : null, $ai['success'] ? ($ai['risks'] ?: null) : null, $ai['success'] ? $ai['model'] : null]);
        $planId = (int) $pdo->lastInsertId();

        $ins = $pdo->prepare('INSERT INTO grow_plan_stages (plan_id, stage_order, stage_name, day_from, day_to, description, tasks, watch) VALUES (?,?,?,?,?,?,?,?)');
        foreach ($stages as $s) {
            $o = (int) $s['stage_order'];
            $tasks = $ai['success'] ? ($ai['stages'][$o]['tasks'] ?? '') : '';
            $watch = $ai['success'] ? ($ai['stages'][$o]['watch'] ?? '') : '';
            $ins->execute([$planId, $o, $s['stage_name'], $s['day_from'], $s['day_to'], $s['description'] ?: null, $tasks !== '' ? $tasks : null, $watch !== '' ? $watch : null]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'สร้างแผนไม่สำเร็จ: ' . $e->getMessage();
        return null;
    }
    logActivity($uid, 'grow_plan_create', 'plan#' . $planId . ' ' . $cropRow['crop_name'] . ($cs['generic'] ? ' (ระยะกลาง)' : ''));
    return $planId;
}

/** โหลดแผน + ตรวจว่าเป็นของผู้ใช้ */
function growLoadPlan(PDO $pdo, int $uid, int $planId): ?array
{
    $st = $pdo->prepare('
        SELECT g.*, c.crop_name, c.plant_family, c.fertilizer_formula, p.plot_name, f.farm_name,
               (SELECT m.market_price FROM market_data m WHERE m.crop_id = g.crop_id ORDER BY m.record_date DESC, m.market_id DESC LIMIT 1) AS price
        FROM grow_plans g JOIN crops c ON c.crop_id = g.crop_id JOIN plots p ON p.plot_id = g.plot_id JOIN farms f ON f.farm_id = p.farm_id
        WHERE g.plan_id = ? AND g.user_id = ?');
    $st->execute([$planId, $uid]);
    $plan = $st->fetch();
    if (!$plan) {
        return null;
    }
    $ss = $pdo->prepare('SELECT * FROM grow_plan_stages WHERE plan_id = ? ORDER BY stage_order');
    $ss->execute([$planId]);
    $plan['stages'] = $ss->fetchAll();
    return $plan;
}

/** ให้ AI เขียนแผนใหม่ (คงสถานะติ๊ก/บันทึกของเกษตรกรไว้ เปลี่ยนเฉพาะข้อความ AI) */
function growRegenerateAi(PDO $pdo, int $uid, int $planId, ?string &$error): bool
{
    $plan = growLoadPlan($pdo, $uid, $planId);
    if (!$plan || $plan['status'] !== 'active') {
        $error = 'ไม่พบแผนที่กำลังดำเนินการ';
        return false;
    }
    $ctx = growLoadContext($pdo, (int) $plan['plot_id'], (int) $plan['crop_id'], $plan['start_date']);
    $ai = growGenerateAi($ctx, $plan['stages']);
    if (!$ai['success']) {
        $error = $ai['error'];
        return false;
    }
    $pdo->prepare('UPDATE grow_plans SET overview = ?, risks = ?, explained_by = ? WHERE plan_id = ?')
        ->execute([$ai['overview'] ?: null, $ai['risks'] ?: null, $ai['model'], $planId]);
    $up = $pdo->prepare('UPDATE grow_plan_stages SET tasks = ?, watch = ? WHERE plan_id = ? AND stage_order = ?');
    foreach ($plan['stages'] as $s) {
        $o = (int) $s['stage_order'];
        if (isset($ai['stages'][$o]) && $ai['stages'][$o]['tasks'] !== '') {
            $up->execute([$ai['stages'][$o]['tasks'], $ai['stages'][$o]['watch'] !== '' ? $ai['stages'][$o]['watch'] : null, $planId, $o]);
        }
    }
    logActivity($uid, 'grow_plan_regen', 'plan#' . $planId);
    return true;
}

function growToggleStage(PDO $pdo, int $uid, int $planId, int $order, bool $done): bool
{
    $own = $pdo->prepare("SELECT 1 FROM grow_plans WHERE plan_id = ? AND user_id = ? AND status = 'active'");
    $own->execute([$planId, $uid]);
    if (!$own->fetch()) {
        return false;
    }
    // ติ๊กว่าทำเสร็จ = ทำตามแล้ว จึงล้างสถานะ "ข้าม" และเหตุผลเดิมออก
    $pdo->prepare('UPDATE grow_plan_stages SET is_done = ?, done_at = ?, is_skipped = 0, skip_type = NULL, skip_reason = NULL, skipped_at = NULL WHERE plan_id = ? AND stage_order = ?')
        ->execute([$done ? 1 : 0, $done ? date('Y-m-d H:i:s') : null, $planId, $order]);
    return true;
}

/**
 * ข้ามขั้นตอน = เกษตรกรไม่ได้ทำตามที่ AI/แผนแนะนำในระยะนี้ ต้องระบุเหตุผล แล้วไปขั้นตอนถัดไปได้
 * @param string $type  คีย์ใน GROW_SKIP_REASONS
 * @param string $text  คำอธิบายเพิ่มเติม (บังคับสำหรับ GROW_SKIP_NEED_DETAIL)
 */
function growSkipStage(PDO $pdo, int $uid, int $planId, int $order, string $type, string $text, ?string &$error): bool
{
    $own = $pdo->prepare("SELECT 1 FROM grow_plans WHERE plan_id = ? AND user_id = ? AND status = 'active'");
    $own->execute([$planId, $uid]);
    if (!$own->fetch()) {
        $error = 'ไม่พบแผนที่กำลังดำเนินการ';
        return false;
    }
    $st = $pdo->prepare('SELECT stage_name, is_done FROM grow_plan_stages WHERE plan_id = ? AND stage_order = ?');
    $st->execute([$planId, $order]);
    $stage = $st->fetch();
    if (!$stage) {
        $error = 'ไม่พบระยะที่เลือก';
        return false;
    }
    if ($stage['is_done']) {
        $error = 'ระยะนี้ติ๊กว่าทำเสร็จแล้ว หากต้องการข้ามให้ยกเลิกการติ๊กก่อน';
        return false;
    }
    if (!isset(GROW_SKIP_REASONS[$type])) {
        $error = 'กรุณาเลือกเหตุผลที่ไม่ได้ทำตามขั้นตอนนี้';
        return false;
    }
    $text = trim(mb_substr($text, 0, 500));
    if (in_array($type, GROW_SKIP_NEED_DETAIL, true) && mb_strlen($text) < 3) {
        $error = 'กรุณาอธิบายเพิ่มเติม (เช่น ใช้วิธีอะไรแทน หรือเกิดอะไรขึ้น) ' . ($type === 'other' ? 'เมื่อเลือกเหตุผล "อื่นๆ"' : 'เมื่อเลือก "ใช้วิธีอื่นแทน"');
        return false;
    }
    $reason = GROW_SKIP_REASONS[$type] . ($text !== '' ? ': ' . $text : '');
    $pdo->prepare('UPDATE grow_plan_stages SET is_skipped = 1, skip_type = ?, skip_reason = ?, skipped_at = ?, is_done = 0, done_at = NULL WHERE plan_id = ? AND stage_order = ?')
        ->execute([$type, $reason, date('Y-m-d H:i:s'), $planId, $order]);
    logActivity($uid, 'grow_plan_skip', 'plan#' . $planId . ' stage' . $order . ' ' . $type);
    return true;
}

/** ยกเลิกการข้าม (กลับเป็นสถานะตามเวลา) */
function growUnskipStage(PDO $pdo, int $uid, int $planId, int $order): bool
{
    $own = $pdo->prepare("SELECT 1 FROM grow_plans WHERE plan_id = ? AND user_id = ? AND status = 'active'");
    $own->execute([$planId, $uid]);
    if (!$own->fetch()) {
        return false;
    }
    $pdo->prepare('UPDATE grow_plan_stages SET is_skipped = 0, skip_type = NULL, skip_reason = NULL, skipped_at = NULL WHERE plan_id = ? AND stage_order = ?')
        ->execute([$planId, $order]);
    return true;
}

function growSaveNote(PDO $pdo, int $uid, int $planId, int $order, string $note): bool
{
    $own = $pdo->prepare('SELECT 1 FROM grow_plans WHERE plan_id = ? AND user_id = ?');
    $own->execute([$planId, $uid]);
    if (!$own->fetch()) {
        return false;
    }
    $pdo->prepare('UPDATE grow_plan_stages SET note = ? WHERE plan_id = ? AND stage_order = ?')
        ->execute([mb_substr(trim($note), 0, 1000) ?: null, $planId, $order]);
    return true;
}

/** บันทึกการเก็บเกี่ยว: ปิดแผน + อัปเดตผลผลิตใน crop_history (ไหลต่อไปรายงานและผลตอบกลับ) */
function growHarvest(PDO $pdo, int $uid, int $planId, string $harvestDate, ?float $yield, ?string &$error): bool
{
    $plan = growLoadPlan($pdo, $uid, $planId);
    if (!$plan || $plan['status'] !== 'active') {
        $error = 'ไม่พบแผนที่กำลังดำเนินการ';
        return false;
    }
    $d = DateTime::createFromFormat('Y-m-d', $harvestDate);
    if (!$d || $d->format('Y-m-d') !== $harvestDate) {
        $error = 'วันที่เก็บเกี่ยวไม่ถูกต้อง';
        return false;
    }
    if ($harvestDate < $plan['start_date']) {
        $error = 'วันที่เก็บเกี่ยวต้องไม่ก่อนวันที่เริ่มปลูก';
        return false;
    }
    if ($harvestDate > date('Y-m-d')) {
        $error = 'วันที่เก็บเกี่ยวต้องไม่เกินวันนี้';
        return false;
    }
    if ($yield !== null && ($yield < 0 || $yield > 100000000)) {
        $error = 'ผลผลิตต้องเป็นตัวเลขที่ไม่ติดลบ';
        return false;
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE grow_plans SET status = 'harvested', harvest_date = ?, yield_amount = ? WHERE plan_id = ?")->execute([$harvestDate, $yield, $planId]);
        if ($plan['history_id']) {
            $pdo->prepare('UPDATE crop_history SET harvest_date = ?, yield_amount = ? WHERE history_id = ?')->execute([$harvestDate, $yield, $plan['history_id']]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'บันทึกไม่สำเร็จ: ' . $e->getMessage();
        return false;
    }
    logActivity($uid, 'grow_plan_harvest', 'plan#' . $planId);
    return true;
}

/** ยกเลิกแผน: ลบรายการที่สร้างไว้ใน crop_history ด้วย เพื่อไม่ให้นับเป็น "พืชที่ปลูกล่าสุด" ของการหมุนเวียน */
function growCancel(PDO $pdo, int $uid, int $planId, ?string &$error): bool
{
    $plan = growLoadPlan($pdo, $uid, $planId);
    if (!$plan || $plan['status'] !== 'active') {
        $error = 'ไม่พบแผนที่กำลังดำเนินการ';
        return false;
    }
    $pdo->beginTransaction();
    try {
        if ($plan['history_id']) {
            $pdo->prepare('DELETE FROM crop_history WHERE history_id = ?')->execute([$plan['history_id']]);
        }
        $pdo->prepare("UPDATE grow_plans SET status = 'cancelled', history_id = NULL WHERE plan_id = ?")->execute([$planId]);
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'ยกเลิกไม่สำเร็จ: ' . $e->getMessage();
        return false;
    }
    logActivity($uid, 'grow_plan_cancel', 'plan#' . $planId);
    return true;
}

/* =========================================================
 * แผง "ถ้านำไปปลูกจริง" (แสดงบนการ์ดคำแนะนำ AI — ดูได้ทันทีไม่ต้องรอ AI)
 * ========================================================= */

/**
 * @param array $crop ต้องมี crop_id, crop_name
 */
function growRenderPreview(PDO $pdo, array $crop, int $recommendId, int $plotId): string
{
    $cs = growCropStages($pdo, (int) $crop['crop_id']);
    $stages = $cs['stages'];
    $total = max(array_map(fn($s) => (int) $s['day_to'], $stages));
    $months = $total >= 45 ? ' (ราว ' . number_format($total / 30, $total >= 300 ? 0 : 1) . ' เดือน)' : '';
    $colors = ['#d4efe5', '#a9dccb', '#7fc9b2', '#5cb99f', '#3fa085', '#2f7a66', '#235c4e'];

    $bar = '';
    $list = '';
    foreach ($stages as $i => $s) {
        $len = (int) $s['day_to'] - (int) $s['day_from'] + 1;
        $w = max(3.0, round($len / $total * 100, 1));
        $bar .= '<div title="' . e($s['stage_name'] . ' (วันที่ ' . $s['day_from'] . '-' . $s['day_to'] . ')') . '" style="width:' . $w . '%;background:' . $colors[$i % count($colors)] . ';"></div>';
        $range = $s['day_from'] == $s['day_to'] ? 'วันที่ ' . $s['day_from'] : 'วันที่ ' . $s['day_from'] . '–' . $s['day_to'];
        $list .= '<li class="mb-1"><b>' . e($s['stage_name']) . '</b> <span class="text-success">' . $range . ' (' . $len . ' วัน)</span>'
            . ($s['description'] ? '<br><span class="text-muted">' . e($s['description']) . '</span>' : '') . '</li>';
    }

    $h = '<details class="mt-2 grow-preview"><summary class="small text-success fw-semibold" style="cursor:pointer;">'
        . '<i class="bi bi-calendar2-check me-1"></i>ถ้านำ' . e($crop['crop_name']) . 'ไปปลูกจริง: ระยะต่างๆ (รวมประมาณ ' . $total . ' วัน' . $months . ')</summary>'
        . '<div class="mt-2 p-3 rounded-3" style="background:#f8fcfa;border:1px solid var(--primary-light);">'
        . '<div class="d-flex rounded overflow-hidden mb-3" style="height:14px;">' . $bar . '</div>'
        . '<ol class="small ps-3 mb-2">' . $list . '</ol>'
        . '<p class="small text-muted mb-2">* ระยะเวลาเป็นค่าโดยประมาณ ขึ้นกับพันธุ์ ฤดูกาล และสภาพอากาศ'
        . ($cs['generic'] ? ' (พืชนี้ผู้ดูแลยังไม่ได้กำหนดระยะเฉพาะ จึงใช้ระยะกลาง)' : '') . '</p>';

    if ($active = growActivePlanForPlot($pdo, $plotId)) {
        $h .= '<div class="alert alert-info small py-2 mb-0"><i class="bi bi-info-circle me-1"></i>แปลงนี้มีแผนปลูก' . e($active['crop_name'])
            . 'ที่กำลังดำเนินการอยู่ <a href="grow_plan.php?plan=' . (int) $active['plan_id'] . '">ดูแผนนั้น</a></div>';
    } else {
        $h .= '<form method="post" action="grow_plan.php" class="d-flex gap-2 flex-wrap align-items-end grow-start-form js-wait" data-wait-text="กำลังวางแผน... (AI อาจใช้เวลาสักครู่)">' . csrfField()
            . '<input type="hidden" name="action" value="create"><input type="hidden" name="recommend_id" value="' . $recommendId . '">'
            . '<input type="hidden" name="crop_id" value="' . (int) $crop['crop_id'] . '">'
            . '<div><label class="small text-muted d-block">วันที่เริ่มปลูก</label><input type="date" name="start_date" value="' . date('Y-m-d') . '" class="form-control form-control-sm" required></div>'
            . '<button class="btn btn-primary-app btn-sm"><i class="bi bi-play-circle me-1"></i> เริ่มปลูกจริง + ให้ AI วางแผน</button>'
            . '<span class="small text-muted w-100">AI จะเขียนงานที่ควรทำในแต่ละระยะให้เข้ากับแปลงของคุณ (ใช้เวลาสักครู่)</span></form>';
    }
    return $h . '</div></details>';
}
