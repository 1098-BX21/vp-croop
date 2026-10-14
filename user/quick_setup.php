<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
$pageTitle = 'ตั้งค่าเริ่มต้นแบบเร็ว';
$uid = currentUser()['user_id'];
$pdo = getDBConnection();

// ---- บันทึกฟาร์ม + แปลงแรก + (ถ้าไม่ข้าม) ข้อมูลดิน ในครั้งเดียว ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf('quick_setup.php');

    $farmName = trim($_POST['farm_name'] ?? '');
    $farmArea = $_POST['farm_area'] !== '' ? (float) $_POST['farm_area'] : null;
    $hasWater = (int) ($_POST['has_water_source'] ?? 0);
    $plotName = trim($_POST['plot_name'] ?? '');
    $plotSize = $_POST['plot_size'] !== '' ? (float) $_POST['plot_size'] : null;
    $skipSoil = ($_POST['skip_soil'] ?? '0') === '1';
    $soilType = trim($_POST['soil_type'] ?? '');
    $phRaw = trim($_POST['ph_value'] ?? '');

    if ($farmName === '' || $plotName === '') {
        flash('error', 'กรุณากรอกชื่อฟาร์มและชื่อแปลงให้ครบถ้วน');
        redirect('quick_setup.php');
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare('INSERT INTO farms (user_id, farm_name, area_size, has_water_source) VALUES (?,?,?,?)')
            ->execute([$uid, $farmName, $farmArea, $hasWater]);
        $farmId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO plots (farm_id, plot_name, plot_size) VALUES (?,?,?)')
            ->execute([$farmId, $plotName, $plotSize]);
        $plotId = (int) $pdo->lastInsertId();

        // บันทึกข้อมูลดินเฉพาะกรณีไม่กดข้าม และกรอกค่า pH มาด้วย (pH บังคับตามโครงสร้างฐานข้อมูล)
        $soilSaved = false;
        if (!$skipSoil && $phRaw !== '') {
            $pdo->prepare('INSERT INTO soil_data (plot_id, ph_value, soil_type, record_date) VALUES (?,?,?,CURDATE())')
                ->execute([$plotId, (float) $phRaw, $soilType !== '' ? $soilType : null]);
            $soilSaved = true;
        }

        $pdo->commit();
        logActivity($uid, 'quick_setup', $farmName . ' / ' . $plotName);

        $msg = 'สร้างฟาร์ม "' . $farmName . '" และแปลง "' . $plotName . '" สำเร็จ!';
        $msg .= $soilSaved ? ' บันทึกข้อมูลดินเรียบร้อยแล้วด้วย 🌱' : ' (ยังไม่ได้บันทึกข้อมูลดิน เพิ่มทีหลังได้ที่เมนู "บันทึกค่าดิน")';
        $msg .= ' เลือกแปลงด้านล่างเพื่อขอคำแนะนำจาก AI ได้เลย';
        flash('success', $msg);
        redirect('ai_advice.php');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', 'เกิดข้อผิดพลาดในการบันทึก กรุณาลองใหม่อีกครั้ง');
        redirect('quick_setup.php');
    }
}

require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar_user.php'; ?>
  <main class="main-content">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>

    <div class="d-flex justify-content-center">
      <div style="width:100%; max-width:620px;">

        <!-- แถบสถานะขั้นตอน -->
        <div class="d-flex align-items-center justify-content-center mb-4" style="gap:0;">
          <div class="d-flex flex-column align-items-center" style="width:80px;">
            <div id="dot1" class="qs-dot qs-dot-active">1</div>
            <div class="small text-muted mt-1">ฟาร์ม+แปลง</div>
          </div>
          <div id="line1" class="qs-line"></div>
          <div class="d-flex flex-column align-items-center" style="width:80px;">
            <div id="dot2" class="qs-dot">2</div>
            <div class="small text-muted mt-1">ข้อมูลดิน</div>
          </div>
        </div>

        <div class="app-card p-4 p-md-5">
          <form method="post" id="quickSetupForm" novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="skip_soil" id="skipSoilInput" value="0">
            <input type="hidden" name="soil_type" id="soilTypeInput" value="">

            <!-- ===== ขั้นที่ 1: ฟาร์ม + แปลงแรก ===== -->
            <div id="step1">
              <h5 class="fw-bold mb-1">ตั้งค่าเริ่มต้นแบบเร็ว</h5>
              <p class="text-muted small mb-4">กรอกครั้งเดียว ได้ทั้งฟาร์มและแปลงเพาะปลูกแรกเลย ไม่ต้องสลับหน้า</p>

              <div class="mb-3">
                <label class="form-label">ชื่อฟาร์ม</label>
                <input type="text" name="farm_name" id="qsFarmName" class="form-control" required placeholder="เช่น ฟาร์มสุขใจ">
              </div>
              <div class="mb-3">
                <label class="form-label">พื้นที่ทั้งหมด (ไร่)</label>
                <input type="number" step="0.01" name="farm_area" class="form-control" placeholder="เช่น 10">
              </div>

              <div class="mb-2">
                <label class="form-label">พื้นที่นี้มีแหล่งน้ำหรือไม่?</label>
                <div class="d-flex gap-2">
                  <button type="button" id="waterYesBtn" class="btn btn-outline-app flex-fill qs-pill" onclick="setWater(1)">มีแหล่งน้ำ</button>
                  <button type="button" id="waterNoBtn" class="btn btn-outline-app flex-fill qs-pill qs-pill-active" onclick="setWater(0)">ไม่มีแหล่งน้ำ</button>
                </div>
                <input type="hidden" name="has_water_source" id="hasWaterInput" value="0">
              </div>

              <hr class="my-4">
              <div class="fw-bold text-success mb-3"><i class="bi bi-flower2 me-1"></i> แปลงเพาะปลูกแรกของคุณ</div>

              <div class="mb-3">
                <label class="form-label">ชื่อแปลง</label>
                <input type="text" name="plot_name" id="qsPlotName" class="form-control" required value="แปลง A1">
              </div>
              <div class="mb-4">
                <label class="form-label">ขนาดแปลง (ไร่)</label>
                <input type="number" step="0.01" name="plot_size" class="form-control" placeholder="เช่น 2.5">
              </div>

              <button type="button" class="btn btn-primary-app w-100" onclick="goStep2()">
                ถัดไป: ข้อมูลดิน (ไม่บังคับ) <i class="bi bi-arrow-right ms-1"></i>
              </button>
            </div>

            <!-- ===== ขั้นที่ 2: ข้อมูลดิน (ข้ามได้) ===== -->
            <div id="step2" style="display:none;">
              <h5 class="fw-bold mb-1">ค่าดินเบื้องต้น</h5>
              <p class="text-muted small mb-4">ไม่บังคับ — ข้ามไปก่อนแล้วเพิ่มทีหลังก็ได้ ไม่กระทบการใช้งาน</p>

              <div class="mb-3">
                <label class="form-label">ประเภทดิน</label>
                <div class="d-flex flex-wrap gap-2" id="soilPillBox">
                  <?php foreach (['ดินดำ', 'ดินทราย', 'ดินร่วน', 'ดินเหนียว', 'ดินลูกรัง'] as $st): ?>
                    <button type="button" class="btn btn-outline-app btn-sm qs-pill" onclick="pickSoil(this, '<?= e($st) ?>')"><?= e($st) ?></button>
                  <?php endforeach; ?>
                </div>
              </div>

              <div class="mb-4">
                <label class="form-label">ค่า pH (กรอกเมื่อต้องการบันทึกข้อมูลดิน)</label>
                <input type="number" step="0.1" min="0" max="14" name="ph_value" id="qsPh" class="form-control" placeholder="เช่น 6.5">
              </div>

              <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-app" onclick="goStep1()"><i class="bi bi-arrow-left"></i></button>
                <button type="button" class="btn btn-outline-app flex-fill" onclick="submitForm(true)">ข้ามขั้นตอนนี้</button>
                <button type="button" class="btn btn-primary-app flex-fill" onclick="submitForm(false)">บันทึกและเสร็จสิ้น <i class="bi bi-check-lg ms-1"></i></button>
              </div>
            </div>
          </form>
        </div>

        <p class="text-center text-muted small mt-3">
          ต้องการควบคุมรายละเอียดมากขึ้น (เช่น หลายแปลงต่อฟาร์ม, ประเภทแหล่งน้ำ)?
          <a href="farms.php">ไปที่หน้าจัดการฟาร์มแบบละเอียด</a>
        </p>
      </div>
    </div>
  </main>
</div>

<style>
.qs-dot {
  width: 36px; height: 36px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 14px;
  background: var(--primary-light); color: var(--muted);
}
.qs-dot-active { background: var(--primary); color: #fff; box-shadow: 0 0 0 4px var(--primary-light); }
.qs-dot-done { background: var(--primary); color: #fff; }
.qs-line { flex: 0 0 80px; height: 3px; background: var(--border); margin: 0 4px 22px; }
.qs-line-done { background: var(--primary); }
.qs-pill-active { background: var(--primary) !important; color: #fff !important; border-color: var(--primary) !important; }
</style>

<script>
function setWater(val) {
  document.getElementById('hasWaterInput').value = val;
  document.getElementById('waterYesBtn').classList.toggle('qs-pill-active', val === 1);
  document.getElementById('waterNoBtn').classList.toggle('qs-pill-active', val === 0);
}

function pickSoil(btn, name) {
  document.getElementById('soilTypeInput').value = name;
  document.querySelectorAll('#soilPillBox .qs-pill').forEach(function (b) { b.classList.remove('qs-pill-active'); });
  btn.classList.add('qs-pill-active');
}

function goStep2() {
  var farmName = document.getElementById('qsFarmName').value.trim();
  var plotName = document.getElementById('qsPlotName').value.trim();
  if (!farmName || !plotName) {
    alert('กรุณากรอกชื่อฟาร์มและชื่อแปลงก่อนไปขั้นตอนถัดไป');
    return;
  }
  document.getElementById('step1').style.display = 'none';
  document.getElementById('step2').style.display = 'block';
  document.getElementById('dot1').classList.remove('qs-dot-active');
  document.getElementById('dot1').classList.add('qs-dot-done');
  document.getElementById('dot1').innerHTML = '<i class="bi bi-check-lg"></i>';
  document.getElementById('line1').classList.add('qs-line-done');
  document.getElementById('dot2').classList.add('qs-dot-active');
}

function goStep1() {
  document.getElementById('step2').style.display = 'none';
  document.getElementById('step1').style.display = 'block';
  document.getElementById('dot2').classList.remove('qs-dot-active');
}

function submitForm(skip) {
  document.getElementById('skipSoilInput').value = skip ? '1' : '0';
  var phInput = document.getElementById('qsPh');
  if (!skip && phInput.value.trim() === '') {
    alert('กรุณากรอกค่า pH ก่อนบันทึก หรือกด "ข้ามขั้นตอนนี้" แทน');
    return;
  }
  document.getElementById('quickSetupForm').submit();
}
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
