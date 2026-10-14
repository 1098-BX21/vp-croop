<?php
require_once __DIR__ . '/../config/config.php';
$pageTitle = 'ข่าวสาร/บทความ';
$pdo = getDBConnection();
$articles = $pdo->query('SELECT * FROM articles ORDER BY created_at DESC')->fetchAll();
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/navbar_public.php';
?>
<div class="container py-5">
  <h2 class="fw-bold mb-4"><i class="bi bi-newspaper text-success"></i> ข่าวสารและบทความความรู้การเกษตร</h2>
  <div class="row g-4">
    <?php foreach ($articles as $a): ?>
      <div class="col-md-6">
        <div class="app-card p-4 h-100">
          <h5 class="fw-bold"><?= e($a['title']) ?></h5>
          <p class="text-muted small mb-1"><?= formatDateThai($a['created_at']) ?></p>
          <p><?= nl2br(e($a['content'])) ?></p>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$articles): ?><p class="text-muted">ยังไม่มีบทความในระบบ</p><?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
