<nav class="navbar navbar-expand-lg navbar-app sticky-top">
  <div class="container">
    <a class="navbar-brand" href="<?= BASE_URL ?>/public/index.php">
      <i class="bi bi-flower2 fs-4"></i> Smart Farm <span class="text-dark">Advisory</span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navPublic">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navPublic">
      <ul class="navbar-nav me-auto ms-lg-4">
        <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/public/index.php">หน้าแรก</a></li>
        <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/public/articles.php">ข่าวสาร/บทความ</a></li>
        <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/public/case_studies.php">กรณีศึกษา</a></li>
      </ul>
      <div class="d-flex gap-2">
        <?php if (isLoggedIn()): ?>
          <a href="<?= BASE_URL ?>/<?= isAdmin() ? 'admin/dashboard.php' : 'user/dashboard.php' ?>" class="btn btn-primary-app">
            <i class="bi bi-speedometer2 me-1"></i> เข้าสู่แดชบอร์ด
          </a>
        <?php else: ?>
          <a href="<?= BASE_URL ?>/public/login.php" class="btn btn-outline-app">เข้าสู่ระบบ</a>
          <a href="<?= BASE_URL ?>/public/register.php" class="btn btn-primary-app">สมัครสมาชิก</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</nav>
