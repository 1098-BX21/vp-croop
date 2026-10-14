<?php
require_once __DIR__ . '/../config/config.php';
requireAdmin();
$pageTitle = 'จัดการผู้ใช้งาน';
$pdo = getDBConnection();
$myId = currentUser()['user_id'];

// เพิ่มผู้ใช้ใหม่ (admin หรือ farmer)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    requireCsrf('users.php');
    $name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] === 'admin' ? 'admin' : 'farmer';
    $password = $_POST['password'] ?? '';
    $province = trim($_POST['province'] ?? '');
    if ($name === '' || $email === '' || strlen($password) < 8) {
        flash('error', 'กรอกข้อมูลไม่ครบ หรือรหัสผ่านสั้นเกินไป (อย่างน้อย 8 ตัว)');
    } elseif ($province !== '' && !thProvinceValid($province)) {
        flash('error', 'กรุณาเลือกจังหวัดจากรายการ');
    } else {
        $check = $pdo->prepare('SELECT user_id FROM users WHERE email=?');
        $check->execute([$email]);
        if ($check->fetch()) {
            flash('error', 'อีเมลนี้ถูกใช้งานแล้ว');
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare('INSERT INTO users (full_name, email, province, password_hash, role) VALUES (?,?,?,?,?)')
                ->execute([$name, $email, $province !== '' ? $province : null, $hash, $role]);
            flash('success', 'เพิ่มผู้ใช้งานสำเร็จ');
        }
    }
    redirect('users.php');
}

// ระงับ/เปิดใช้งาน
if (isset($_GET['toggle'])) {
    $tid = (int) $_GET['toggle'];
    if ($tid !== $myId) {
        $pdo->prepare('UPDATE users SET status = IF(status="active","suspended","active") WHERE user_id=?')->execute([$tid]);
        flash('success', 'เปลี่ยนสถานะผู้ใช้สำเร็จ');
    }
    redirect('users.php');
}

// ลบผู้ใช้
if (isset($_GET['delete'])) {
    $tid = (int) $_GET['delete'];
    if ($tid !== $myId) {
        $pdo->prepare('DELETE FROM users WHERE user_id=?')->execute([$tid]);
        flash('success', 'ลบผู้ใช้สำเร็จ');
    } else {
        flash('error', 'ไม่สามารถลบบัญชีตัวเองได้');
    }
    redirect('users.php');
}

[$perPage, $offset, $currentPage] = getPagination(15);
$total = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$stmt = $pdo->prepare('SELECT * FROM users ORDER BY created_at DESC LIMIT ? OFFSET ?');
$stmt->bindValue(1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar_admin.php'; ?>
  <main class="main-content">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>
    <div class="d-flex justify-content-end mb-3">
      <button class="btn btn-primary-app" data-bs-toggle="modal" data-bs-target="#userModal"><i class="bi bi-person-plus me-1"></i> เพิ่มผู้ใช้งาน</button>
    </div>
    <div class="app-card p-3">
      <div class="table-responsive">
        <table class="table align-middle">
          <thead><tr><th>ชื่อ</th><th>อีเมล</th><th>จังหวัด</th><th>บทบาท</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead>
          <tbody>
            <?php foreach ($users as $u): ?>
              <tr>
                <td class="fw-semibold"><?= e($u['full_name']) ?></td>
                <td class="small"><?= e($u['email']) ?></td>
                <td class="small"><?= $u['province'] ? e($u['province']) : '<span class="text-muted">-</span>' ?></td>
                <td><span class="badge <?= $u['role']==='admin'?'badge-soft-blue':'badge-soft-green' ?>"><?= $u['role']==='admin'?'ผู้ดูแล':'เกษตรกร' ?></span></td>
                <td><span class="badge <?= $u['status']==='active'?'badge-soft-green':'badge-soft-red' ?>"><?= $u['status']==='active'?'ใช้งาน':'ระงับ' ?></span></td>
                <td class="text-end">
                  <?php if ($u['user_id'] != $myId): ?>
                    <a class="btn btn-sm btn-outline-app" href="?toggle=<?= $u['user_id'] ?>" title="เปลี่ยนสถานะ"><i class="bi bi-toggle-on"></i></a>
                    <a class="btn btn-sm btn-outline-danger" href="?delete=<?= $u['user_id'] ?>" onclick="return confirm('ยืนยันการลบผู้ใช้นี้?')"><i class="bi bi-trash"></i></a>
                  <?php else: ?>
                    <span class="small text-muted">บัญชีของคุณ</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?= renderPagination($total, $perPage, $currentPage) ?>
    </div>
  </main>
</div>

<div class="modal fade" id="userModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content" style="border-radius:16px;">
      <form method="post">
        <?= csrfField() ?>
        <div class="modal-header"><h5 class="modal-title">เพิ่มผู้ใช้งานใหม่</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <input type="hidden" name="action" value="add">
          <div class="mb-3"><label class="form-label">ชื่อ-นามสกุล</label><input type="text" name="full_name" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">อีเมล</label><input type="email" name="email" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">รหัสผ่าน</label><input type="password" name="password" class="form-control" minlength="8" required></div>
          <div class="mb-3"><label class="form-label">บทบาท</label>
            <select name="role" class="form-select"><option value="farmer">เกษตรกร</option><option value="admin">ผู้ดูแลระบบ</option></select>
          </div>
          <div class="mb-3"><label class="form-label">จังหวัด (ไม่บังคับ)</label>
            <select name="province" class="form-select"><?= thProvinceOptions(null, '— ไม่ระบุ —') ?></select>
          </div>
        </div>
        <div class="modal-footer"><button class="btn btn-primary-app w-100">เพิ่มผู้ใช้งาน</button></div>
      </form>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
