<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
$pageTitle = 'วิเคราะห์โรคพืช';
$uid = currentUser()['user_id'];
$pdo = getDBConnection();

// ---------- ลบผลวิเคราะห์ (เฉพาะของตัวเอง) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    requireCsrf('disease_scan.php');
    $st = $pdo->prepare('SELECT scan_id, image FROM plant_disease_scans WHERE scan_id = ? AND user_id = ?');
    $st->execute([(int) ($_POST['scan_id'] ?? 0), $uid]);
    if ($row = $st->fetch()) {
        $pdo->prepare('DELETE FROM plant_disease_scans WHERE scan_id = ?')->execute([$row['scan_id']]);
        diseaseScanDeleteImage($row['image']);
        logActivity($uid, 'disease_scan_delete', 'scan#' . $row['scan_id']);
        flash('success', 'ลบผลวิเคราะห์แล้ว');
    } else {
        flash('error', 'ไม่พบรายการที่ต้องการลบ');
    }
    redirect('disease_scan.php');
}

// ---------- วิเคราะห์ภาพใหม่ ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'scan') {
    requireCsrf('disease_scan.php');

    if (!checkRateLimit($uid, 'disease_scan', RATE_LIMIT_DISEASE_PER_DAY)) {
        flash('error', 'วิเคราะห์โรคพืชครบโควตาต่อวันแล้ว (' . RATE_LIMIT_DISEASE_PER_DAY . ' ครั้ง) กรุณาลองใหม่พรุ่งนี้');
        redirect('disease_scan.php');
    }
    if (empty($_FILES['plant_image']['name'])) {
        flash('error', 'กรุณาเลือกรูปภาพพืชที่ต้องการวิเคราะห์');
        redirect('disease_scan.php');
    }

    $plantName = mb_substr(trim($_POST['plant_name'] ?? ''), 0, 150);
    $note = mb_substr(trim($_POST['user_note'] ?? ''), 0, 1000);

    $plotId = null;
    if (!empty($_POST['plot_id'])) {
        $chk = $pdo->prepare('SELECT p.plot_id FROM plots p JOIN farms f ON f.farm_id = p.farm_id WHERE p.plot_id = ? AND f.user_id = ?');
        $chk->execute([(int) $_POST['plot_id'], $uid]);
        $plotId = $chk->fetchColumn() ?: null;
    }

    $image = uploadImageFile($_FILES['plant_image'], UPLOAD_PATH_DISEASE);
    if ($image === null) {
        flash('error', 'อัปโหลดรูปไม่สำเร็จ รองรับเฉพาะไฟล์ JPG, PNG, WebP');
        redirect('disease_scan.php');
    }

    $res = diseaseScanAnalyze($pdo, UPLOAD_PATH_DISEASE . $image, $plantName, $note);
    if (!$res['success']) {
        diseaseScanDeleteImage($image); // ไม่เก็บรูปที่วิเคราะห์ไม่สำเร็จ
        flash('error', $res['error']);
        redirect('disease_scan.php');
    }

    $d = $res['data'];
    $pdo->prepare('INSERT INTO plant_disease_scans
        (user_id, plot_id, plant_name, user_note, image, status, disease_name, confidence, severity, summary, symptoms, causes, treatment, prevention, raw_response, model)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$uid, $plotId, $plantName ?: null, $note ?: null, $image, $d['status'], $d['disease_name'], $d['confidence'], $d['severity'],
            $d['summary'], $d['symptoms'], $d['causes'], $d['treatment'], $d['prevention'], $res['raw'], $res['model']]);
    $scanId = (int) $pdo->lastInsertId();
    logActivity($uid, 'disease_scan', 'scan#' . $scanId . ' ' . $d['status']);
    redirect('disease_scan.php?view=' . $scanId);
}

// ---------- ข้อมูลสำหรับแสดงผล ----------
$plots = $pdo->prepare('SELECT p.plot_id, p.plot_name, f.farm_name FROM plots p JOIN farms f ON f.farm_id = p.farm_id WHERE f.user_id = ? ORDER BY f.farm_name, p.plot_name');
$plots->execute([$uid]);
$plots = $plots->fetchAll();
$cropNames = $pdo->query('SELECT crop_name FROM crops ORDER BY crop_name')->fetchAll(PDO::FETCH_COLUMN);

$view = null;
if (isset($_GET['view'])) {
    $st = $pdo->prepare('SELECT s.*, p.plot_name FROM plant_disease_scans s LEFT JOIN plots p ON p.plot_id = s.plot_id WHERE s.scan_id = ? AND s.user_id = ?');
    $st->execute([(int) $_GET['view'], $uid]);
    $view = $st->fetch() ?: null;
    if (!$view) {
        flash('error', 'ไม่พบผลวิเคราะห์นี้');
        redirect('disease_scan.php');
    }
}

$filterPlot = (int) ($_GET['plot'] ?? 0);
$sql = 'SELECT s.*, p.plot_name FROM plant_disease_scans s LEFT JOIN plots p ON p.plot_id = s.plot_id WHERE s.user_id = :u';
if ($filterPlot > 0) {
    $sql .= ' AND s.plot_id = :p';
}
$sql .= ' ORDER BY s.created_at DESC, s.scan_id DESC LIMIT 30';
$hs = $pdo->prepare($sql);
$hs->bindValue(':u', $uid, PDO::PARAM_INT);
if ($filterPlot > 0) {
    $hs->bindValue(':p', $filterPlot, PDO::PARAM_INT);
}
$hs->execute();
$history = $hs->fetchAll();

$imgUrl = fn($f) => BASE_URL . '/uploads/disease/' . rawurlencode($f);

require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar_user.php'; ?>
  <main class="main-content">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>

    <div class="row g-4">
      <div class="col-lg-5">
        <div class="app-card p-4">
          <h6 class="fw-bold mb-1"><i class="bi bi-bug text-success"></i> วิเคราะห์โรคพืชจากภาพ</h6>
          <p class="small text-muted">ถ่ายภาพใบหรือต้นที่มีอาการผิดปกติ ให้ชัดและเห็นอาการใกล้ๆ AI จะประเมินโรค/ศัตรูพืชเบื้องต้นและแนวทางจัดการ ผลทุกครั้งจะถูกเก็บไว้ให้ย้อนดูได้</p>
          <form method="post" enctype="multipart/form-data" id="scanForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="scan">
            <div class="mb-3">
              <label class="form-label">รูปภาพพืช <span class="text-danger">*</span></label>
              <input type="file" name="plant_image" accept="image/*" class="form-control" required data-preview="scanPreview">
              <img id="scanPreview" class="d-none rounded mt-2" style="max-height:160px;" alt="ตัวอย่างรูป">
            </div>
            <div class="mb-3">
              <label class="form-label">ชื่อพืช (ไม่บังคับ)</label>
              <input type="text" name="plant_name" class="form-control" list="cropList" maxlength="150" placeholder="เช่น อ้อย มันสำปะหลัง พิมพ์เองได้">
              <datalist id="cropList"><?php foreach ($cropNames as $n): ?><option value="<?= e($n) ?>"></option><?php endforeach; ?></datalist>
            </div>
            <div class="mb-3">
              <label class="form-label">แปลงที่เกี่ยวข้อง (ไม่บังคับ)</label>
              <select name="plot_id" class="form-select">
                <option value="">— ไม่ระบุแปลง —</option>
                <?php foreach ($plots as $p): ?>
                  <option value="<?= $p['plot_id'] ?>"><?= e($p['farm_name'] . ' - ' . $p['plot_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">อาการที่สังเกตเห็น (ไม่บังคับ)</label>
              <textarea name="user_note" class="form-control" rows="2" maxlength="1000" placeholder="เช่น ใบเหลืองเป็นจุด เริ่มเป็นมา 3 วัน หลังฝนตก"></textarea>
            </div>
            <button type="submit" id="scanBtn" class="btn btn-primary-app w-100"><i class="bi bi-search-heart me-1"></i> วิเคราะห์โรคพืช</button>
            <p id="scanWait" class="small text-muted mt-2 mb-0 d-none">กำลังวิเคราะห์ภาพ อาจใช้เวลาหลายสิบวินาที (ครั้งแรกหลังเปิดเครื่องจะนานกว่า) กรุณาอย่าปิดหน้านี้</p>
          </form>
        </div>
      </div>

      <div class="col-lg-7">
        <div class="ai-box" style="min-height:300px;">
          <?php if (!$view): ?>
            <h6 class="fw-bold mb-3"><i class="bi bi-robot text-success"></i> ผลการวิเคราะห์</h6>
            <p class="text-muted mb-0">เลือกรูปแล้วกด "วิเคราะห์โรคพืช" ผลจะแสดงที่นี่ หรือเลือกดูผลเก่าจากประวัติด้านล่าง</p>
          <?php else: ?>
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
              <div>
                <?= diseaseStatusBadge($view['status'], $view['severity']) ?>
                <?php if ($view['severity'] && $view['severity'] !== 'none'): ?>
                  <span class="badge badge-soft-amber">ความรุนแรง: <?= e(DISEASE_SEVERITY_TH[$view['severity']] ?? $view['severity']) ?></span>
                <?php endif; ?>
                <?php if ($view['confidence']): ?>
                  <span class="badge badge-soft-blue">ความมั่นใจ: <?= e(DISEASE_CONFIDENCE_TH[$view['confidence']] ?? $view['confidence']) ?></span>
                <?php endif; ?>
              </div>
              <span class="small text-muted"><?= formatDateThai($view['created_at']) ?> <?= date('H:i', strtotime($view['created_at'])) ?> น.</span>
            </div>
            <div class="row g-3">
              <div class="col-md-5"><img src="<?= $imgUrl($view['image']) ?>" class="img-fluid rounded-3 w-100" style="max-height:260px;object-fit:cover;" alt="ภาพที่วิเคราะห์"></div>
              <div class="col-md-7">
                <h5 class="fw-bold text-success mb-1"><?= e($view['disease_name']) ?></h5>
                <p class="small text-muted mb-2">
                  <?= $view['plant_name'] ? 'พืช: ' . e($view['plant_name']) : '' ?>
                  <?= $view['plot_name'] ? ' · แปลง: ' . e($view['plot_name']) : '' ?>
                </p>
                <p class="mb-0"><?= nl2br(e($view['summary'])) ?></p>
              </div>
            </div>
            <?php foreach (['symptoms' => ['อาการที่พบ', 'bi-eye'], 'causes' => ['สาเหตุที่เป็นไปได้', 'bi-question-circle'], 'treatment' => ['แนวทางจัดการเบื้องต้น', 'bi-bandaid'], 'prevention' => ['การป้องกัน', 'bi-shield-check']] as $k => [$label, $icon]): ?>
              <?php if (!empty($view[$k])): ?>
                <div class="mt-3 p-3 rounded-3" style="background:var(--primary-lighter);border:1px solid var(--primary-light);">
                  <h6 class="fw-bold mb-1"><i class="bi <?= $icon ?> me-1"></i> <?= $label ?></h6>
                  <p class="mb-0"><?= nl2br(e($view[$k])) ?></p>
                </div>
              <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($view['user_note']): ?>
              <p class="small text-muted mt-3 mb-0"><b>อาการที่คุณแจ้ง:</b> <?= e($view['user_note']) ?></p>
            <?php endif; ?>
            <div class="alert alert-warning small mt-3 mb-2"><i class="bi bi-exclamation-triangle me-1"></i>
              ผลนี้เป็นการประเมินเบื้องต้นจาก AI อาจคลาดเคลื่อนได้ ก่อนใช้สารเคมีหรือตัดสินใจรักษา ควรปรึกษาเจ้าหน้าที่เกษตรหรือสำนักงานเกษตรในพื้นที่ และอ่านฉลากผลิตภัณฑ์ทุกครั้ง</div>
            <div class="d-flex justify-content-between align-items-center">
              <span class="small text-muted">วิเคราะห์โดย: <?= e($view['model'] ?: '-') ?></span>
              <form method="post" onsubmit="return confirm('ยืนยันการลบผลวิเคราะห์นี้และรูปภาพ?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete"><input type="hidden" name="scan_id" value="<?= (int) $view['scan_id'] ?>">
                <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1"></i> ลบ</button>
              </form>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="app-card p-4 mt-4" id="history">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h6 class="fw-bold mb-0"><i class="bi bi-clock-history text-success"></i> ประวัติการวิเคราะห์โรคพืช</h6>
        <form method="get" class="d-flex gap-2 align-items-center">
          <select name="plot" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="0">ทุกแปลง</option>
            <?php foreach ($plots as $p): ?>
              <option value="<?= $p['plot_id'] ?>" <?= $filterPlot === (int) $p['plot_id'] ? 'selected' : '' ?>><?= e($p['farm_name'] . ' - ' . $p['plot_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>
      <?php if (!$history): ?>
        <p class="text-muted mb-0">ยังไม่มีประวัติการวิเคราะห์<?= $filterPlot ? 'ของแปลงนี้' : '' ?></p>
      <?php else: ?>
        <div class="row g-3">
          <?php foreach ($history as $h): ?>
            <div class="col-md-6 col-xl-4">
              <a href="disease_scan.php?view=<?= (int) $h['scan_id'] ?>#top" class="text-decoration-none text-reset">
                <div class="app-card h-100 overflow-hidden <?= $view && (int) $view['scan_id'] === (int) $h['scan_id'] ? 'border border-success' : '' ?>">
                  <img src="<?= $imgUrl($h['image']) ?>" style="width:100%;height:140px;object-fit:cover;" alt="" loading="lazy">
                  <div class="p-3">
                    <div class="mb-1"><?= diseaseStatusBadge($h['status'], $h['severity']) ?></div>
                    <div class="fw-semibold"><?= e($h['disease_name']) ?></div>
                    <div class="small text-muted"><?= $h['plant_name'] ? e($h['plant_name']) . ' · ' : '' ?><?= $h['plot_name'] ? e($h['plot_name']) . ' · ' : '' ?><?= formatDateThai($h['created_at']) ?></div>
                  </div>
                </div>
              </a>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>
<script>
document.getElementById('scanForm').addEventListener('submit', function () {
  var b = document.getElementById('scanBtn');
  b.disabled = true;
  b.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>กำลังวิเคราะห์...';
  document.getElementById('scanWait').classList.remove('d-none');
});
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
