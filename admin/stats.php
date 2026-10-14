<?php
require_once __DIR__ . '/../config/config.php';
requireAdmin();
$pageTitle = 'สถิติการใช้ AI';
$pdo = getDBConnection();

// ---- สรุปการใช้งาน AI (token) ----
$aiSummary = $pdo->query('
  SELECT
    COUNT(*) AS total_calls,
    COALESCE(SUM(total_tokens),0) AS total_tokens,
    COALESCE(SUM(CASE WHEN DATE(created_at)=CURDATE() THEN total_tokens ELSE 0 END),0) AS today_tokens,
    COALESCE(SUM(CASE WHEN DATE(created_at)=CURDATE() THEN 1 ELSE 0 END),0) AS today_calls
  FROM ai_usage_logs
')->fetch();

// การใช้ AI แยกตามผู้ให้บริการ
$byProvider = $pdo->query('
  SELECT provider, COUNT(*) AS cnt, COALESCE(SUM(total_tokens),0) AS tokens
  FROM ai_usage_logs GROUP BY provider ORDER BY cnt DESC
')->fetchAll();

// การใช้ AI แยกตามฟีเจอร์
$byFeature = $pdo->query('
  SELECT feature, COUNT(*) AS cnt, COALESCE(SUM(total_tokens),0) AS tokens
  FROM ai_usage_logs GROUP BY feature ORDER BY cnt DESC
')->fetchAll();

// token ต่อวัน (7 วันล่าสุด)
$tokenPerDay = $pdo->query('
  SELECT DATE(created_at) AS d, COALESCE(SUM(total_tokens),0) AS tokens
  FROM ai_usage_logs
  WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
  GROUP BY DATE(created_at) ORDER BY d ASC
')->fetchAll();

// ผู้ใช้ที่ใช้งาน AI มากที่สุด
$topUsers = $pdo->query('
  SELECT u.full_name, COUNT(*) AS cnt, COALESCE(SUM(a.total_tokens),0) AS tokens
  FROM ai_usage_logs a JOIN users u ON u.user_id = a.user_id
  GROUP BY a.user_id ORDER BY cnt DESC LIMIT 5
')->fetchAll();

// พืชที่ AI แนะนำมากที่สุด
$topCrops = $pdo->query('
  SELECT recommended_crop, COUNT(*) AS cnt FROM ai_recommendations
  GROUP BY recommended_crop ORDER BY cnt DESC LIMIT 8
')->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar_admin.php'; ?>
  <main class="main-content">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>

    <!-- สรุปการใช้งาน AI -->
    <div class="row g-3 mb-4">
      <div class="col-md-3 col-6">
        <div class="stat-card"><div class="icon-box"><i class="bi bi-cpu"></i></div>
          <div><div class="stat-value"><?= number_format($aiSummary['total_calls']) ?></div><div class="stat-label">เรียก AI ทั้งหมด</div></div></div>
      </div>
      <div class="col-md-3 col-6">
        <div class="stat-card"><div class="icon-box"><i class="bi bi-coin"></i></div>
          <div><div class="stat-value"><?= number_format($aiSummary['total_tokens']) ?></div><div class="stat-label">Token รวมทั้งหมด</div></div></div>
      </div>
      <div class="col-md-3 col-6">
        <div class="stat-card"><div class="icon-box"><i class="bi bi-calendar-day"></i></div>
          <div><div class="stat-value"><?= number_format($aiSummary['today_calls']) ?></div><div class="stat-label">เรียก AI วันนี้</div></div></div>
      </div>
      <div class="col-md-3 col-6">
        <div class="stat-card"><div class="icon-box"><i class="bi bi-graph-up"></i></div>
          <div><div class="stat-value"><?= number_format($aiSummary['today_tokens']) ?></div><div class="stat-label">Token วันนี้</div></div></div>
      </div>
    </div>

    <div class="row g-4 mb-4">
      <!-- Token ต่อวัน -->
      <div class="col-lg-8">
        <div class="app-card p-4">
          <h6 class="fw-bold mb-3"><i class="bi bi-bar-chart text-success"></i> ปริมาณ Token ที่ใช้ (7 วันล่าสุด)</h6>
          <?php if ($tokenPerDay): ?><canvas id="tokenChart" height="90"></canvas>
          <?php else: ?><p class="text-muted mb-0">ยังไม่มีข้อมูลการใช้งาน AI</p><?php endif; ?>
        </div>
      </div>

      <!-- แยกตามผู้ให้บริการ -->
      <div class="col-lg-4">
        <div class="app-card p-4 h-100">
          <h6 class="fw-bold mb-3"><i class="bi bi-diagram-3 text-success"></i> แยกตามผู้ให้บริการ</h6>
          <?php if ($byProvider): ?>
            <?php $provNames = ['ollama'=>'Ollama (ในเครื่อง)','openai'=>'OpenAI','deepseek'=>'DeepSeek','mistral'=>'Mistral AI','gemini'=>'Gemini']; ?>
            <?php foreach ($byProvider as $p): ?>
              <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="badge <?= $p['provider']==='ollama'?'badge-soft-green':'badge-soft-blue' ?>"><?= e($provNames[$p['provider']] ?? $p['provider']) ?></span>
                <span class="small"><?= number_format($p['cnt']) ?> ครั้ง / <?= number_format($p['tokens']) ?> token</span>
              </div>
            <?php endforeach; ?>
          <?php else: ?><p class="text-muted mb-0">ยังไม่มีข้อมูล</p><?php endif; ?>
        </div>
      </div>

      <!-- แยกตามฟีเจอร์ -->
      <div class="col-lg-6">
        <div class="app-card p-4">
          <h6 class="fw-bold mb-3"><i class="bi bi-grid text-success"></i> การใช้ AI แยกตามฟีเจอร์</h6>
          <?php if ($byFeature): ?>
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th>ฟีเจอร์</th><th class="text-end">ครั้ง</th><th class="text-end">Token</th></tr></thead>
            <tbody>
              <?php
              $featNames = ['advice'=>'คำแนะนำพืช', 'chat'=>'แชทบอท', 'vision'=>'วิเคราะห์ภาพ', 'embed'=>'ทำดัชนี RAG', 'disease'=>'วิเคราะห์โรคพืช', 'plan'=>'วางแผนการปลูก', 'test'=>'ทดสอบระบบ (หน้า AI ในเครื่อง)'];
              foreach ($byFeature as $f): ?>
                <tr>
                  <td><?= e($featNames[$f['feature']] ?? $f['feature']) ?></td>
                  <td class="text-end"><?= number_format($f['cnt']) ?></td>
                  <td class="text-end"><?= number_format($f['tokens']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php else: ?><p class="text-muted mb-0">ยังไม่มีข้อมูล</p><?php endif; ?>
        </div>
      </div>

      <!-- ผู้ใช้ที่ใช้ AI มากสุด -->
      <div class="col-lg-6">
        <div class="app-card p-4">
          <h6 class="fw-bold mb-3"><i class="bi bi-people text-success"></i> ผู้ใช้ที่ใช้งาน AI มากที่สุด</h6>
          <?php if ($topUsers): ?>
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th>ผู้ใช้</th><th class="text-end">ครั้ง</th><th class="text-end">Token</th></tr></thead>
            <tbody>
              <?php foreach ($topUsers as $u): ?>
                <tr>
                  <td><?= e($u['full_name']) ?></td>
                  <td class="text-end"><?= number_format($u['cnt']) ?></td>
                  <td class="text-end"><?= number_format($u['tokens']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php else: ?><p class="text-muted mb-0">ยังไม่มีข้อมูล</p><?php endif; ?>
        </div>
      </div>
    </div>

    <div class="row g-4">
      <div class="col-lg-6">
        <div class="app-card p-4">
          <h6 class="fw-bold mb-3"><i class="bi bi-pie-chart text-success"></i> พืชที่ AI แนะนำมากที่สุด</h6>
          <?php if ($topCrops): ?><canvas id="cropChart" height="240"></canvas>
          <?php else: ?><p class="text-muted mb-0">ยังไม่มีข้อมูลคำแนะนำ</p><?php endif; ?>
        </div>
      </div>
    </div>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
<?php if ($tokenPerDay): ?>
new Chart(document.getElementById('tokenChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_map(fn($r) => date('j/n', strtotime($r['d'])), $tokenPerDay)) ?>,
    datasets: [{ label: 'Token', data: <?= json_encode(array_map(fn($r) => (int)$r['tokens'], $tokenPerDay)) ?>, backgroundColor: '#5cb99f', borderRadius: 6 }]
  },
  options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});
<?php endif; ?>

<?php if ($topCrops): ?>
new Chart(document.getElementById('cropChart'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode(array_column($topCrops, 'recommended_crop'), JSON_UNESCAPED_UNICODE) ?>,
    datasets: [{
      data: <?= json_encode(array_map(fn($r) => (int) $r['cnt'], $topCrops)) ?>,
      backgroundColor: ['#5cb99f','#429b83','#8fd4bf','#f2c14e','#e8836e','#6ab7d6','#a3cf6b','#d98cb3']
    }]
  }
});
<?php endif; ?>
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
