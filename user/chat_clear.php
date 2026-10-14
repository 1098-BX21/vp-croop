<?php
require_once __DIR__ . '/../config/config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบ']);
    exit;
}
requireCsrfAjax();

$uid = currentUser()['user_id'];
$pdo = getDBConnection();
$pdo->prepare('DELETE FROM chat_messages WHERE user_id=?')->execute([$uid]);
logActivity($uid, 'chatbot_clear', '');

echo json_encode(['success' => true]);
