<?php
require_once __DIR__ . '/../config/config.php';
$pageTitle = 'ลืมรหัสผ่าน';

if (isLoggedIn()) {
    redirect(BASE_URL . '/public/index.php');
}

$error = '';
$step = 'verify'; // verify -> reset
$verifiedUserId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf('forgot_password.php');
    $pdo = getDBConnection();

    if (($_POST['step'] ?? '') === 'verify') {
        // ยืนยันตัวตนด้วยอีเมล + เบอร์โทรที่ลงทะเบียนไว้
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $stmt = $pdo->prepare('SELECT user_id FROM users WHERE email=? AND phone=? AND status="active" LIMIT 1');
        $stmt->execute([$email, $phone]);
        $user = $stmt->fetch();
        if ($user) {
            $_SESSION['reset_user_id'] = (int) $user['user_id'];
            $_SESSION['reset_expires'] = time() + 600; // 10 นาที
            $step = 'reset';
        } else {
            $error = 'ไม่พบบัญชีที่ตรงกับอีเมลและเบอร์โทรนี้ กรุณาตรวจสอบอีกครั้ง';
        }
    } elseif (($_POST['step'] ?? '') === 'reset') {
        // ตั้งรหัสผ่านใหม่
        $uid = $_SESSION['reset_user_id'] ?? null;
        $expires = $_SESSION['reset_expires'] ?? 0;
        $newPass = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!$uid || time() > $expires) {
            $error = 'หมดเวลาการตั้งรหัสผ่านใหม่ กรุณาเริ่มใหม่อีกครั้ง';
            unset($_SESSION['reset_user_id'], $_SESSION['reset_expires']);
        } elseif (strlen($newPass) < 8) {
            $error = 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร';
            $step = 'reset';
        } elseif ($newPass !== $confirm) {
            $error = 'รหัสผ่านและยืนยันรหัสผ่านไม่ตรงกัน';
            $step = 'reset';
        } else {
            $hash = password_hash($newPass, PASSWORD_BCRYPT);
            $pdo->prepare('UPDATE users SET password_hash=? WHERE user_id=?')->execute([$hash, $uid]);
            logActivity($uid, 'reset_password', 'ตั้งรหัสผ่านใหม่ผ่านหน้าลืมรหัสผ่าน');
            unset($_SESSION['reset_user_id'], $_SESSION['reset_expires']);
            flash('success', 'ตั้งรหัสผ่านใหม่สำเร็จ กรุณาเข้าสู่ระบบด้วยรหัสผ่านใหม่');
            redirect(BASE_URL . '/public/login.php');
        }
    }
}

// ถ้ามี session reset ค้างอยู่ ให้อยู่ขั้น reset
if (isset($_SESSION['reset_user_id']) && (($_SESSION['reset_expires'] ?? 0) > time()) && $step !== 'reset' && !$error) {
    $step = 'reset';
}

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/navbar_public.php';
?>
<div class="container py-5" style="max-width: 480px;">
  <div class="app-card p-4 p-md-5">
    <h3 class="fw-bold text-center mb-1"><i class="bi bi-key text-success"></i> ลืมรหัสผ่าน</h3>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

    <?php if ($step === 'verify'): ?>
      <p class="text-muted text-center mb-4">ยืนยันตัวตนด้วยอีเมลและเบอร์โทรศัพท์ที่ลงทะเบียนไว้</p>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="step" value="verify">
        <div class="mb-3">
          <label class="form-label">อีเมล</label>
          <input type="email" name="email" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">เบอร์โทรศัพท์ที่ลงทะเบียน</label>
          <input type="text" name="phone" class="form-control" required>
        </div>
        <button class="btn btn-primary-app w-100">ยืนยันตัวตน</button>
        <p class="text-center mt-3 mb-0 small"><a href="login.php">กลับไปหน้าเข้าสู่ระบบ</a></p>
      </form>
    <?php else: ?>
      <p class="text-muted text-center mb-4">ยืนยันตัวตนสำเร็จ กรุณาตั้งรหัสผ่านใหม่ (ภายใน 10 นาที)</p>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="step" value="reset">
        <div class="mb-3">
          <label class="form-label">รหัสผ่านใหม่</label>
          <input type="password" name="new_password" class="form-control" minlength="8" required>
        </div>
        <div class="mb-3">
          <label class="form-label">ยืนยันรหัสผ่านใหม่</label>
          <input type="password" name="confirm_password" class="form-control" minlength="8" required>
        </div>
        <button class="btn btn-primary-app w-100">ตั้งรหัสผ่านใหม่</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
