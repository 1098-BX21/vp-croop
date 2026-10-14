<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
if (isAdmin()) redirect(BASE_URL . '/admin/dashboard.php');

$pageTitle = 'หน้าหลัก';
$uid = currentUser()['user_id'];
$pdo = getDBConnection();

$farmCount = $pdo->prepare('SELECT COUNT(*) FROM farms WHERE user_id=?');
$farmCount->execute([$uid]);
$farmCount = $farmCount->fetchColumn();

$plotCount = $pdo->prepare('SELECT COUNT(*) FROM plots p JOIN farms f ON f.farm_id=p.farm_id WHERE f.user_id=?');
$plotCount->execute([$uid]);
$plotCount = $plotCount->fetchColumn();

$soilCount = $pdo->prepare('SELECT COUNT(*) FROM soil_data s JOIN plots p ON p.plot_id=s.plot_id JOIN farms f ON f.farm_id=p.farm_id WHERE f.user_id=?');
$soilCount->execute([$uid]);
$soilCount = $soilCount->fetchColumn();

$aiCount = $pdo->prepare('SELECT COUNT(*) FROM ai_recommendations r JOIN plots p ON p.plot_id=r.plot_id JOIN farms f ON f.farm_id=p.farm_id WHERE f.user_id=?');
$aiCount->execute([$uid]);
$aiCount = $aiCount->fetchColumn();

$recentRecs = $pdo->prepare('
  SELECT r.*, pl.plot_name FROM ai_recommendations r
  JOIN plots pl ON pl.plot_id = r.plot_id
  JOIN farms f ON f.farm_id = pl.farm_id
  WHERE f.user_id = ? ORDER BY r.created_at DESC LIMIT 5
');
$recentRecs->execute([$uid]);
$recentRecs = $recentRecs->fetchAll();

$myProvince = $pdo->prepare('SELECT province FROM users WHERE user_id = ?');
$myProvince->execute([$uid]);
$needProvince = $myProvince->fetchColumn() === null;

require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar_user.php'; ?>
  <main class="main-content">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>

    <?php if ($needProvince): ?>
      <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div><i class="bi bi-geo-alt me-1"></i> คุณยังไม่ได้ระบุ<b>จังหวัด</b> (ใช้ทำสถิติผู้ใช้งานตามพื้นที่ของระบบ)</div>
        <a href="profile.php" class="btn btn-sm btn-primary-app">ระบุจังหวัด</a>
      </div>
    <?php endif; ?>

    <?php if ($farmCount == 0): ?>
      <div class="ai-box mb-4">
        <div class="row align-items-center">
          <div class="col-md-8">
            <h6 class="fw-bold mb-1"><i class="bi bi-rocket-takeoff text-success"></i> เริ่มต้นใช้งานแบบเร็ว</h6>
            <p class="small text-muted mb-0">ยังไม่มีฟาร์มในระบบ ตั้งค่าฟาร์มและแปลงแรกให้เสร็จภายใน 1 นาที แล้วเริ่มขอคำแนะนำจาก AI ได้ทันที</p>
          </div>
          <div class="col-md-4 text-md-end mt-3 mt-md-0">
            <a href="quick_setup.php" class="btn btn-primary-app"><i class="bi bi-lightning-charge me-1"></i> เริ่มตั้งค่าเลย</a>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
      <div class="col-md-3 col-6">
        <div class="stat-card"><div class="icon-box"><i class="bi bi-houses"></i></div>
          <div><div class="stat-value"><?= (int)$farmCount ?></div><div class="stat-label">ฟาร์มทั้งหมด</div></div></div>
      </div>
      <div class="col-md-3 col-6">
        <div class="stat-card"><div class="icon-box"><i class="bi bi-bounding-box"></i></div>
          <div><div class="stat-value"><?= (int)$plotCount ?></div><div class="stat-label">แปลงเพาะปลูก</div></div></div>
      </div>
      <div class="col-md-3 col-6">
        <div class="stat-card"><div class="icon-box"><i class="bi bi-moisture"></i></div>
          <div><div class="stat-value"><?= (int)$soilCount ?></div><div class="stat-label">บันทึกค่าดิน</div></div></div>
      </div>
      <div class="col-md-3 col-6">
        <div class="stat-card"><div class="icon-box"><i class="bi bi-stars"></i></div>
          <div><div class="stat-value"><?= (int)$aiCount ?></div><div class="stat-label">คำแนะนำ AI</div></div></div>
      </div>
    </div>

    <div class="row g-3 mb-4">
      <div class="col-6 col-md"><a href="quick_setup.php" class="text-decoration-none"><div class="app-card p-3 text-center"><i class="bi bi-plus-circle text-success fs-3"></i><div class="fw-bold mt-1">เพิ่มฟาร์ม</div></div></a></div>
      <div class="col-6 col-md"><a href="soil_data.php" class="text-decoration-none"><div class="app-card p-3 text-center"><i class="bi bi-moisture text-success fs-3"></i><div class="fw-bold mt-1">บันทึกค่าดิน</div></div></a></div>
      <div class="col-6 col-md"><a href="ai_advice.php" class="text-decoration-none"><div class="app-card p-3 text-center"><i class="bi bi-stars text-success fs-3"></i><div class="fw-bold mt-1">ขอคำแนะนำ AI</div></div></a></div>
      <div class="col-6 col-md"><a href="grow_plan.php" class="text-decoration-none"><div class="app-card p-3 text-center"><i class="bi bi-calendar2-check text-success fs-3"></i><div class="fw-bold mt-1">แผนการปลูก</div></div></a></div>
      <div class="col-6 col-md"><a href="disease_scan.php" class="text-decoration-none"><div class="app-card p-3 text-center"><i class="bi bi-bug text-success fs-3"></i><div class="fw-bold mt-1">วิเคราะห์โรคพืช</div></div></a></div>
      <div class="col-6 col-md"><a href="chatbot.php" class="text-decoration-none"><div class="app-card p-3 text-center"><i class="bi bi-chat-dots text-success fs-3"></i><div class="fw-bold mt-1">ผู้ช่วยแชท AI</div></div></a></div>
    </div>

    <div class="app-card p-4">
      <h6 class="fw-bold mb-3"><i class="bi bi-clock-history text-success"></i> คำแนะนำ AI ล่าสุด</h6>
      <?php if (!$recentRecs): ?>
        <p class="text-muted mb-0">ยังไม่มีกิจกรรม เริ่มต้นใช้งานโดยการเพิ่มฟาร์มของคุณ!</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table align-middle">
            <thead><tr><th>แปลง</th><th>พืชที่แนะนำ</th><th>วันที่</th></tr></thead>
            <tbody>
              <?php foreach ($recentRecs as $r): ?>
                <tr>
                  <td><?= e($r['plot_name']) ?></td>
                  <td><span class="badge badge-soft-green"><?= e($r['recommended_crop']) ?></span></td>
                  <td class="small text-muted"><?= formatDateThai($r['created_at']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
