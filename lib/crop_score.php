<?php
/**
 * ============================================================
 * ระบบให้คะแนนความเหมาะสมของพืช (Rule-based Crop Scoring)
 *
 * คำนวณด้วยกฎใน PHP ทั้งหมด ไม่พึ่ง AI เพื่อให้ตัวเลขนิ่ง ตรวจสอบย้อนกลับได้ และอธิบายที่มาของคะแนนได้
 * ส่วน AI (qwen3) ทำหน้าที่ "เขียนคำอธิบาย" ตามผลคะแนนเท่านั้น (ดู user/ai_advice_request.php)
 *
 * คะแนนเต็ม (ตามตัวอย่างที่กำหนด):
 *   ค่า pH 25 | ธาตุอาหาร 25 | การหมุนเวียน 20 | แหล่งน้ำ 15 | ตลาด 10 | ผลปลูกจริง ±10
 *
 * ค่าเกณฑ์ต่าง ๆ (ระดับธาตุอาหาร, ช่วง pH ของพืช, ผลผลิตต่อไร่) เป็น "ค่าประมาณทั่วไป"
 * ผู้ดูแลระบบควรตรวจสอบและปรับให้ตรงกับพื้นที่จริงในเมนู "ข้อมูลพืช/สูตรปุ๋ย"
 * ============================================================
 */

const CROP_SCORE_MAX = ['ph' => 25, 'nutrient' => 25, 'rotation' => 20, 'water' => 15, 'market' => 10, 'actual' => 10];

/** เกณฑ์แบ่งระดับธาตุอาหารในดิน (mg/kg): [ต่ำกว่าค่านี้ = ต่ำ, ตั้งแต่ค่านี้ขึ้นไป = สูง] */
const CROP_SOIL_CUTOFFS = ['N' => [20, 40], 'P' => [10, 25], 'K' => [60, 90]];

const CROP_LEVEL_TH = ['low' => 'ต่ำ', 'medium' => 'ปานกลาง', 'high' => 'สูง'];

/** คะแนนความเข้ากันของ "ความต้องการไนโตรเจนของพืช" กับ "ระดับไนโตรเจนในดิน" (เต็ม 15) */
const CROP_N_MATRIX = [
    'fix'    => ['low' => 15, 'medium' => 13, 'high' => 9],   // พืชตระกูลถั่ว: เหมาะเมื่อดินขาด N และช่วยเพิ่ม N ให้ดิน
    'low'    => ['low' => 12, 'medium' => 14, 'high' => 15],
    'medium' => ['low' => 7,  'medium' => 13, 'high' => 15],
    'high'   => ['low' => 3,  'medium' => 9,  'high' => 15],
];

const CROP_N_EFFECT_TH = [
    'fix'    => 'พืชนี้ตรึงไนโตรเจนให้ดิน',
    'low'    => 'พืชนี้ใช้ไนโตรเจนน้อย',
    'medium' => 'พืชนี้ใช้ไนโตรเจนปานกลาง',
    'high'   => 'พืชนี้ดึงไนโตรเจนจากดินมาก',
];

function cropScoreLevel(?float $value, string $nutrient): ?string
{
    if ($value === null) {
        return null;
    }
    [$lowMax, $highMin] = CROP_SOIL_CUTOFFS[$nutrient];
    return $value < $lowMax ? 'low' : ($value >= $highMin ? 'high' : 'medium');
}

function cropScoreShift(string $level, int $delta): string
{
    $order = ['low', 'medium', 'high'];
    $i = max(0, min(2, array_search($level, $order, true) + $delta));
    return $order[$i];
}

function cropScoreNum(float $v): string
{
    return number_format($v, 1);
}

/** ปัดเป็นทศนิยม 1 ตำแหน่ง */
function cropScoreRound(float $v): float
{
    return round($v, 1);
}

/* ---------------- คะแนนแต่ละด้าน (คืน ['score','max','note']) ---------------- */

function cropScorePh(float $ph, ?float $min, ?float $max): array
{
    $maxScore = CROP_SCORE_MAX['ph'];
    if ($min === null || $max === null) {
        return ['score' => 15.0, 'max' => $maxScore, 'note' => 'ยังไม่มีข้อมูลช่วง pH ที่เหมาะสมของพืชนี้ จึงใช้ค่ากลาง'];
    }
    if ($ph >= $min && $ph <= $max) {
        return ['score' => (float) $maxScore, 'max' => $maxScore, 'note' => 'pH ' . cropScoreNum($ph) . ' อยู่ในช่วงเหมาะสม ' . cropScoreNum($min) . '–' . cropScoreNum($max)];
    }
    $dist = $ph < $min ? $min - $ph : $ph - $max;
    // ห่างจากช่วงเหมาะสม 2 หน่วยขึ้นไป = 0 คะแนน, ระหว่างนั้นลดตามสัดส่วน
    $score = cropScoreRound(max(0.0, $maxScore * (1 - $dist / 2)));
    return ['score' => $score, 'max' => $maxScore, 'note' => 'pH ' . cropScoreNum($ph) . ' อยู่นอกช่วงเหมาะสม ' . cropScoreNum($min) . '–' . cropScoreNum($max)];
}

/**
 * ธาตุอาหาร: ไนโตรเจน (เต็ม 15) + ฟอสฟอรัส/โพแทสเซียม (เต็ม 10)
 * ปรับระดับไนโตรเจนของดินตามผลของพืชที่ปลูกล่าสุด ถ้าค่าดินถูกวัดก่อนปลูกพืชนั้น
 */
function cropScoreNutrient(array $soil, ?array $lastCrop, array $crop): array
{
    $nLevel = cropScoreLevel($soil['n'], 'N');
    $notes = [];

    $adjNote = '';
    if ($nLevel !== null && $lastCrop && !empty($soil['date']) && !empty($lastCrop['planting_date'])
        && $soil['date'] <= $lastCrop['planting_date']) {
        $effect = $lastCrop['n_effect'] ?? 'medium';
        $delta = $effect === 'fix' ? 1 : ($effect === 'high' ? -1 : 0);
        if ($delta !== 0) {
            $nLevel = cropScoreShift($nLevel, $delta);
            $adjNote = 'ค่าดินวัดก่อนปลูก' . $lastCrop['crop_name'] . ' จึงปรับประมาณการไนโตรเจนตามผลของพืชนั้น';
        }
    }
    if ($adjNote !== '') {
        $notes[] = $adjNote;
    }

    $effect = in_array($crop['n_effect'] ?? '', array_keys(CROP_N_MATRIX), true) ? $crop['n_effect'] : 'medium';
    if ($nLevel === null) {
        $nScore = 9.0;
        $notes[] = 'ไม่มีค่าไนโตรเจนของดิน จึงใช้ค่ากลาง';
    } else {
        $nScore = (float) CROP_N_MATRIX[$effect][$nLevel];
        $notes[] = 'ดินมีไนโตรเจน' . CROP_LEVEL_TH[$nLevel];
        $notes[] = CROP_N_EFFECT_TH[$effect];
    }

    $pkMap = ['low' => 4, 'medium' => 8, 'high' => 10];
    $pl = cropScoreLevel($soil['p'], 'P');
    $kl = cropScoreLevel($soil['k'], 'K');
    $pkParts = array_filter([$pl, $kl], fn($x) => $x !== null);
    if ($pkParts) {
        $pkScore = array_sum(array_map(fn($l) => $pkMap[$l], $pkParts)) / count($pkParts);
        $txt = [];
        if ($pl !== null) $txt[] = 'ฟอสฟอรัส' . CROP_LEVEL_TH[$pl];
        if ($kl !== null) $txt[] = 'โพแทสเซียม' . CROP_LEVEL_TH[$kl];
        $notes[] = implode(' ', $txt);
    } else {
        $pkScore = 7.0;
        $notes[] = 'ไม่มีค่าฟอสฟอรัส/โพแทสเซียม จึงใช้ค่ากลาง';
    }

    return ['score' => cropScoreRound($nScore + $pkScore), 'max' => CROP_SCORE_MAX['nutrient'], 'note' => implode(' / ', $notes)];
}

function cropScoreRotation(?array $lastCrop, array $crop): array
{
    $max = CROP_SCORE_MAX['rotation'];
    if (!$lastCrop) {
        return ['score' => 15.0, 'max' => $max, 'note' => 'ยังไม่มีประวัติการปลูกของแปลงนี้ จึงใช้ค่ากลาง'];
    }
    $fam = $crop['plant_family'] ?? null;
    $lastFam = $lastCrop['plant_family'] ?? null;
    if ($fam === null || $fam === '' || $lastFam === null || $lastFam === '') {
        return ['score' => 14.0, 'max' => $max, 'note' => 'ยังไม่มีข้อมูลวงศ์พืช จึงใช้ค่ากลาง'];
    }
    if ($fam === $lastFam) {
        return ['score' => 8.0, 'max' => $max, 'note' => 'วงศ์เดียวกับพืชที่ปลูกล่าสุด (' . $lastCrop['crop_name'] . ') โรคและแมลงอาจสะสม'];
    }
    return ['score' => (float) $max, 'max' => $max, 'note' => 'ต่างวงศ์จากพืชที่ปลูกล่าสุด (' . $lastCrop['crop_name'] . ') ช่วยตัดวงจรโรค'];
}

function cropScoreWater(bool $hasWater, string $need): array
{
    $max = CROP_SCORE_MAX['water'];
    if ($hasWater) {
        return ['score' => (float) $max, 'max' => $max, 'note' => 'แปลงมีแหล่งน้ำ'];
    }
    return match ($need) {
        'low'  => ['score' => 12.0, 'max' => $max, 'note' => 'พืชนี้ใช้น้ำน้อย ปลูกได้แม้ไม่มีแหล่งน้ำ'],
        'high' => ['score' => 0.0,  'max' => $max, 'note' => 'พืชนี้ต้องการน้ำมาก แต่แปลงไม่มีแหล่งน้ำ'],
        default => ['score' => 6.0, 'max' => $max, 'note' => 'พืชนี้ต้องการน้ำปานกลาง แต่แปลงไม่มีแหล่งน้ำ'],
    };
}

function cropScoreMarket(?float $income, float $maxIncome): array
{
    $max = CROP_SCORE_MAX['market'];
    if ($income === null || $maxIncome <= 0) {
        return ['score' => 5.0, 'max' => $max, 'note' => 'ยังไม่มีข้อมูลราคาหรือผลผลิตของพืชนี้ จึงใช้ค่ากลาง'];
    }
    return ['score' => cropScoreRound($max * $income / $maxIncome), 'max' => $max, 'note' => 'รายได้คาดการณ์ ~' . number_format($income) . ' บาท/ไร่'];
}

/** ผลปลูกจริง: ปรับ ±10 ตามคะแนนที่เกษตรกรให้หลังทำตามคำแนะนำ (ยิ่งมีข้อมูลหลายรายการ ยิ่งน้ำหนักมาก) */
function cropScoreActual(?array $stat): array
{
    if (!$stat || (int) $stat['n'] < 1) {
        return ['score' => 0.0, 'max' => 10, 'note' => 'ยังไม่มีข้อมูลผลปลูกจริงของพืชนี้'];
    }
    $n = (int) $stat['n'];
    $avg = (float) $stat['avg'];
    $adj = (($avg - 3) / 2) * 10 * min(1, $n / 3);
    $adj = cropScoreRound(max(-10.0, min(10.0, $adj)));
    return ['score' => $adj, 'max' => 10, 'note' => 'เกษตรกรที่ทำตามคำแนะนำให้คะแนนพอใจเฉลี่ย ' . cropScoreNum($avg) . '/5 (จาก ' . $n . ' รายการ)'];
}

/* ---------------- ตัวจัดอันดับหลัก (ฟังก์ชันบริสุทธิ์ ไม่ใช้ฐานข้อมูล) ---------------- */

/**
 * @param array $in [
 *   'soil'      => ['ph'=>float,'n'=>?float,'p'=>?float,'k'=>?float,'date'=>'Y-m-d'],
 *   'has_water' => bool,
 *   'history'   => [ ['crop_id','crop_name','plant_family','n_effect','planting_date'], ... ]  (ล่าสุดก่อน),
 *   'crops'     => [ ['crop_id','crop_name','plant_family','ph_min','ph_max','n_effect','water_need','yield_per_rai','price'], ... ],
 *   'actual'    => [ crop_name => ['n'=>int,'avg'=>float] ],
 * ]
 * @return array ['ranking'=>[...], 'excluded'=>[...], 'context'=>[...]]
 */
function cropScoreRank(array $in): array
{
    $soil = $in['soil'];
    $history = $in['history'] ?? [];
    $last = $history[0] ?? null;

    // รายได้คาดการณ์ต่อไร่ของแต่ละพืช (ผลผลิต × ราคา) เพื่อเทียบสัมพัทธ์กันในด้านตลาด
    $incomes = [];
    foreach ($in['crops'] as $c) {
        $incomes[$c['crop_id']] = (isset($c['yield_per_rai'], $c['price']) && $c['yield_per_rai'] > 0 && $c['price'] > 0)
            ? (float) $c['yield_per_rai'] * (float) $c['price'] : null;
    }
    $maxIncome = $incomes ? max(array_map(fn($v) => $v ?? 0, $incomes)) : 0;

    $ranking = [];
    $excluded = [];
    foreach ($in['crops'] as $c) {
        if ($last && (int) $last['crop_id'] === (int) $c['crop_id']) {
            $excluded[] = [
                'crop_id'   => $c['crop_id'],
                'crop_name' => $c['crop_name'],
                'reason'    => 'เป็นพืชชนิดเดียวกับรอบล่าสุด (ปลูกซ้ำทำให้ดินเสื่อมและโรคสะสม)',
            ];
            continue;
        }
        $parts = [
            'ph'       => cropScorePh((float) $soil['ph'], isset($c['ph_min']) ? (float) $c['ph_min'] : null, isset($c['ph_max']) ? (float) $c['ph_max'] : null),
            'nutrient' => cropScoreNutrient($soil, $last, $c),
            'rotation' => cropScoreRotation($last, $c),
            'water'    => cropScoreWater((bool) $in['has_water'], (string) ($c['water_need'] ?? 'medium')),
            'market'   => cropScoreMarket($incomes[$c['crop_id']], (float) $maxIncome),
            'actual'   => cropScoreActual($in['actual'][$c['crop_name']] ?? null),
        ];
        $total = array_sum(array_column($parts, 'score'));
        $ranking[] = [
            'crop_id'   => $c['crop_id'],
            'crop_name' => $c['crop_name'],
            'family'    => $c['plant_family'] ?? null,
            'total'     => cropScoreRound(max(0.0, min(100.0, $total))),
            'income'    => $incomes[$c['crop_id']],
            'parts'     => $parts,
        ];
    }

    usort($ranking, fn($a, $b) => [$b['total'], (float) $b['income']] <=> [$a['total'], (float) $a['income']] ?: strcmp($a['crop_name'], $b['crop_name']));

    return [
        'ranking'  => $ranking,
        'excluded' => $excluded,
        'context'  => [
            'ph'        => (float) $soil['ph'],
            'n'         => $soil['n'], 'p' => $soil['p'], 'k' => $soil['k'],
            'soil_date' => $soil['date'] ?? null,
            'last_crop' => $last['crop_name'] ?? null,
            'has_water' => (bool) $in['has_water'],
        ],
    ];
}

/** ชื่อหัวข้อของแต่ละด้าน (ใช้แสดงผล) */
function cropScorePartLabel(string $key): string
{
    return ['ph' => 'ค่า pH', 'nutrient' => 'ธาตุอาหาร', 'rotation' => 'การหมุนเวียน', 'water' => 'แหล่งน้ำ', 'market' => 'ตลาด', 'actual' => 'ผลปลูกจริง'][$key] ?? $key;
}

/** สรุปเหตุผลจากกฎ (ใช้เป็นคำอธิบายสำรองเมื่อ AI ไม่พร้อม) */
function cropScoreFallbackReason(array $top, ?string $lastCrop): string
{
    $good = [];
    foreach ($top['parts'] as $key => $p) {
        if ($key !== 'actual' && $p['max'] > 0 && $p['score'] / $p['max'] >= 0.8) {
            $good[] = cropScorePartLabel($key) . ': ' . $p['note'];
        }
    }
    $txt = 'ระบบจัดอันดับ ' . $top['crop_name'] . ' เป็นอันดับ 1 ด้วยคะแนน ' . cropScoreNum($top['total']) . '/100';
    if ($good) {
        $txt .= ' เพราะ ' . implode(' ; ', array_slice($good, 0, 3));
    }
    $weak = [];
    foreach ($top['parts'] as $key => $p) {
        if ($key !== 'actual' && $p['max'] > 0 && $p['score'] / $p['max'] < 0.4) {
            $weak[] = cropScorePartLabel($key) . ' (' . $p['note'] . ')';
        }
    }
    if ($weak) {
        $txt .= ' ข้อควรระวัง: ' . implode(' ; ', $weak);
    }
    return $txt;
}

/* ---------------- ส่วนที่ใช้ฐานข้อมูล ---------------- */

/**
 * โหลดข้อมูลของแปลงจากฐานข้อมูลเพื่อป้อนให้ cropScoreRank()
 * @return array|null null ถ้าไม่พบแปลง/ไม่มีค่าดิน (คืนเหตุผลใน $error)
 */
function cropScoreLoadInput(PDO $pdo, int $plotId, int $userId, ?string &$error = null): ?array
{
    $stmt = $pdo->prepare('
        SELECT p.plot_id, p.plot_name, f.farm_name, f.has_water_source, f.water_source_types
        FROM plots p JOIN farms f ON f.farm_id = p.farm_id
        WHERE p.plot_id = ? AND f.user_id = ?');
    $stmt->execute([$plotId, $userId]);
    $plot = $stmt->fetch();
    if (!$plot) {
        $error = 'ไม่พบแปลงเพาะปลูกนี้';
        return null;
    }

    $s = $pdo->prepare('SELECT * FROM soil_data WHERE plot_id = ? ORDER BY record_date DESC, soil_id DESC LIMIT 1');
    $s->execute([$plotId]);
    $soilRow = $s->fetch();
    if (!$soilRow) {
        $error = 'แปลงนี้ยังไม่มีข้อมูลดิน กรุณาบันทึกค่าดินก่อน';
        return null;
    }

    $h = $pdo->prepare('
        SELECT ch.crop_id, c.crop_name, c.plant_family, c.n_effect, ch.planting_date, ch.harvest_date, ch.yield_amount
        FROM crop_history ch JOIN crops c ON c.crop_id = ch.crop_id
        WHERE ch.plot_id = ? ORDER BY ch.planting_date DESC, ch.history_id DESC LIMIT 6');
    $h->execute([$plotId]);

    $crops = $pdo->query('
        SELECT c.crop_id, c.crop_name, c.crop_type, c.fertilizer_formula, c.soil_recovery_note,
               c.plant_family, c.ph_min, c.ph_max, c.n_effect, c.water_need, c.yield_per_rai,
               (SELECT m.market_price FROM market_data m WHERE m.crop_id = c.crop_id ORDER BY m.record_date DESC, m.market_id DESC LIMIT 1) AS price
        FROM crops c ORDER BY c.crop_name')->fetchAll();
    if (!$crops) {
        $error = 'ยังไม่มีข้อมูลพืชในระบบ กรุณาให้ผู้ดูแลระบบเพิ่มข้อมูลพืชก่อน';
        return null;
    }

    // ผลปลูกจริง: คะแนนพึงพอใจที่เกษตรกรให้หลังทำตามคำแนะนำ (รวมทุกผู้ใช้ เฉพาะตัวเลขสรุป ไม่ระบุตัวตน)
    $actual = [];
    foreach ($pdo->query('
        SELECT r.recommended_crop AS crop_name, COUNT(*) AS n, AVG(fb.outcome_rating) AS avg_rating
        FROM recommendation_feedback fb JOIN ai_recommendations r ON r.recommend_id = fb.recommend_id
        WHERE fb.followed = 1 AND fb.outcome_rating IS NOT NULL
        GROUP BY r.recommended_crop')->fetchAll() as $a) {
        $actual[$a['crop_name']] = ['n' => (int) $a['n'], 'avg' => (float) $a['avg_rating']];
    }

    return [
        'plot'      => $plot,
        'soil_row'  => $soilRow,
        'soil'      => [
            'ph'   => (float) $soilRow['ph_value'],
            'n'    => $soilRow['nitrogen'] !== null ? (float) $soilRow['nitrogen'] : null,
            'p'    => $soilRow['phosphorus'] !== null ? (float) $soilRow['phosphorus'] : null,
            'k'    => $soilRow['potassium'] !== null ? (float) $soilRow['potassium'] : null,
            'date' => $soilRow['record_date'],
        ],
        'has_water' => (bool) $plot['has_water_source'],
        'history'   => $h->fetchAll(),
        'crops'     => $crops,
        'actual'    => $actual,
    ];
}
