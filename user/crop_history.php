<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
$pageTitle = 'ประวัติการปลูก';
$uid = currentUser()['user_id'];
$pdo = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    requireCsrf('crop_history.php');
    $historyId = (int) ($_POST['history_id'] ?? 0);
    $plotId = (int) ($_POST['plot_id'] ?? 0);
    $cropId = (int) ($_POST['crop_id'] ?? 0);
    $plantingDate = $_POST['planting_date'] ?: date('Y-m-d');
    $harvestDate = $_POST['harvest_date'] ?: null;
    $yield = $_POST['yield_amount'] !== '' ? (float) $_POST['yield_amount'] : null;
    $fertilizer = trim($_POST['fertilizer_used'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    $check = $pdo->prepare('SELECT p.plot_id FROM plots p JOIN farms f ON f.farm_id=p.farm_id WHERE p.plot_id=? AND f.user_id=?');
    $check->execute([$plotId, $uid]);
    if (!$check->fetch() || $cropId === 0) {
        flash('error', 'ข้อมูลไม่ถูกต้อง');
    } elseif ($historyId > 0) {
        $pdo->prepare('UPDATE crop_history SET plot_id=?, crop_id=?, planting_date=?, harvest_date=?, yield_amount=?, fertilizer_used=?, notes=? WHERE history_id=?')
            ->execute([$plotId, $cropId, $plantingDate, $harvestDate, $yield, $fertilizer, $notes, $historyId]);
        flash('success', 'แก้ไขประวัติการปลูกสำเร็จ');
    } else {
        $pdo->prepare('INSERT INTO crop_history (plot_id, crop_id, planting_date, harvest_date, yield_amount, fertilizer_used, notes) VALUES (?,?,?,?,?,?,?)')
            ->execute([$plotId, $cropId, $plantingDate, $harvestDate, $yield, $fertilizer, $notes]);
        flash('success', 'เพิ่มประวัติการปลูกสำเร็จ');
    }
    redirect('crop_history.php');
}

if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare('DELETE ch FROM crop_history ch JOIN plots p ON p.plot_id=ch.plot_id JOIN farms f ON f.farm_id=p.farm_id WHERE ch.history_id=? AND f.user_id=?');
    $stmt->execute([(int) $_GET['delete'], $uid]);
    flash('success', 'ลบประวัติการปลูกสำเร็จ');
    redirect('crop_history.php');
}

$plots = $pdo->prepare('SELECT p.plot_id, p.plot_name, f.farm_name FROM plots p JOIN farms f ON f.farm_id=p.farm_id WHERE f.user_id=? ORDER BY f.farm_name, p.plot_name');
$plots->execute([$uid]);
$plots = $plots->fetchAll();

$crops = $pdo->query('SELECT crop_id, crop_name FROM crops ORDER BY crop_name')->fetchAll();

[$perPage, $offset, $currentPage] = getPagination(15);
$totalHistory = $pdo->prepare('SELECT COUNT(*) FROM crop_history ch JOIN plots p ON p.plot_id=ch.plot_id JOIN farms f ON f.farm_id=p.farm_id WHERE f.user_id=?');
$totalHistory->execute([$uid]);
$totalHistory = (int) $totalHistory->fetchColumn();

$history = $pdo->prepare('
  SELECT ch.*, p.plot_name, c.crop_name FROM crop_history ch
  JOIN plots p ON p.plot_id = ch.plot_id
  JOIN farms f ON f.farm_id = p.farm_id
  JOIN crops c ON c.crop_id = ch.crop_id
  WHERE f.user_id = ? ORDER BY ch.planting_date DESC
  LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset . '
');
$history->execute([$uid]);
$history = $history->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar_user.php'; ?>
  <main class="main-content">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>

    <?php if (!$plots): ?>
      <div class="alert alert-warning">กรุณาเพิ่มแปลงเพาะปลูกก่อน <a href="plots.php">ไปที่หน้าแปลงเพาะปลูก</a> หรือ <a href="quick_setup.php">ใช้ตั้งค่าเริ่มต้นแบบเร็ว</a></div>
    <?php else: ?>
    <div class="d-flex justify-content-end mb-3">
      <button class="btn btn-primary-app" data-bs-toggle="modal" data-bs-target="#histModal" onclick="openHistModal()">
        <i class="bi bi-plus-circle me-1"></i> เพิ่มประวัติการปลูก
      </button>
    </div>
    <?php endif; ?>

    <div class="app-card p-3">
      <div class="table-responsive">
        <table class="table align-middle">
          <thead><tr><th>แปลง</th><th>พืช</th><th>วันปลูก</th><th>วันเก็บเกี่ยว</th><th>ผลผลิต (กก.)</th><th class="text-end">จัดการ</th></tr></thead>
          <tbody>
            <?php foreach ($history as $h): ?>
              <tr>
                <td class="fw-semibold"><?= e($h['plot_name']) ?></td>
                <td><span class="badge badge-soft-green"><?= e($h['crop_name']) ?></span></td>
                <td class="small"><?= formatDateThai($h['planting_date']) ?></td>
                <td class="small"><?= $h['harvest_date'] ? formatDateThai($h['harvest_date']) : '<span class="text-muted">ยังไม่เก็บเกี่ยว</span>' ?></td>
                <td><?= $h['yield_amount'] !== null ? number_format($h['yield_amount'], 2) : '-' ?></td>
                <td class="text-end">
                  <button class="btn btn-sm btn-outline-app" data-bs-toggle="modal" data-bs-target="#histModal" onclick='openHistModal(<?= json_encode($h, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="bi bi-pencil"></i></button>
                  <a class="btn btn-sm btn-outline-danger" href="?delete=<?= $h['history_id'] ?>" onclick="return confirm('ยืนยันการลบประวัตินี้?')"><i class="bi bi-trash"></i></a>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$history): ?><tr><td colspan="6" class="text-center text-muted py-4">ยังไม่มีประวัติการปลูก</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <?= renderPagination($totalHistory, $perPage, $currentPage) ?>
    </div>
  </main>
</div>

<div class="modal fade" id="histModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="border-radius:16px;">
      <form method="post">
        <?= csrfField() ?>
        <div class="modal-header">
          <h5 class="modal-title" id="histModalTitle">เพิ่มประวัติการปลูก</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="history_id" id="h_history_id" value="">
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">แปลงเพาะปลูก</label>
              <select name="plot_id" id="h_plot_id" class="form-select" required>
                <?php foreach ($plots as $p): ?>
                  <option value="<?= $p['plot_id'] ?>"><?= e($p['farm_name'] . ' - ' . $p['plot_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">พืชที่ปลูก</label>
              <select name="crop_id" id="h_crop_id" class="form-select" required>
                <?php foreach ($crops as $c): ?>
                  <option value="<?= $c['crop_id'] ?>"><?= e($c['crop_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">วันที่ปลูก</label>
              <input type="date" name="planting_date" id="h_planting_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">วันที่เก็บเกี่ยว (ถ้ามี)</label>
              <input type="date" name="harvest_date" id="h_harvest_date" class="form-control">
            </div>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">ผลผลิต (กก.)</label>
              <input type="number" step="0.01" name="yield_amount" id="h_yield_amount" class="form-control">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">ปุ๋ยที่ใช้</label>
              <input type="text" name="fertilizer_used" id="h_fertilizer_used" class="form-control" placeholder="เช่น 16-20-0">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">หมายเหตุ</label>
            <textarea name="notes" id="h_notes" class="form-control" rows="2"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary-app w-100">บันทึกประวัติ</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openHistModal(data) {
  document.getElementById('histModalTitle').innerText = data ? 'แก้ไขประวัติการปลูก' : 'เพิ่มประวัติการปลูก';
  document.getElementById('h_history_id').value = data ? data.history_id : '';
  document.getElementById('h_planting_date').value = data ? data.planting_date : '<?= date('Y-m-d') ?>';
  document.getElementById('h_harvest_date').value = data && data.harvest_date ? data.harvest_date : '';
  document.getElementById('h_yield_amount').value = data && data.yield_amount ? data.yield_amount : '';
  document.getElementById('h_fertilizer_used').value = data && data.fertilizer_used ? data.fertilizer_used : '';
  document.getElementById('h_notes').value = data && data.notes ? data.notes : '';
  if (data) {
    document.getElementById('h_plot_id').value = data.plot_id;
    document.getElementById('h_crop_id').value = data.crop_id;
  }
}
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
