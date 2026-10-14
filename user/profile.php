<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
$pageTitle = 'ข้อมูลส่วนตัว';
$uid = currentUser()['user_id'];
$pdo = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf('profile.php');
    $fullName = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $province = trim($_POST['province'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($fullName === '' || $email === '') {
        flash('error', 'กรุณากรอกชื่อและอีเมลให้ครบถ้วน');
        redirect('profile.php');
    }

    if ($province !== '' && !thProvinceValid($province)) {
        flash('error', 'กรุณาเลือกจังหวัดจากรายการ');
        redirect('profile.php');
    }

    $check = $pdo->prepare('SELECT user_id FROM users WHERE email=? AND user_id<>?');
    $check->execute([$email, $uid]);
    if ($check->fetch()) {
        flash('error', 'อีเมลนี้ถูกใช้งานโดยผู้อื่นแล้ว');
        redirect('profile.php');
    }

    // อัปโหลดรูปโปรไฟล์ (ถ้ามี)
    $avatarName = null;
    if (!empty($_FILES['avatar']['name'])) {
        $avatarName = uploadImageFile($_FILES['avatar'], UPLOAD_PATH_AVATAR);
        if ($avatarName === null) {
            flash('error', 'อัปโหลดรูปโปรไฟล์ไม่สำเร็จ (รองรับเฉพาะ jpg, png, webp)');
            redirect('profile.php');
        }
    }

    // อัปเดตข้อมูลพื้นฐาน
    $pdo->prepare('UPDATE users SET full_name=?, phone=?, address=?, province=?, email=? WHERE user_id=?')
        ->execute([$fullName, $phone, $address, $province !== '' ? $province : null, $email, $uid]);
    $_SESSION['user']['full_name'] = $fullName;
    $_SESSION['user']['email'] = $email;

    if ($avatarName !== null) {
        $pdo->prepare('UPDATE users SET avatar=? WHERE user_id=?')->execute([$avatarName, $uid]);
        $_SESSION['user']['avatar'] = $avatarName;
    }

    // เปลี่ยนรหัสผ่าน (ถ้ากรอก)
    if (!empty($_POST['new_password'])) {
        if (strlen($_POST['new_password']) < 8) {
            flash('error', 'รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร');
            redirect('profile.php');
        }
        $hash = password_hash($_POST['new_password'], PASSWORD_BCRYPT);
        $pdo->prepare('UPDATE users SET password_hash=? WHERE user_id=?')->execute([$hash, $uid]);
    }

    logActivity($uid, 'update_profile', 'แก้ไขข้อมูลส่วนตัว');
    flash('success', 'บันทึกข้อมูลสำเร็จ');
    redirect('profile.php');
}

$stmt = $pdo->prepare('SELECT * FROM users WHERE user_id=?');
$stmt->execute([$uid]);
$user = $stmt->fetch();

require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar_user.php'; ?>
  <main class="main-content">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>
    <div class="app-card p-4" style="max-width: 680px;">
      <h6 class="fw-bold mb-3">แก้ไขข้อมูลส่วนตัว</h6>
      <form method="post" enctype="multipart/form-data">
        <?= csrfField() ?>

        <div class="text-center mb-4">
          <div class="d-inline-block position-relative">
            <?php $av = $user['avatar'] ? BASE_URL . '/uploads/avatars/' . e($user['avatar']) : ''; ?>
            <img id="avatarPreview" src="<?= $av ?>" class="rounded-circle <?= $av ? '' : 'd-none' ?>" style="width:110px;height:110px;object-fit:cover;border:3px solid var(--primary-light);">
            <?php if (!$av): ?>
              <div id="avatarPlaceholder" class="rounded-circle d-flex align-items-center justify-content-center" style="width:110px;height:110px;background:var(--primary);color:#fff;font-size:2.5rem;font-weight:700;margin:auto;">
                <?= e(mb_substr($user['full_name'], 0, 1)) ?>
              </div>
            <?php endif; ?>
          </div>
          <div class="mt-2">
            <label class="btn btn-outline-app btn-sm mb-0">
              <i class="bi bi-camera me-1"></i> เปลี่ยนรูปโปรไฟล์
              <input type="file" name="avatar" accept="image/*" data-preview="avatarPreview" hidden onchange="document.getElementById('avatarPlaceholder') && document.getElementById('avatarPlaceholder').classList.add('d-none')">
            </label>
          </div>
        </div>

        <div class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label">ชื่อ-นามสกุล</label>
            <input type="text" name="full_name" class="form-control" value="<?= e($user['full_name']) ?>" required>
          </div>
          <div class="col-md-6 mb-3">
            <label class="form-label">เบอร์โทรศัพท์</label>
            <input type="text" name="phone" class="form-control" value="<?= e($user['phone']) ?>">
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label">อีเมล</label>
          <input type="email" name="email" class="form-control" value="<?= e($user['email']) ?>" required>
        </div>
        <div class="mb-3">
          <label class="form-label">ที่อยู่</label>
          <textarea name="address" class="form-control" rows="2"><?= e($user['address']) ?></textarea>
        </div>
        <div class="mb-3">
          <label class="form-label">จังหวัด</label>
          <select name="province" class="form-select"><?= thProvinceOptions($user['province'] ?? null, '— ยังไม่ได้ระบุ —') ?></select>
          <div class="form-text">ใช้ทำสถิติผู้ใช้งานตามพื้นที่ของระบบ <?= empty($user['province']) ? '<span class="text-danger">(คุณยังไม่ได้ระบุจังหวัด จึงยังไม่ถูกนับในสถิติ)</span>' : '' ?></div>
        </div>
        <div class="mb-3">
          <label class="form-label">รหัสผ่านใหม่ (เว้นว่างหากไม่เปลี่ยน)</label>
          <input type="password" name="new_password" class="form-control" minlength="8" placeholder="อย่างน้อย 8 ตัวอักษร">
        </div>
        <button class="btn btn-primary-app">บันทึกข้อมูล</button>
      </form>
    </div>
  </main>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
