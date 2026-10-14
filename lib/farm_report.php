<?php
/**
 * ============================================================
 * ข้อมูลรายงานของเกษตรกร (หน้า "รายงาน")
 *
 * เน้นข้อมูลที่ "ใช้ตัดสินใจ/ลงมือทำ" ไม่ใช่แค่กราฟ:
 *   1) สิ่งที่ควรทำ    — แจ้งเตือนรายแปลงจากกฎ (ดินกรด/ด่าง ธาตุอาหารต่ำ ปลูกวงศ์เดิมซ้ำ แผนเลยกำหนด ฯลฯ)
 *   2) แผนปลูกที่กำลังดำเนินการ — ความคืบหน้า ระยะปัจจุบัน วันเก็บเกี่ยวที่คาด
 *   3) ผลผลิตและรายได้โดยประมาณ — ผลผลิต × ราคาอ้างอิงในระบบ แยกตามพืช
 *   4) สถานะดินล่าสุดรายแปลง — ระดับ N/P/K และ pH (แทนแนวโน้ม pH เดิม)
 *   5) สุขภาพการหมุนเวียนพืช — พืชล่าสุดของแต่ละแปลง และการปลูกวงศ์เดียวกันติดกัน
 *   6) การใช้ AI — คำแนะนำที่ได้/ที่นำไปปลูกจริง ความพึงพอใจ และผลวิเคราะห์โรคพืช
 * ============================================================
 */

const REPORT_SOIL_OLD_DAYS = 180;   // ค่าดินเก่ากว่านี้ถือว่าควรตรวจใหม่
const REPORT_PH_LOW = 5.5;
const REPORT_PH_HIGH = 7.5;

/** ลำดับความสำคัญของแจ้งเตือน */
function reportLevelRank(string $level): int
{
    return ['danger' => 0, 'warning' => 1, 'info' => 2][$level] ?? 3;
}

/**
 * แจ้งเตือนจากสถานะดินของแปลงหนึ่ง (ฟังก์ชันบริสุทธิ์ ทดสอบได้)
 * @param array|null $soil แถวค่าดินล่าสุด (ph_value, nitrogen, phosphorus, potassium, record_date) หรือ null
 * @return array[] [['level','text','link']]
 */
function reportSoilAlerts(?array $soil, string $today): array
{
    if (!$soil) {
        return [['level' => 'info', 'text' => 'ยังไม่มีค่าดินของแปลงนี้ บันทึกค่าดินเพื่อให้ AI แนะนำพืชได้แม่นยำขึ้น', 'link' => 'soil_data.php']];
    }
    $out = [];
    $ageDays = (int) (new DateTime($soil['record_date']))->diff(new DateTime($today))->format('%r%a');
    if ($ageDays > REPORT_SOIL_OLD_DAYS) {
        $out[] = ['level' => 'info', 'text' => 'ค่าดินล่าสุดบันทึกเมื่อ ' . formatDateThai($soil['record_date']) . ' (นานกว่า 6 เดือน) ควรตรวจดินใหม่ก่อนวางแผนรอบถัดไป', 'link' => 'soil_data.php'];
    }
    $ph = (float) $soil['ph_value'];
    if ($ph < REPORT_PH_LOW) {
        $out[] = ['level' => 'warning', 'text' => 'ดินเป็นกรด (pH ' . number_format($ph, 1) . ') ควรปรับปรุงดิน เช่น ใส่ปูนขาวหรือวัสดุปรับปรุงดินก่อนปลูกรอบถัดไป ปรึกษาเจ้าหน้าที่เกษตรเรื่องปริมาณ', 'link' => 'ai_advice.php'];
    } elseif ($ph > REPORT_PH_HIGH) {
        $out[] = ['level' => 'warning', 'text' => 'ดินเป็นด่าง (pH ' . number_format($ph, 1) . ') ควรเพิ่มอินทรียวัตถุ เช่น ปุ๋ยหมัก ปุ๋ยพืชสด เพื่อช่วยปรับสภาพดิน', 'link' => 'ai_advice.php'];
    }
    foreach ([['nitrogen', 'N', 'ไนโตรเจน', 'ควรพิจารณาพืชตระกูลถั่วหรือปุ๋ยพืชสดเพื่อเพิ่มไนโตรเจน'],
              ['phosphorus', 'P', 'ฟอสฟอรัส', 'ควรเสริมปุ๋ยที่มีฟอสฟอรัสตามคำแนะนำของเจ้าหน้าที่เกษตร'],
              ['potassium', 'K', 'โพแทสเซียม', 'ควรเสริมปุ๋ยที่มีโพแทสเซียมตามคำแนะนำของเจ้าหน้าที่เกษตร']] as [$col, $key, $th, $hint]) {
        if ($soil[$col] !== null && cropScoreLevel((float) $soil[$col], $key) === 'low') {
            $out[] = ['level' => 'info', 'text' => $th . 'ในดินอยู่ในระดับต่ำ (' . rtrim(rtrim(number_format((float) $soil[$col], 2), '0'), '.') . ' มก./กก.) ' . $hint, 'link' => 'ai_advice.php'];
        }
    }
    return $out;
}

/**
 * แจ้งเตือนการหมุนเวียนพืชของแปลงหนึ่ง จากประวัติ (ล่าสุดก่อน)
 * @param array $history [['crop_name','plant_family','crop_id'], ...]
 */
function reportRotationAlerts(array $history): array
{
    if (count($history) < 2) {
        return [];
    }
    [$a, $b] = [$history[0], $history[1]];
    if ((int) $a['crop_id'] === (int) $b['crop_id']) {
        return [['level' => 'warning', 'text' => 'ปลูก' . $a['crop_name'] . 'ซ้ำติดกัน 2 รอบ ดินเสื่อมและโรคสะสมได้ รอบหน้าควรเปลี่ยนชนิดพืช', 'link' => 'ai_advice.php']];
    }
    if (!empty($a['plant_family']) && $a['plant_family'] === $b['plant_family']) {
        return [['level' => 'warning', 'text' => 'ปลูกพืชวงศ์เดียวกันติดกัน (' . $b['crop_name'] . ' → ' . $a['crop_name'] . ' วงศ์' . $a['plant_family'] . ') เสี่ยงโรคสะสม รอบหน้าควรเปลี่ยนวงศ์', 'link' => 'ai_advice.php']];
    }
    return [];
}

/** รายได้โดยประมาณจากผลผลิต (กก.) × ราคาล่าสุด (บาท/กก.) null ถ้าไม่มีราคา */
function reportIncome(?float $yield, ?float $price): ?float
{
    return ($yield !== null && $price !== null && $price > 0) ? $yield * $price : null;
}

/**
 * รวบรวมข้อมูลรายงานทั้งหมดของผู้ใช้
 */
function farmerReport(PDO $pdo, int $uid, ?string $today = null): array
{
    $today = $today ?? date('Y-m-d');

    $ps = $pdo->prepare('SELECT p.plot_id, p.plot_name, f.farm_name FROM plots p JOIN farms f ON f.farm_id = p.farm_id WHERE f.user_id = ? ORDER BY f.farm_name, p.plot_name');
    $ps->execute([$uid]);
    $plots = $ps->fetchAll();

    // ค่าดินล่าสุดของแต่ละแปลง
    $ss = $pdo->prepare('
        SELECT s.* FROM soil_data s JOIN plots p ON p.plot_id = s.plot_id JOIN farms f ON f.farm_id = p.farm_id
        WHERE f.user_id = ? AND s.soil_id = (SELECT s2.soil_id FROM soil_data s2 WHERE s2.plot_id = s.plot_id ORDER BY s2.record_date DESC, s2.soil_id DESC LIMIT 1)');
    $ss->execute([$uid]);
    $soilByPlot = [];
    foreach ($ss->fetchAll() as $r) {
        $soilByPlot[(int) $r['plot_id']] = $r;
    }

    // ประวัติการปลูกของทุกแปลง (ล่าสุดก่อน) + ข้อมูลพืช
    $hs = $pdo->prepare('
        SELECT ch.history_id, ch.plot_id, ch.planting_date, ch.harvest_date, ch.yield_amount, c.crop_id, c.crop_name, c.plant_family
        FROM crop_history ch JOIN plots p ON p.plot_id = ch.plot_id JOIN farms f ON f.farm_id = p.farm_id JOIN crops c ON c.crop_id = ch.crop_id
        WHERE f.user_id = ? ORDER BY ch.planting_date DESC, ch.history_id DESC');
    $hs->execute([$uid]);
    $historyAll = $hs->fetchAll();
    $histByPlot = [];
    foreach ($historyAll as $h) {
        $histByPlot[(int) $h['plot_id']][] = $h;
    }

    // แผนที่กำลังปลูก + ความคืบหน้า
    $as = $pdo->prepare("
        SELECT g.*, c.crop_name, p.plot_name, f.farm_name FROM grow_plans g
        JOIN crops c ON c.crop_id = g.crop_id JOIN plots p ON p.plot_id = g.plot_id JOIN farms f ON f.farm_id = p.farm_id
        WHERE g.user_id = ? AND g.status = 'active' ORDER BY g.start_date");
    $as->execute([$uid]);
    $stSt = $pdo->prepare('SELECT * FROM grow_plan_stages WHERE plan_id = ? ORDER BY stage_order');
    $plans = [];
    foreach ($as->fetchAll() as $pl) {
        $stSt->execute([$pl['plan_id']]);
        $stages = $stSt->fetchAll();
        $pg = growPlanProgress($stages, $pl['start_date'], $today);
        $pl['progress'] = $pg;
        $pl['focus_name'] = $pg['focus'] !== null ? $stages[$pg['focus']]['stage_name'] : null;
        $pl['focus_overdue_stage'] = null;
        foreach ($pg['states'] as $i => $st) {
            if ($st === 'overdue') {
                $pl['focus_overdue_stage'] = $stages[$i];
                break;
            }
        }
        $plans[] = $pl;
    }

    /* ---------- แจ้งเตือน ---------- */
    $alerts = [];
    $attention = [];
    foreach ($plots as $p) {
        $pid = (int) $p['plot_id'];
        $label = $p['farm_name'] . ' / ' . $p['plot_name'];
        $found = array_merge(reportSoilAlerts($soilByPlot[$pid] ?? null, $today), reportRotationAlerts($histByPlot[$pid] ?? []));
        foreach ($found as $a) {
            $a['plot'] = $label;
            $alerts[] = $a;
            if (in_array($a['level'], ['danger', 'warning'], true)) {
                $attention[$pid] = true;
            }
        }
    }
    $dueSoon = 0;
    foreach ($plans as $pl) {
        $pg = $pl['progress'];
        $label = $pl['farm_name'] . ' / ' . $pl['plot_name'];
        $link = 'grow_plan.php?plan=' . $pl['plan_id'];
        if ($pl['focus_overdue_stage']) {
            $alerts[] = ['level' => 'danger', 'plot' => $label, 'link' => $link,
                'text' => 'แผนปลูก' . $pl['crop_name'] . ': ระยะ "' . $pl['focus_overdue_stage']['stage_name'] . '" เลยกำหนดมา ' . ($pg['day'] - (int) $pl['focus_overdue_stage']['day_to']) . ' วัน ถ้าทำเสร็จแล้วให้ติ๊กในแผน'];
            $attention[(int) $pl['plot_id']] = true;
        }
        if ($pg['day'] > $pg['total_days']) {
            $alerts[] = ['level' => 'warning', 'plot' => $label, 'link' => $link, 'text' => 'แผนปลูก' . $pl['crop_name'] . ' ครบกำหนดเก็บเกี่ยวโดยประมาณแล้ว ถ้าเก็บเกี่ยวแล้วให้บันทึกผลเก็บเกี่ยว'];
            $attention[(int) $pl['plot_id']] = true;
        } elseif ($pg['days_left'] >= 0 && $pg['days_left'] <= 7) {
            $dueSoon++;
            $alerts[] = ['level' => 'info', 'plot' => $label, 'link' => $link, 'text' => 'แผนปลูก' . $pl['crop_name'] . ' ใกล้ถึงเวลาเก็บเกี่ยว (อีกประมาณ ' . $pg['days_left'] . ' วัน)'];
        } elseif ($pg['days_left'] > 7 && $pg['days_left'] <= 30) {
            $dueSoon++;
        }
    }
    // เก็บเกี่ยวแล้วแต่ยังไม่รายงานผลตอบกลับ
    $fs = $pdo->prepare("
        SELECT g.plan_id, g.recommend_id, c.crop_name, p.plot_name, f.farm_name FROM grow_plans g
        JOIN crops c ON c.crop_id = g.crop_id JOIN plots p ON p.plot_id = g.plot_id JOIN farms f ON f.farm_id = p.farm_id
        WHERE g.user_id = ? AND g.status = 'harvested' AND g.recommend_id IS NOT NULL
          AND NOT EXISTS (SELECT 1 FROM recommendation_feedback fb WHERE fb.recommend_id = g.recommend_id AND fb.user_id = g.user_id)
        ORDER BY g.harvest_date DESC LIMIT 5");
    $fs->execute([$uid]);
    foreach ($fs->fetchAll() as $r) {
        $alerts[] = ['level' => 'info', 'plot' => $r['farm_name'] . ' / ' . $r['plot_name'], 'link' => 'give_feedback.php?open=' . $r['recommend_id'],
            'text' => 'เก็บเกี่ยว' . $r['crop_name'] . 'แล้ว แต่ยังไม่ได้รายงานผลตอบกลับ (แบ่งปันให้เกษตรกรท่านอื่นเรียนรู้ได้ ผู้ดูแลระบบตรวจก่อนขึ้นเว็บไซต์)'];
    }
    usort($alerts, fn($a, $b) => reportLevelRank($a['level']) <=> reportLevelRank($b['level']));

    /* ---------- ผลผลิตและรายได้โดยประมาณ ---------- */
    $ys = $pdo->prepare('
        SELECT c.crop_id, c.crop_name, COUNT(*) AS times, SUM(ch.yield_amount) AS total_yield, AVG(ch.yield_amount) AS avg_yield,
               (SELECT m.market_price FROM market_data m WHERE m.crop_id = c.crop_id ORDER BY m.record_date DESC, m.market_id DESC LIMIT 1) AS price
        FROM crop_history ch JOIN plots p ON p.plot_id = ch.plot_id JOIN farms f ON f.farm_id = p.farm_id JOIN crops c ON c.crop_id = ch.crop_id
        WHERE f.user_id = ? AND ch.yield_amount IS NOT NULL
        GROUP BY c.crop_id, c.crop_name ORDER BY total_yield DESC');
    $ys->execute([$uid]);
    $crops = [];
    $totalYield = 0.0;
    $totalIncome = 0.0;
    $harvests = 0;
    foreach ($ys->fetchAll() as $r) {
        $r['income'] = reportIncome((float) $r['total_yield'], $r['price'] !== null ? (float) $r['price'] : null);
        $crops[] = $r;
        $totalYield += (float) $r['total_yield'];
        $totalIncome += $r['income'] ?? 0;
        $harvests += (int) $r['times'];
    }

    /* ---------- สถานะดินและการหมุนเวียนรายแปลง ---------- */
    $soils = [];
    $rotation = [];
    foreach ($plots as $p) {
        $pid = (int) $p['plot_id'];
        $soil = $soilByPlot[$pid] ?? null;
        $soils[] = [
            'plot' => $p['farm_name'] . ' / ' . $p['plot_name'],
            'date' => $soil['record_date'] ?? null,
            'ph'   => $soil ? (float) $soil['ph_value'] : null,
            'n'    => $soil && $soil['nitrogen'] !== null ? cropScoreLevel((float) $soil['nitrogen'], 'N') : null,
            'p'    => $soil && $soil['phosphorus'] !== null ? cropScoreLevel((float) $soil['phosphorus'], 'P') : null,
            'k'    => $soil && $soil['potassium'] !== null ? cropScoreLevel((float) $soil['potassium'], 'K') : null,
        ];
        $h = $histByPlot[$pid] ?? [];
        $rot = reportRotationAlerts($h);
        $rotation[] = [
            'plot'   => $p['farm_name'] . ' / ' . $p['plot_name'],
            'last'   => $h[0]['crop_name'] ?? null,
            'family' => $h[0]['plant_family'] ?? null,
            'prev'   => $h[1]['crop_name'] ?? null,
            'count'  => count($h),
            'ok'     => count($h) >= 2 ? empty($rot) : null,   // null = ข้อมูลยังไม่พอประเมิน
        ];
    }

    /* ---------- การใช้ AI ---------- */
    $one = function (string $sql, array $params) use ($pdo) {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetch();
    };
    $recs = (int) $one('SELECT COUNT(*) c FROM ai_recommendations r JOIN plots p ON p.plot_id = r.plot_id JOIN farms f ON f.farm_id = p.farm_id WHERE f.user_id = ?', [$uid])['c'];
    $planned = (int) $one('SELECT COUNT(DISTINCT recommend_id) c FROM grow_plans WHERE user_id = ? AND recommend_id IS NOT NULL', [$uid])['c'];
    $fb = $one('SELECT COUNT(*) c, AVG(outcome_rating) a FROM recommendation_feedback WHERE user_id = ?', [$uid]);
    $sc = $one("SELECT COUNT(*) c, SUM(status = 'diseased') d FROM plant_disease_scans WHERE user_id = ?", [$uid]);
    $td = $pdo->prepare("SELECT disease_name, COUNT(*) n FROM plant_disease_scans WHERE user_id = ? AND status = 'diseased' GROUP BY disease_name ORDER BY n DESC, disease_name LIMIT 3");
    $td->execute([$uid]);

    // การทำตามแผน AI: ระยะที่ติ๊กว่าทำแล้ว เทียบกับระยะที่ข้าม (ไม่ได้ทำตาม) + เหตุผลที่ข้ามบ่อย
    $fo = $one("SELECT COALESCE(SUM(s.is_done),0) d, COALESCE(SUM(s.is_skipped),0) k
                FROM grow_plan_stages s JOIN grow_plans g ON g.plan_id = s.plan_id WHERE g.user_id = ? AND g.status <> 'cancelled'", [$uid]);
    $followDone = (int) $fo['d'];
    $followSkipped = (int) $fo['k'];
    $sr = $pdo->prepare("SELECT s.skip_type, COUNT(*) n FROM grow_plan_stages s JOIN grow_plans g ON g.plan_id = s.plan_id
                         WHERE g.user_id = ? AND g.status <> 'cancelled' AND s.is_skipped = 1 GROUP BY s.skip_type ORDER BY n DESC, s.skip_type LIMIT 3");
    $sr->execute([$uid]);
    $skipReasons = [];
    foreach ($sr->fetchAll() as $r) {
        $skipReasons[] = ['label' => GROW_SKIP_REASONS[$r['skip_type']] ?? 'ไม่ระบุ', 'n' => (int) $r['n']];
    }

    return [
        'today'    => $today,
        'kpi'      => [
            'active_plans'    => count($plans),
            'due_soon'        => $dueSoon,
            'total_yield'     => $totalYield,
            'harvest_count'   => $harvests,
            'income'          => $totalIncome,
            'attention_plots' => count($attention),
            'plot_count'      => count($plots),
        ],
        'alerts'   => $alerts,
        'plans'    => $plans,
        'crops'    => $crops,
        'soils'    => $soils,
        'rotation' => $rotation,
        'ai'       => [
            'recs'           => $recs,
            'planned'        => $planned,
            'plan_rate'      => $recs > 0 ? round($planned / $recs * 100) : null,
            'feedback'       => (int) $fb['c'],
            'avg_rating'     => $fb['a'] !== null ? round((float) $fb['a'], 1) : null,
            'scans'          => (int) $sc['c'],
            'diseased'       => (int) ($sc['d'] ?? 0),
            'top_diseases'   => $td->fetchAll(),
            'follow_done'    => $followDone,
            'follow_skipped' => $followSkipped,
            'follow_rate'    => ($followDone + $followSkipped) > 0 ? (int) round($followDone / ($followDone + $followSkipped) * 100) : null,
            'skip_reasons'   => $skipReasons,
        ],
    ];
}
