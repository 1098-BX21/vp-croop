<?php
require_once __DIR__ . '/../config/config.php';
$pageTitle = 'สมัครสมาชิก';

if (isLoggedIn()) {
    redirect(BASE_URL . '/' . (isAdmin() ? 'admin/dashboard.php' : 'user/dashboard.php'));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf('register.php');
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $province = trim($_POST['province'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($fullName === '' || $email === '' || $password === '') {
        $error = 'กรุณากรอกข้อมูลให้ครบถ้วน';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'รูปแบบอีเมลไม่ถูกต้อง';
    } elseif ($province === '') {
        $error = 'กรุณาเลือกจังหวัดของคุณ';
    } elseif (!thProvinceValid($province)) {
        $error = 'กรุณาเลือกจังหวัดจากรายการ';
    } elseif ($password !== $confirm) {
        $error = 'รหัสผ่านและยืนยันรหัสผ่านไม่ตรงกัน';
    } else {
        $result = registerUser($fullName, $email, $phone, $password, $province);
        if ($result['success']) {
            flash('success', 'สมัครสมาชิกสำเร็จ กรุณาเข้าสู่ระบบ');
            redirect(BASE_URL . '/public/login.php');
        } else {
            $error = $result['message'];
        }
    }
}

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/navbar_public.php';
?>
<div class="container py-5" style="max-width: 520px;">
  <div class="app-card p-4 p-md-5">
    <h3 class="fw-bold text-center mb-1"><i class="bi bi-person-plus text-success"></i> สมัครสมาชิก</h3>
    <p class="text-muted text-center mb-4">สมัครฟรีเพื่อเริ่มใช้งานระบบวิเคราะห์การปลูกพืชหมุนเวียน</p>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <?= csrfField() ?>
      <div class="mb-3">
        <label class="form-label">ชื่อ-นามสกุล</label>
        <input type="text" name="full_name" class="form-control" required value="<?= e($_POST['full_name'] ?? '') ?>">
      </div>
      <div class="mb-3">
        <label class="form-label">อีเมล</label>
        <input type="email" name="email" class="form-control" required value="<?= e($_POST['email'] ?? '') ?>">
      </div>
      <div class="mb-3">
        <label class="form-label">เบอร์โทรศัพท์</label>
        <input type="text" name="phone" class="form-control" value="<?= e($_POST['phone'] ?? '') ?>">
      </div>
      <div class="mb-3">
        <label class="form-label">จังหวัด <span class="text-danger">*</span></label>
        <select name="province" class="form-select" required><?= thProvinceOptions($_POST['province'] ?? null) ?></select>
        <div class="form-text">ใช้ทำสถิติผู้ใช้งานตามพื้นที่ของระบบ (ผู้ดูแลระบบเห็นเป็นภาพรวมรายจังหวัด)</div>
      </div>
      <div class="row">
        <div class="col-md-6 mb-3">
          <label class="form-label">รหัสผ่าน</label>
          <input type="password" name="password" class="form-control" required minlength="8">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">ยืนยันรหัสผ่าน</label>
          <input type="password" name="confirm_password" class="form-control" required minlength="8">
        </div>
      </div>
      <button type="submit" class="btn btn-primary-app w-100">สมัครสมาชิก</button>
      <p class="text-center mt-3 mb-0 small">มีบัญชีอยู่แล้ว? <a href="login.php">เข้าสู่ระบบ</a></p>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
