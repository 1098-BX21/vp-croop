<?php $current = basename($_SERVER['SCRIPT_NAME']); ?>
<aside class="sidebar">
  <div class="brand"><i class="bi bi-flower2 fs-4 text-success"></i> Smart Farm</div>
  <nav class="nav flex-column">
    <a class="nav-link <?= $current === 'dashboard.php' ? 'active' : '' ?>" href="dashboard.php"><i class="bi bi-house-door"></i> หน้าหลัก</a>
    <a class="nav-link <?= $current === 'profile.php' ? 'active' : '' ?>" href="profile.php"><i class="bi bi-person-circle"></i> ข้อมูลส่วนตัว</a>
    <a class="nav-link <?= $current === 'farms.php' ? 'active' : '' ?>" href="farms.php"><i class="bi bi-houses"></i> ฟาร์มของฉัน</a>
    <a class="nav-link <?= $current === 'plots.php' ? 'active' : '' ?>" href="plots.php"><i class="bi bi-bounding-box"></i> แปลงเพาะปลูก</a>
    <a class="nav-link <?= $current === 'soil_data.php' ? 'active' : '' ?>" href="soil_data.php"><i class="bi bi-moisture"></i> บันทึกค่าดิน</a>
    <a class="nav-link <?= $current === 'crop_history.php' ? 'active' : '' ?>" href="crop_history.php"><i class="bi bi-clock-history"></i> ประวัติการปลูก</a>
    <a class="nav-link <?= $current === 'ai_advice.php' ? 'active' : '' ?>" href="ai_advice.php"><i class="bi bi-stars"></i> คำแนะนำ AI</a>
    <a class="nav-link <?= $current === 'grow_plan.php' ? 'active' : '' ?>" href="grow_plan.php"><i class="bi bi-calendar2-check"></i> แผนการปลูกจริง</a>
    <a class="nav-link <?= $current === 'disease_scan.php' ? 'active' : '' ?>" href="disease_scan.php"><i class="bi bi-bug"></i> วิเคราะห์โรคพืช</a>
    <a class="nav-link <?= $current === 'give_feedback.php' ? 'active' : '' ?>" href="give_feedback.php"><i class="bi bi-reply-fill"></i> ผลตอบกลับคำแนะนำ</a>
    <a class="nav-link <?= $current === 'chatbot.php' ? 'active' : '' ?>" href="chatbot.php"><i class="bi bi-chat-dots"></i> ผู้ช่วยแชท AI</a>
    <a class="nav-link <?= $current === 'reports.php' ? 'active' : '' ?>" href="reports.php"><i class="bi bi-bar-chart-line"></i> รายงาน</a>
    <hr>
    <a class="nav-link text-danger" href="logout.php"><i class="bi bi-box-arrow-right"></i> ออกจากระบบ</a>
  </nav>
</aside>
