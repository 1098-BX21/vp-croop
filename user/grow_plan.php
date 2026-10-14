<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
$pageTitle = 'แผนการปลูกจริง';
$uid = currentUser()['user_id'];
$pdo = getDBConnection();

/* ======================= การกระทำ (POST) ======================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf('grow_plan.php');
    $action = $_POST['action'] ?? '';
    $planId = (int) ($_POST['plan_id'] ?? 0);

    if ($action === 'create') {
        $recommendId = (int) ($_POST['recommend_id'] ?? 0) ?: null;
        $cropId = (int) ($_POST['crop_id'] ?? 0);
        $plotId = (int) ($_POST['plot_id'] ?? 0);
        $err = null;
        if ($recommendId) {
            // มาจากคำแนะนำ AI: แปลงต้องเป็นแปลงของคำแนะนำนั้น (ไม่รับค่าแปลงจากฟอร์ม)
            $rec = growCheckRecommendation($pdo, $uid, $recommendId, $cropId, $err);
            $plotId = $rec ? (int) $rec['plot_id'] : 0;
        }
        $newId = $err ? null : growCreatePlan($pdo, $uid, $plotId, $cropId, trim($_POST['start_date'] ?? ''), $recommendId, $err);
        if ($newId) {
            flash('success', 'สร้างแผนการปลูกเรียบร้อยแล้ว ติดตามความคืบหน้าและติ๊กเมื่อทำเสร็จแต่ละระยะได้ที่หน้านี้');
            redirect('grow_plan.php?plan=' . $newId);
        }
        flash('error', $err ?: 'สร้างแผนไม่สำเร็จ');
        redirect('grow_plan.php');
    }

    $back = 'grow_plan.php?plan=' . $planId;
    if ($action === 'toggle') {
        growToggleStage($pdo, $uid, $planId, (int) ($_POST['order'] ?? 0), !empty($_POST['done']));
        redirect($back . '#stage' . (int) ($_POST['order'] ?? 0));
    }
    if ($action === 'note') {
        growSaveNote($pdo, $uid, $planId, (int) ($_POST['order'] ?? 0), $_POST['note'] ?? '') ? flash('success', 'บันทึกโน้ตแล้ว') : flash('error', 'ไม่พบแผนนี้');
        redirect($back . '#stage' . (int) ($_POST['order'] ?? 0));
    }
    if ($action === 'skip') {
        // ไม่ได้ทำตามขั้นตอนนี้: ต้องเลือกเหตุผล แล้วพาไปขั้นตอนถัดไป
        $ord = (int) ($_POST['order'] ?? 0);
        $err = null;
        if (growSkipStage($pdo, $uid, $planId, $ord, trim($_POST['reason_type'] ?? ''), $_POST['reason_text'] ?? '', $err)) {
            flash('success', 'บันทึกการข้ามขั้นตอนพร้อมเหตุผลแล้ว ไปขั้นตอนถัดไปได้เลย');
            redirect($back . '#stage' . ($ord + 1));
        }
        flash('error', $err);
        redirect($back . '#stage' . $ord);
    }
    if ($action === 'unskip') {
        $ord = (int) ($_POST['order'] ?? 0);
        growUnskipStage($pdo, $uid, $planId, $ord) ? flash('success', 'ยกเลิกการข้ามแล้ว') : flash('error', 'ไม่พบแผนนี้');
        redirect($back . '#stage' . $ord);
    }
    if ($action === 'harvest') {
        $y = ($_POST['yield_amount'] ?? '') !== '' ? (float) $_POST['yield_amount'] : null;
        $err = null;
        if (growHarvest($pdo, $uid, $planId, trim($_POST['harvest_date'] ?? ''), $y, $err)) {
            flash('success', 'บันทึกการเก็บเกี่ยวแล้ว ผลผลิตถูกบันทึกลงประวัติการปลูกและรายงานให้อัตโนมัติ');
        } else {
            flash('error', $err);
        }
        redirect($back);
    }
    if ($action === 'regen') {
        $err = null;
        growRegenerateAi($pdo, $uid, $planId, $err) ? flash('success', 'ให้ AI วางแผนใหม่เรียบร้อยแล้ว (ความคืบหน้าที่ติ๊กไว้ไม่เปลี่ยน)') : flash('error', 'AI วางแผนไม่สำเร็จ: ' . $err);
        redirect($back);
    }
    if ($action === 'cancel') {
        $err = null;
        growCancel($pdo, $uid, $planId, $err) ? flash('success', 'ยกเลิกแผนแล้ว รายการที่สร้างในประวัติการปลูกถูกลบออกด้วย') : flash('error', $err);
        redirect('grow_plan.php');
    }
    redirect('grow_plan.php');
}

/* ======================= ข้อมูลแสดงผล ======================= */
$view = null;
if (isset($_GET['plan'])) {
    $view = growLoadPlan($pdo, $uid, (int) $_GET['plan']);
    if (!$view) {
        flash('error', 'ไม่พบแผนการปลูกนี้');
        redirect('grow_plan.php');
    }
}

$plots = $pdo->prepare('SELECT p.plot_id, p.plot_name, f.farm_name FROM plots p JOIN farms f ON f.farm_id = p.farm_id WHERE f.user_id = ? ORDER BY f.farm_name, p.plot_name');
$plots->execute([$uid]);
$plots = $plots->fetchAll();
$crops = $pdo->query('SELECT crop_id, crop_name FROM crops ORDER BY crop_name')->fetchAll();

$fmtKg = fn($v) => number_format((float) $v, 2);

require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar_user.php'; ?>
  <main class="main-content">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>

<?php if ($view): /* ============================ หน้าแผนหนึ่งแผน ============================ */
    $stages = $view['stages'];
    $pg = growPlanProgress($stages, $view['start_date']);
    $isActive = $view['status'] === 'active';
    $focus = $isActive && $pg['focus'] !== null ? $stages[$pg['focus']] : null;
    $stateColor = ['done' => '#2f7a66', 'skipped' => '#8d99a6', 'current' => '#2eaf8a', 'overdue' => '#dc3545', 'upcoming' => '#adb5bd'];
    $stateBadge = ['done' => 'badge-soft-green', 'skipped' => 'badge-soft-amber', 'current' => 'badge-soft-blue', 'overdue' => 'badge-soft-red', 'upcoming' => 'badge-soft-amber'];
    $aiWritten = !empty($view['explained_by']);
    $beforeStart = $pg['day'] < 1;
?>
    <a href="grow_plan.php" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> แผนการปลูกทั้งหมด</a>

    <div class="app-card p-4 my-3">
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
          <h5 class="fw-bold mb-1"><i class="bi bi-flower1 text-success me-1"></i> <?= e($view['crop_name']) ?>
            <span class="badge <?= $view['status'] === 'active' ? 'badge-soft-green' : ($view['status'] === 'harvested' ? 'badge-soft-blue' : 'badge-soft-red') ?> ms-1"><?= e(GROW_STATUS_TH[$view['status']] ?? $view['status']) ?></span></h5>
          <div class="small text-muted"><?= e($view['farm_name']) ?> / <?= e($view['plot_name']) ?> · เริ่มปลูก <?= formatDateThai($view['start_date']) ?> · คาดว่าเก็บเกี่ยว <?= formatDateThai($pg['harvest_date']) ?> (รวม <?= $pg['total_days'] ?> วัน)</div>
        </div>
        <?php if ($isActive): ?>
        <div class="d-flex gap-2 flex-wrap">
          <form method="post" class="js-wait" data-wait-text="AI กำลังวางแผน...">
            <?= csrfField() ?><input type="hidden" name="action" value="regen"><input type="hidden" name="plan_id" value="<?= (int) $view['plan_id'] ?>">
            <button class="btn btn-outline-app btn-sm"><i class="bi bi-stars me-1"></i> ให้ AI วางแผนอีกครั้ง</button></form>
          <form method="post" onsubmit="return confirm('ยืนยันการยกเลิกแผนนี้? รายการที่สร้างในประวัติการปลูกจะถูกลบด้วย')">
            <?= csrfField() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="plan_id" value="<?= (int) $view['plan_id'] ?>">
            <button class="btn btn-outline-danger btn-sm"><i class="bi bi-x-circle me-1"></i> ยกเลิกแผน</button></form>
        </div>
        <?php endif; ?>
      </div>

      <?php if ($isActive): ?>
        <div class="mt-3">
          <div class="d-flex justify-content-between small mb-1">
            <span><b><?= $beforeStart ? 'ยังไม่ถึงวันปลูก (อีก ' . (1 - $pg['day']) . ' วัน)' : 'วันที่ ' . min($pg['day'], $pg['total_days']) . ' จาก ' . $pg['total_days'] ?></b></span>
            <span class="text-muted">ทำเสร็จแล้ว <?= $pg['done_count'] ?>/<?= $pg['stage_count'] ?> ระยะ<?= $pg['skipped_count'] ? ' · ข้าม ' . $pg['skipped_count'] . ' ระยะ' : '' ?></span>
          </div>
          <div class="progress" style="height:12px;"><div class="progress-bar bg-success" style="width:<?= $pg['time_pct'] ?>%"></div></div>
          <?php if ($pg['day'] > $pg['total_days']): ?>
            <div class="alert alert-warning small mt-3 mb-0"><i class="bi bi-flag me-1"></i> ครบกำหนดเก็บเกี่ยวโดยประมาณแล้ว (<?= $pg['day'] - $pg['total_days'] ?> วัน) ถ้าเก็บเกี่ยวแล้ว ให้บันทึกการเก็บเกี่ยวด้านล่าง</div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($view['status'] === 'harvested'):
        $days = (int) (new DateTime($view['start_date']))->diff(new DateTime($view['harvest_date']))->days + 1;
        $income = ($view['yield_amount'] !== null && $view['price']) ? (float) $view['yield_amount'] * (float) $view['price'] : null;
        $fb = null;
        if ($view['recommend_id']) {
            $f = $pdo->prepare('SELECT is_public, admin_approved FROM recommendation_feedback WHERE recommend_id = ? AND user_id = ?');
            $f->execute([$view['recommend_id'], $uid]);
            $fb = $f->fetch() ?: null;
        }
    ?>
      <div class="ai-box mb-4">
        <h6 class="fw-bold"><i class="bi bi-trophy text-success me-1"></i> สรุปการปลูกครั้งนี้</h6>
        <div class="row g-3 text-center">
          <div class="col-md-3 col-6"><div class="fs-4 fw-bold text-success"><?= $days ?></div><div class="small text-muted">วันที่ใช้ปลูกจริง (ประมาณการ <?= $pg['total_days'] ?>)</div></div>
          <div class="col-md-3 col-6"><div class="fs-4 fw-bold text-success"><?= $view['yield_amount'] !== null ? $fmtKg($view['yield_amount']) : '-' ?></div><div class="small text-muted">ผลผลิต (กก.)</div></div>
          <div class="col-md-3 col-6"><div class="fs-4 fw-bold text-success"><?= $income !== null ? number_format($income) : '-' ?></div><div class="small text-muted">รายได้โดยประมาณ (บาท)</div></div>
          <div class="col-md-3 col-6"><div class="fs-4 fw-bold text-success"><?= formatDateThai($view['harvest_date']) ?></div><div class="small text-muted">วันที่เก็บเกี่ยว</div></div>
        </div>
        <?php $skippedStages = array_values(array_filter($stages, fn($x) => !empty($x['is_skipped']))); ?>
        <?php if ($pg['done_count'] + $pg['skipped_count'] > 0): ?>
          <hr>
          <h6 class="fw-bold mb-1"><i class="bi bi-list-check text-success me-1"></i> การทำตามแผน</h6>
          <p class="small mb-1">ทำตามแผนแล้ว <b><?= $pg['done_count'] ?>/<?= $pg['stage_count'] ?></b> ระยะ<?= $skippedStages ? ' · ไม่ได้ทำตาม <b>' . count($skippedStages) . '</b> ระยะ' : '' ?></p>
          <?php if ($skippedStages): ?>
            <ul class="small mb-0 ps-3">
              <?php foreach ($skippedStages as $sk): ?><li><b><?= e($sk['stage_name']) ?>:</b> <?= e($sk['skip_reason']) ?></li><?php endforeach; ?>
            </ul>
          <?php endif; ?>
        <?php endif; ?>
        <hr>
        <h6 class="fw-bold mb-1"><i class="bi bi-megaphone text-success me-1"></i> แบ่งปันผลลัพธ์ให้เกษตรกรท่านอื่น</h6>
        <?php if (!$view['recommend_id']): ?>
          <p class="small text-muted mb-0">แผนนี้สร้างเอง ไม่ได้ผูกกับคำแนะนำของ AI จึงยังไม่มีผลตอบกลับให้รายงาน (ผลตอบกลับใช้ได้กับแผนที่เริ่มจากคำแนะนำ AI)</p>
        <?php elseif ($fb): ?>
          <p class="small mb-1">คุณรายงานผลตอบกลับแล้ว
            <?php if ($fb['is_public'] && $fb['admin_approved']): ?><span class="badge badge-soft-green">แสดงบนหน้าเว็บไซต์แล้ว</span>
            <?php elseif ($fb['is_public']): ?><span class="badge badge-soft-amber">รอผู้ดูแลระบบอนุมัติก่อนขึ้นเว็บไซต์</span>
            <?php else: ?><span class="badge badge-soft-blue">ไม่เผยแพร่</span><?php endif; ?></p>
          <a href="give_feedback.php?open=<?= (int) $view['recommend_id'] ?>" class="btn btn-outline-app btn-sm">ดู/แก้ไขผลตอบกลับ</a>
        <?php else: ?>
          <p class="small text-muted">เล่าผลลัพธ์ที่ได้ ถ้าเลือก "อนุญาตให้เผยแพร่" ผู้ดูแลระบบจะตรวจก่อน แล้วจึงแสดงเป็นกรณีศึกษา <b>แบบไม่ระบุตัวตน</b> บนหน้าเว็บไซต์ (พร้อมจำนวนวันที่ปลูกและผลผลิตของคุณ) เพื่อช่วยให้เกษตรกรท่านอื่นเรียนรู้</p>
          <a href="give_feedback.php?open=<?= (int) $view['recommend_id'] ?>" class="btn btn-primary-app btn-sm"><i class="bi bi-reply me-1"></i> รายงานผลตอบกลับ</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($focus): $fs = $stages[$pg['focus']]; $fstate = $pg['states'][$pg['focus']]; ?>
      <div class="app-card p-4 mb-4 border border-2 <?= $fstate === 'overdue' ? 'border-danger' : 'border-success' ?>">
        <div class="small text-muted mb-1"><?= $fstate === 'upcoming' ? 'ระยะถัดไป' : ($fstate === 'overdue' ? 'ควรจัดการต่อ (เลยกำหนด)' : 'ตอนนี้อยู่ในระยะ') ?></div>
        <h5 class="fw-bold mb-1"><?= e($fs['stage_name']) ?> <span class="small text-muted fw-normal">วันที่ <?= $fs['day_from'] ?>–<?= $fs['day_to'] ?> · <?= growRangeThai($view['start_date'], $fs['day_from'], $fs['day_to']) ?></span></h5>
        <?php if ($fstate === 'upcoming'): ?>
          <p class="small text-muted mb-2">เริ่มอีก <?= max(0, $fs['day_from'] - $pg['day']) ?> วัน</p>
        <?php elseif ($fstate === 'overdue'): ?>
          <p class="small text-danger mb-2"><i class="bi bi-exclamation-circle me-1"></i> เลยกำหนดมา <?= $pg['day'] - $fs['day_to'] ?> วัน ถ้าทำเสร็จแล้วให้กด "ทำเสร็จแล้ว" ที่ระยะนี้</p>
        <?php endif; ?>
        <p class="mb-1"><i class="bi bi-check2-square text-success me-1"></i><b>งานที่ควรทำ:</b> <?= nl2br(e($fs['tasks'] ?: $fs['description'] ?: '-')) ?></p>
        <?php if ($fs['watch']): ?><p class="mb-2 text-warning-emphasis"><i class="bi bi-exclamation-triangle me-1"></i><b>ระวัง:</b> <?= nl2br(e($fs['watch'])) ?></p><?php endif; ?>
        <a href="#stage<?= (int) $fs['stage_order'] ?>" class="btn btn-sm btn-outline-app"><i class="bi bi-arrow-down-circle me-1"></i> ติ๊ก "ทำเสร็จแล้ว" หรือ "ไม่ได้ทำตาม" ที่ระยะนี้</a>
      </div>
    <?php elseif ($isActive): ?>
      <div class="alert alert-success"><i class="bi bi-check-circle me-1"></i> ทำครบทุกระยะแล้ว ถ้าเก็บเกี่ยวเรียบร้อย ให้บันทึกการเก็บเกี่ยวด้านล่าง</div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
      <div class="col-lg-7">
        <div class="app-card p-4 h-100">
          <h6 class="fw-bold"><i class="bi bi-stars text-success me-1"></i> ภาพรวมแผนจาก AI</h6>
          <?php if ($aiWritten): ?>
            <p class="mb-2"><?= nl2br(e($view['overview'] ?: '-')) ?></p>
            <p class="small text-muted mb-0">เขียนโดย: <?= e($view['explained_by']) ?> · ระยะและจำนวนวันเป็นค่ามาตรฐานของพืช (โดยประมาณ ขึ้นกับพันธุ์/ฤดูกาล/สภาพอากาศ)</p>
          <?php else: ?>
            <div class="alert alert-warning small mb-0"><i class="bi bi-exclamation-triangle me-1"></i> AI ยังไม่ได้เขียนแผนให้ (อาจไม่พร้อมในขณะสร้างแผน) จึงแสดงคำแนะนำมาตรฐานของแต่ละระยะแทน
              <?php if ($isActive): ?>กดปุ่ม "ให้ AI วางแผนอีกครั้ง" ด้านบนเพื่อให้ AI เขียนงานที่ควรทำให้เข้ากับแปลงของคุณ<?php endif; ?></div>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="app-card p-4 h-100">
          <h6 class="fw-bold"><i class="bi bi-shield-exclamation text-warning me-1"></i> ข้อควรระวังหลัก</h6>
          <p class="mb-0"><?= $view['risks'] ? nl2br(e($view['risks'])) : '<span class="text-muted">ยังไม่มีข้อมูล</span>' ?></p>
        </div>
      </div>
    </div>

    <h6 class="fw-bold mb-3"><i class="bi bi-calendar2-week text-success me-1"></i> ระยะการปลูกทั้งหมด</h6>
    <?php foreach ($stages as $i => $s):
        $st = $pg['states'][$i];
        $len = (int) $s['day_to'] - (int) $s['day_from'] + 1;
    ?>
      <div class="d-flex gap-3" id="stage<?= (int) $s['stage_order'] ?>">
        <div class="text-center flex-shrink-0" style="width:40px;">
          <span class="d-inline-flex align-items-center justify-content-center rounded-circle fw-bold text-white" style="width:36px;height:36px;background:<?= $stateColor[$st] ?>;"><?= $st === 'done' ? '<i class="bi bi-check-lg"></i>' : ($st === 'skipped' ? '<i class="bi bi-skip-forward-fill"></i>' : (int) $s['stage_order']) ?></span>
          <?php if ($i < count($stages) - 1): ?><div style="width:2px;background:#cfe8df;margin:4px auto 0;height:calc(100% - 40px);min-height:24px;"></div><?php endif; ?>
        </div>
        <div class="app-card p-3 flex-grow-1 mb-3 <?= $isActive && $pg['focus'] === $i ? 'border border-success' : '' ?>">
          <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
            <div>
              <div class="fw-bold"><?= e($s['stage_name']) ?></div>
              <div class="small text-muted">วันที่ <?= $s['day_from'] == $s['day_to'] ? $s['day_from'] : $s['day_from'] . '–' . $s['day_to'] ?> · <?= growRangeThai($view['start_date'], $s['day_from'], $s['day_to']) ?> · <?= $len ?> วัน</div>
            </div>
            <span class="badge <?= $stateBadge[$st] ?>"><?= e(GROW_STATE_TH[$st]) ?><?= $st === 'done' && $s['done_at'] ? ' ' . date('j/n', strtotime($s['done_at'])) : '' ?></span>
          </div>
          <?php if ($s['description']): ?><p class="small text-muted mt-2 mb-1"><?= e($s['description']) ?></p><?php endif; ?>
          <?php if ($s['tasks']): ?><p class="small mb-1"><i class="bi bi-check2-square text-success me-1"></i><b>งานที่ควรทำ:</b> <?= nl2br(e($s['tasks'])) ?></p><?php endif; ?>
          <?php if ($s['watch']): ?><p class="small mb-1"><i class="bi bi-exclamation-triangle text-warning me-1"></i><b>ระวัง:</b> <?= nl2br(e($s['watch'])) ?></p><?php endif; ?>
          <?php if ($st === 'skipped'): ?>
            <div class="small p-2 rounded mb-1" style="background:#fff7ed;border:1px solid #fed7aa;">
              <i class="bi bi-skip-forward-fill me-1"></i><b>ไม่ได้ทำตามขั้นตอนนี้</b> — เหตุผล: <?= e($s['skip_reason']) ?>
              <?php if ($s['skipped_at']): ?><span class="text-muted">(<?= date('j/n/Y', strtotime($s['skipped_at'])) ?>)</span><?php endif; ?>
            </div>
          <?php endif; ?>
          <?php if ($s['note']): ?><p class="small mb-1 p-2 rounded" style="background:#f6f8f7;"><i class="bi bi-pencil me-1"></i><?= nl2br(e($s['note'])) ?></p><?php endif; ?>
          <?php if ($isActive): ?>
          <div class="d-flex flex-wrap gap-2 mt-2">
            <form method="post">
              <?= csrfField() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="plan_id" value="<?= (int) $view['plan_id'] ?>">
              <input type="hidden" name="order" value="<?= (int) $s['stage_order'] ?>"><input type="hidden" name="done" value="<?= $s['is_done'] ? 0 : 1 ?>">
              <button class="btn btn-sm <?= $s['is_done'] ? 'btn-outline-secondary' : 'btn-primary-app' ?>"><?= $s['is_done'] ? '<i class="bi bi-arrow-counterclockwise me-1"></i>ยกเลิกการติ๊ก' : '<i class="bi bi-check-lg me-1"></i>ทำเสร็จแล้ว' ?></button>
            </form>
            <?php if ($st === 'skipped'): ?>
              <form method="post">
                <?= csrfField() ?><input type="hidden" name="action" value="unskip"><input type="hidden" name="plan_id" value="<?= (int) $view['plan_id'] ?>"><input type="hidden" name="order" value="<?= (int) $s['stage_order'] ?>">
                <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-counterclockwise me-1"></i>ยกเลิกการข้าม</button>
              </form>
            <?php elseif (!$s['is_done']): ?>
              <details>
                <summary class="btn btn-sm btn-outline-secondary" style="list-style:none;cursor:pointer;"><i class="bi bi-skip-forward me-1"></i>ไม่ได้ทำตามขั้นตอนนี้</summary>
                <form method="post" class="mt-2 p-3 rounded-3" style="background:#fff7ed;border:1px solid #fed7aa;min-width:260px;">
                  <?= csrfField() ?><input type="hidden" name="action" value="skip"><input type="hidden" name="plan_id" value="<?= (int) $view['plan_id'] ?>"><input type="hidden" name="order" value="<?= (int) $s['stage_order'] ?>">
                  <label class="form-label small mb-1">เหตุผลที่ไม่ได้ทำตาม <span class="text-danger">*</span></label>
                  <select name="reason_type" class="form-select form-select-sm mb-2" required>
                    <option value="">— เลือกเหตุผล —</option>
                    <?php foreach (GROW_SKIP_REASONS as $rk => $rl): ?><option value="<?= e($rk) ?>"><?= e($rl) ?></option><?php endforeach; ?>
                  </select>
                  <textarea name="reason_text" class="form-control form-control-sm mb-2" rows="2" maxlength="500" placeholder="อธิบายเพิ่มเติม (จำเป็นเมื่อเลือก “ใช้วิธีอื่นแทน” หรือ “อื่นๆ”)"></textarea>
                  <button class="btn btn-sm btn-primary-app">ยืนยัน แล้วไปขั้นตอนถัดไป</button>
                </form>
              </details>
            <?php endif; ?>
            <details>
              <summary class="btn btn-sm btn-outline-app" style="list-style:none;cursor:pointer;"><i class="bi bi-pencil me-1"></i>บันทึกโน้ต</summary>
              <form method="post" class="mt-2">
                <?= csrfField() ?><input type="hidden" name="action" value="note"><input type="hidden" name="plan_id" value="<?= (int) $view['plan_id'] ?>"><input type="hidden" name="order" value="<?= (int) $s['stage_order'] ?>">
                <textarea name="note" class="form-control form-control-sm mb-1" rows="2" maxlength="1000" placeholder="เช่น ใส่ปุ๋ยไปเมื่อวันที่..., พบหนอนที่แปลงมุมซ้าย"><?= e($s['note'] ?? '') ?></textarea>
                <button class="btn btn-sm btn-primary-app">บันทึก</button>
              </form>
            </details>
          </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <?php if ($isActive): ?>
      <div class="app-card p-4 mt-2">
        <h6 class="fw-bold"><i class="bi bi-basket text-success me-1"></i> บันทึกการเก็บเกี่ยว</h6>
        <p class="small text-muted">เมื่อเก็บเกี่ยวแล้ว บันทึกวันที่และผลผลิต ระบบจะปิดแผน ลงผลผลิตในประวัติการปลูกและรายงานให้อัตโนมัติ</p>
        <form method="post" class="row g-2 align-items-end">
          <?= csrfField() ?><input type="hidden" name="action" value="harvest"><input type="hidden" name="plan_id" value="<?= (int) $view['plan_id'] ?>">
          <div class="col-md-4"><label class="form-label small mb-1">วันที่เก็บเกี่ยว</label><input type="date" name="harvest_date" class="form-control" value="<?= date('Y-m-d') ?>" min="<?= e($view['start_date']) ?>" max="<?= date('Y-m-d') ?>" required></div>
          <div class="col-md-4"><label class="form-label small mb-1">ผลผลิตที่ได้ (กก.)</label><input type="number" step="0.01" min="0" name="yield_amount" class="form-control" placeholder="ไม่ทราบให้เว้นว่าง"></div>
          <div class="col-md-4"><button class="btn btn-primary-app w-100" onclick="return confirm('ยืนยันการบันทึกเก็บเกี่ยวและปิดแผนนี้?')"><i class="bi bi-check2-circle me-1"></i> บันทึกการเก็บเกี่ยว</button></div>
        </form>
      </div>
    <?php endif; ?>

<?php else: /* ============================ รายการแผน ============================ */
    $tab = in_array($_GET['tab'] ?? '', ['active', 'harvested', 'cancelled'], true) ? $_GET['tab'] : 'active';
    $cnt = $pdo->prepare('SELECT status, COUNT(*) FROM grow_plans WHERE user_id = ? GROUP BY status');
    $cnt->execute([$uid]);
    $cnt = $cnt->fetchAll(PDO::FETCH_KEY_PAIR);
    $ls = $pdo->prepare('SELECT g.*, c.crop_name, p.plot_name, f.farm_name FROM grow_plans g JOIN crops c ON c.crop_id = g.crop_id JOIN plots p ON p.plot_id = g.plot_id JOIN farms f ON f.farm_id = p.farm_id WHERE g.user_id = ? AND g.status = ? ORDER BY g.start_date DESC, g.plan_id DESC');
    $ls->execute([$uid, $tab]);
    $plans = $ls->fetchAll();
    $stSt = $pdo->prepare('SELECT * FROM grow_plan_stages WHERE plan_id = ? ORDER BY stage_order');
?>
    <div class="ai-box mb-4">
      <div class="row align-items-center">
        <div class="col-md-8">
          <h6 class="fw-bold mb-1"><i class="bi bi-calendar2-check text-success"></i> แผนการปลูกจริง</h6>
          <p class="small text-muted mb-0">เมื่อนำพืชที่ AI แนะนำไปปลูกจริง ระบบสร้างแผนตามระยะ (เช่น เพาะเมล็ด 1–3 วัน → ต้นกล้า → โตทางลำต้น → เก็บเกี่ยว) ให้ AI เขียนงานที่ควรทำในแต่ละระยะตามสภาพแปลงของคุณ และติดตามความคืบหน้าจนเก็บเกี่ยว</p>
        </div>
        <div class="col-md-4 text-md-end mt-3 mt-md-0"><a href="ai_advice.php" class="btn btn-primary-app"><i class="bi bi-stars me-1"></i> ขอคำแนะนำพืชจาก AI</a></div>
      </div>
    </div>

    <ul class="nav nav-pills mb-3">
      <?php foreach (['active' => 'กำลังปลูก', 'harvested' => 'เก็บเกี่ยวแล้ว', 'cancelled' => 'ยกเลิก'] as $k => $lb): ?>
        <li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active' : '' ?>" href="grow_plan.php?tab=<?= $k ?>"><?= $lb ?> <span class="badge bg-light text-dark"><?= (int) ($cnt[$k] ?? 0) ?></span></a></li>
      <?php endforeach; ?>
    </ul>

    <?php if (!$plans): ?>
      <div class="app-card p-4 text-center text-muted mb-4">
        <?= $tab === 'active' ? 'ยังไม่มีแผนที่กำลังปลูก เริ่มจากการขอคำแนะนำ AI แล้วกด "ถ้านำไปปลูกจริง" ที่พืชที่ต้องการ หรือสร้างแผนเองด้านล่าง' : 'ยังไม่มีรายการ' ?>
      </div>
    <?php else: ?>
      <div class="row g-3 mb-4">
        <?php foreach ($plans as $pl):
            $stSt->execute([$pl['plan_id']]);
            $pst = $stSt->fetchAll();
            $pp = growPlanProgress($pst, $pl['start_date']);
            $fs = $pp['focus'] !== null ? $pst[$pp['focus']] : null;
        ?>
          <div class="col-md-6 col-xl-4">
            <a href="grow_plan.php?plan=<?= (int) $pl['plan_id'] ?>" class="text-decoration-none text-reset">
              <div class="app-card p-3 h-100">
                <div class="d-flex justify-content-between"><div class="fw-bold"><i class="bi bi-flower1 text-success me-1"></i><?= e($pl['crop_name']) ?></div>
                  <?php if ($tab === 'active' && $pp['overdue_count'] > 0): ?><span class="badge badge-soft-red">เลยกำหนด <?= $pp['overdue_count'] ?> ระยะ</span><?php endif; ?></div>
                <div class="small text-muted mb-2"><?= e($pl['farm_name']) ?> / <?= e($pl['plot_name']) ?> · เริ่ม <?= formatDateThai($pl['start_date']) ?></div>
                <?php if ($tab === 'active'): ?>
                  <div class="progress mb-1" style="height:8px;"><div class="progress-bar bg-success" style="width:<?= $pp['time_pct'] ?>%"></div></div>
                  <div class="small mb-1">วันที่ <?= max(0, min($pp['day'], $pp['total_days'])) ?>/<?= $pp['total_days'] ?> · เสร็จ <?= $pp['done_count'] ?>/<?= $pp['stage_count'] ?> ระยะ<?= $pp['skipped_count'] ? ' · ข้าม ' . $pp['skipped_count'] : '' ?></div>
                  <div class="small"><?= $fs ? 'ระยะ: <b>' . e($fs['stage_name']) . '</b>' : 'ทำครบทุกระยะแล้ว' ?></div>
                  <div class="small text-muted">เก็บเกี่ยวคาดว่า <?= formatDateThai($pp['harvest_date']) ?></div>
                <?php elseif ($tab === 'harvested'): ?>
                  <div class="small">เก็บเกี่ยว <?= formatDateThai($pl['harvest_date']) ?> · ผลผลิต <?= $pl['yield_amount'] !== null ? $fmtKg($pl['yield_amount']) . ' กก.' : '-' ?></div>
                <?php else: ?>
                  <div class="small text-muted">ยกเลิกแล้ว</div>
                <?php endif; ?>
              </div>
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <details class="app-card p-3">
      <summary class="fw-semibold" style="cursor:pointer;"><i class="bi bi-plus-circle text-success me-1"></i> สร้างแผนเอง (ไม่ผ่านคำแนะนำ AI)</summary>
      <?php if (!$plots): ?>
        <p class="small text-muted mt-3 mb-0">ยังไม่มีแปลงเพาะปลูก <a href="quick_setup.php">เริ่มต้นแบบเร็วที่นี่</a></p>
      <?php else: ?>
      <form method="post" class="row g-2 align-items-end mt-2 js-wait" data-wait-text="กำลังวางแผน... (AI อาจใช้เวลาสักครู่)">
        <?= csrfField() ?><input type="hidden" name="action" value="create">
        <div class="col-md-4"><label class="form-label small mb-1">แปลง</label>
          <select name="plot_id" class="form-select" required><?php foreach ($plots as $p): ?><option value="<?= $p['plot_id'] ?>"><?= e($p['farm_name'] . ' - ' . $p['plot_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label small mb-1">พืช</label>
          <select name="crop_id" class="form-select" required><?php foreach ($crops as $c): ?><option value="<?= $c['crop_id'] ?>"><?= e($c['crop_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label small mb-1">วันที่เริ่มปลูก</label><input type="date" name="start_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
        <div class="col-md-2"><button class="btn btn-primary-app w-100">สร้างแผน</button></div>
      </form>
      <?php endif; ?>
    </details>
<?php endif; ?>

  </main>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
