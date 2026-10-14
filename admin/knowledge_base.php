<?php
require_once __DIR__ . '/../config/config.php';
requireAdmin();
$pageTitle = 'คลังความรู้ AI (RAG)';
$pdo = getDBConnection();
$uid = currentUser()['user_id'];

// อัปโหลดเอกสารใหม่
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    requireCsrf('knowledge_base.php');
    $title = trim($_POST['title'] ?? '');
    if ($title === '' || empty($_FILES['doc_file']['name'])) {
        flash('error', 'กรุณากรอกชื่อเอกสารและเลือกไฟล์');
        redirect('knowledge_base.php');
    }
    $errorReason = null;
    $upload = uploadDocFile($_FILES['doc_file'], UPLOAD_PATH_KNOWLEDGE, $errorReason);
    if ($upload === null) {
        flash('error', $errorReason ?: 'อัปโหลดไม่สำเร็จ (รองรับเฉพาะ PDF, Word .docx, CSV, TXT, Markdown .md)');
        redirect('knowledge_base.php');
    }
    [$fileName, $fileType] = $upload;

    // บันทึกเอกสาร
    $pdo->prepare('INSERT INTO knowledge_docs (title, file_path, file_type, status, uploaded_by) VALUES (?,?,?,"pending",?)')
        ->execute([$title, $fileName, $fileType, $uid]);
    $docId = (int) $pdo->lastInsertId();

    // จัดทำ index ทันที (สกัดข้อความ → chunk → embedding → เก็บ MySQL)
    $result = ragIndexDocument($docId, UPLOAD_PATH_KNOWLEDGE . $fileName, $fileType);
    if ($result['success']) {
        $pdo->prepare('UPDATE knowledge_docs SET status="indexed", chunk_count=? WHERE doc_id=?')
            ->execute([$result['chunk_count'], $docId]);
        flash('success', 'อัปโหลดและจัดทำดัชนีสำเร็จ (' . $result['chunk_count'] . ' chunks) พร้อมใช้งานกับ AI แล้ว');
    } else {
        $pdo->prepare('UPDATE knowledge_docs SET status="failed" WHERE doc_id=?')->execute([$docId]);
        flash('error', 'อัปโหลดสำเร็จ แต่จัดทำดัชนีไม่สำเร็จ: ' . $result['error']);
    }
    logActivity($uid, 'upload_knowledge', $title);
    redirect('knowledge_base.php');
}

// ลบเอกสาร
if (isset($_GET['delete'])) {
    $docId = (int) $_GET['delete'];
    $stmt = $pdo->prepare('SELECT file_path FROM knowledge_docs WHERE doc_id=?');
    $stmt->execute([$docId]);
    if ($doc = $stmt->fetch()) {
        @unlink(UPLOAD_PATH_KNOWLEDGE . $doc['file_path']);
    }
    $pdo->prepare('DELETE FROM knowledge_docs WHERE doc_id=?')->execute([$docId]); // chunks ลบ cascade
    flash('success', 'ลบเอกสารและดัชนีสำเร็จ');
    redirect('knowledge_base.php');
}

// re-index เอกสารที่ล้มเหลว
if (isset($_GET['reindex'])) {
    $docId = (int) $_GET['reindex'];
    $stmt = $pdo->prepare('SELECT * FROM knowledge_docs WHERE doc_id=?');
    $stmt->execute([$docId]);
    if ($doc = $stmt->fetch()) {
        $result = ragIndexDocument($docId, UPLOAD_PATH_KNOWLEDGE . $doc['file_path'], $doc['file_type']);
        if ($result['success']) {
            $pdo->prepare('UPDATE knowledge_docs SET status="indexed", chunk_count=? WHERE doc_id=?')->execute([$result['chunk_count'], $docId]);
            flash('success', 'จัดทำดัชนีใหม่สำเร็จ (' . $result['chunk_count'] . ' chunks)');
        } else {
            flash('error', 'จัดทำดัชนีไม่สำเร็จ: ' . $result['error']);
        }
    }
    redirect('knowledge_base.php');
}

// จัดทำดัชนีใหม่ทั้งหมด (ใช้หลังเปลี่ยนมาใช้ AI ในเครื่อง หรือเปลี่ยนโมเดล Embedding)
if (isset($_GET['reindex_all'])) {
    @set_time_limit(0);
    $okCount = 0;
    $failCount = 0;
    $lastError = '';
    foreach ($pdo->query('SELECT * FROM knowledge_docs ORDER BY doc_id')->fetchAll() as $doc) {
        $path = UPLOAD_PATH_KNOWLEDGE . $doc['file_path'];
        if (!is_file($path)) {
            $failCount++;
            $lastError = 'ไม่พบไฟล์ ' . $doc['title'];
            $pdo->prepare('UPDATE knowledge_docs SET status="failed" WHERE doc_id=?')->execute([$doc['doc_id']]);
            continue;
        }
        $result = ragIndexDocument((int) $doc['doc_id'], $path, $doc['file_type']);
        if ($result['success']) {
            $okCount++;
            $pdo->prepare('UPDATE knowledge_docs SET status="indexed", chunk_count=? WHERE doc_id=?')->execute([$result['chunk_count'], $doc['doc_id']]);
        } else {
            $failCount++;
            $lastError = $result['error'];
            $pdo->prepare('UPDATE knowledge_docs SET status="failed" WHERE doc_id=?')->execute([$doc['doc_id']]);
        }
    }
    logActivity($uid, 'reindex_all', "ok=$okCount fail=$failCount");
    if ($failCount === 0) {
        flash('success', "จัดทำดัชนีใหม่ครบทุกเอกสารสำเร็จ ($okCount เอกสาร)");
    } else {
        flash('error', "จัดทำดัชนีสำเร็จ $okCount เอกสาร ล้มเหลว $failCount เอกสาร — $lastError");
    }
    redirect('knowledge_base.php');
}

// ทดสอบการค้นหา (ดูคะแนนความคล้ายของ chunk ที่ใกล้เคียงที่สุด เพื่อปรับค่า RAG_MIN_SIMILARITY)
$searchQuery = trim($_GET['q'] ?? '');
$searchResults = null;
if ($searchQuery !== '') {
    $searchResults = ragRetrieve($searchQuery, 0.0, 6);
}

$docs = $pdo->query('
  SELECT k.*, u.full_name FROM knowledge_docs k
  LEFT JOIN users u ON u.user_id = k.uploaded_by
  ORDER BY k.created_at DESC
')->fetchAll();
$usableChunks = ragUsableChunkCount();   // ใช้ค้นได้ (สร้างด้วยโมเดล Embedding ที่ใช้อยู่)
$staleChunks = ragStaleChunkCount();     // สร้างด้วยระบบ/โมเดลเดิม ต้องจัดทำดัชนีใหม่

require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar_admin.php'; ?>
  <main class="main-content">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>

    <div class="ai-box mb-4">
      <div class="row align-items-center">
        <div class="col-md-8">
          <h6 class="fw-bold mb-1"><i class="bi bi-journal-richtext text-success"></i> คลังความรู้สำหรับ AI (RAG)</h6>
          <p class="small text-muted mb-0">อัปโหลดคู่มือ/เอกสารวิชาการเกษตร (PDF, Word .docx, CSV, TXT, Markdown .md) ระบบจะสกัดข้อความ แปลงเป็นเวกเตอร์ด้วยโมเดล <b><?= e(OLLAMA_EMBED_MODEL) ?></b> ในเครื่อง และให้ AI ใช้อ้างอิงในการตอบคำถามและให้คำแนะนำ ปัจจุบันมี <b><?= $usableChunks ?></b> chunks ที่พร้อมใช้งาน<br>
          <span class="badge badge-soft-green mt-1"><i class="bi bi-cpu"></i> AI: <?= e(aiActiveProviderName()) ?></span></p>
        </div>
        <div class="col-md-4 text-md-end mt-3 mt-md-0">
          <button class="btn btn-primary-app" data-bs-toggle="modal" data-bs-target="#uploadModal"><i class="bi bi-upload me-1"></i> อัปโหลดเอกสาร</button>
        </div>
      </div>
    </div>

    <?php if ($staleChunks > 0): ?>
      <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
          <i class="bi bi-exclamation-triangle"></i>
          พบ <b><?= $staleChunks ?></b> chunks ที่สร้างด้วยระบบ/โมเดลเดิม ซึ่ง AI ใหม่ <b>ค้นหาไม่ได้</b> (เวกเตอร์ต่างโมเดลเทียบกันไม่ได้)
          จนกว่าจะจัดทำดัชนีใหม่ ผู้ใช้จะถามแชทแล้วไม่พบข้อมูลจากเอกสารเหล่านี้
        </div>
        <a class="btn btn-sm btn-primary-app" href="?reindex_all=1"
           onclick="return confirm('จัดทำดัชนีใหม่ทุกเอกสารด้วย <?= e(OLLAMA_EMBED_MODEL) ?>? ใช้เวลาตามจำนวนเอกสาร (ห้ามปิดหน้านี้จนกว่าจะเสร็จ)')">
          <i class="bi bi-arrow-repeat me-1"></i> จัดทำดัชนีใหม่ทั้งหมด
        </a>
      </div>
    <?php endif; ?>

    <div class="app-card p-4 mb-4">
      <h6 class="fw-bold mb-1"><i class="bi bi-search text-success"></i> ทดสอบการค้นหา (RAG)</h6>
      <p class="small text-muted">พิมพ์คำถามตัวอย่างเพื่อดูว่า AI จะค้นเจอ chunk ไหน พร้อมคะแนนความคล้าย (0-1) ใช้ปรับค่า <code>RAG_MIN_SIMILARITY</code> (ตอนนี้ <b><?= RAG_MIN_SIMILARITY ?></b>)
        — ถ้าคำถามที่ควรตอบได้ถูกตัดทิ้ง ให้ลดค่า / ถ้าคำถามนอกเรื่องยังค้นเจอ ให้เพิ่มค่า</p>
      <form method="get" class="d-flex gap-2">
        <input type="text" name="q" class="form-control" placeholder="เช่น ดินกรดควรปรับปรุงอย่างไร" value="<?= e($searchQuery) ?>">
        <button class="btn btn-primary-app flex-shrink-0"><i class="bi bi-search"></i> ค้นหา</button>
      </form>
      <?php if ($searchResults !== null): ?>
        <?php if (ragLastError() !== ''): ?>
          <div class="alert alert-danger mt-3 mb-0"><?= e(ragLastError()) ?></div>
        <?php elseif (!$searchResults): ?>
          <p class="text-muted mt-3 mb-0">ไม่พบ chunk ที่เกี่ยวข้องเลย (ยังไม่มีเอกสารที่จัดทำดัชนีแล้ว หรือไม่มีคำที่เกี่ยวข้อง)</p>
        <?php else: ?>
          <div class="table-responsive mt-3">
            <table class="table table-sm align-middle mb-0">
              <thead><tr><th style="width:90px;">คะแนน</th><th>สถานะ</th><th>เอกสาร</th><th>ข้อความ (ตัดมาบางส่วน)</th></tr></thead>
              <tbody>
                <?php foreach ($searchResults as $i => $r): $pass = $r['sim'] >= RAG_MIN_SIMILARITY; ?>
                  <tr>
                    <td><b><?= number_format($r['sim'], 3) ?></b></td>
                    <td>
                      <?php if ($pass && $i < RAG_TOP_K): ?><span class="badge badge-soft-green">ถูกใช้ตอบ</span>
                      <?php elseif ($pass): ?><span class="badge badge-soft-blue">ผ่านเกณฑ์ (เกิน Top-K)</span>
                      <?php else: ?><span class="badge badge-soft-red">ต่ำกว่าเกณฑ์</span><?php endif; ?>
                    </td>
                    <td class="small"><?= e($r['title']) ?></td>
                    <td class="small text-muted"><?= e(mb_strimwidth($r['content'], 0, 140, '...')) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="app-card p-3">
      <div class="table-responsive">
        <table class="table align-middle">
          <thead><tr><th>ชื่อเอกสาร</th><th>ชนิด</th><th>สถานะ</th><th>Chunks</th><th>อัปโหลดโดย</th><th class="text-end">จัดการ</th></tr></thead>
          <tbody>
            <?php foreach ($docs as $d): ?>
              <tr>
                <td class="fw-semibold"><?= e($d['title']) ?></td>
                <td><span class="badge badge-soft-blue text-uppercase"><?= e($d['file_type']) ?></span></td>
                <td>
                  <?php if ($d['status']==='indexed'): ?><span class="badge badge-soft-green">พร้อมใช้งาน</span>
                  <?php elseif ($d['status']==='failed'): ?><span class="badge badge-soft-red">ล้มเหลว</span>
                  <?php else: ?><span class="badge badge-soft-amber">รอดำเนินการ</span><?php endif; ?>
                </td>
                <td><?= (int)$d['chunk_count'] ?></td>
                <td class="small text-muted"><?= e($d['full_name'] ?? '-') ?></td>
                <td class="text-end">
                  <a class="btn btn-sm btn-outline-app" href="?reindex=<?= $d['doc_id'] ?>" title="จัดทำดัชนีใหม่ (ใช้เมื่อเปลี่ยนโมเดล Embedding หรือดัชนีล้มเหลว)" onclick="return confirm('จัดทำดัชนีใหม่สำหรับเอกสารนี้? ใช้เวลาสักครู่')"><i class="bi bi-arrow-clockwise"></i></a>
                  <a class="btn btn-sm btn-outline-danger" href="?delete=<?= $d['doc_id'] ?>" onclick="return confirm('ยืนยันการลบเอกสารและดัชนีทั้งหมด?')"><i class="bi bi-trash"></i></a>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$docs): ?><tr><td colspan="6" class="text-center text-muted py-4">ยังไม่มีเอกสารในคลังความรู้</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>
</div>

<div class="modal fade" id="uploadModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content" style="border-radius:16px;">
      <form method="post" enctype="multipart/form-data">
        <?= csrfField() ?>
        <div class="modal-header"><h5 class="modal-title">อัปโหลดเอกสารเข้าคลังความรู้</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <input type="hidden" name="action" value="upload">
          <div class="mb-3"><label class="form-label">ชื่อเอกสาร</label><input type="text" name="title" class="form-control" required placeholder="เช่น คู่มือการปลูกพืชหมุนเวียน"></div>
          <div class="mb-3"><label class="form-label">ไฟล์ (PDF, Word, CSV, TXT, MD)</label><input type="file" name="doc_file" accept=".pdf,.docx,.csv,.txt,.md" class="form-control" required></div>
          <p class="small text-muted mb-0"><i class="bi bi-info-circle"></i> ระบบจะจัดทำดัชนีทันทีหลังอัปโหลด อาจใช้เวลาสักครู่ขึ้นกับขนาดเอกสาร (ใช้ bge-m3 ในเครื่อง ถ้าเอกสารยาวอาจใช้เวลาหลายนาที ห้ามปิดหน้านี้)</p>
        </div>
        <div class="modal-footer"><button class="btn btn-primary-app w-100">อัปโหลดและจัดทำดัชนี</button></div>
      </form>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
