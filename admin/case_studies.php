<?php
require_once __DIR__ . '/../config/config.php';
requireAdmin();
$pageTitle = 'จัดการกรณีศึกษา';
$pdo = getDBConnection();
$me = currentUser()['user_id'];

$filter = in_array($_GET['filter'] ?? '', ['pending', 'approved', 'all'], true) ? $_GET['filter'] : 'pending';
$back = 'case_studies.php?filter=' . $filter;

/* ======================= การกระทำ (POST + CSRF ทั้งหมด) ======================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf($back);
    $action = $_POST['action'] ?? '';
    $kind = ($_POST['kind'] ?? '') === 'manual' ? 'manual' : 'feedback';
    $id = (int) ($_POST['id'] ?? 0);
    $num = fn($k) => (isset($_POST[$k]) && $_POST[$k] !== '' && is_numeric($_POST[$k])) ? (float) $_POST[$k] : null;

    if ($action === 'publish' || $action === 'unpublish') {
        $on = $action === 'publish';
        if ($kind === 'feedback') {
            // เผยแพร่ได้เฉพาะรายการที่เกษตรกรยินยอมเผยแพร่เท่านั้น
            $st = $pdo->prepare('UPDATE recommendation_feedback SET admin_approved = ?' . ($on ? ', approved_by = ?, approved_at = NOW()' : '') . ' WHERE feedback_id = ? AND is_public = 1');
            $st->execute($on ? [1, $me, $id] : [0, $id]);
        } else {
            $st = $pdo->prepare('UPDATE site_case_studies SET is_published = ?' . ($on ? ', published_by = ?, published_at = NOW()' : '') . ' WHERE case_id = ?');
            $st->execute($on ? [1, $me, $id] : [0, $id]);
        }
        if ($st->rowCount() > 0 || !$on) {
            logActivity($me, $on ? 'publish_case_study' : 'unpublish_case_study', $kind . '#' . $id);
            flash('success', $on ? 'เผยแพร่ขึ้นหน้าเว็บไซต์แล้ว' : 'ยกเลิกการเผยแพร่แล้ว (ไม่แสดงบนหน้าเว็บไซต์)');
        } else {
            flash('error', 'เผยแพร่ไม่ได้: รายการนี้เกษตรกรไม่ได้ยินยอมให้เผยแพร่ หรือไม่พบรายการ');
        }
        redirect($back);
    }

    if ($action === 'save_text') {
        if ($kind === 'feedback') {
            // ปรับข้อความที่จะแสดงบนเว็บ (ข้อความต้นฉบับของเกษตรกรไม่ถูกแก้) เว้นว่าง = ใช้ต้นฉบับ
            $title = mb_substr(trim($_POST['public_title'] ?? ''), 0, 200);
            $summary = mb_substr(trim($_POST['public_summary'] ?? ''), 0, 3000);
            $pdo->prepare('UPDATE recommendation_feedback SET public_title = ?, public_summary = ? WHERE feedback_id = ?')
                ->execute([$title !== '' ? $title : null, $summary !== '' ? $summary : null, $id]);
            flash('success', 'บันทึกข้อความที่จะแสดงบนเว็บไซต์แล้ว');
        } else {
            $title = mb_substr(trim($_POST['title'] ?? ''), 0, 200);
            $desc = mb_substr(trim($_POST['description'] ?? ''), 0, 3000);
            $crop = mb_substr(trim($_POST['crop_name'] ?? ''), 0, 100);
            $rating = (int) ($_POST['rating'] ?? 0);
            if ($title === '' || $crop === '' || mb_strlen($desc) < 10) {
                flash('error', 'กรุณากรอกชนิดพืช หัวข้อ และรายละเอียด (อย่างน้อย 10 ตัวอักษร)');
            } else {
                $pdo->prepare('UPDATE site_case_studies SET crop_name = ?, title = ?, description = ?, yield_amount = ?, plan_days = ?, rating = ? WHERE case_id = ?')
                    ->execute([$crop, $title, $desc, $num('yield_amount'), $num('plan_days') !== null ? (int) $num('plan_days') : null, $rating >= 1 && $rating <= 5 ? $rating : null, $id]);
                flash('success', 'บันทึกการแก้ไขแล้ว');
            }
        }
        redirect($back);
    }

    if ($action === 'create') {
        // ผู้ดูแลบันทึกกรณีศึกษาเอง (เช่น จากการลงพื้นที่) — เป็นฉบับร่างก่อน เว้นแต่เลือก "เผยแพร่ทันที"
        $title = mb_substr(trim($_POST['title'] ?? ''), 0, 200);
        $desc = mb_substr(trim($_POST['description'] ?? ''), 0, 3000);
        $crop = mb_substr(trim($_POST['crop_name'] ?? ''), 0, 100);
        $rating = (int) ($_POST['rating'] ?? 0);
        if ($title === '' || $crop === '' || mb_strlen($desc) < 10) {
            flash('error', 'กรุณากรอกชนิดพืช หัวข้อ และรายละเอียด (อย่างน้อย 10 ตัวอักษร)');
            redirect($back);
        }
        if (($num('yield_amount') ?? 0) < 0 || ($num('plan_days') ?? 0) < 0) {
            flash('error', 'ผลผลิตและจำนวนวันต้องไม่ติดลบ');
            redirect($back);
        }
        $image = null;
        if (!empty($_FILES['result_image']['name'])) {
            $image = uploadImageFile($_FILES['result_image'], UPLOAD_PATH_CASE);
            if ($image === null) {
                flash('error', 'อัปโหลดรูปไม่สำเร็จ รองรับเฉพาะไฟล์ JPG, PNG, WebP');
                redirect($back);
            }
        }
        $now = !empty($_POST['publish_now']);
        $pdo->prepare('INSERT INTO site_case_studies (crop_name, title, description, yield_amount, plan_days, rating, result_image, is_published, created_by, published_by, published_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$crop, $title, $desc, $num('yield_amount'), $num('plan_days') !== null ? (int) $num('plan_days') : null, $rating >= 1 && $rating <= 5 ? $rating : null,
                $image, $now ? 1 : 0, $me, $now ? $me : null, $now ? date('Y-m-d H:i:s') : null]);
        logActivity($me, 'create_case_study', 'manual#' . $pdo->lastInsertId());
        flash('success', $now ? 'บันทึกและเผยแพร่ขึ้นหน้าเว็บไซต์แล้ว' : 'บันทึกเป็นฉบับร่างแล้ว กด "เผยแพร่ขึ้นหน้าเว็บไซต์" เมื่อพร้อม');
        redirect('case_studies.php?filter=' . ($now ? 'approved' : 'pending'));
    }

    if ($action === 'delete') {
        if ($kind === 'feedback') {
            $pdo->prepare('DELETE FROM recommendation_feedback WHERE feedback_id = ?')->execute([$id]);
        } else {
            $img = $pdo->prepare('SELECT result_image FROM site_case_studies WHERE case_id = ?');
            $img->execute([$id]);
            if (($f = $img->fetchColumn()) && is_file(UPLOAD_PATH_CASE . basename($f))) {
                @unlink(UPLOAD_PATH_CASE . basename($f));
            }
            $pdo->prepare('DELETE FROM site_case_studies WHERE case_id = ?')->execute([$id]);
        }
        logActivity($me, 'delete_case_study', $kind . '#' . $id);
        flash('success', 'ลบรายการแล้ว');
        redirect($back);
    }
    redirect($back);
}

/* ======================= รายการ ======================= */
// ผลตอบกลับ: เฉพาะที่เกษตรกรยินยอมเผยแพร่ | กรณีบันทึกเอง: ทุกรายการ
$cond = ['pending' => ['fb.admin_approved = 0', 'sc.is_published = 0'], 'approved' => ['fb.admin_approved = 1', 'sc.is_published = 1'], 'all' => ['1=1', '1=1']][$filter];
$items = $pdo->query('SELECT * FROM (' . caseFeedbackSelect(true) . ' WHERE fb.is_public = 1 AND ' . $cond[0]
    . ' UNION ALL ' . caseManualSelect(true) . ' WHERE ' . $cond[1] . ') x ORDER BY x.created_at DESC')->fetchAll();

$counts = $pdo->query("
    SELECT (SELECT COUNT(*) FROM recommendation_feedback WHERE is_public = 1 AND admin_approved = 0) + (SELECT COUNT(*) FROM site_case_studies WHERE is_published = 0) AS pending,
           (SELECT COUNT(*) FROM recommendation_feedback WHERE is_public = 1 AND admin_approved = 1) + (SELECT COUNT(*) FROM site_case_studies WHERE is_published = 1) AS approved")->fetch();
$admins = $pdo->query("SELECT user_id, full_name FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_KEY_PAIR);
$cropNames = $pdo->query('SELECT crop_name FROM crops ORDER BY crop_name')->fetchAll(PDO::FETCH_COLUMN);

require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar_admin.php'; ?>
  <main class="main-content">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>

    <div class="ai-box mb-4">
      <h6 class="fw-bold mb-1"><i class="bi bi-patch-check text-success"></i> จัดการกรณีศึกษาที่แสดงบนเว็บไซต์</h6>
      <p class="small text-muted mb-0">
        ทุกเรื่องต้องผ่านการกด <b>"เผยแพร่ขึ้นหน้าเว็บไซต์"</b> โดยผู้ดูแลระบบก่อนจึงจะแสดงบนหน้าเว็บ
        · ผลตอบกลับของเกษตรกร: แสดงเฉพาะรายการที่เกษตรกรกด "ยินยอมเผยแพร่" ผู้ดูแลปรับหัวข้อ/ข้อความที่จะแสดงได้ ข้อความต้นฉบับไม่ถูกแก้ และหน้าเว็บไม่แสดงชื่อ/ที่อยู่เกษตรกร
        · ผู้ดูแลยังบันทึกกรณีศึกษาเองได้ (เช่น จากการลงพื้นที่) หน้าเว็บจะติดป้าย "บันทึกโดยผู้ดูแลระบบ"
      </p>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-3">
      <a href="?filter=pending" class="btn btn-sm <?= $filter === 'pending' ? 'btn-primary-app' : 'btn-outline-app' ?>">รอตรวจ/ฉบับร่าง <span class="badge bg-light text-dark ms-1"><?= (int) $counts['pending'] ?></span></a>
      <a href="?filter=approved" class="btn btn-sm <?= $filter === 'approved' ? 'btn-primary-app' : 'btn-outline-app' ?>">แสดงบนเว็บไซต์แล้ว <span class="badge bg-light text-dark ms-1"><?= (int) $counts['approved'] ?></span></a>
      <a href="?filter=all" class="btn btn-sm <?= $filter === 'all' ? 'btn-primary-app' : 'btn-outline-app' ?>">ทั้งหมด</a>
      <a href="<?= BASE_URL ?>/public/case_studies.php" target="_blank" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-box-arrow-up-right me-1"></i> ดูหน้าเว็บไซต์</a>
    </div>

    <details class="app-card p-3 mb-4" <?= !$items && $filter === 'pending' ? 'open' : '' ?>>
      <summary class="fw-semibold" style="cursor:pointer;"><i class="bi bi-plus-circle text-success me-1"></i> บันทึกกรณีศึกษาใหม่ (โดยผู้ดูแลระบบ)</summary>
      <form method="post" enctype="multipart/form-data" class="row g-2 mt-2">
        <?= csrfField() ?><input type="hidden" name="action" value="create">
        <div class="col-md-4"><label class="form-label small mb-1">ชนิดพืช <span class="text-danger">*</span></label>
          <input type="text" name="crop_name" class="form-control" list="caseCrops" maxlength="100" required>
          <datalist id="caseCrops"><?php foreach ($cropNames as $cn): ?><option value="<?= e($cn) ?>"></option><?php endforeach; ?></datalist></div>
        <div class="col-md-8"><label class="form-label small mb-1">หัวข้อ <span class="text-danger">*</span></label><input type="text" name="title" class="form-control" maxlength="200" required placeholder="เช่น ปลูกถั่วเขียวหลังนาช่วยฟื้นดิน ได้ผลผลิตดีขึ้น"></div>
        <div class="col-12"><label class="form-label small mb-1">รายละเอียด <span class="text-danger">*</span></label><textarea name="description" class="form-control" rows="3" minlength="10" maxlength="3000" required placeholder="เล่าสิ่งที่ทำ ผลที่ได้ และสิ่งที่ได้เรียนรู้ (ไม่ใส่ชื่อ/ที่อยู่ของเกษตรกร)"></textarea></div>
        <div class="col-md-3"><label class="form-label small mb-1">ผลผลิต (กก.)</label><input type="number" step="0.01" min="0" name="yield_amount" class="form-control"></div>
        <div class="col-md-3"><label class="form-label small mb-1">ใช้เวลาปลูก (วัน)</label><input type="number" min="0" name="plan_days" class="form-control"></div>
        <div class="col-md-3"><label class="form-label small mb-1">ความพึงพอใจ</label><select name="rating" class="form-select"><option value="">-</option><?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= $i ?> ดาว</option><?php endfor; ?></select></div>
        <div class="col-md-3"><label class="form-label small mb-1">รูปประกอบ</label><input type="file" name="result_image" accept="image/*" class="form-control"></div>
        <div class="col-12 d-flex align-items-center gap-3">
          <div class="form-check"><input class="form-check-input" type="checkbox" name="publish_now" value="1" id="pubNow"><label class="form-check-label small" for="pubNow">เผยแพร่ขึ้นหน้าเว็บไซต์ทันที (ไม่เลือก = บันทึกเป็นฉบับร่าง)</label></div>
          <button class="btn btn-primary-app ms-auto"><i class="bi bi-save me-1"></i> บันทึก</button>
        </div>
      </form>
    </details>

    <?php if (!$items): ?>
      <div class="app-card p-4 text-center text-muted">ไม่มีรายการในหมวดนี้<?= $filter === 'pending' ? ' — เมื่อเกษตรกรรายงานผลตอบกลับและยินยอมเผยแพร่ หรือผู้ดูแลบันทึกกรณีศึกษาเป็นฉบับร่าง จะมาแสดงที่นี่' : '' ?></div>
    <?php endif; ?>

    <?php foreach ($items as $it):
        $isFb = $it['kind'] === 'feedback';
        $curated = $isFb && (trim((string) $it['public_title']) !== '' || trim((string) $it['public_summary']) !== '');
        $hid = '<input type="hidden" name="kind" value="' . $it['kind'] . '"><input type="hidden" name="id" value="' . (int) $it['id'] . '">';
    ?>
      <div class="app-card p-4 mb-3" id="case-<?= $it['kind'] ?>-<?= (int) $it['id'] ?>">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
          <div>
            <span class="badge <?= $it['published'] ? 'badge-soft-green' : 'badge-soft-amber' ?>"><?= $it['published'] ? 'แสดงบนเว็บไซต์อยู่' : ($isFb ? 'รอตรวจ' : 'ฉบับร่าง') ?></span>
            <span class="badge badge-soft-blue"><?= $isFb ? 'จากผลตอบกลับเกษตรกร' : 'บันทึกโดยผู้ดูแลระบบ' ?></span>
            <?php if ($curated): ?><span class="badge badge-soft-amber">ปรับข้อความแล้ว</span><?php endif; ?>
            <div class="small text-muted mt-1">
              <?= $isFb ? 'โดย ' . e($it['author_name']) . ' (เห็นเฉพาะ Admin — หน้าเว็บไม่แสดงชื่อนี้)' : 'บันทึกโดย ' . e($it['author_name'] ?: '-') ?> · <?= formatDateThai($it['created_at']) ?>
              <?php if ($it['published'] && $it['published_at']): ?> · เผยแพร่โดย <?= e($admins[$it['published_by']] ?? '-') ?> เมื่อ <?= formatDateThai($it['published_at']) ?> <?= date('H:i', strtotime($it['published_at'])) ?> น.<?php endif; ?>
            </div>
          </div>
          <div class="d-flex gap-2">
            <form method="post"><?= csrfField() ?><?= $hid ?>
              <?php if (!$it['published']): ?><input type="hidden" name="action" value="publish"><button class="btn btn-sm btn-primary-app"><i class="bi bi-globe me-1"></i> เผยแพร่ขึ้นหน้าเว็บไซต์</button>
              <?php else: ?><input type="hidden" name="action" value="unpublish"><button class="btn btn-sm btn-outline-app"><i class="bi bi-eye-slash me-1"></i> ยกเลิกการเผยแพร่</button><?php endif; ?>
            </form>
            <form method="post" onsubmit="return confirm('ยืนยันการลบรายการนี้? ลบแล้วกู้คืนไม่ได้')"><?= csrfField() ?><?= $hid ?><input type="hidden" name="action" value="delete">
              <button class="btn btn-sm btn-outline-danger" title="ลบ"><i class="bi bi-trash"></i></button></form>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-lg-5">
            <div class="small text-muted mb-1"><i class="bi bi-eye me-1"></i> ตัวอย่างที่จะแสดงบนหน้าเว็บไซต์</div>
            <?= caseRenderCard($it) ?>
          </div>
          <div class="col-lg-7">
            <?php if ($isFb): ?>
              <div class="small text-muted mb-1">ข้อความต้นฉบับจากเกษตรกร (ไม่ถูกแก้)</div>
              <div class="p-3 rounded-3 mb-2" style="background:#f6f8f7;border:1px solid #dfe8e4;"><?= nl2br(e($it['outcome_description'] ?: '(ไม่ได้กรอกรายละเอียด)')) ?></div>
              <?php if ($it['plan_days']): ?><p class="small text-success mb-1"><i class="bi bi-calendar2-check"></i> มีแผนการปลูกจริงรองรับ: ปลูก <?= (int) $it['plan_days'] ?> วัน — ข้อมูลนี้มาจากการบันทึกเก็บเกี่ยวในระบบ ไม่ใช่การกรอกเอง</p><?php endif; ?>
              <?php if ($it['plan_skip_list']): ?><p class="small text-muted mb-1"><i class="bi bi-skip-forward"></i> เหตุผลที่ข้าม (เห็นเฉพาะผู้ดูแล): <?= e($it['plan_skip_list']) ?></p><?php endif; ?>
              <details class="mt-2">
                <summary class="btn btn-sm btn-outline-app" style="list-style:none;cursor:pointer;"><i class="bi bi-pencil me-1"></i> แก้ไขข้อความที่จะแสดงบนเว็บ</summary>
                <form method="post" class="mt-2"><?= csrfField() ?><?= $hid ?><input type="hidden" name="action" value="save_text">
                  <label class="form-label small mb-1">หัวข้อ (เว้นว่าง = "ผลการปลูก<?= e($it['crop_name']) ?>")</label>
                  <input type="text" name="public_title" class="form-control form-control-sm mb-2" maxlength="200" value="<?= e($it['public_title'] ?? '') ?>">
                  <label class="form-label small mb-1">ข้อความที่จะแสดง (เว้นว่าง = ใช้ข้อความต้นฉบับของเกษตรกร)</label>
                  <textarea name="public_summary" class="form-control form-control-sm mb-2" rows="3" maxlength="3000" placeholder="เช่น ตัดข้อมูลส่วนตัว แก้คำผิด หรือสรุปให้อ่านง่าย"><?= e($it['public_summary'] ?? '') ?></textarea>
                  <button class="btn btn-sm btn-primary-app">บันทึกข้อความ</button>
                </form>
              </details>
            <?php else: ?>
              <details>
                <summary class="btn btn-sm btn-outline-app" style="list-style:none;cursor:pointer;"><i class="bi bi-pencil me-1"></i> แก้ไขกรณีศึกษานี้</summary>
                <form method="post" class="row g-2 mt-2"><?= csrfField() ?><?= $hid ?><input type="hidden" name="action" value="save_text">
                  <div class="col-md-5"><label class="form-label small mb-1">ชนิดพืช</label><input type="text" name="crop_name" class="form-control form-control-sm" list="caseCrops" maxlength="100" required value="<?= e($it['crop_name']) ?>"></div>
                  <div class="col-md-7"><label class="form-label small mb-1">หัวข้อ</label><input type="text" name="title" class="form-control form-control-sm" maxlength="200" required value="<?= e($it['public_title']) ?>"></div>
                  <div class="col-12"><label class="form-label small mb-1">รายละเอียด</label><textarea name="description" class="form-control form-control-sm" rows="3" maxlength="3000" required><?= e($it['outcome_description']) ?></textarea></div>
                  <div class="col-md-4"><label class="form-label small mb-1">ผลผลิต (กก.)</label><input type="number" step="0.01" min="0" name="yield_amount" class="form-control form-control-sm" value="<?= e($it['yield_amount'] ?? '') ?>"></div>
                  <div class="col-md-4"><label class="form-label small mb-1">ใช้เวลาปลูก (วัน)</label><input type="number" min="0" name="plan_days" class="form-control form-control-sm" value="<?= e($it['plan_days'] ?? '') ?>"></div>
                  <div class="col-md-4"><label class="form-label small mb-1">ความพึงพอใจ</label><select name="rating" class="form-select form-select-sm"><option value="">-</option><?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>" <?= (int) $it['rating'] === $i ? 'selected' : '' ?>><?= $i ?> ดาว</option><?php endfor; ?></select></div>
                  <div class="col-12"><button class="btn btn-sm btn-primary-app">บันทึกการแก้ไข</button></div>
                </form>
              </details>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </main>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
