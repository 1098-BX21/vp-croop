<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
$pageTitle = 'แปลงเพาะปลูก';
$uid = currentUser()['user_id'];
$pdo = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    requireCsrf('plots.php');
    $plotId = (int) ($_POST['plot_id'] ?? 0);
    $farmId = (int) ($_POST['farm_id'] ?? 0);
    $name = trim($_POST['plot_name'] ?? '');
    $size = $_POST['plot_size'] !== '' ? (float) $_POST['plot_size'] : null;

    $check = $pdo->prepare('SELECT farm_id FROM farms WHERE farm_id=? AND user_id=?');
    $check->execute([$farmId, $uid]);
    if (!$check->fetch() || $name === '') {
        flash('error', 'ข้อมูลไม่ถูกต้อง');
    } elseif ($plotId > 0) {
        $pdo->prepare('UPDATE plots SET plot_name=?, plot_size=?, farm_id=? WHERE plot_id=?')->execute([$name, $size, $farmId, $plotId]);
        flash('success', 'แก้ไขแปลงเพาะปลูกสำเร็จ');
    } else {
        $pdo->prepare('INSERT INTO plots (farm_id, plot_name, plot_size) VALUES (?,?,?)')->execute([$farmId, $name, $size]);
        flash('success', 'เพิ่มแปลงเพาะปลูกสำเร็จ');
    }
    redirect('plots.php');
}

if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare('DELETE p FROM plots p JOIN farms f ON f.farm_id=p.farm_id WHERE p.plot_id=? AND f.user_id=?');
    $stmt->execute([(int) $_GET['delete'], $uid]);
    flash('success', 'ลบแปลงเพาะปลูกสำเร็จ');
    redirect('plots.php');
}

$farms = $pdo->prepare('SELECT * FROM farms WHERE user_id=? ORDER BY farm_name');
$farms->execute([$uid]);
$farms = $farms->fetchAll();

$plots = $pdo->prepare('SELECT p.*, f.farm_name FROM plots p JOIN farms f ON f.farm_id=p.farm_id WHERE f.user_id=? ORDER BY p.created_at DESC');
$plots->execute([$uid]);
$plots = $plots->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar_user.php'; ?>
  <main class="main-content">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>

    <?php if (!$farms): ?>
      <div class="alert alert-warning">กรุณาเพิ่มฟาร์มก่อน จึงจะสามารถเพิ่มแปลงเพาะปลูกได้ <a href="farms.php">ไปที่หน้าฟาร์ม</a> หรือ <a href="quick_setup.php">ใช้ตั้งค่าเริ่มต้นแบบเร็ว</a></div>
    <?php else: ?>
    <div class="d-flex justify-content-end mb-3">
      <button class="btn btn-primary-app" data-bs-toggle="modal" data-bs-target="#plotModal" onclick="openPlotModal()">
        <i class="bi bi-plus-circle me-1"></i> เพิ่มแปลงเพาะปลูก
      </button>
    </div>
    <?php endif; ?>

    <div class="app-card p-3">
      <div class="table-responsive">
        <table class="table align-middle">
          <thead><tr><th>ชื่อแปลง</th><th>ฟาร์ม</th><th>ขนาด (ไร่)</th><th class="text-end">จัดการ</th></tr></thead>
          <tbody>
            <?php foreach ($plots as $p): ?>
              <tr>
                <td class="fw-semibold"><?= e($p['plot_name']) ?></td>
                <td><?= e($p['farm_name']) ?></td>
                <td><?= $p['plot_size'] ? number_format($p['plot_size'], 2) : '-' ?></td>
                <td class="text-end">
                  <button class="btn btn-sm btn-outline-app" data-bs-toggle="modal" data-bs-target="#plotModal" onclick='openPlotModal(<?= json_encode($p, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="bi bi-pencil"></i></button>
                  <a class="btn btn-sm btn-outline-danger" href="?delete=<?= $p['plot_id'] ?>" onclick="return confirm('ยืนยันการลบแปลงนี้?')"><i class="bi bi-trash"></i></a>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$plots): ?><tr><td colspan="4" class="text-center text-muted py-4">ยังไม่มีแปลงเพาะปลูก</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>
</div>

<div class="modal fade" id="plotModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content" style="border-radius:16px;">
      <form method="post">
        <?= csrfField() ?>
        <div class="modal-header">
          <h5 class="modal-title" id="plotModalTitle">เพิ่มแปลงเพาะปลูก</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="plot_id" id="p_plot_id" value="">
          <div class="mb-3">
            <label class="form-label">ฟาร์ม</label>
            <select name="farm_id" id="p_farm_id" class="form-select" required>
              <?php foreach ($farms as $f): ?>
                <option value="<?= $f['farm_id'] ?>"><?= e($f['farm_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">ชื่อแปลง</label>
            <input type="text" name="plot_name" id="p_plot_name" class="form-control" required placeholder="เช่น แปลง A1">
          </div>
          <div class="mb-3">
            <label class="form-label">ขนาด (ไร่)</label>
            <input type="number" step="0.01" name="plot_size" id="p_plot_size" class="form-control" placeholder="เช่น 2.5">
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary-app w-100">บันทึกแปลง</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openPlotModal(data) {
  document.getElementById('plotModalTitle').innerText = data ? 'แก้ไขแปลงเพาะปลูก' : 'เพิ่มแปลงเพาะปลูก';
  document.getElementById('p_plot_id').value = data ? data.plot_id : '';
  document.getElementById('p_plot_name').value = data ? data.plot_name : '';
  document.getElementById('p_plot_size').value = data ? (data.plot_size || '') : '';
  if (data) document.getElementById('p_farm_id').value = data.farm_id;
}
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
