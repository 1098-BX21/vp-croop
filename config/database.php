<?php
/**
 * การเชื่อมต่อฐานข้อมูล MySQL ด้วย PDO
 */

function getDBConnection(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            if (APP_ENV === 'development') {
                die('เชื่อมต่อฐานข้อมูลล้มเหลว: ' . htmlspecialchars($e->getMessage()));
            }
            die('ระบบขัดข้องชั่วคราว กรุณาลองใหม่ภายหลัง');
        }
    }
    return $pdo;
}
