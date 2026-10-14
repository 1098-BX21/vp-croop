<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
$pageTitle = 'บันทึกค่าดิน';
$uid = currentUser()['user_id'];
$pdo = getDBConnection();

$soilTypes = ['ดินดำ (ดินร่วนอินทรีย์)', 'ดินทราย', 'ดินร่วน', 'ดินร่วนปนทราย', 'ดินเหนียว', 'ดินลูกรัง', 'อื่น ๆ'];

// ---- Save ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    requireCsrf('soil_data.php');
    $soilId = (int) ($_POST['soil_id'] ?? 0);
    $plotId = (int) ($_POST['plot_id'] ?? 0);
    $ph = (float) ($_POST['ph_value'] ?? 0);
    $n = $_POST['nitrogen'] !== '' ? (float) $_POST['nitrogen'] : null;
    $p = $_POST['phosphorus'] !== '' ? (float) $_POST['phosphorus'] : null;
    $k = $_POST['potassium'] !== '' ? (float) $_POST['potassium'] : null;
    $recordDate = $_POST['record_date'] ?: date('Y-m-d');

    // ตรวจว่า plot เป็นของผู้ใช้จริง
    $check = $pdo->prepare('SELECT p.plot_id FROM plots p JOIN farms f ON f.farm_id=p.farm_id WHERE p.plot_id=? AND f.user_id=?');
    $check->execute([$plotId, $uid]);
    if (!$check->fetch()) {
        flash('error', 'ไม่พบแปลงเพาะปลูกนี้');
        redirect('soil_data.php');
    }

    // อัปโหลดรูปดิน (ถ้ามี)
    $soilImage = null;
    $hasNewImage = !empty($_FILES['soil_image']['name']);
    if ($hasNewImage) {
        $soilImage = uploadImageFile($_FILES['soil_image'], UPLOAD_PATH_SOIL);
        if ($soilImage === null) {
            flash('error', 'อัปโหลดรูปดินไม่สำเร็จ (รองรับเฉพาะ jpg, png, webp)');
            redirect('soil_data.php');
        }
    }

    // ถ้าไม่มีรูป ต้องระบุประเภทดิน
    $soilType = trim($_POST['soil_type'] ?? '');
    if (!$hasNewImage && $soilId === 0 && $soilType === '') {
        flash('error', 'กรุณาระบุประเภทดิน หรืออัปโหลดรูปถ่ายดิน');
        redirect('soil_data.php');
    }

    // ถ้ามีรูป ให้ AI ในเครื่อง (gemma3:4b) วิเคราะห์ภาพ (ผลแสดงเป็นเฟรมบนหน้า ไม่ใช้ข้อความแจ้งเตือน)
    $visionResult = null;
    $visionModel = null;
    $visionError = null;
    if ($soilImage !== null) {
        $prompt = 'วิเคราะห์ลักษณะทางกายภาพของดินในภาพนี้สำหรับการเกษตร ให้ระบุ: สีดิน เนื้อดินโดยประมาณ (ทราย/ร่วน/เหนียว) ความชื้นที่สังเกตได้ และข้อสังเกตอื่นที่เป็นประโยชน์ต่อการปลูกพืช ตอบเป็นภาษาไทยกระชับ 2-3 ประโยค';
        $vision = aiVision(UPLOAD_PATH_SOIL . $soilImage, $prompt, ['maxOutputTokens' => 512]);
        if ($vision['success']) {
            $visionResult = $vision['text'];
            $visionModel = $vision['model'] ?? OLLAMA_VISION_MODEL;
        } else {
            $visionError = $vision['error'];
        }
    }

    if ($soilId > 0) {
        // update
        if ($soilImage !== null) {
            $pdo->prepare('UPDATE soil_data SET plot_id=?, ph_value=?, nitrogen=?, phosphorus=?, potassium=?, soil_type=?, soil_image=?, soil_vision_result=?, soil_vision_model=?, record_date=? WHERE soil_id=?')
                ->execute([$plotId, $ph, $n, $p, $k, $soilType ?: null, $soilImage, $visionResult, $visionModel, $recordDate, $soilId]);
        } else {
            $pdo->prepare('UPDATE soil_data SET plot_id=?, ph_value=?, nitrogen=?, phosphorus=?, potassium=?, soil_type=?, record_date=? WHERE soil_id=?')
                ->execute([$plotId, $ph, $n, $p, $k, $soilType ?: null, $recordDate, $soilId]);
        }
        flash('success', 'แก้ไขข้อมูลดินสำเร็จ');
    } else {
        $pdo->prepare('INSERT INTO soil_data (plot_id, ph_value, nitrogen, phosphorus, potassium, soil_type, soil_image, soil_vision_result, soil_vision_model, record_date) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$plotId, $ph, $n, $p, $k, $soilType ?: null, $soilImage, $visionResult, $visionModel, $recordDate]);
        $soilId = (int) $pdo->lastInsertId();
        flash('success', 'บันทึกข้อมูลดินสำเร็จ');
    }
    logActivity($uid, 'save_soil', 'plot#' . $plotId);

    // มีรูปใหม่: พาไปดูผลวิเคราะห์ของ AI ในเฟรมบนหน้านี้ (ถ้า AI ล้มเหลว เก็บสาเหตุไว้แสดงในเฟรมด้วย)
    if ($soilImage !== null) {
        if ($visionError !== null) {
            $_SESSION['soil_ai_error'][$soilId] = $visionError;
        }
        redirect('soil_data.php?result=' . $soilId . '#aiResult');
    }
    redirect('soil_data.php');
}

// ---- Delete ----
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare('DELETE s FROM soil_data s JOIN plots p ON p.plot_id=s.plot_id JOIN farms f ON f.farm_id=p.farm_id WHERE s.soil_id=? AND f.user_id=?');
    $stmt->execute([(int) $_GET['delete'], $uid]);
    flash('success', 'ลบข้อมูลดินสำเร็จ');
    redirect('soil_data.php');
}

// ---- เฟรมผลวิเคราะห์ภาพดินด้วย AI (เปิดด้วย ?result=รหัสค่าดิน เฉพาะของตัวเอง) ----
$resultId = (int) ($_GET['result'] ?? 0);
$resultRow = null;
$resultError = null;
if ($resultId > 0) {
    $rs = $pdo->prepare('SELECT s.*, p.plot_name, f.farm_name FROM soil_data s JOIN plots p ON p.plot_id = s.plot_id JOIN farms f ON f.farm_id = p.farm_id WHERE s.soil_id = ? AND f.user_id = ?');
    $rs->execute([$resultId, $uid]);
    $resultRow = $rs->fetch() ?: null;
    $resultError = $_SESSION['soil_ai_error'][$resultId] ?? null;
    unset($_SESSION['soil_ai_error'][$resultId]);
}

$plots = $pdo->prepare('SELECT p.plot_id, p.plot_name, f.farm_name FROM plots p JOIN farms f ON f.farm_id=p.farm_id WHERE f.user_id=? ORDER BY f.farm_name, p.plot_name');
$plots->execute([$uid]);
$plots = $plots->fetchAll();

[$perPage, $offset, $currentPage] = getPagination(15);
$totalRecords = $pdo->prepare('SELECT COUNT(*) FROM soil_data s JOIN plots p ON p.plot_id=s.plot_id JOIN farms f ON f.farm_id=p.farm_id WHERE f.user_id=?');
$totalRecords->execute([$uid]);
$totalRecords = (int) $totalRecords->fetchColumn();

$records = $pdo->prepare('
  SELECT s.*, p.plot_name FROM soil_data s
  JOIN plots p ON p.plot_id = s.plot_id
  JOIN farms f ON f.farm_id = p.farm_id
  WHERE f.user_id = ? ORDER BY s.record_date DESC, s.soil_id DESC
  LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset . '
');
$records->execute([$uid]);
$records = $records->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar_user.php'; ?>
  <main class="main-content">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>

    <?php if ($resultRow): ?>
      <div class="ai-box mb-4" id="aiResult">
        <div class="d-flex justify-content-between align-items-start mb-3">
          <h6 class="fw-bold mb-0"><i class="bi bi-robot text-success"></i> ผลวิเคราะห์ภาพดินด้วย AI</h6>
          <a href="soil_data.php" class="btn-close" aria-label="ปิดเฟรมผลวิเคราะห์"></a>
        </div>
        <div class="row g-3">
          <?php if ($resultRow['soil_image']): ?>
            <div class="col-md-4">
              <a href="<?= BASE_URL ?>/uploads/soil/<?= e($resultRow['soil_image']) ?>" target="_blank">
                <img src="<?= BASE_URL ?>/uploads/soil/<?= e($resultRow['soil_image']) ?>" class="img-fluid rounded-3 w-100" style="max-height:240px;object-fit:cover;" alt="ภาพดินที่วิเคราะห์">
              </a>
            </div>
          <?php endif; ?>
          <div class="col-md-<?= $resultRow['soil_image'] ? '8' : '12' ?>">
            <div class="small text-muted mb-2"><?= e($resultRow['farm_name']) ?> / <?= e($resultRow['plot_name']) ?> · วันที่บันทึก <?= formatDateThai($resultRow['record_date']) ?></div>
            <div class="mb-3">
              <span class="badge badge-soft-green">pH <?= number_format($resultRow['ph_value'], 1) ?></span>
              <span class="badge badge-soft-blue">N <?= $resultRow['nitrogen'] !== null ? number_format($resultRow['nitrogen'], 1) : '-' ?></span>
              <span class="badge badge-soft-blue">P <?= $resultRow['phosphorus'] !== null ? number_format($resultRow['phosphorus'], 1) : '-' ?></span>
              <span class="badge badge-soft-blue">K <?= $resultRow['potassium'] !== null ? number_format($resultRow['potassium'], 1) : '-' ?></span>
              <?php if ($resultRow['soil_type']): ?><span class="badge badge-soft-amber"><?= e($resultRow['soil_type']) ?></span><?php endif; ?>
            </div>
            <?php if ($resultRow['soil_vision_result']): ?>
              <div class="p-3 rounded-3" style="background:#fff;border:1px solid var(--primary-light);"><?= nl2br(e($resultRow['soil_vision_result'])) ?></div>
              <p class="small text-muted mt-2 mb-0">วิเคราะห์โดย: <?= e($resultRow['soil_vision_model'] ?: 'AI วิเคราะห์ภาพ (รายการเก่าไม่ได้บันทึกชื่อโมเดล)') ?> · เป็นการประเมินเบื้องต้นจากภาพ ไม่แทนการตรวจดินในห้องปฏิบัติการ</p>
            <?php elseif ($resultRow['soil_image']): ?>
              <div class="p-3 rounded-3" style="background:#fff7ed;border:1px solid #fed7aa;">
                <b><i class="bi bi-exclamation-triangle me-1"></i> ยังไม่มีผลวิเคราะห์สำหรับรูปนี้</b>
                <p class="small mb-0 mt-1">บันทึกค่าดินเรียบร้อยแล้ว แต่ AI ไม่พร้อมวิเคราะห์ภาพในขณะนั้น<?= $resultError ? ' (' . e($resultError) . ')' : '' ?> เมื่อ AI พร้อม กดปุ่มแก้ไข <i class="bi bi-pencil"></i> ของรายการนี้ แล้วอัปโหลดรูปอีกครั้งเพื่อให้วิเคราะห์ใหม่</p>
              </div>
            <?php else: ?>
              <div class="p-3 rounded-3" style="background:#f6f8f7;border:1px solid #dfe8e4;"><span class="small text-muted">รายการนี้ไม่มีรูปถ่ายดิน จึงไม่มีผลวิเคราะห์ภาพ (อัปโหลดรูปผ่านปุ่มแก้ไขเพื่อให้ AI วิเคราะห์ได้)</span></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!$plots): ?>
      <div class="alert alert-warning">กรุณาเพิ่มแปลงเพาะปลูกก่อน <a href="plots.php">ไปที่หน้าแปลงเพาะปลูก</a> หรือ <a href="quick_setup.php">ใช้ตั้งค่าเริ่มต้นแบบเร็ว</a></div>
    <?php else: ?>
    <div class="d-flex justify-content-end mb-3">
      <button class="btn btn-primary-app" data-bs-toggle="modal" data-bs-target="#soilModal" onclick="openSoilModal()">
        <i class="bi bi-plus-circle me-1"></i> บันทึกค่าดิน
      </button>
    </div>
    <?php endif; ?>

    <div class="app-card p-3">
      <div class="table-responsive">
        <table class="table align-middle">
          <thead><tr><th>แปลง</th><th>pH</th><th>N</th><th>P</th><th>K</th><th>ประเภท/รูป</th><th>วันที่</th><th class="text-end">จัดการ</th></tr></thead>
          <tbody>
            <?php foreach ($records as $r): ?>
              <tr class="<?= $resultId === (int) $r['soil_id'] ? 'table-success' : '' ?>">
                <td class="fw-semibold"><?= e($r['plot_name']) ?></td>
                <td><span class="badge badge-soft-green"><?= number_format($r['ph_value'], 1) ?></span></td>
                <td><?= $r['nitrogen'] !== null ? number_format($r['nitrogen'], 1) : '-' ?></td>
                <td><?= $r['phosphorus'] !== null ? number_format($r['phosphorus'], 1) : '-' ?></td>
                <td><?= $r['potassium'] !== null ? number_format($r['potassium'], 1) : '-' ?></td>
                <td>
                  <?php if ($r['soil_image']): ?>
                    <a href="<?= BASE_URL ?>/uploads/soil/<?= e($r['soil_image']) ?>" target="_blank"><i class="bi bi-image text-success"></i> ดูรูป</a>
                  <?php else: ?>
                    <span class="small"><?= e($r['soil_type'] ?: '-') ?></span>
                  <?php endif; ?>
                </td>
                <td class="small text-muted"><?= formatDateThai($r['record_date']) ?></td>
                <td class="text-end">
                  <?php if ($r['soil_image']): ?>
                    <a class="btn btn-sm btn-outline-app" href="?result=<?= (int) $r['soil_id'] ?>#aiResult" title="ดูผลวิเคราะห์ภาพดินจาก AI"><i class="bi bi-robot"></i></a>
                  <?php endif; ?>
                  <button class="btn btn-sm btn-outline-app" data-bs-toggle="modal" data-bs-target="#soilModal" onclick='openSoilModal(<?= json_encode($r, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="bi bi-pencil"></i></button>
                  <a class="btn btn-sm btn-outline-danger" href="?delete=<?= $r['soil_id'] ?>" onclick="return confirm('ยืนยันการลบข้อมูลดินนี้?')"><i class="bi bi-trash"></i></a>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$records): ?><tr><td colspan="8" class="text-center text-muted py-4">ยังไม่มีข้อมูลดิน</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <?= renderPagination($totalRecords, $perPage, $currentPage) ?>
    </div>
  </main>
</div>

<div class="modal fade" id="soilModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="border-radius:16px;">
      <form method="post" enctype="multipart/form-data">
        <?= csrfField() ?>
        <div class="modal-header">
          <h5 class="modal-title" id="soilModalTitle">บันทึกค่าดิน</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="soil_id" id="s_soil_id" value="">
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">แปลงเพาะปลูก</label>
              <select name="plot_id" id="s_plot_id" class="form-select" required>
                <?php foreach ($plots as $p): ?>
                  <option value="<?= $p['plot_id'] ?>"><?= e($p['farm_name'] . ' - ' . $p['plot_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">วันที่บันทึก</label>
              <input type="date" name="record_date" id="s_record_date" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
          </div>
          <div class="p-3 rounded-3 mb-3" style="background:#fff7ed; border:1px solid #fed7aa;">
            <label class="form-label mb-1"><i class="bi bi-geo-alt"></i> ไม่ทราบค่าดินที่แน่นอน? เลือกภูมิภาคเพื่อเติมค่ากลางโดยประมาณ</label>
            <p class="small text-muted mb-2">เป็นค่าเฉลี่ยทั่วไปของภูมิภาค ใช้เริ่มต้นได้ แต่แนะนำให้ตรวจดินจริงเพื่อความแม่นยำ</p>
            <div class="d-flex flex-wrap gap-2">
              <button type="button" class="btn btn-outline-app btn-sm" onclick="fillRegion('central')" title="pH ~6.0-8.0 · ดินเหนียวลุ่มแม่น้ำ อุดมสมบูรณ์ปานกลาง-สูง">ภาคกลาง</button>
              <button type="button" class="btn btn-outline-app btn-sm" onclick="fillRegion('northeast')" title="pH ~4.5-6.0 · ดินทราย แร่ธาตุต่ำ N และ K มักต่ำมาก">ภาคอีสาน</button>
              <button type="button" class="btn btn-outline-app btn-sm" onclick="fillRegion('north')" title="pH ~5.5-7.5 · พื้นที่ดอน/หุบเขา อุดมสมบูรณ์ปานกลาง">ภาคเหนือ</button>
              <button type="button" class="btn btn-outline-app btn-sm" onclick="fillRegion('south')" title="pH ~4.0-5.5 · ฝนชุก ดินกรดจัด ฟอสฟอรัสมักถูกตรึง">ภาคใต้ / ภาคตะวันออก</button>
            </div>
          </div>

          <div class="row">
            <div class="col-md-3 mb-3">
              <label class="form-label">ค่า pH</label>
              <input type="number" step="0.1" min="0" max="14" name="ph_value" id="s_ph_value" class="form-control" required placeholder="6.5">
            </div>
            <div class="col-md-3 mb-3">
              <label class="form-label">N (mg/kg)</label>
              <input type="number" step="0.01" name="nitrogen" id="s_nitrogen" class="form-control" placeholder="ไนโตรเจน">
            </div>
            <div class="col-md-3 mb-3">
              <label class="form-label">P (mg/kg)</label>
              <input type="number" step="0.01" name="phosphorus" id="s_phosphorus" class="form-control" placeholder="ฟอสฟอรัส">
            </div>
            <div class="col-md-3 mb-3">
              <label class="form-label">K (mg/kg)</label>
              <input type="number" step="0.01" name="potassium" id="s_potassium" class="form-control" placeholder="โพแทสเซียม">
            </div>
          </div>

          <div class="p-3 rounded-3 mb-3" style="background:var(--primary-lighter);">
            <label class="form-label mb-2"><i class="bi bi-camera"></i> รูปถ่ายดิน (ไม่บังคับ — หากมีรูป AI จะช่วยวิเคราะห์ลักษณะดินให้)</label>
            <input type="file" name="soil_image" accept="image/*" class="form-control" data-preview="soilPreview" id="s_soil_image" onchange="onSoilImageChange()">
            <img id="soilPreview" class="d-none rounded mt-2" style="max-height:120px;">
          </div>

          <div class="mb-3" id="soilTypeBox">
            <label class="form-label">ประเภทดิน <span class="text-muted small">(กรอกกรณีไม่มีรูปถ่าย)</span></label>
            <select name="soil_type" id="s_soil_type" class="form-select">
              <option value="">— เลือกประเภทดิน —</option>
              <?php foreach ($soilTypes as $t): ?>
                <option value="<?= e($t) ?>"><?= e($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary-app w-100">บันทึกค่าดิน</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// ค่ากลางโดยประมาณของแต่ละภูมิภาค (สำหรับกรอกเบื้องต้นเมื่อยังไม่มีผลตรวจดินจริง)
// pH ใช้จุดกึ่งกลางของช่วงทั่วไปในภูมิภาค / N-P-K (mg/kg) อ้างอิงเกณฑ์ต่ำ-ปานกลาง-สูงของกรมพัฒนาที่ดิน
// (N: ต่ำ<20 ปานกลาง20-40 สูง>40 | P: ต่ำ<10 ปานกลาง10-25 สูง>25 | K: ต่ำ<60 ปานกลาง60-90 สูง>90)
const REGION_SOIL_DEFAULTS = {
  central:   { ph: 6.8, n: 38, p: 16, k: 85, soilType: 'ดินเหนียว',      label: 'ภาคกลาง (ดินเหนียวลุ่มแม่น้ำ อุดมสมบูรณ์ปานกลาง-สูง)' },
  northeast: { ph: 5.2, n: 10, p: 8,  k: 30, soilType: 'ดินทราย',        label: 'ภาคอีสาน (ดินทราย แร่ธาตุต่ำ N และ K มักต่ำมาก)' },
  north:     { ph: 6.5, n: 28, p: 14, k: 65, soilType: 'ดินร่วน',        label: 'ภาคเหนือ (พื้นที่ดอน/หุบเขา อุดมสมบูรณ์ปานกลาง)' },
  south:     { ph: 4.7, n: 18, p: 7,  k: 42, soilType: 'ดินร่วนปนทราย',  label: 'ภาคใต้/ภาคตะวันออก (ฝนชุก ดินกรดจัด P มักถูกตรึง)' },
};

function fillRegion(region) {
  const d = REGION_SOIL_DEFAULTS[region];
  if (!d) return;
  document.getElementById('s_ph_value').value = d.ph;
  document.getElementById('s_nitrogen').value = d.n;
  document.getElementById('s_phosphorus').value = d.p;
  document.getElementById('s_potassium').value = d.k;
  document.getElementById('s_soil_type').value = d.soilType;
}

function onSoilImageChange() {
  const hasImg = document.getElementById('s_soil_image').files.length > 0;
  // ถ้ามีรูปแล้ว ประเภทดินไม่บังคับ
  document.getElementById('s_soil_type').required = false;
}
function openSoilModal(data) {
  document.getElementById('soilModalTitle').innerText = data ? 'แก้ไขข้อมูลดิน' : 'บันทึกค่าดิน';
  document.getElementById('s_soil_id').value = data ? data.soil_id : '';
  document.getElementById('s_ph_value').value = data ? data.ph_value : '';
  document.getElementById('s_nitrogen').value = data && data.nitrogen ? data.nitrogen : '';
  document.getElementById('s_phosphorus').value = data && data.phosphorus ? data.phosphorus : '';
  document.getElementById('s_potassium').value = data && data.potassium ? data.potassium : '';
  document.getElementById('s_record_date').value = data ? data.record_date : '<?= date('Y-m-d') ?>';
  document.getElementById('s_soil_type').value = data && data.soil_type ? data.soil_type : '';
  if (data) document.getElementById('s_plot_id').value = data.plot_id;
  const preview = document.getElementById('soilPreview');
  preview.classList.add('d-none');
  document.getElementById('s_soil_image').value = '';
}
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
