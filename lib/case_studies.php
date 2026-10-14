<?php
/**
 * ============================================================
 * กรณีศึกษาที่แสดงบนหน้าเว็บไซต์ — รวม 2 แหล่ง:
 *   1) ผลตอบกลับของเกษตรกร (recommendation_feedback) ที่เกษตรกร "ยินยอมเผยแพร่" + ผู้ดูแล "อนุมัติ"
 *      ผู้ดูแลปรับหัวข้อ/ข้อความที่จะแสดงได้ (public_title/public_summary) ข้อความต้นฉบับของเกษตรกรไม่ถูกแก้
 *   2) กรณีศึกษาที่ผู้ดูแลบันทึกเอง (site_case_studies) — เป็นฉบับร่างจนกว่าจะกด "เผยแพร่"
 *      หน้าเว็บแสดงป้าย "บันทึกโดยผู้ดูแลระบบ" ให้ผู้อ่านแยกออกจากผลที่เกษตรกรรายงานในระบบ
 * หน้าเว็บสาธารณะไม่ดึงชื่อ/อีเมล/ที่อยู่/ชื่อฟาร์มของเกษตรกรเลย
 * ============================================================
 */

/** ส่วน SQL ของผลตอบกลับเกษตรกร (ใช้ซ้ำทั้งหน้าเว็บและหน้า Admin) — ข้อมูลแผนปลูกจริงที่เก็บเกี่ยวแล้วล่าสุด */
function caseFeedbackSelect(bool $withPrivate = false): string
{
    return "
    SELECT 'feedback' AS kind, fb.feedback_id AS id, r.recommended_crop AS crop_name,
           fb.public_title, fb.public_summary, fb.outcome_description, fb.outcome_rating AS rating, fb.followed,
           COALESCE(fb.yield_amount, gp.yield_amount) AS yield_amount, fb.result_image, fb.created_at,
           fb.is_public, fb.admin_approved AS published, fb.approved_at AS published_at, fb.approved_by AS published_by,
           DATEDIFF(gp.harvest_date, gp.start_date) + 1 AS plan_days,
           (SELECT MAX(s.day_to) FROM grow_plan_stages s WHERE s.plan_id = gp.plan_id) AS plan_total_days,
           (SELECT COUNT(*) FROM grow_plan_stages s WHERE s.plan_id = gp.plan_id AND s.is_done = 1) AS plan_done,
           (SELECT COUNT(*) FROM grow_plan_stages s WHERE s.plan_id = gp.plan_id AND s.is_skipped = 1) AS plan_skipped,
           (SELECT COUNT(*) FROM grow_plan_stages s WHERE s.plan_id = gp.plan_id) AS plan_stage_count"
        . ($withPrivate ? ",
           u.full_name AS author_name,
           (SELECT GROUP_CONCAT(CONCAT(s.stage_name, ': ', s.skip_reason) SEPARATOR ' | ') FROM grow_plan_stages s WHERE s.plan_id = gp.plan_id AND s.is_skipped = 1) AS plan_skip_list" : "") . "
    FROM recommendation_feedback fb
    JOIN ai_recommendations r ON r.recommend_id = fb.recommend_id"
        . ($withPrivate ? "
    JOIN users u ON u.user_id = fb.user_id" : "") . "
    LEFT JOIN grow_plans gp ON gp.plan_id = (
        SELECT g2.plan_id FROM grow_plans g2 WHERE g2.recommend_id = fb.recommend_id AND g2.user_id = fb.user_id AND g2.status = 'harvested'
        ORDER BY g2.plan_id DESC LIMIT 1)";
}

function caseManualSelect(bool $withPrivate = false): string
{
    return "
    SELECT 'manual' AS kind, sc.case_id AS id, sc.crop_name, sc.title AS public_title, NULL AS public_summary, sc.description AS outcome_description,
           sc.rating, NULL AS followed, sc.yield_amount, sc.result_image, sc.created_at,
           1 AS is_public, sc.is_published AS published, sc.published_at, sc.published_by,
           sc.plan_days, NULL AS plan_total_days, 0 AS plan_done, 0 AS plan_skipped, 0 AS plan_stage_count"
        . ($withPrivate ? ", u.full_name AS author_name, NULL AS plan_skip_list" : "") . "
    FROM site_case_studies sc" . ($withPrivate ? " LEFT JOIN users u ON u.user_id = sc.created_by" : "");
}

/** หัวข้อ/ข้อความที่จะแสดงบนเว็บ (ใช้ข้อความที่ผู้ดูแลปรับถ้ามี ไม่งั้นใช้ต้นฉบับ) */
function casePublicTitle(array $c): string
{
    return trim((string) $c['public_title']) !== '' ? $c['public_title'] : 'ผลการปลูก' . $c['crop_name'];
}

function casePublicText(array $c): string
{
    if (trim((string) $c['public_summary']) !== '') {
        return $c['public_summary'];
    }
    return trim((string) $c['outcome_description']) !== '' ? $c['outcome_description'] : 'เกษตรกรนำคำแนะนำไปใช้จริงแล้ว (ไม่มีรายละเอียดเพิ่มเติม)';
}

/**
 * กรณีศึกษาที่เผยแพร่แล้วทั้งหมด (สำหรับหน้าเว็บสาธารณะ) เรียงใหม่สุดก่อน
 */
function caseStudiesPublic(PDO $pdo, string $crop = '', int $limit = 60): array
{
    $fb = caseFeedbackSelect() . " WHERE fb.is_public = 1 AND fb.admin_approved = 1" . ($crop !== '' ? " AND r.recommended_crop = :c1" : '');
    $mn = caseManualSelect() . " WHERE sc.is_published = 1" . ($crop !== '' ? " AND sc.crop_name = :c2" : '');
    $st = $pdo->prepare("SELECT * FROM ($fb UNION ALL $mn) x ORDER BY COALESCE(x.published_at, x.created_at) DESC, x.created_at DESC LIMIT " . max(1, $limit));
    $st->execute($crop !== '' ? [':c1' => $crop, ':c2' => $crop] : []);
    return $st->fetchAll();
}

/** ชนิดพืชที่มีกรณีศึกษาเผยแพร่ (ใช้ทำตัวกรอง) */
function caseStudyCropsPublic(PDO $pdo): array
{
    return $pdo->query("
        SELECT DISTINCT crop_name FROM (
          SELECT r.recommended_crop AS crop_name FROM recommendation_feedback fb JOIN ai_recommendations r ON r.recommend_id = fb.recommend_id
          WHERE fb.is_public = 1 AND fb.admin_approved = 1
          UNION SELECT crop_name FROM site_case_studies WHERE is_published = 1) x ORDER BY crop_name")->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * การ์ดกรณีศึกษาแบบที่แสดงบนหน้าเว็บ (ใช้ร่วมกันทั้งหน้าเว็บ หน้าแรก และ "ตัวอย่างก่อนเผยแพร่" ในหน้า Admin)
 * @param bool $compact แบบย่อสำหรับหน้าแรก (ตัดข้อความ ไม่แสดงรูป)
 */
function caseRenderCard(array $c, bool $compact = false): string
{
    $text = casePublicText($c);
    if ($compact && mb_strlen($text) > 120) {
        $text = mb_substr($text, 0, 120) . '...';
    }
    $h = '<div class="app-card p-4 h-100 d-flex flex-column case-card">';
    if (!$compact && $c['result_image']) {
        $dir = $c['kind'] === 'manual' ? 'case_studies' : 'feedback';
        $h .= '<img src="' . BASE_URL . '/uploads/' . $dir . '/' . e($c['result_image']) . '" class="rounded-3 mb-3" style="width:100%;height:180px;object-fit:cover;" alt="ผลลัพธ์">';
    }
    $h .= '<div class="d-flex justify-content-between align-items-start gap-2 mb-1"><h6 class="fw-bold mb-0">🌾 ' . e(casePublicTitle($c)) . '</h6>'
        . ($c['rating'] ? '<span class="small text-nowrap">' . str_repeat('⭐', (int) $c['rating']) . '</span>' : '') . '</div>';
    $h .= '<div class="mb-2"><span class="badge badge-soft-blue me-1">' . e($c['crop_name']) . '</span>'
        . ($c['kind'] === 'manual'
            ? '<span class="badge badge-soft-amber">บันทึกโดยผู้ดูแลระบบ</span>'
            : ($c['followed'] ? '<span class="badge badge-soft-green">ทำตามคำแนะนำ AI</span>' : '')) . '</div>';
    $h .= '<p class="small flex-grow-1">' . nl2br(e($text)) . '</p>';
    if ($c['yield_amount']) {
        $h .= '<p class="small text-muted mb-1"><i class="bi bi-basket"></i> ผลผลิต: ' . number_format((float) $c['yield_amount'], 2) . ' กก.</p>';
    }
    if ($c['plan_days']) {
        $h .= '<p class="small text-muted mb-1"><i class="bi bi-calendar2-check"></i> ปลูกจริง ' . (int) $c['plan_days'] . ' วัน'
            . ($c['plan_total_days'] ? ' (แผนประมาณ ' . (int) $c['plan_total_days'] . ' วัน)' : '') . '</p>';
        if ((int) $c['plan_done'] + (int) $c['plan_skipped'] > 0) {
            $h .= '<p class="small text-muted mb-1"><i class="bi bi-list-check"></i> ทำตามแผน ' . (int) $c['plan_done'] . '/' . (int) $c['plan_stage_count'] . ' ระยะ'
                . ($c['plan_skipped'] ? ' · ไม่ได้ทำตาม ' . (int) $c['plan_skipped'] . ' ระยะ' : '') . '</p>';
        }
    }
    $h .= '<p class="small text-muted mb-0"><i class="bi bi-patch-check"></i> ผ่านการตรวจสอบโดยผู้ดูแลระบบ · ' . formatDateThai($c['published_at'] ?: $c['created_at']) . '</p>';
    return $h . '</div>';
}
