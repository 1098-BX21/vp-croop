<?php
require_once __DIR__ . '/../config/config.php';
$pageTitle = 'กรณีศึกษาจากเกษตรกร';
$pdo = getDBConnection();

// แสดงเฉพาะกรณีศึกษาที่ผ่านการอนุมัติ/เผยแพร่โดยผู้ดูแลระบบ (ผลตอบกลับของเกษตรกรต้องยินยอมเผยแพร่ด้วย)
// ไม่ดึงชื่อ/อีเมล/ที่อยู่/ชื่อฟาร์มของเกษตรกร (ดู lib/case_studies.php)
$filterCrop = trim($_GET['crop'] ?? '');
$cases = caseStudiesPublic($pdo, $filterCrop);
$crops = caseStudyCropsPublic($pdo);
$all = $filterCrop === '' ? $cases : caseStudiesPublic($pdo);
$rated = array_filter(array_column($all, 'rating'));
$summary = [
    'total'          => count($all),
    'followed_count' => count(array_filter($all, fn($x) => $x['kind'] === 'feedback' && $x['followed'])),
    'avg_rating'     => $rated ? array_sum($rated) / count($rated) : null,
];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/navbar_public.php';
?>
<div class="container py-5">
  <div class="text-center mb-5">
    <h2 class="fw-bold"><i class="bi bi-people-fill text-success"></i> กรณีศึกษาจากเกษตรกรจริง</h2>
    <p class="text-muted">ผลลัพธ์จริงจากเกษตรกรที่นำคำแนะนำของ AI ไปใช้ แบ่งปันโดยสมัครใจ (ไม่แสดงชื่อหรือที่อยู่ฟาร์ม) ทุกเรื่องผ่านการตรวจสอบโดยผู้ดูแลระบบก่อนเผยแพร่</p>
  </div>

  <div class="row g-3 mb-4 text-center">
    <div class="col-md-4">
      <div class="app-card p-3"><div class="fs-3 fw-bold text-success"><?= (int) $summary['total'] ?></div><div class="small text-muted">กรณีศึกษาทั้งหมด</div></div>
    </div>
    <div class="col-md-4">
      <div class="app-card p-3"><div class="fs-3 fw-bold text-success"><?= (int) $summary['followed_count'] ?></div><div class="small text-muted">ทำตามคำแนะนำจริง</div></div>
    </div>
    <div class="col-md-4">
      <div class="app-card p-3"><div class="fs-3 fw-bold text-success"><?= $summary['avg_rating'] ? number_format($summary['avg_rating'], 1) : '-' ?> ⭐</div><div class="small text-muted">คะแนนความพึงพอใจเฉลี่ย</div></div>
    </div>
  </div>

  <?php if ($crops): ?>
  <div class="mb-4">
    <form method="get" class="d-flex gap-2 align-items-center">
      <label class="small text-muted">กรองตามพืช:</label>
      <select name="crop" class="form-select form-select-sm" style="max-width:260px;" onchange="this.form.submit()">
        <option value="">— ทุกชนิดพืช —</option>
        <?php foreach ($crops as $c): ?>
          <option value="<?= e($c) ?>" <?= $filterCrop === $c ? 'selected' : '' ?>><?= e($c) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
  <?php endif; ?>

  <div class="row g-4">
    <?php foreach ($cases as $c): ?>
      <div class="col-md-6 col-lg-4"><?= caseRenderCard($c) ?></div>
    <?php endforeach; ?>

    <?php if (!$cases): ?>
      <div class="col-12">
        <div class="text-center text-muted py-5">
          <i class="bi bi-inboxes fs-1"></i>
          <p class="mt-2">ยังไม่มีกรณีศึกษาที่เผยแพร่ในขณะนี้ — เมื่อเกษตรกรเริ่มแบ่งปันผลลัพธ์ จะแสดงที่นี่</p>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
