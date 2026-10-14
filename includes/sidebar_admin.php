<?php $current = basename($_SERVER['SCRIPT_NAME']); ?>
<aside class="sidebar">
  <div class="brand"><i class="bi bi-shield-lock fs-4 text-success"></i> Admin Panel</div>
  <nav class="nav flex-column">
    <a class="nav-link <?= $current === 'dashboard.php' ? 'active' : '' ?>" href="dashboard.php"><i class="bi bi-speedometer2"></i> แดชบอร์ด</a>
    <a class="nav-link <?= $current === 'users.php' ? 'active' : '' ?>" href="users.php"><i class="bi bi-people"></i> จัดการผู้ใช้งาน</a>
    <a class="nav-link <?= $current === 'user_geo.php' ? 'active' : '' ?>" href="user_geo.php"><i class="bi bi-geo-alt"></i> ผู้ใช้งานตามจังหวัด</a>
    <a class="nav-link <?= $current === 'crops.php' ? 'active' : '' ?>" href="crops.php"><i class="bi bi-flower3"></i> ข้อมูลพืช/สูตรปุ๋ย</a>
    <a class="nav-link <?= $current === 'knowledge_base.php' ? 'active' : '' ?>" href="knowledge_base.php"><i class="bi bi-journal-richtext"></i> คลังความรู้ AI (RAG)</a>
    <a class="nav-link <?= $current === 'ai_local.php' ? 'active' : '' ?>" href="ai_local.php"><i class="bi bi-cpu"></i> AI ในเครื่อง (Ollama)</a>
    <a class="nav-link <?= $current === 'articles.php' ? 'active' : '' ?>" href="articles.php"><i class="bi bi-newspaper"></i> บทความ</a>
    <a class="nav-link <?= $current === 'case_studies.php' ? 'active' : '' ?>" href="case_studies.php"><i class="bi bi-patch-check"></i> กรณีศึกษา</a>
    <a class="nav-link <?= $current === 'stats.php' ? 'active' : '' ?>" href="stats.php"><i class="bi bi-bar-chart"></i> สถิติการใช้ AI</a>
    <hr>
    <a class="nav-link text-danger" href="logout.php"><i class="bi bi-box-arrow-right"></i> ออกจากระบบ</a>
  </nav>
</aside>
