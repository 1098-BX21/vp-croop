<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
$pageTitle = 'รายงาน';
$uid = currentUser()['user_id'];
$pdo = getDBConnection();

$rp = farmerReport($pdo, $uid);
$k = $rp['kpi'];
$ai = $rp['ai'];

$lvTh = ['low' => 'ต่ำ', 'medium' => 'ปานกลาง', 'high' => 'สูง'];
$lvCls = ['low' => 'badge-soft-amber', 'medium' => 'badge-soft-green', 'high' => 'badge-soft-blue'];
$alertCls = ['danger' => 'badge-soft-red', 'warning' => 'badge-soft-amber', 'info' => 'badge-soft-blue'];
$alertIcon = ['danger' => 'bi-exclamation-octagon', 'warning' => 'bi-exclamation-triangle', 'info' => 'bi-info-circle'];
$alertTh = ['danger' => 'เร่งด่วน', 'warning' => 'ควรทำ', 'info' => 'ข้อแนะนำ'];
$lvBadge = fn($l) => $l ? '<span class="badge ' . $lvCls[$l] . '">' . $lvTh[$l] . '</span>' : '<span class="text-muted">-</span>';

require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar_user.php'; ?>
  <main class="main-content">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>

    <div class="ai-box mb-4">
      <h6 class="fw-bold mb-1"><i class="bi bi-clipboard-data text-success"></i> รายงานภาพรวมฟาร์มของคุณ</h6>
      <p class="small text-muted mb-0">สรุปสิ่งที่ควรทำ ความคืบหน้าการปลูก ผลผลิตและรายได้โดยประมาณ สถานะดิน การหมุนเวียนพืช และการใช้ AI คำนวณจากข้อมูลที่คุณบันทึกไว้ในระบบ</p>
    </div>

    <!-- ===== ตัวเลขสำคัญ ===== -->
    <div class="row g-3 mb-4">
      <div class="col-md-3 col-6"><div class="stat-card"><div class="icon-box"><i class="bi bi-calendar2-check"></i></div>
        <div><div class="stat-value"><?= $k['active_plans'] ?></div><div class="stat-label">แผนที่กำลังปลูก<?= $k['due_soon'] ? ' (ใกล้เก็บเกี่ยว ' . $k['due_soon'] . ')' : '' ?></div></div></div></div>
      <div class="col-md-3 col-6"><div class="stat-card"><div class="icon-box"><i class="bi bi-basket"></i></div>
        <div><div class="stat-value"><?= number_format($k['total_yield']) ?></div><div class="stat-label">ผลผลิตรวม (กก.) · เก็บเกี่ยว <?= $k['harvest_count'] ?> ครั้ง</div></div></div></div>
      <div class="col-md-3 col-6"><div class="stat-card"><div class="icon-box"><i class="bi bi-cash-coin"></i></div>
        <div><div class="stat-value"><?= number_format($k['income']) ?></div><div class="stat-label">รายได้โดยประมาณ (บาท)</div></div></div></div>
      <div class="col-md-3 col-6"><div class="stat-card"><div class="icon-box"><i class="bi bi-exclamation-triangle"></i></div>
        <div><div class="stat-value"><?= $k['attention_plots'] ?><span class="fs-6 text-muted">/<?= $k['plot_count'] ?></span></div><div class="stat-label">แปลงที่ควรดูแล</div></div></div></div>
    </div>

    <!-- ===== สิ่งที่ควรทำ ===== -->
    <div class="app-card p-4 mb-4">
      <h6 class="fw-bold mb-3"><i class="bi bi-list-check text-success"></i> สิ่งที่ควรทำ</h6>
      <?php if (!$rp['alerts']): ?>
        <p class="text-success mb-0"><i class="bi bi-check-circle me-1"></i> ตอนนี้ไม่มีรายการที่ต้องดำเนินการ ดินและแผนการปลูกอยู่ในสภาพดี</p>
      <?php else: ?>
        <?php
        $renderAlert = function ($a) use ($alertCls, $alertIcon, $alertTh) { ob_start(); ?>
            <li class="d-flex gap-2 py-2 border-bottom align-items-start">
              <span class="badge <?= $alertCls[$a['level']] ?> flex-shrink-0"><i class="bi <?= $alertIcon[$a['level']] ?> me-1"></i><?= $alertTh[$a['level']] ?></span>
              <div class="flex-grow-1">
                <div class="small text-muted"><?= e($a['plot']) ?></div>
                <div><?= e($a['text']) ?></div>
              </div>
              <?php if (!empty($a['link'])): ?><a href="<?= e($a['link']) ?>" class="btn btn-sm btn-outline-app flex-shrink-0">ไปดู</a><?php endif; ?>
            </li>
        <?php return ob_get_clean(); };
        $firstAlerts = array_slice($rp['alerts'], 0, 12);
        $moreAlerts = array_slice($rp['alerts'], 12);
        ?>
        <ul class="list-unstyled mb-0"><?php foreach ($firstAlerts as $a) { echo $renderAlert($a); } ?></ul>
        <?php if ($moreAlerts): ?>
          <details class="mt-2">
            <summary class="small text-success" style="cursor:pointer;">แสดงอีก <?= count($moreAlerts) ?> รายการ</summary>
            <ul class="list-unstyled mb-0"><?php foreach ($moreAlerts as $a) { echo $renderAlert($a); } ?></ul>
          </details>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="row g-4 mb-4">
      <!-- ===== แผนที่กำลังปลูก ===== -->
      <div class="col-lg-7">
        <div class="app-card p-4 h-100">
          <h6 class="fw-bold mb-3"><i class="bi bi-calendar2-week text-success"></i> แผนปลูกที่กำลังดำเนินการ</h6>
          <?php if (!$rp['plans']): ?>
            <p class="text-muted mb-0">ยังไม่มีแผนที่กำลังปลูก เริ่มจาก <a href="ai_advice.php">ขอคำแนะนำ AI</a> แล้วกด "ถ้านำไปปลูกจริง"</p>
          <?php else: ?>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
              <thead><tr><th>พืช / แปลง</th><th style="min-width:130px;">ความคืบหน้า</th><th>ระยะปัจจุบัน</th><th>คาดเก็บเกี่ยว</th></tr></thead>
              <tbody>
              <?php foreach ($rp['plans'] as $pl): $pg = $pl['progress']; ?>
                <tr>
                  <td><a href="grow_plan.php?plan=<?= (int) $pl['plan_id'] ?>" class="fw-semibold text-decoration-none"><?= e($pl['crop_name']) ?></a><div class="small text-muted"><?= e($pl['plot_name']) ?></div></td>
                  <td><div class="progress" style="height:8px;"><div class="progress-bar <?= $pg['overdue_count'] ? 'bg-danger' : 'bg-success' ?>" style="width:<?= $pg['time_pct'] ?>%"></div></div>
                      <div class="small text-muted">วันที่ <?= max(0, min($pg['day'], $pg['total_days'])) ?>/<?= $pg['total_days'] ?> · ทำแล้ว <?= $pg['done_count'] ?>/<?= $pg['stage_count'] ?><?= $pg['skipped_count'] ? ' · ข้าม ' . $pg['skipped_count'] : '' ?></div></td>
                  <td class="small"><?= $pl['focus_name'] ? e($pl['focus_name']) : 'ครบทุกระยะ' ?><?= $pg['overdue_count'] ? ' <span class="badge badge-soft-red">เลยกำหนด</span>' : '' ?></td>
                  <td class="small"><?= formatDateThai($pg['harvest_date']) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody></table></div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ===== สุขภาพการหมุนเวียน ===== -->
      <div class="col-lg-5">
        <div class="app-card p-4 h-100">
          <h6 class="fw-bold mb-3"><i class="bi bi-arrow-repeat text-success"></i> สุขภาพการหมุนเวียนพืช</h6>
          <?php if (!$rp['rotation']): ?>
            <p class="text-muted mb-0">ยังไม่มีแปลงเพาะปลูก</p>
          <?php else: ?>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
              <thead><tr><th>แปลง</th><th>พืชล่าสุด</th><th>สถานะ</th></tr></thead>
              <tbody>
              <?php foreach ($rp['rotation'] as $r): ?>
                <tr>
                  <td class="small"><?= e($r['plot']) ?></td>
                  <td class="small"><?= $r['last'] ? e($r['last']) . ($r['family'] ? '<div class="text-muted">วงศ์' . e($r['family']) . '</div>' : '') : '<span class="text-muted">ยังไม่มีประวัติ</span>' ?></td>
                  <td><?php if ($r['ok'] === null): ?><span class="badge badge-soft-blue">ข้อมูลยังไม่พอ</span>
                      <?php elseif ($r['ok']): ?><span class="badge badge-soft-green">หมุนเวียนดี</span>
                      <?php else: ?><span class="badge badge-soft-amber">ควรเปลี่ยนวงศ์</span><?php endif; ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody></table></div>
            <p class="small text-muted mt-2 mb-0">"หมุนเวียนดี" = 2 รอบล่าสุดต่างวงศ์พืชกัน (ตัดวงจรโรคและบำรุงดิน)</p>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ===== ผลผลิตและรายได้ ===== -->
    <div class="app-card p-4 mb-4">
      <h6 class="fw-bold mb-3"><i class="bi bi-bar-chart text-success"></i> ผลผลิตและรายได้โดยประมาณแยกตามพืช</h6>
      <?php if (!$rp['crops']): ?>
        <p class="text-muted mb-0">ยังไม่มีข้อมูลผลผลิต เมื่อบันทึกการเก็บเกี่ยวในแผนการปลูก หรือกรอกผลผลิตในประวัติการปลูก ข้อมูลจะมาแสดงที่นี่</p>
      <?php else: ?>
        <div class="row g-4 align-items-center">
          <div class="col-lg-5"><canvas id="yieldChart" height="220"></canvas></div>
          <div class="col-lg-7">
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
              <thead><tr><th>พืช</th><th class="text-end">เก็บเกี่ยว (ครั้ง)</th><th class="text-end">ผลผลิตรวม (กก.)</th><th class="text-end">เฉลี่ย/ครั้ง</th><th class="text-end">ราคาอ้างอิง</th><th class="text-end">รายได้โดยประมาณ</th></tr></thead>
              <tbody>
              <?php foreach ($rp['crops'] as $c): ?>
                <tr>
                  <td class="fw-semibold"><?= e($c['crop_name']) ?></td>
                  <td class="text-end"><?= (int) $c['times'] ?></td>
                  <td class="text-end"><?= number_format((float) $c['total_yield'], 1) ?></td>
                  <td class="text-end"><?= number_format((float) $c['avg_yield'], 1) ?></td>
                  <td class="text-end"><?= $c['price'] !== null ? number_format((float) $c['price'], 2) : '<span class="text-muted">-</span>' ?></td>
                  <td class="text-end fw-semibold"><?= $c['income'] !== null ? number_format($c['income']) . ' บาท' : '<span class="text-muted">ไม่มีราคา</span>' ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody></table></div>
            <p class="small text-muted mt-2 mb-0">รายได้โดยประมาณ = ผลผลิต × ราคาอ้างอิงในระบบ (ค่าคงที่ ไม่ได้อัปเดตตามตลาด ยังไม่หักต้นทุน และอาจต่างจากราคาที่ขายจริง)</p>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <!-- ===== สถานะดินล่าสุด ===== -->
    <div class="app-card p-4 mb-4">
      <h6 class="fw-bold mb-3"><i class="bi bi-moisture text-success"></i> สถานะดินล่าสุดรายแปลง</h6>
      <?php if (!$rp['soils']): ?>
        <p class="text-muted mb-0">ยังไม่มีแปลงเพาะปลูก</p>
      <?php else: ?>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
          <thead><tr><th>แปลง</th><th>วันที่วัด</th><th>pH</th><th>ไนโตรเจน (N)</th><th>ฟอสฟอรัส (P)</th><th>โพแทสเซียม (K)</th></tr></thead>
          <tbody>
          <?php foreach ($rp['soils'] as $s): ?>
            <tr>
              <td class="small"><?= e($s['plot']) ?></td>
              <?php if ($s['ph'] === null): ?>
                <td colspan="5" class="small text-muted">ยังไม่มีค่าดิน <a href="soil_data.php">บันทึกค่าดิน</a></td>
              <?php else: ?>
                <td class="small"><?= formatDateThai($s['date']) ?></td>
                <td><span class="fw-semibold"><?= number_format($s['ph'], 1) ?></span>
                  <?php if ($s['ph'] < REPORT_PH_LOW): ?><span class="badge badge-soft-amber">กรด</span>
                  <?php elseif ($s['ph'] > REPORT_PH_HIGH): ?><span class="badge badge-soft-amber">ด่าง</span>
                  <?php else: ?><span class="badge badge-soft-green">เหมาะสม</span><?php endif; ?></td>
                <td><?= $lvBadge($s['n']) ?></td><td><?= $lvBadge($s['p']) ?></td><td><?= $lvBadge($s['k']) ?></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody></table></div>
        <p class="small text-muted mt-2 mb-0">เกณฑ์ระดับเป็นค่าประมาณทั่วไป (N ต่ำ &lt;20, P ต่ำ &lt;10, K ต่ำ &lt;60 มก./กก.) ควรปรึกษาเจ้าหน้าที่เกษตรเพื่อตีความผลตรวจดินในพื้นที่ของคุณ</p>
      <?php endif; ?>
    </div>

    <!-- ===== การใช้ AI ===== -->
    <div class="app-card p-4">
      <h6 class="fw-bold mb-3"><i class="bi bi-robot text-success"></i> การใช้ AI ของคุณ</h6>
      <div class="row g-3 text-center justify-content-center">
        <div class="col-md-3 col-6"><div class="fs-4 fw-bold text-success"><?= $ai['recs'] ?></div><div class="small text-muted">คำแนะนำพืชที่ได้รับ</div></div>
        <div class="col-md-3 col-6"><div class="fs-4 fw-bold text-success"><?= $ai['planned'] ?><?= $ai['plan_rate'] !== null ? ' <span class="fs-6 text-muted">(' . $ai['plan_rate'] . '%)</span>' : '' ?></div><div class="small text-muted">นำไปวางแผนปลูกจริง</div></div>
        <div class="col-md-3 col-6"><div class="fs-4 fw-bold text-success"><?= $ai['avg_rating'] !== null ? number_format($ai['avg_rating'], 1) . '/5' : '-' ?></div><div class="small text-muted">ความพึงพอใจเฉลี่ย (จาก <?= $ai['feedback'] ?> รายการ)</div></div>
        <div class="col-md-3 col-6"><div class="fs-4 fw-bold text-success"><?= $ai['follow_rate'] !== null ? $ai['follow_rate'] . '%' : '-' ?></div><div class="small text-muted">ทำตามแผน AI<?= $ai['follow_rate'] !== null ? ' (ทำ ' . $ai['follow_done'] . ' · ข้าม ' . $ai['follow_skipped'] . ' ระยะ)' : ' (ยังไม่มีข้อมูล)' ?></div></div>
        <div class="col-md-3 col-6"><div class="fs-4 fw-bold text-success"><?= $ai['scans'] ?><?= $ai['scans'] ? ' <span class="fs-6 text-muted">(พบโรค ' . $ai['diseased'] . ')</span>' : '' ?></div><div class="small text-muted">ครั้งที่วิเคราะห์โรคพืช</div></div>
      </div>
      <?php if ($ai['skip_reasons']): ?>
        <hr><p class="small mb-0"><b>เหตุผลที่ไม่ได้ทำตามแผนบ่อย:</b>
          <?php foreach ($ai['skip_reasons'] as $r): ?><span class="badge badge-soft-amber me-1"><?= e($r['label']) ?> × <?= $r['n'] ?></span><?php endforeach; ?></p>
      <?php endif; ?>
      <?php if ($ai['top_diseases']): ?>
        <hr><p class="small mb-0"><b>โรค/ศัตรูพืชที่พบบ่อย:</b>
          <?php foreach ($ai['top_diseases'] as $d): ?><span class="badge badge-soft-amber me-1"><?= e($d['disease_name']) ?> × <?= (int) $d['n'] ?></span><?php endforeach; ?>
          <a href="disease_scan.php" class="ms-1">ดูประวัติ</a></p>
      <?php endif; ?>
    </div>
  </main>
</div>

<?php if ($rp['crops']): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('yieldChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_column($rp['crops'], 'crop_name'), JSON_UNESCAPED_UNICODE) ?>,
    datasets: [{ label: 'ผลผลิต (กก.)', data: <?= json_encode(array_map(fn($r) => (float) $r['total_yield'], $rp['crops'])) ?>, backgroundColor: '#5cb99f', borderRadius: 8 }]
  },
  options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});
</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
