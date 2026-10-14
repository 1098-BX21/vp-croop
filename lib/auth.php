<?php
/**
 * ============================================================
 * ระบบยืนยันตัวตนและสิทธิ์การใช้งาน
 * ============================================================
 */

function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

function isLoggedIn(): bool
{
    return isset($_SESSION['user']);
}

function isAdmin(): bool
{
    return isLoggedIn() && $_SESSION['user']['role'] === 'admin';
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        redirect(BASE_URL . '/public/login.php');
    }
}

function requireAdmin(): void
{
    requireLogin();
    if (!isAdmin()) {
        http_response_code(403);
        die('คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
    }
}

/** พยายามเข้าสู่ระบบ */
function attemptLogin(string $email, string $password): array
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'message' => 'ไม่พบบัญชีผู้ใช้งานนี้'];
    }
    if ($user['status'] !== 'active') {
        return ['success' => false, 'message' => 'บัญชีนี้ถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแลระบบ'];
    }
    if (!password_verify($password, $user['password_hash'])) {
        return ['success' => false, 'message' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง'];
    }

    // ป้องกัน Session Fixation
    session_regenerate_id(true);

    $_SESSION['user'] = [
        'user_id'   => (int) $user['user_id'],
        'full_name' => $user['full_name'],
        'email'     => $user['email'],
        'role'      => $user['role'],
        'avatar'    => $user['avatar'],
    ];
    logActivity((int) $user['user_id'], 'login', 'เข้าสู่ระบบสำเร็จ');
    return ['success' => true, 'role' => $user['role']];
}

/** สมัครสมาชิกใหม่ (เป็น farmer) */
function registerUser(string $fullName, string $email, string $phone, string $password, ?string $province = null): array
{
    if ($province !== null && !thProvinceValid($province)) {
        return ['success' => false, 'message' => 'กรุณาเลือกจังหวัดจากรายการ'];
    }
    $pdo = getDBConnection();
    $stmt = $pdo->prepare('SELECT user_id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => 'อีเมลนี้ถูกใช้งานแล้ว'];
    }
    if (strlen($password) < 8) {
        return ['success' => false, 'message' => 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร'];
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare('INSERT INTO users (full_name, email, phone, province, password_hash, role) VALUES (?, ?, ?, ?, ?, "farmer")');
    $stmt->execute([$fullName, $email, $phone, $province, $hash]);

    logActivity((int) $pdo->lastInsertId(), 'register', 'สมัครสมาชิกใหม่' . ($province ? ' จ.' . $province : ''));
    return ['success' => true];
}

/** ออกจากระบบ */
function logoutUser(): void
{
    if (isLoggedIn()) {
        logActivity($_SESSION['user']['user_id'], 'logout', 'ออกจากระบบ');
    }
    $_SESSION = [];
    session_destroy();
}
