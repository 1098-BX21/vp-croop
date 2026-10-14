<?php
require_once __DIR__ . '/../config/config.php';
requireAdmin();
$pageTitle = 'แดชบอร์ด';
$pdo = getDBConnection();

$userCount = $pdo->query('SELECT COUNT(*) FROM users WHERE role="farmer"')->fetchColumn();
$cropCount = $pdo->query('SELECT COUNT(*) FROM crops')->fetchColumn();
$aiCount = $pdo->query('SELECT COUNT(*) FROM ai_recommendations')->fetchColumn();
$docCount = $pdo->query('SELECT COUNT(*) FROM knowledge_docs')->fetchColumn();
$pendingCaseStudies = $pdo->query('SELECT COUNT(*) FROM recommendation_feedback WHERE is_public=1 AND admin_approved=0')->fetchColumn();

$recentLogs = $pdo->query('
  SELECT a.*, u.full_name FROM activity_logs a
  LEFT JOIN users u ON u.user_id = a.user_id
  ORDER BY a.log_id DESC LIMIT 10
')->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar_admin.php'; ?>
  <main class="main-content">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>

    <?php if ($pendingCaseStudies > 0): ?>
      <div class="ai-box mb-4">
        <div class="row align-items-center">
          <div class="col-md-8">
            <h6 class="fw-bold mb-1"><i class="bi bi-patch-check text-success"></i> มีกรณีศึกษารออนุมัติ <?= (int) $pendingCaseStudies ?> รายการ</h6>
            <p class="small text-muted mb-0">เกษตรกรส่งผลตอบกลับและยินยอมให้เผยแพร่ รอผู้ดูแลระบบตรวจสอบก่อนขึ้นหน้าเว็บ</p>
          </div>
          <div class="col-md-4 text-md-end mt-3 mt-md-0">
            <a href="case_studies.php" class="btn btn-primary-app"><i class="bi bi-arrow-right-circle me-1"></i> ไปตรวจสอบ</a>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
      <div class="col-md-3 col-6"><div class="stat-card"><div class="icon-box"><i class="bi bi-people"></i></div><div><div class="stat-value"><?= (int)$userCount ?></div><div class="stat-label">เกษตรกร</div></div></div></div>
      <div class="col-md-3 col-6"><div class="stat-card"><div class="icon-box"><i class="bi bi-flower3"></i></div><div><div class="stat-value"><?= (int)$cropCount ?></div><div class="stat-label">ชนิดพืช</div></div></div></div>
      <div class="col-md-3 col-6"><div class="stat-card"><div class="icon-box"><i class="bi bi-stars"></i></div><div><div class="stat-value"><?= (int)$aiCount ?></div><div class="stat-label">คำแนะนำ AI</div></div></div></div>
      <div class="col-md-3 col-6"><div class="stat-card"><div class="icon-box"><i class="bi bi-journal-richtext"></i></div><div><div class="stat-value"><?= (int)$docCount ?></div><div class="stat-label">เอกสารคลังความรู้</div></div></div></div>
    </div>

    <div class="app-card p-4">
      <h6 class="fw-bold mb-3"><i class="bi bi-activity text-success"></i> กิจกรรมล่าสุดในระบบ</h6>
      <div class="table-responsive">
        <table class="table align-middle">
          <thead><tr><th>ผู้ใช้</th><th>กิจกรรม</th><th>รายละเอียด</th><th>เวลา</th></tr></thead>
          <tbody>
            <?php foreach ($recentLogs as $l): ?>
              <tr>
                <td><?= e($l['full_name'] ?? 'ระบบ') ?></td>
                <td><span class="badge badge-soft-green"><?= e($l['action']) ?></span></td>
                <td class="small text-muted"><?= e(mb_substr($l['detail'] ?? '', 0, 60)) ?></td>
                <td class="small text-muted"><?= formatDateThai($l['created_at']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$recentLogs): ?><tr><td colspan="4" class="text-center text-muted py-3">ยังไม่มีกิจกรรม</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
