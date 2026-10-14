<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
$pageTitle = 'ผลตอบกลับคำแนะนำ AI';
$uid = currentUser()['user_id'];
$pdo = getDBConnection();

// ---- บันทึกผลตอบกลับ ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf('give_feedback.php');

    $recommendId = (int) ($_POST['recommend_id'] ?? 0);
    $followed = (int) ($_POST['followed'] ?? 0);
    $rating = $_POST['outcome_rating'] !== '' ? (int) $_POST['outcome_rating'] : null;
    $desc = trim($_POST['outcome_description'] ?? '');
    $yield = $_POST['yield_amount'] !== '' ? (float) $_POST['yield_amount'] : null;
    $isPublic = isset($_POST['is_public']) ? 1 : 0;

    // ตรวจว่าคำแนะนำนี้เป็นของผู้ใช้คนนี้จริง (ผ่านการเชื่อม plot -> farm -> user)
    $check = $pdo->prepare('
        SELECT r.recommend_id FROM ai_recommendations r
        JOIN plots p ON p.plot_id = r.plot_id
        JOIN farms f ON f.farm_id = p.farm_id
        WHERE r.recommend_id = ? AND f.user_id = ?
    ');
    $check->execute([$recommendId, $uid]);
    if (!$check->fetch()) {
        flash('error', 'ไม่พบคำแนะนำนี้ หรือคุณไม่มีสิทธิ์ให้ผลตอบกลับรายการนี้');
        redirect('give_feedback.php');
    }

    $resultImage = null;
    if (!empty($_FILES['result_image']['name'])) {
        $resultImage = uploadImageFile($_FILES['result_image'], UPLOAD_PATH_FEEDBACK);
    }

    // อนุญาตแก้ไขผลตอบกลับเดิมได้ถ้าเคยให้ไว้แล้ว (อัปเดตแทนสร้างซ้ำ)
    $existing = $pdo->prepare('SELECT feedback_id, result_image FROM recommendation_feedback WHERE recommend_id = ? AND user_id = ?');
    $existing->execute([$recommendId, $uid]);
    $existingRow = $existing->fetch();

    if ($existingRow) {
        if ($resultImage === null) {
            $resultImage = $existingRow['result_image']; // ไม่ได้อัปโหลดใหม่ คงรูปเดิมไว้
        }
        $pdo->prepare('UPDATE recommendation_feedback SET followed=?, outcome_rating=?, outcome_description=?, yield_amount=?, result_image=?, is_public=? WHERE feedback_id=?')
            ->execute([$followed, $rating, $desc, $yield, $resultImage, $isPublic, $existingRow['feedback_id']]);
        flash('success', 'อัปเดตผลตอบกลับสำเร็จ ขอบคุณที่แบ่งปันผลลัพธ์ครับ');
    } else {
        $pdo->prepare('INSERT INTO recommendation_feedback (recommend_id, user_id, followed, outcome_rating, outcome_description, yield_amount, result_image, is_public) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$recommendId, $uid, $followed, $rating, $desc, $yield, $resultImage, $isPublic]);
        flash('success', 'บันทึกผลตอบกลับสำเร็จ ขอบคุณที่แบ่งปันผลลัพธ์ครับ' . ($isPublic ? ' ข้อมูลนี้จะถูกนำไปแสดงเป็นกรณีศึกษาแบบไม่ระบุตัวตนให้เกษตรกรท่านอื่นได้เรียนรู้ด้วย' : ''));
        logActivity($uid, 'give_feedback', 'recommend#' . $recommendId);
    }
    redirect('give_feedback.php');
}

// ---- รายการคำแนะนำทั้งหมดของผู้ใช้ พร้อมสถานะว่าให้ feedback แล้วหรือยัง ----
$recs = $pdo->prepare('
    SELECT r.recommend_id, r.recommended_crop, r.reason, r.created_at,
           p.plot_name, fm.farm_name,
           fb.feedback_id, fb.followed, fb.outcome_rating, fb.outcome_description, fb.yield_amount, fb.is_public, fb.admin_approved,
           gp.plan_id, gp.status AS plan_status, gp.start_date AS plan_start, gp.harvest_date AS plan_harvest, gp.yield_amount AS plan_yield
    FROM ai_recommendations r
    JOIN plots p ON p.plot_id = r.plot_id
    JOIN farms fm ON fm.farm_id = p.farm_id
    LEFT JOIN recommendation_feedback fb ON fb.recommend_id = r.recommend_id AND fb.user_id = ?
    LEFT JOIN grow_plans gp ON gp.plan_id = (
        SELECT g2.plan_id FROM grow_plans g2 WHERE g2.recommend_id = r.recommend_id AND g2.user_id = ? AND g2.status <> "cancelled"
        ORDER BY (g2.status = "harvested") DESC, g2.plan_id DESC LIMIT 1)
    WHERE fm.user_id = ?
    ORDER BY r.created_at DESC
');
$recs->execute([$uid, $uid, $uid]);
$recs = $recs->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar_user.php'; ?>
  <main class="main-content">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>

    <div class="ai-box mb-4">
      <h6 class="fw-bold mb-1"><i class="bi bi-reply-fill text-success"></i> บอกผลลัพธ์หลังทำตามคำแนะนำ</h6>
      <p class="small text-muted mb-0">ทำตามคำแนะนำของ AI แล้วผลเป็นอย่างไรบ้าง? บอกเราได้เลย — ถ้าอนุญาตให้เผยแพร่ ข้อมูลของคุณ (ไม่ระบุตัวตน) จะช่วยให้เกษตรกรท่านอื่นเรียนรู้จากประสบการณ์จริงผ่านหน้า "กรณีศึกษา" ได้ด้วย</p>
    </div>

    <?php if (!$recs): ?>
      <div class="alert alert-warning">ยังไม่มีคำแนะนำจาก AI ให้รายงานผล <a href="ai_advice.php">ไปขอคำแนะนำก่อน</a></div>
    <?php endif; ?>

    <?php foreach ($recs as $r): ?>
      <div class="app-card p-4 mb-3">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
          <div>
            <h6 class="fw-bold mb-1">🌾 <?= e($r['recommended_crop']) ?></h6>
            <p class="small text-muted mb-1"><?= e($r['farm_name']) ?> / <?= e($r['plot_name']) ?> — <?= date('d/m/Y', strtotime($r['created_at'])) ?></p>
            <p class="small mb-0"><?= e(mb_strimwidth($r['reason'], 0, 150, '...')) ?></p>
          </div>
          <div>
            <?php if ($r['feedback_id']): ?>
              <span class="badge badge-soft-green"><i class="bi bi-check-circle"></i> ให้ผลตอบกลับแล้ว</span>
            <?php else: ?>
              <span class="badge badge-soft-amber">ยังไม่ได้ให้ผลตอบกลับ</span>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($r['plan_id']):
            $planDays = $r['plan_harvest'] ? (int) (new DateTime($r['plan_start']))->diff(new DateTime($r['plan_harvest']))->days + 1 : null; ?>
          <div class="alert alert-success small py-2 mt-3 mb-0">
            <i class="bi bi-calendar2-check me-1"></i>
            <?php if ($r['plan_status'] === 'harvested'): ?>
              คุณปลูกตามคำแนะนำนี้จริงแล้ว <b><?= $planDays ?> วัน</b><?= $r['plan_yield'] !== null ? ' ได้ผลผลิต <b>' . number_format((float) $r['plan_yield'], 2) . ' กก.</b>' : '' ?> (ดึงมาจากแผนการปลูกให้อัตโนมัติ) <a href="grow_plan.php?plan=<?= (int) $r['plan_id'] ?>">ดูแผน</a>
            <?php else: ?>
              คุณกำลังปลูกตามคำแนะนำนี้อยู่ เก็บเกี่ยวแล้วให้บันทึกที่ <a href="grow_plan.php?plan=<?= (int) $r['plan_id'] ?>">หน้าแผนการปลูก</a> ระบบจะเติมผลผลิตให้ผลตอบกลับอัตโนมัติ
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <button class="btn btn-outline-app btn-sm mt-3" type="button" data-bs-toggle="collapse" data-bs-target="#fb<?= $r['recommend_id'] ?>">
          <?= $r['feedback_id'] ? 'แก้ไขผลตอบกลับ' : 'ให้ผลตอบกลับ' ?> <i class="bi bi-chevron-down"></i>
        </button>

        <div class="collapse <?= isset($_GET['open']) && (int)$_GET['open'] === (int)$r['recommend_id'] ? 'show' : '' ?>" id="fb<?= $r['recommend_id'] ?>">
          <form method="post" enctype="multipart/form-data" class="mt-3 pt-3 border-top">
            <?= csrfField() ?>
            <input type="hidden" name="recommend_id" value="<?= $r['recommend_id'] ?>">

            <div class="mb-3">
              <label class="form-label">ได้นำคำแนะนำนี้ไปใช้จริงหรือไม่?</label>
              <div class="d-flex gap-2">
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="followed" value="1" id="fy<?= $r['recommend_id'] ?>" <?= ($r['followed'] == 1 || (!$r['feedback_id'] && $r['plan_id'])) ? 'checked' : '' ?> required>
                  <label class="form-check-label" for="fy<?= $r['recommend_id'] ?>">ทำตามแล้ว</label>
                </div>
                <div class="form-check ms-4">
                  <input class="form-check-input" type="radio" name="followed" value="0" id="fn<?= $r['recommend_id'] ?>" <?= $r['feedback_id'] && $r['followed'] == 0 ? 'checked' : '' ?>>
                  <label class="form-check-label" for="fn<?= $r['recommend_id'] ?>">ยังไม่ได้ทำตาม</label>
                </div>
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label">ผลลัพธ์เป็นอย่างไรบ้าง</label>
              <textarea name="outcome_description" class="form-control" rows="3" placeholder="เช่น ปลูกแล้วโตดี ผลผลิตเพิ่มขึ้น หรือเจอปัญหาอะไรบ้าง"><?= e($r['outcome_description'] ?? '') ?></textarea>
            </div>

            <div class="row">
              <div class="col-md-6 mb-3">
                <label class="form-label">คะแนนความพึงพอใจต่อผลลัพธ์ (1-5)</label>
                <select name="outcome_rating" class="form-select">
                  <option value="">— ไม่ระบุ —</option>
                  <?php for ($i = 1; $i <= 5; $i++): ?>
                    <option value="<?= $i ?>" <?= (int)($r['outcome_rating'] ?? 0) === $i ? 'selected' : '' ?>><?= $i ?> <?= str_repeat('⭐', $i) ?></option>
                  <?php endfor; ?>
                </select>
              </div>
              <div class="col-md-6 mb-3">
                <label class="form-label">ผลผลิตที่ได้จริง (กก. ถ้ามี)</label>
                <input type="number" step="0.01" name="yield_amount" class="form-control" value="<?= e($r['yield_amount'] ?? ($r['plan_status'] === 'harvested' ? ($r['plan_yield'] ?? '') : '')) ?>">
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label">รูปผลลัพธ์ประกอบ (ถ้ามี)</label>
              <input type="file" name="result_image" accept="image/*" class="form-control">
            </div>

            <div class="form-check mb-3">
              <input class="form-check-input" type="checkbox" name="is_public" id="pub<?= $r['recommend_id'] ?>" <?= $r['is_public'] ? 'checked' : '' ?>>
              <label class="form-check-label" for="pub<?= $r['recommend_id'] ?>">
                อนุญาตให้นำผลลัพธ์นี้ไปแสดงเป็น "กรณีศึกษา" ให้เกษตรกรท่านอื่นดู (จะไม่แสดงชื่อ-ที่อยู่ของคุณ)
              </label>
            </div>

            <button class="btn btn-primary-app"><i class="bi bi-save me-1"></i> บันทึกผลตอบกลับ</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </main>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
