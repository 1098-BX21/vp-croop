<?php
/**
 * ตัวแสดงผลการ์ดคำแนะนำ AI (ใช้ร่วมกันทั้ง "ผลที่เพิ่งได้" และ "ประวัติคำแนะนำของแปลง")
 * สร้าง HTML ฝั่งเซิร์ฟเวอร์ที่เดียว เพื่อให้หน้าตาเหมือนกันและไม่ต้องเขียนซ้ำใน JavaScript
 */

/** แสดงคะแนนแบบกระชับ: 25 / 22.5 / -6.7 */
function adviceFmtScore(float $v): string
{
    $s = rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    return ($s === '-0' || $s === '') ? '0' : $s;
}

/** บรรทัด "ที่มาของคะแนน" 1 ด้าน */
function adviceRenderPart(string $key, array $p): string
{
    $label = cropScorePartLabel($key);
    $score = $key === 'actual'
        ? adviceFmtScore((float) $p['score']) . ' (±10)'
        : adviceFmtScore((float) $p['score']) . '/' . adviceFmtScore((float) $p['max']);
    $ratio = $p['max'] > 0 ? $p['score'] / $p['max'] : 0;
    $cls = $key === 'actual' ? 'text-muted' : ($ratio >= 0.8 ? 'text-success' : ($ratio < 0.4 ? 'text-danger' : 'text-warning'));
    return '<li><b>' . e($label) . ':</b> <span class="fw-semibold ' . $cls . '">' . e($score) . '</span> — ' . e($p['note']) . '</li>';
}

/**
 * การ์ดคำแนะนำ 1 รายการ
 * @param array $rec แถวจาก ai_recommendations (+ plot_name, farm_name)
 * @param array $opts fresh=true (ผลที่เพิ่งได้), ai_warning=ข้อความเตือนเมื่อ AI ไม่พร้อม
 */
function adviceRenderCard(array $rec, array $opts = []): string
{
    $detail = !empty($rec['score_detail']) ? json_decode($rec['score_detail'], true) : null;
    $fresh = !empty($opts['fresh']);
    $when = formatDateThai($rec['created_at']) . ' ' . date('H:i', strtotime($rec['created_at'])) . ' น.';
    $plotLabel = trim(($rec['farm_name'] ?? '') . ' - ' . ($rec['plot_name'] ?? ''), ' -');

    $h = '<div class="app-card p-3 mb-3 advice-card' . ($fresh ? ' border border-success' : '') . '" data-rec-id="' . (int) $rec['recommend_id'] . '">';
    $h .= '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">'
        . '<div class="small text-muted"><i class="bi bi-clock me-1"></i>' . e($when) . ($plotLabel !== '' ? ' · ' . e($plotLabel) : '') . '</div>'
        . ($fresh ? '<span class="badge badge-soft-green rounded-pill px-3 py-2"><i class="bi bi-check-circle me-1"></i>วิเคราะห์สำเร็จ</span>' : '')
        . '</div>';

    if (!empty($opts['ai_warning'])) {
        $h .= '<div class="alert alert-warning small py-2"><i class="bi bi-exclamation-triangle me-1"></i>'
            . 'AI ไม่พร้อมสร้างคำอธิบายในขณะนี้ จึงแสดงผลจากการคำนวณคะแนนของระบบ (' . e($opts['ai_warning']) . ')</div>';
    }

    if ($detail && !empty($detail['ranking'])) {
        $rank = 0;
        foreach (array_slice($detail['ranking'], 0, 3) as $item) {
            $rank++;
            $fam = !empty($item['family']) ? ' <span class="small text-muted">(วงศ์' . e($item['family']) . ')</span>' : '';
            $pct = max(0, min(100, (float) $item['total']));
            $h .= '<div class="advice-rank py-2 border-bottom">'
                . '<div class="d-flex align-items-baseline gap-2 flex-wrap">'
                . '<span class="fw-bold' . ($rank === 1 ? ' text-success fs-5' : '') . '">' . $rank . '. ' . e($item['crop_name']) . '</span>' . $fam
                . '<span class="ms-auto fw-bold ' . ($rank === 1 ? 'text-success fs-5' : '') . '">' . number_format((float) $item['total'], 1) . '</span></div>'
                . '<div class="progress my-1" style="height:6px;"><div class="progress-bar bg-success" style="width:' . $pct . '%"></div></div>'
                . '<details><summary class="small text-success" style="cursor:pointer;">ดูที่มาของคะแนน</summary><ul class="small mt-2 mb-1 ps-3">';
            foreach ($item['parts'] as $key => $p) {
                $h .= adviceRenderPart($key, $p);
            }
            $h .= '</ul></details>';
            if (!empty($item['crop_id'])) {
                // "ถ้านำไปปลูกจริง": ระยะต่างๆ ของพืชนี้ + ปุ่มเริ่มปลูกจริง (สร้างแผนให้ AI ช่วยวางแผน)
                $h .= growRenderPreview(getDBConnection(), ['crop_id' => $item['crop_id'], 'crop_name' => $item['crop_name']], (int) $rec['recommend_id'], (int) $rec['plot_id']);
            }
            $h .= '</div>';
        }
        foreach ($detail['excluded'] ?? [] as $ex) {
            $h .= '<p class="small text-danger mt-2 mb-0"><i class="bi bi-slash-circle me-1"></i><b>ไม่แนะนำ:</b> ' . e($ex['crop_name']) . ' — ' . e($ex['reason']) . '</p>';
        }
    } else {
        // คำแนะนำรุ่นเก่า (ก่อนมีระบบให้คะแนน)
        $h .= '<h6 class="fw-bold text-success mb-1"><i class="bi bi-flower3 me-1"></i> พืชที่แนะนำ: ' . e($rec['recommended_crop']) . '</h6>'
            . '<p class="small text-muted mb-0">คำแนะนำรุ่นเก่า (ก่อนมีระบบให้คะแนน)</p>';
    }

    if (!empty($rec['reason'])) {
        $h .= '<p class="mt-3 mb-2">' . nl2br(e($rec['reason'])) . '</p>';
    }
    if (!empty($rec['fertilizer_plan'])) {
        $h .= '<div class="p-3 rounded-3 mb-2" style="background:var(--primary-lighter);border:1px solid var(--primary-light);">'
            . '<h6 class="fw-bold mb-1"><i class="bi bi-droplet-half me-1"></i> แผนการใส่ปุ๋ยที่แนะนำ</h6>'
            . '<p class="mb-0">' . nl2br(e($rec['fertilizer_plan'])) . '</p></div>';
    }
    if (!empty($rec['disease_analysis'])) {
        $h .= '<div class="p-3 rounded-3 mb-2" style="background:#fff7ed;border:1px solid #fed7aa;">'
            . '<h6 class="fw-bold mb-1"><i class="bi bi-bug me-1"></i> ผลวิเคราะห์ภาพ (ข้อมูลเดิม)</h6>'
            . '<p class="mb-0">' . e($rec['disease_analysis']) . '</p></div>';
    }

    $by = !empty($rec['explained_by'])
        ? 'คำอธิบายโดย: ' . e($rec['explained_by'])
        : ($detail ? 'คำอธิบายสร้างจากกฎของระบบ (AI ไม่พร้อมในขณะนั้น)' : '');
    $h .= '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-2">'
        . '<span class="small text-muted">' . $by . '</span>'
        . '<a class="small" href="give_feedback.php?open=' . (int) $rec['recommend_id'] . '"><i class="bi bi-reply me-1"></i>รายงานผลหลังทำตามคำแนะนำ</a></div>';
    return $h . '</div>';
}

/** ประวัติคำแนะนำของแปลงหนึ่ง (ผู้เรียกต้องตรวจสิทธิ์เจ้าของแปลงก่อน) */
function adviceRenderHistory(PDO $pdo, int $plotId, int $limit = 10, int $skipId = 0): string
{
    $stmt = $pdo->prepare('
        SELECT r.*, p.plot_name, f.farm_name
        FROM ai_recommendations r
        JOIN plots p ON p.plot_id = r.plot_id
        JOIN farms f ON f.farm_id = p.farm_id
        WHERE r.plot_id = :plot AND r.recommend_id <> :skip
        ORDER BY r.created_at DESC, r.recommend_id DESC
        LIMIT :lim');
    $stmt->bindValue(':plot', $plotId, PDO::PARAM_INT);
    $stmt->bindValue(':skip', $skipId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return '<p class="text-muted mb-0">ยังไม่มีคำแนะนำก่อนหน้าของแปลงนี้</p>';
    }
    $h = '';
    foreach ($rows as $r) {
        $h .= adviceRenderCard($r);
    }
    return $h;
}
