<?php
require_once __DIR__ . '/../config/config.php';
requireAdmin();
$pageTitle = 'ผู้ใช้งานตามจังหวัด';
$pdo = getDBConnection();

$sum = userGeoSummary($pdo);
$provinces = thProvinces();

// จังหวัดที่เลือก (ตรวจกับรายชื่อ 77 จังหวัดเสมอ ไม่เชื่อค่าจาก URL) หรือ __none = ผู้ใช้ที่ยังไม่ระบุจังหวัด
$sel = trim($_GET['province'] ?? '');
if ($sel !== USER_GEO_NONE && !thProvinceValid($sel)) {
    $sel = '';
}
$detail = $sel !== '' ? userGeoProvince($pdo, $sel) : null;
$selForMap = ($sel !== '' && $sel !== USER_GEO_NONE) ? $sel : null;

$pct = fn($n) => $sum['total'] > 0 ? round($n / $sum['total'] * 100) : 0;
$maxTop = $sum['top'] ? $sum['top'][0][1] : 1;

require __DIR__ . '/../includes/header.php';
?>
<style>
  .th-map { width: 100%; max-height: 78vh; height: auto; }
  .th-prov { stroke: #fff; stroke-width: .7; stroke-linejoin: round; transition: opacity .15s; }
  .th-prov-link { cursor: pointer; }
  .th-prov-link:hover .th-prov { stroke: #0d3b2e; stroke-width: 1.8; }
  .th-prov-link.sel .th-prov { stroke: #d9480f; stroke-width: 2.4; }
  .th-legend i { display: inline-block; width: 14px; height: 14px; border-radius: 3px; margin-right: 4px; vertical-align: -2px; border: 1px solid #cfd8d4; }
</style>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar_admin.php'; ?>
  <main class="main-content">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>

    <div class="ai-box mb-4">
      <h6 class="fw-bold mb-1"><i class="bi bi-geo-alt text-success"></i> ผู้ใช้งานตามจังหวัด</h6>
      <p class="small text-muted mb-0">จังหวัดมาจากที่ผู้ใช้เลือกตอนสมัครสมาชิก (หรือในโปรไฟล์) นับเฉพาะเกษตรกร สีเข้มบนแผนที่ = มีผู้ใช้มาก <b>คลิกจังหวัด</b>เพื่อดูผู้ใช้ทั้งหมดในจังหวัดนั้นและพืชที่ปลูก</p>
    </div>

    <div class="row g-3 mb-4">
      <div class="col-md-3 col-6"><div class="stat-card"><div class="icon-box"><i class="bi bi-people"></i></div><div><div class="stat-value"><?= $sum['total'] ?></div><div class="stat-label">เกษตรกรทั้งหมด</div></div></div></div>
      <div class="col-md-3 col-6"><div class="stat-card"><div class="icon-box"><i class="bi bi-geo-alt"></i></div><div><div class="stat-value"><?= $sum['with_province'] ?><span class="fs-6 text-muted"> (<?= $pct($sum['with_province']) ?>%)</span></div><div class="stat-label">ระบุจังหวัดแล้ว</div></div></div></div>
      <div class="col-md-3 col-6"><div class="stat-card"><div class="icon-box"><i class="bi bi-map"></i></div><div><div class="stat-value"><?= $sum['province_count'] ?><span class="fs-6 text-muted">/77</span></div><div class="stat-label">จังหวัดที่มีผู้ใช้</div></div></div></div>
      <div class="col-md-3 col-6"><a href="?province=<?= USER_GEO_NONE ?>#detail" class="text-decoration-none"><div class="stat-card"><div class="icon-box"><i class="bi bi-question-circle"></i></div><div><div class="stat-value"><?= $sum['without_province'] ?></div><div class="stat-label">ยังไม่ระบุจังหวัด (คลิกดู)</div></div></div></a></div>
    </div>

    <div class="row g-4 mb-4">
      <div class="col-lg-5">
        <div class="app-card p-3">
          <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
            <h6 class="fw-bold mb-0"><i class="bi bi-map text-success"></i> แผนที่ประเทศไทย</h6>
            <form method="get" class="d-flex gap-1">
              <select name="province" class="form-select form-select-sm" aria-label="เลือกจังหวัด">
                <option value="">— เลือกจังหวัด —</option>
                <?php foreach ($provinces as $name => $pv): ?>
                  <option value="<?= e($name) ?>" <?= $sel === $name ? 'selected' : '' ?>><?= e($name) ?><?= !empty($sum['counts'][$name]) ? ' (' . $sum['counts'][$name] . ')' : '' ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-sm btn-outline-app">ดู</button>
            </form>
          </div>
          <?= userGeoMapSvg($sum['counts'], $selForMap) ?>
          <div class="th-legend small text-muted mt-2">
            <?php foreach (USER_GEO_BINS as $b): ?><span class="me-2"><i style="background:<?= $b[1] ?>"></i><?= e($b[3]) ?></span><?php endforeach; ?>
          </div>
          <p class="small text-muted mt-2 mb-0">ขอบเขตจังหวัด: <a href="https://github.com/chingchai/OpenGISData-Thailand" target="_blank" rel="noopener">OpenGISData-Thailand</a> (CC BY 4.0) อ้างอิงข้อมูลของกรมการปกครอง กระทรวงมหาดไทย ลดจำนวนจุดเพื่อให้โหลดเร็ว</p>
        </div>
      </div>

      <div class="col-lg-7">
        <div class="app-card p-4 mb-3">
          <h6 class="fw-bold mb-3"><i class="bi bi-bar-chart text-success"></i> จังหวัดที่มีผู้ใช้มากที่สุด</h6>
          <?php if (!$sum['top']): ?>
            <p class="text-muted mb-0">ยังไม่มีผู้ใช้ที่ระบุจังหวัด ผู้ใช้ใหม่จะเลือกจังหวัดตอนสมัครสมาชิก ส่วนผู้ใช้เดิมให้ระบุที่หน้า "ข้อมูลส่วนตัว"</p>
          <?php else: ?>
            <table class="table table-sm align-middle mb-0">
              <tbody>
              <?php foreach (array_slice($sum['top'], 0, 10) as [$name, $n]): ?>
                <tr>
                  <td style="width:34%;"><a href="?province=<?= rawurlencode($name) ?>#detail" class="text-decoration-none fw-semibold"><?= e($name) ?></a>
                    <div class="small text-muted"><?= e(thRegionLabel($provinces[$name]['region'])) ?></div></td>
                  <td><div class="progress" style="height:10px;"><div class="progress-bar bg-success" style="width:<?= round($n / $maxTop * 100) ?>%"></div></div></td>
                  <td class="text-end fw-semibold" style="width:70px;"><?= $n ?> คน</td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

        <div class="app-card p-4">
          <h6 class="fw-bold mb-3"><i class="bi bi-compass text-success"></i> แยกตามภูมิภาค</h6>
          <div class="row g-2">
            <?php foreach ($sum['regions'] as $rk => $n): ?>
              <div class="col-md-4 col-6"><div class="p-2 rounded-3 text-center" style="background:var(--primary-lighter);border:1px solid var(--primary-light);">
                <div class="fs-5 fw-bold text-success"><?= $n ?></div><div class="small text-muted"><?= e(thRegionLabel($rk)) ?></div></div></div>
            <?php endforeach; ?>
            <div class="col-md-4 col-6"><a href="?province=<?= USER_GEO_NONE ?>#detail" class="text-decoration-none"><div class="p-2 rounded-3 text-center" style="background:#fff7ed;border:1px solid #fed7aa;">
              <div class="fs-5 fw-bold text-warning-emphasis"><?= $sum['without_province'] ?></div><div class="small text-muted">ไม่ระบุจังหวัด</div></div></a></div>
          </div>
          <?php if ($sum['unknown'] > 0): ?><p class="small text-danger mt-2 mb-0">มีผู้ใช้ <?= $sum['unknown'] ?> คนที่ชื่อจังหวัดไม่ตรงกับรายชื่อ 77 จังหวัด (ไม่ถูกนับลงแผนที่)</p><?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($detail): ?>
    <div id="detail" class="app-card p-4 mb-4">
      <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div>
          <h5 class="fw-bold mb-1"><i class="bi bi-geo-alt-fill text-success"></i>
            <?= $detail['is_none'] ? 'ผู้ใช้ที่ยังไม่ระบุจังหวัด' : 'จังหวัด' . e($detail['province']) ?>
            <?php if (!$detail['is_none']): ?><span class="small text-muted fw-normal"> · <?= e(thRegionLabel($provinces[$detail['province']]['region'])) ?></span><?php endif; ?></h5>
          <div class="small text-muted"><?= count($detail['users']) ?> คน · <?= (int) $detail['farms'] ?> ฟาร์ม · <?= (int) $detail['plots'] ?> แปลง · พืชที่ปลูก <?= count($detail['crops']) ?> ชนิด</div>
        </div>
        <a href="user_geo.php" class="btn btn-sm btn-outline-app"><i class="bi bi-x-lg me-1"></i> ปิดรายละเอียด</a>
      </div>

      <?php if (!$detail['users']): ?>
        <p class="text-muted mb-0"><?= $detail['is_none'] ? 'ผู้ใช้ทุกคนระบุจังหวัดแล้ว' : 'ยังไม่มีผู้ใช้ในจังหวัดนี้' ?></p>
      <?php else: ?>
      <div class="row g-4">
        <div class="col-lg-7">
          <h6 class="fw-bold">ผู้ใช้งานทั้งหมดในจังหวัดนี้</h6>
          <div class="table-responsive"><table class="table table-sm align-middle">
            <thead><tr><th>ชื่อ</th><th>ติดต่อ</th><th>ฟาร์ม/แปลง</th><th>พืชที่ปลูก</th></tr></thead>
            <tbody>
            <?php foreach ($detail['users'] as $u): ?>
              <tr>
                <td class="fw-semibold"><?= e($u['full_name']) ?>
                  <?= $u['status'] === 'suspended' ? '<span class="badge badge-soft-red">ระงับ</span>' : '' ?>
                  <div class="small text-muted fw-normal">สมัคร <?= formatDateThai($u['created_at']) ?></div></td>
                <td class="small"><?= e($u['email']) ?><?= $u['phone'] ? '<br>' . e($u['phone']) : '' ?></td>
                <td class="small"><?= (int) $u['farms'] ?> / <?= (int) $u['plots'] ?></td>
                <td>
                  <?php if (!$u['crops']): ?><span class="small text-muted">ยังไม่มีประวัติการปลูก</span>
                  <?php else: foreach ($u['crops'] as $c): ?>
                    <span class="badge <?= $c['ongoing'] ? 'badge-soft-green' : 'badge-soft-blue' ?> mb-1" title="<?= $c['ongoing'] ? 'กำลังปลูกอยู่ ' . $c['ongoing'] . ' รอบ' : 'เก็บเกี่ยวแล้ว' ?>"><?= e($c['crop_name']) ?><?= $c['times'] > 1 ? ' ×' . $c['times'] : '' ?></span>
                  <?php endforeach; endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
          <p class="small text-muted mb-0"><span class="badge badge-soft-green">เขียว</span> = มีรอบที่กำลังปลูกอยู่ · <span class="badge badge-soft-blue">ฟ้า</span> = เก็บเกี่ยวแล้ว · ×n = จำนวนรอบที่ปลูก</p>
        </div>

        <div class="col-lg-5">
          <h6 class="fw-bold">พืชที่ปลูกทั้งหมดในจังหวัดนี้</h6>
          <?php if (!$detail['crops']): ?>
            <p class="text-muted mb-0">ผู้ใช้ในจังหวัดนี้ยังไม่มีประวัติการปลูก</p>
          <?php else: $maxU = max(array_column($detail['crops'], 'users')); ?>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
              <thead><tr><th>พืช</th><th class="text-end">ผู้ปลูก (คน)</th><th class="text-end">รอบที่ปลูก</th><th class="text-end">กำลังปลูก</th><th class="text-end">ผลผลิต (กก.)</th></tr></thead>
              <tbody>
              <?php foreach ($detail['crops'] as $c): ?>
                <tr>
                  <td class="fw-semibold"><?= e($c['crop_name']) ?>
                    <div class="progress mt-1" style="height:5px;"><div class="progress-bar bg-success" style="width:<?= round($c['users'] / $maxU * 100) ?>%"></div></div></td>
                  <td class="text-end"><?= (int) $c['users'] ?></td>
                  <td class="text-end"><?= (int) $c['times'] ?></td>
                  <td class="text-end"><?= (int) $c['ongoing'] ?></td>
                  <td class="text-end"><?= $c['total_yield'] > 0 ? number_format($c['total_yield'], 1) : '-' ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody></table></div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </main>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
