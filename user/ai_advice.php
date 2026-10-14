<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
$pageTitle = 'คำแนะนำ AI';
$uid = currentUser()['user_id'];
$pdo = getDBConnection();

$plots = $pdo->prepare('
  SELECT p.plot_id, p.plot_name, f.farm_name,
    (SELECT COUNT(*) FROM soil_data s WHERE s.plot_id = p.plot_id) AS soil_count
  FROM plots p JOIN farms f ON f.farm_id = p.farm_id
  WHERE f.user_id = ? ORDER BY f.farm_name, p.plot_name
');
$plots->execute([$uid]);
$plots = $plots->fetchAll();

// แปลงที่เลือกเริ่มต้น: ตามพารามิเตอร์ ?plot= ถ้าไม่มีใช้แปลงแรกที่มีข้อมูลดิน
$selectedPlot = (int) ($_GET['plot'] ?? 0);
if (!in_array($selectedPlot, array_map('intval', array_column($plots, 'plot_id')), true)) {
    $selectedPlot = 0;
    foreach ($plots as $p) {
        if ((int) $p['soil_count'] > 0) { $selectedPlot = (int) $p['plot_id']; break; }
    }
}

require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar_user.php'; ?>
  <main class="main-content">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>

    <?php if (!$plots): ?>
      <div class="alert alert-warning">กรุณาเพิ่มแปลงเพาะปลูกและบันทึกค่าดินก่อน จึงจะขอคำแนะนำได้ <a href="quick_setup.php">เริ่มต้นแบบเร็วที่นี่</a></div>
    <?php else: ?>
    <div class="row g-4">
      <div class="col-lg-5">
        <div class="app-card p-4">
          <h6 class="fw-bold mb-3"><i class="bi bi-stars text-success"></i> ขอคำแนะนำการปลูกพืชหมุนเวียน</h6>
          <p class="small text-muted">ระบบจะให้คะแนนพืชแต่ละชนิดจากค่า pH ธาตุอาหาร การหมุนเวียน แหล่งน้ำ และราคาตลาด แล้วให้ AI อธิบายเหตุผล ผลทุกครั้งถูกเก็บไว้ดูย้อนหลังได้</p>
          <form id="aiAdviceForm" action="ai_advice_request.php" method="post">
            <?= csrfField() ?>
            <div class="mb-3">
              <label class="form-label">เลือกแปลงเพาะปลูก</label>
              <select name="plot_id" id="aiPlotSelect" class="form-select" required>
                <?php foreach ($plots as $p): ?>
                  <option value="<?= $p['plot_id'] ?>" <?= $p['soil_count'] == 0 ? 'disabled' : '' ?> <?= (int) $p['plot_id'] === $selectedPlot ? 'selected' : '' ?>>
                    <?= e($p['farm_name'] . ' - ' . $p['plot_name']) ?>
                    <?= $p['soil_count'] == 0 ? '(ยังไม่มีข้อมูลดิน)' : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <p class="small text-muted mb-3"><i class="bi bi-bug me-1"></i> ต้องการวิเคราะห์โรคพืชจากภาพ? ไปที่เมนู <a href="disease_scan.php">วิเคราะห์โรคพืช</a></p>
            <button type="submit" id="aiSubmitBtn" class="btn btn-primary-app w-100"><i class="bi bi-stars me-1"></i> ขอคำแนะนำ</button>
          </form>
        </div>
      </div>
      <div class="col-lg-7">
        <div class="ai-box" style="min-height: 300px;">
          <h6 class="fw-bold mb-3"><i class="bi bi-robot text-success"></i> ผลการวิเคราะห์</h6>
          <div id="aiResultBox">
            <p class="text-muted">เลือกแปลงและกด "ขอคำแนะนำ" เพื่อเริ่มการวิเคราะห์ด้วย AI</p>
          </div>
        </div>
      </div>
    </div>

    <div class="mt-4">
      <h6 class="fw-bold mb-3"><i class="bi bi-clock-history text-success"></i> คำแนะนำก่อนหน้าของแปลงนี้</h6>
      <div id="adviceHistory">
        <?= $selectedPlot ? adviceRenderHistory($pdo, $selectedPlot, 10) : '<p class="text-muted">เลือกแปลงเพื่อดูประวัติคำแนะนำ</p>' ?>
      </div>
    </div>
    <?php endif; ?>
  </main>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
