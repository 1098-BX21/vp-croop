<?php
// ส่งคืนประวัติคำแนะนำของแปลงเป็น HTML (เรียกด้วย fetch จากหน้าคำแนะนำ AI เมื่อเปลี่ยนแปลง/หลังได้คำแนะนำใหม่)
require_once __DIR__ . '/../config/config.php';
header('Content-Type: text/html; charset=utf-8');
if (!isLoggedIn()) {
    http_response_code(401);
    echo '<p class="text-danger">กรุณาเข้าสู่ระบบ</p>';
    exit;
}
$uid = currentUser()['user_id'];
$pdo = getDBConnection();
$plotId = (int) ($_GET['plot_id'] ?? 0);

$check = $pdo->prepare('SELECT 1 FROM plots p JOIN farms f ON f.farm_id = p.farm_id WHERE p.plot_id = ? AND f.user_id = ?');
$check->execute([$plotId, $uid]);
if (!$check->fetch()) {
    http_response_code(403);
    echo '<p class="text-danger">ไม่พบแปลงเพาะปลูกนี้</p>';
    exit;
}
echo adviceRenderHistory($pdo, $plotId, 10, (int) ($_GET['skip'] ?? 0));
