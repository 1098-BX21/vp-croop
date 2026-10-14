<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
$pageTitle = 'ฟาร์มของฉัน';
$uid = currentUser()['user_id'];
$pdo = getDBConnection();

// ตัวเลือกแหล่งน้ำ
$waterOptions = ['ใกล้แหล่งน้ำธรรมชาติ (แม่น้ำ/ลำคลอง/หนอง)', 'บ่อน้ำบาดาล', 'บ่อเก็บน้ำ/สระ', 'ระบบชลประทาน', 'น้ำประปา', 'อื่น ๆ'];

// ---- Create / Update ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    requireCsrf('farms.php');
    $farmId = (int) ($_POST['farm_id'] ?? 0);
    $name = trim($_POST['farm_name'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $area = $_POST['area_size'] !== '' ? (float) $_POST['area_size'] : null;
    $hasWater = (int) ($_POST['has_water_source'] ?? 0);
    $waterTypes = '';
    if ($hasWater && !empty($_POST['water_types']) && is_array($_POST['water_types'])) {
        // เก็บเฉพาะค่าที่อยู่ในตัวเลือกจริง
        $valid = array_intersect($_POST['water_types'], $waterOptions);
        $waterTypes = implode(',', $valid);
    }

    if ($name === '') {
        flash('error', 'กรุณากรอกชื่อฟาร์ม');
    } elseif ($farmId > 0) {
        $stmt = $pdo->prepare('UPDATE farms SET farm_name=?, location=?, area_size=?, has_water_source=?, water_source_types=? WHERE farm_id=? AND user_id=?');
        $stmt->execute([$name, $location, $area, $hasWater, $waterTypes, $farmId, $uid]);
        flash('success', 'แก้ไขข้อมูลฟาร์มสำเร็จ');
    } else {
        $stmt = $pdo->prepare('INSERT INTO farms (user_id, farm_name, location, area_size, has_water_source, water_source_types) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$uid, $name, $location, $area, $hasWater, $waterTypes]);
        flash('success', 'เพิ่มฟาร์มใหม่สำเร็จ');
    }
    logActivity($uid, 'save_farm', $name);
    redirect('farms.php');
}

// ---- Delete ----
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare('DELETE FROM farms WHERE farm_id=? AND user_id=?');
    $stmt->execute([(int) $_GET['delete'], $uid]);
    flash('success', 'ลบฟาร์มสำเร็จ');
    redirect('farms.php');
}

$stmt = $pdo->prepare('SELECT * FROM farms WHERE user_id=? ORDER BY created_at DESC');
$stmt->execute([$uid]);
$farms = $stmt->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar_user.php'; ?>
  <main class="main-content">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>

    <div class="d-flex justify-content-end mb-3">
      <button class="btn btn-primary-app" data-bs-toggle="modal" data-bs-target="#farmModal" onclick="openFarmModal()">
        <i class="bi bi-plus-circle me-1"></i> เพิ่มฟาร์ม
      </button>
    </div>

    <div class="row g-3">
      <?php foreach ($farms as $f): ?>
        <div class="col-md-4">
          <div class="app-card p-4 h-100">
            <div class="d-flex justify-content-between">
              <i class="bi bi-houses text-success fs-3"></i>
              <div>
                <button class="btn btn-sm btn-outline-app" data-bs-toggle="modal" data-bs-target="#farmModal" onclick='openFarmModal(<?= json_encode($f, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="bi bi-pencil"></i></button>
                <a class="btn btn-sm btn-outline-danger" href="?delete=<?= $f['farm_id'] ?>" onclick="return confirm('ยืนยันการลบฟาร์มนี้? แปลงและข้อมูลที่เกี่ยวข้องจะถูกลบทั้งหมด')"><i class="bi bi-trash"></i></a>
              </div>
            </div>
            <h5 class="fw-bold mt-2"><?= e($f['farm_name']) ?></h5>
            <p class="text-muted small mb-1"><i class="bi bi-geo-alt"></i> <?= e($f['location'] ?: '-') ?></p>
            <p class="text-muted small mb-1"><i class="bi bi-rulers"></i> พื้นที่ <?= $f['area_size'] ? number_format($f['area_size'], 2) : '-' ?> ไร่</p>
            <p class="small mb-0">
              <?php if ($f['has_water_source']): ?>
                <span class="badge badge-soft-blue"><i class="bi bi-droplet"></i> มีแหล่งน้ำ</span>
                <?php if ($f['water_source_types']): ?>
                  <br><span class="text-muted" style="font-size:.78rem;"><?= e($f['water_source_types']) ?></span>
                <?php endif; ?>
              <?php else: ?>
                <span class="badge badge-soft-amber"><i class="bi bi-droplet-slash"></i> ไม่มีแหล่งน้ำ</span>
              <?php endif; ?>
            </p>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$farms): ?>
        <div class="col-12"><div class="app-card p-5 text-center text-muted">ยังไม่มีฟาร์ม กดปุ่ม "เพิ่มฟาร์ม" เพื่อเริ่มต้น</div></div>
      <?php endif; ?>
    </div>
  </main>
</div>

<!-- Modal -->
<div class="modal fade" id="farmModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content" style="border-radius:16px;">
      <form method="post">
        <?= csrfField() ?>
        <div class="modal-header">
          <h5 class="modal-title" id="farmModalTitle">เพิ่มฟาร์มใหม่</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="farm_id" id="f_farm_id" value="">
          <div class="mb-3">
            <label class="form-label">ชื่อฟาร์ม</label>
            <input type="text" name="farm_name" id="f_farm_name" class="form-control" required placeholder="เช่น ฟาร์มสุขใจ">
          </div>
          <div class="mb-3">
            <label class="form-label">ที่ตั้ง/ตำบล-อำเภอ</label>
            <input type="text" name="location" id="f_location" class="form-control" placeholder="เช่น ต.ขามเรียง อ.กันทรวิชัย">
          </div>
          <div class="mb-3">
            <label class="form-label">พื้นที่ (ไร่)</label>
            <input type="number" step="0.01" name="area_size" id="f_area_size" class="form-control" placeholder="เช่น 10">
          </div>

          <div class="mb-2">
            <label class="form-label">พื้นที่นี้มีแหล่งน้ำหรือไม่?</label>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="has_water_source" value="1" id="water_yes" onchange="toggleWater(true)">
              <label class="form-check-label" for="water_yes">มีแหล่งน้ำ</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="has_water_source" value="0" id="water_no" checked onchange="toggleWater(false)">
              <label class="form-check-label" for="water_no">ไม่มีแหล่งน้ำ</label>
            </div>
          </div>

          <div id="waterTypesBox" class="mb-3 p-3 rounded-3" style="background:var(--primary-lighter);display:none;">
            <label class="form-label">ประเภทแหล่งน้ำ (เลือกได้หลายข้อ)</label>
            <?php foreach ($waterOptions as $i => $opt): ?>
              <div class="form-check">
                <input class="form-check-input water-type-cb" type="checkbox" name="water_types[]" value="<?= e($opt) ?>" id="wt<?= $i ?>">
                <label class="form-check-label" for="wt<?= $i ?>"><?= e($opt) ?></label>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary-app w-100">บันทึกฟาร์ม</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function toggleWater(show) {
  document.getElementById('waterTypesBox').style.display = show ? 'block' : 'none';
  if (!show) {
    document.querySelectorAll('.water-type-cb').forEach(cb => cb.checked = false);
  }
}
function openFarmModal(data) {
  document.getElementById('farmModalTitle').innerText = data ? 'แก้ไขข้อมูลฟาร์ม' : 'เพิ่มฟาร์มใหม่';
  document.getElementById('f_farm_id').value = data ? data.farm_id : '';
  document.getElementById('f_farm_name').value = data ? data.farm_name : '';
  document.getElementById('f_location').value = data ? (data.location || '') : '';
  document.getElementById('f_area_size').value = data ? (data.area_size || '') : '';

  // reset checkboxes
  document.querySelectorAll('.water-type-cb').forEach(cb => cb.checked = false);

  const hasWater = data && parseInt(data.has_water_source) === 1;
  document.getElementById('water_yes').checked = hasWater;
  document.getElementById('water_no').checked = !hasWater;
  toggleWater(hasWater);

  if (hasWater && data.water_source_types) {
    const types = data.water_source_types.split(',');
    document.querySelectorAll('.water-type-cb').forEach(cb => {
      if (types.includes(cb.value)) cb.checked = true;
    });
  }
}
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
