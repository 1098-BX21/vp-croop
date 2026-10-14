<?php $u = currentUser(); ?>
<div class="topbar">
  <div class="d-flex align-items-center gap-2">
    <button class="btn btn-light d-lg-none" id="sidebarToggle"><i class="bi bi-list"></i></button>
    <h4><?= e($pageTitle ?? '') ?></h4>
  </div>
  <div class="d-flex align-items-center gap-3">
    <div class="datetime-th d-none d-md-flex align-items-center gap-2" id="thaiDateTime">
      <i class="bi bi-calendar3 text-success"></i>
      <span id="thaiDateTimeText">กำลังโหลดเวลา...</span>
    </div>
    <div class="user-chip">
      <div class="avatar">
        <?php if (!empty($u['avatar'])): ?>
          <img src="<?= BASE_URL ?>/uploads/avatars/<?= e($u['avatar']) ?>" alt="avatar">
        <?php else: ?>
          <?= e(mb_substr($u['full_name'], 0, 1)) ?>
        <?php endif; ?>
      </div>
      <div class="small">
        <div class="fw-bold"><?= e($u['full_name']) ?></div>
        <div class="text-muted" style="font-size:.75rem;"><?= $u['role'] === 'admin' ? 'ผู้ดูแลระบบ' : 'เกษตรกร' ?></div>
      </div>
    </div>
  </div>
</div>
<?php if ($msg = flash('success')): ?>
  <div class="alert alert-success alert-dismissible fade show"><?= e($msg) ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($msg = flash('error')): ?>
  <div class="alert alert-danger alert-dismissible fade show"><?= e($msg) ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<script>
(function () {
  var thMonths = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
  var thDays = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
  var el = document.getElementById('thaiDateTimeText');
  if (!el) return;

  function pad(n) { return n < 10 ? '0' + n : '' + n; }

  function render() {
    // ใช้เวลาไทย (Asia/Bangkok) เสมอ ไม่ว่าผู้ใช้จะอยู่โซนเวลาไหน
    var now = new Date();
    var parts = new Intl.DateTimeFormat('en-US', {
      timeZone: 'Asia/Bangkok', hour12: false,
      year: 'numeric', month: 'numeric', day: 'numeric',
      hour: '2-digit', minute: '2-digit', second: '2-digit', weekday: 'short'
    }).formatToParts(now).reduce(function (acc, p) { acc[p.type] = p.value; return acc; }, {});

    var weekdayIndex = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 }[parts.weekday];
    var buddhistYear = parseInt(parts.year, 10) + 543;
    var text = 'วัน' + thDays[weekdayIndex] + 'ที่ ' + parseInt(parts.day, 10) + ' ' +
      thMonths[parseInt(parts.month, 10) - 1] + ' พ.ศ. ' + buddhistYear +
      ' เวลา ' + parts.hour + ':' + parts.minute + ':' + parts.second + ' น.';
    el.textContent = text;
  }

  render();
  setInterval(render, 1000);
})();
</script>

