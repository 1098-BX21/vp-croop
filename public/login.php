<?php
require_once __DIR__ . '/../config/config.php';
$pageTitle = 'เข้าสู่ระบบ';

if (isLoggedIn()) {
    redirect(BASE_URL . '/' . (isAdmin() ? 'admin/dashboard.php' : 'user/dashboard.php'));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf('login.php');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($email === '' || $password === '') {
        $error = 'กรุณากรอกอีเมลและรหัสผ่านให้ครบถ้วน';
    } else {
        $result = attemptLogin($email, $password);
        if ($result['success']) {
            redirect(BASE_URL . '/' . ($result['role'] === 'admin' ? 'admin/dashboard.php' : 'user/dashboard.php'));
        } else {
            $error = $result['message'];
        }
    }
}

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/navbar_public.php';
?>
<div class="container py-5" style="max-width: 480px;">
  <div class="app-card p-4 p-md-5">
    <h3 class="fw-bold text-center mb-1"><i class="bi bi-box-arrow-in-right text-success"></i> เข้าสู่ระบบ</h3>
    <p class="text-muted text-center mb-4">ยินดีต้อนรับกลับสู่ Smart Farm Advisory</p>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <?= csrfField() ?>
      <div class="mb-3">
        <label class="form-label">อีเมล</label>
        <input type="email" name="email" class="form-control" placeholder="email@example.com" required value="<?= e($_POST['email'] ?? '') ?>">
      </div>
      <div class="mb-3">
        <label class="form-label">รหัสผ่าน</label>
        <input type="password" name="password" class="form-control" placeholder="********" required>
      </div>
      <button type="submit" class="btn btn-primary-app w-100">เข้าสู่ระบบ</button>
      <p class="text-center mt-3 mb-1 small"><a href="forgot_password.php">ลืมรหัสผ่าน?</a></p>
      <p class="text-center mb-0 small">ยังไม่มีบัญชี? <a href="register.php">สมัครสมาชิก</a></p>
    </form>
    <div class="mt-4 pt-3 border-top small text-muted">
      <p class="mb-1 fw-bold">บัญชีทดสอบ (Seed data):</p>
      <p class="mb-0">Admin: admin@croprotation.local</p>
      <p class="mb-0">เกษตรกร: sirapop.farmer@croprotation.local</p>
      <p class="mb-0">รหัสผ่าน: Password123!</p>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
