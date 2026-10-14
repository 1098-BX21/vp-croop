<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
requireCsrf('ai_advice.php');

$uid = currentUser()['user_id'];
$pdo = getDBConnection();
$recommendId = (int) ($_POST['recommend_id'] ?? 0);

// ดึงคำแนะนำ + ตรวจสิทธิ์
$stmt = $pdo->prepare('
  SELECT r.*, p.plot_id FROM ai_recommendations r
  JOIN plots p ON p.plot_id = r.plot_id
  JOIN farms f ON f.farm_id = p.farm_id
  WHERE r.recommend_id = ? AND f.user_id = ?
');
$stmt->execute([$recommendId, $uid]);
$rec = $stmt->fetch();

if (!$rec) {
    flash('error', 'ไม่พบคำแนะนำนี้');
    redirect('ai_advice.php');
}

// เลือกพืช: ถ้าระบุ crop_id มา (ปุ่มของพืชอันดับ 1-3) ต้องเป็นพืชที่อยู่ในผลจัดอันดับของคำแนะนำนี้จริง
$detail = !empty($rec['score_detail']) ? json_decode($rec['score_detail'], true) : null;
$rankInfo = '';
$cropId = (int) ($_POST['crop_id'] ?? 0);
if ($cropId > 0) {
    $found = null;
    foreach (($detail['ranking'] ?? []) as $i => $item) {
        if ((int) $item['crop_id'] === $cropId) {
            $found = $item;
            $rankInfo = 'อันดับ ' . ($i + 1) . ' คะแนน ' . number_format((float) $item['total'], 1) . ' ';
        }
    }
    if (!$found) {
        flash('error', 'พืชนี้ไม่อยู่ในผลจัดอันดับของคำแนะนำนี้');
        redirect('ai_advice.php');
    }
    $cropStmt = $pdo->prepare('SELECT crop_id, crop_name FROM crops WHERE crop_id = ?');
    $cropStmt->execute([$cropId]);
} else {
    // ใช้พืชอันดับ 1 (ชื่อที่บันทึกไว้ในคำแนะนำ)
    $cropStmt = $pdo->prepare('SELECT crop_id, crop_name FROM crops WHERE crop_name = ? LIMIT 1');
    $cropStmt->execute([$rec['recommended_crop']]);
}
$crop = $cropStmt->fetch();

if (!$crop) {
    flash('error', 'ไม่พบพืช "' . $rec['recommended_crop'] . '" ในระบบ จึงไม่สามารถบันทึกเป็นแผนได้');
    redirect('ai_advice.php');
}

// เพิ่มลงประวัติการปลูก (เป็นแผน - ยังไม่มีวันเก็บเกี่ยว)
$pdo->prepare('INSERT INTO crop_history (plot_id, crop_id, planting_date, notes) VALUES (?,?,?,?)')
    ->execute([$rec['plot_id'], $crop['crop_id'], date('Y-m-d'), 'บันทึกจากคำแนะนำ AI ' . $rankInfo . ': ' . mb_substr((string) $rec['reason'], 0, 150)]);

logActivity($uid, 'apply_recommendation', $crop['crop_name']);
flash('success', 'บันทึกพืช "' . $crop['crop_name'] . '" เป็นแผนปลูกในประวัติการปลูกเรียบร้อยแล้ว');
redirect('crop_history.php');
