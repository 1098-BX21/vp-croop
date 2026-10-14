<?php
require_once __DIR__ . '/../config/config.php';
requireAdmin();
$pageTitle = 'ข้อมูลพืช/สูตรปุ๋ย';
$pdo = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    requireCsrf('crops.php');
    $id = (int) ($_POST['crop_id'] ?? 0);
    $name = trim($_POST['crop_name'] ?? '');
    $type = trim($_POST['crop_type'] ?? '');
    $formula = trim($_POST['fertilizer_formula'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $recovery = trim($_POST['soil_recovery_note'] ?? '');

    // ข้อมูลสำหรับระบบให้คะแนนความเหมาะสม
    $family = mb_substr(trim($_POST['plant_family'] ?? ''), 0, 80);
    $num = fn($k) => (isset($_POST[$k]) && $_POST[$k] !== '' && is_numeric($_POST[$k])) ? (float) $_POST[$k] : null;
    $phMin = $num('ph_min');
    $phMax = $num('ph_max');
    $yield = $num('yield_per_rai');
    $nEffect = in_array($_POST['n_effect'] ?? '', ['fix', 'low', 'medium', 'high'], true) ? $_POST['n_effect'] : 'medium';
    $waterNeed = in_array($_POST['water_need'] ?? '', ['low', 'medium', 'high'], true) ? $_POST['water_need'] : 'medium';
    $scoreErr = null;
    if (($phMin === null) !== ($phMax === null)) {
        $scoreErr = 'กรุณากรอกช่วง pH ให้ครบทั้งค่าต่ำสุดและสูงสุด (หรือเว้นว่างทั้งคู่)';
    } elseif ($phMin !== null && ($phMin < 0 || $phMax > 14 || $phMin > $phMax)) {
        $scoreErr = 'ช่วง pH ไม่ถูกต้อง (ต้องอยู่ระหว่าง 0-14 และค่าต่ำสุดไม่เกินค่าสูงสุด)';
    } elseif ($yield !== null && $yield < 0) {
        $scoreErr = 'ผลผลิตต่อไร่ต้องไม่ติดลบ';
    }

    // ระยะการปลูก (บรรทัดละ 1 ระยะ) ตรวจให้ถูกต้องก่อนบันทึกอะไรทั้งสิ้น
    $stagesParsed = growParseStagesText($_POST['stages_text'] ?? '');
    if ($stagesParsed['errors']) {
        $scoreErr = 'ระยะการปลูกไม่ถูกต้อง — ' . implode(' / ', array_slice($stagesParsed['errors'], 0, 3));
    }

    if ($name === '' || $type === '') {
        flash('error', 'กรุณากรอกชื่อและประเภทพืช');
    } elseif ($scoreErr) {
        flash('error', $scoreErr);
    } elseif ($id > 0) {
        $pdo->prepare('UPDATE crops SET crop_name=?, crop_type=?, fertilizer_formula=?, description=?, soil_recovery_note=?, plant_family=?, ph_min=?, ph_max=?, n_effect=?, water_need=?, yield_per_rai=? WHERE crop_id=?')
            ->execute([$name, $type, $formula, $desc, $recovery, $family ?: null, $phMin, $phMax, $nEffect, $waterNeed, $yield, $id]);
        growSaveCropStages($pdo, $id, $stagesParsed['stages']);
        flash('success', 'แก้ไขข้อมูลพืชสำเร็จ');
    } else {
        $pdo->prepare('INSERT INTO crops (crop_name, crop_type, fertilizer_formula, description, soil_recovery_note, plant_family, ph_min, ph_max, n_effect, water_need, yield_per_rai) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$name, $type, $formula, $desc, $recovery, $family ?: null, $phMin, $phMax, $nEffect, $waterNeed, $yield]);
        growSaveCropStages($pdo, (int) $pdo->lastInsertId(), $stagesParsed['stages']);
        flash('success', 'เพิ่มพืชใหม่สำเร็จ');
    }
    redirect('crops.php');
}

if (isset($_GET['delete'])) {
    try {
        $pdo->prepare('DELETE FROM crops WHERE crop_id=?')->execute([(int) $_GET['delete']]);
        flash('success', 'ลบพืชสำเร็จ');
    } catch (Exception $e) {
        flash('error', 'ไม่สามารถลบได้ เนื่องจากพืชนี้ถูกใช้ในประวัติการปลูกแล้ว');
    }
    redirect('crops.php');
}

$crops = $pdo->query('SELECT * FROM crops ORDER BY crop_name')->fetchAll();
$allStages = growAllCropStages($pdo, true);
foreach ($crops as &$cr) {
    $cr['stages_text'] = isset($allStages[(int) $cr['crop_id']]) ? growStagesToText($allStages[(int) $cr['crop_id']]) : '';
}
unset($cr);
$families = $pdo->query('SELECT DISTINCT plant_family FROM crops WHERE plant_family IS NOT NULL AND plant_family <> \'\' ORDER BY plant_family')->fetchAll(PDO::FETCH_COLUMN);
$nTh = ['fix' => 'ตรึงไนโตรเจน', 'low' => 'ใช้ N น้อย', 'medium' => 'ใช้ N ปานกลาง', 'high' => 'ใช้ N มาก'];
$wTh = ['low' => 'น้ำน้อย', 'medium' => 'น้ำปานกลาง', 'high' => 'น้ำมาก'];
require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar_admin.php'; ?>
  <main class="main-content">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>
    <div class="d-flex justify-content-end mb-3">
      <button class="btn btn-primary-app" data-bs-toggle="modal" data-bs-target="#cropModal" onclick="openCropModal()"><i class="bi bi-plus-circle me-1"></i> เพิ่มพืช</button>
    </div>
    <div class="app-card p-3">
      <div class="table-responsive">
        <table class="table align-middle">
          <thead><tr><th>ชื่อพืช</th><th>ประเภท</th><th>สูตรปุ๋ย</th><th>ข้อมูลสำหรับให้คะแนน</th><th class="text-end">จัดการ</th></tr></thead>
          <tbody>
            <?php foreach ($crops as $c): ?>
              <tr>
                <td class="fw-semibold"><?= e($c['crop_name']) ?></td>
                <td><span class="badge badge-soft-green"><?= e($c['crop_type']) ?></span></td>
                <td><?= e($c['fertilizer_formula'] ?: '-') ?></td>
                <td class="small">
                  <?php $incomplete = empty($c['plant_family']) || $c['ph_min'] === null; ?>
                  <?php if ($incomplete): ?>
                    <span class="badge badge-soft-amber" title="ระบบจะใช้ค่ากลางในด้านที่ไม่มีข้อมูล">ข้อมูลไม่ครบ</span>
                  <?php endif; ?>
                  <?= $c['plant_family'] ? 'วงศ์' . e($c['plant_family']) : '' ?>
                  <?= $c['ph_min'] !== null ? ' · pH ' . e(number_format((float) $c['ph_min'], 1)) . '–' . e(number_format((float) $c['ph_max'], 1)) : '' ?><br>
                  <?php $stc = $allStages[(int) $c['crop_id']] ?? null; ?>
                  <?php if ($stc): ?><span class="badge badge-soft-blue" title="ระยะการปลูกที่ใช้ในแผนการปลูกจริง">ระยะการปลูก <?= count($stc) ?> ระยะ · <?= (int) max(array_column($stc, 'day_to')) ?> วัน</span>
                  <?php else: ?><span class="badge badge-soft-amber" title="ผู้ดูแลยังไม่ได้กำหนด ระบบใช้ระยะกลาง 5 ระยะ 100 วัน">ใช้ระยะกลาง</span><?php endif; ?><br>
                  <span class="text-muted"><?= e($nTh[$c['n_effect']] ?? '') ?> · <?= e($wTh[$c['water_need']] ?? '') ?><?= $c['yield_per_rai'] !== null ? ' · ' . e(number_format((float) $c['yield_per_rai'])) . ' กก./ไร่' : '' ?></span>
                </td>
                <td class="text-end">
                  <button class="btn btn-sm btn-outline-app" data-bs-toggle="modal" data-bs-target="#cropModal" onclick='openCropModal(<?= json_encode($c, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="bi bi-pencil"></i></button>
                  <a class="btn btn-sm btn-outline-danger" href="?delete=<?= $c['crop_id'] ?>" onclick="return confirm('ยืนยันการลบพืชนี้?')"><i class="bi bi-trash"></i></a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>
</div>

<div class="modal fade" id="cropModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="border-radius:16px;">
      <form method="post">
        <?= csrfField() ?>
        <div class="modal-header"><h5 class="modal-title" id="cropModalTitle">เพิ่มพืช</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="crop_id" id="c_crop_id" value="">
          <div class="row">
            <div class="col-md-6 mb-3"><label class="form-label">ชื่อพืช</label><input type="text" name="crop_name" id="c_crop_name" class="form-control" required></div>
            <div class="col-md-6 mb-3"><label class="form-label">ประเภท</label><input type="text" name="crop_type" id="c_crop_type" class="form-control" required placeholder="เช่น พืชไร่ พืชตระกูลถั่ว"></div>
          </div>
          <div class="mb-3"><label class="form-label">สูตรปุ๋ย</label><input type="text" name="fertilizer_formula" id="c_fertilizer_formula" class="form-control" placeholder="เช่น 16-20-0"></div>
          <div class="mb-3"><label class="form-label">คำอธิบาย</label><textarea name="description" id="c_description" class="form-control" rows="2"></textarea></div>
          <div class="mb-3"><label class="form-label">หมายเหตุการฟื้นฟูดิน</label><textarea name="soil_recovery_note" id="c_soil_recovery_note" class="form-control" rows="2" placeholder="เช่น ช่วยตรึงไนโตรเจน"></textarea></div>
          <hr>
          <h6 class="fw-bold"><i class="bi bi-speedometer2 text-success"></i> ข้อมูลสำหรับระบบให้คะแนนความเหมาะสม</h6>
          <p class="small text-muted">ใช้คำนวณคะแนนในหน้า "คำแนะนำ AI" ถ้าเว้นว่าง ระบบจะใช้ค่ากลางในด้านนั้น (ค่าเริ่มต้นของพืชที่มากับระบบเป็นค่าประมาณทั่วไป ควรตรวจสอบกับข้อมูลวิชาการ/พื้นที่จริง)</p>
          <div class="row">
            <div class="col-md-6 mb-3"><label class="form-label">วงศ์พืช (ใช้ตรวจการหมุนเวียน)</label>
              <input type="text" name="plant_family" id="c_plant_family" class="form-control" list="familyList" maxlength="80" placeholder="เช่น หญ้า/ข้าว, ถั่ว">
              <datalist id="familyList"><?php foreach ($families as $fm): ?><option value="<?= e($fm) ?>"></option><?php endforeach; ?></datalist>
              <div class="form-text">พืชที่พิมพ์ชื่อวงศ์ตรงกันจะถือว่าเป็นวงศ์เดียวกัน</div></div>
            <div class="col-md-3 mb-3"><label class="form-label">pH ต่ำสุดที่เหมาะสม</label><input type="number" step="0.1" min="0" max="14" name="ph_min" id="c_ph_min" class="form-control"></div>
            <div class="col-md-3 mb-3"><label class="form-label">pH สูงสุดที่เหมาะสม</label><input type="number" step="0.1" min="0" max="14" name="ph_max" id="c_ph_max" class="form-control"></div>
          </div>
          <div class="row">
            <div class="col-md-4 mb-3"><label class="form-label">ผลต่อไนโตรเจนในดิน</label>
              <select name="n_effect" id="c_n_effect" class="form-select">
                <option value="fix">ตรึงไนโตรเจน (พืชตระกูลถั่ว)</option><option value="low">ใช้ไนโตรเจนน้อย</option>
                <option value="medium">ใช้ไนโตรเจนปานกลาง</option><option value="high">ใช้ไนโตรเจนมาก</option></select></div>
            <div class="col-md-4 mb-3"><label class="form-label">ความต้องการน้ำ</label>
              <select name="water_need" id="c_water_need" class="form-select">
                <option value="low">น้อย</option><option value="medium">ปานกลาง</option><option value="high">มาก</option></select></div>
            <div class="col-md-4 mb-3"><label class="form-label">ผลผลิตเฉลี่ย (กก./ไร่)</label><input type="number" step="0.01" min="0" name="yield_per_rai" id="c_yield_per_rai" class="form-control" placeholder="ใช้คำนวณรายได้"></div>
          </div>
          <hr>
          <h6 class="fw-bold"><i class="bi bi-calendar2-week text-success"></i> ระยะการปลูก (ใช้ในแผนการปลูกจริงและหน้า "ถ้านำไปปลูกจริง")</h6>
          <p class="small text-muted mb-1">บรรทัดละ 1 ระยะ รูปแบบ <code>ชื่อระยะ | วันที่เริ่ม-วันที่สิ้นสุด | คำอธิบาย</code> นับวันปลูกเป็นวันที่ 1 เรียงตามเวลา (ใส่ได้ไม่เกิน 20 ระยะ) ถ้าเว้นว่าง ระบบใช้ระยะกลาง</p>
          <textarea name="stages_text" id="c_stages_text" class="form-control" rows="7" style="font-size:.9rem;" placeholder="เพาะเมล็ด | 1-3 | แช่เมล็ดและบ่มให้รากงอก&#10;ต้นกล้า | 4-14 | ถอนแยกต้นและกำจัดวัชพืช&#10;เก็บเกี่ยว | 60-70 | เก็บเมื่อฝักแก่"></textarea>
          <div class="form-text">ระยะเวลาเป็นค่าโดยประมาณ ขึ้นกับพันธุ์ ฤดูกาล และสภาพอากาศ</div>
        </div>
        <div class="modal-footer"><button class="btn btn-primary-app w-100">บันทึกพืช</button></div>
      </form>
    </div>
  </div>
</div>

<script>
function openCropModal(data) {
  document.getElementById('cropModalTitle').innerText = data ? 'แก้ไขข้อมูลพืช' : 'เพิ่มพืช';
  document.getElementById('c_crop_id').value = data ? data.crop_id : '';
  document.getElementById('c_crop_name').value = data ? data.crop_name : '';
  document.getElementById('c_crop_type').value = data ? data.crop_type : '';
  document.getElementById('c_fertilizer_formula').value = data ? (data.fertilizer_formula || '') : '';
  document.getElementById('c_description').value = data ? (data.description || '') : '';
  document.getElementById('c_soil_recovery_note').value = data ? (data.soil_recovery_note || '') : '';
  document.getElementById('c_plant_family').value = data ? (data.plant_family || '') : '';
  document.getElementById('c_ph_min').value = data && data.ph_min !== null ? data.ph_min : '';
  document.getElementById('c_ph_max').value = data && data.ph_max !== null ? data.ph_max : '';
  document.getElementById('c_n_effect').value = data ? (data.n_effect || 'medium') : 'medium';
  document.getElementById('c_water_need').value = data ? (data.water_need || 'medium') : 'medium';
  document.getElementById('c_yield_per_rai').value = data && data.yield_per_rai !== null ? data.yield_per_rai : '';
  document.getElementById('c_stages_text').value = data ? (data.stages_text || '') : '';
}
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
