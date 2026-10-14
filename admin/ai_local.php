<?php
require_once __DIR__ . '/../config/config.php';
requireAdmin();
$pageTitle = 'AI ในเครื่อง (Ollama)';

// ภาพทดสอบขนาดเล็ก (พื้นเขียว มีวงกลมแดงตรงกลาง) ใช้ทดสอบโมเดล Vision โดยไม่ต้องอัปโหลดไฟล์
const AI_TEST_IMAGE_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAKAAAACgCAIAAAAErfB6AAAB/0lEQVR42u3dwVECQRRFUfllFuRgEmZgNIZgGEZAVubhxjXWlAL9Xp+7tsruPv5xgAJOL5+vT+ptHAFgARZgARZgARZgwAIswAIswAIswIAFWIAFWIAFWIAFGLAAC7AAC7AACzBgARZgARZgARZgwAIswAIswAIswPrpuX6Hl4+v6z/w9n4u3v6p79NmfxXdyrsK+I+0lcwNwP/o2iedDXxT2g7mobva7zLBjzzuuFEeut2jPHS7jYdut/HQ7TYeut3GQ7fbeOh2r3CcXfc6x6l1r9YL/uWNgehe8zip7pW7RLtEG9/k9ZtgEyzArs/L7sIEm2Djm7wXE2yCBViAtTdw0x3WUjsywSZYgAVYgAVYgAUYsAAL8JH6Po1skR2ZYBMswAKs7YGb7rPW2YsJNsGGOHkXJtgEC7Cr9LLrN8Em2BAnr3ycVPeaXaJdog1E8mrHqXWvc5xd9wrHfHT//WXcZK15jhH/QWLuolc7zZT7g6SHSeucadAdftjj4BVONuvxW94THY8937in2IK/nPLOn5EQ+gx58FOV9zzx3Nc/fH9wLW0P8C2kfQN4LXPZu6QKgY96973vbS/gzfOCP2ABFmABFmABFmDAAizAAizAAizAgAVYgAVYgAVYgAUYsAALsAALsAALMGABFmABFmABFmDAAizAAizAAqwDfQPJMY8D2a1iBQAAAABJRU5ErkJggg==';

$testResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf('ai_local.php');
    $action = $_POST['action'] ?? '';
    $t0 = microtime(true);

    if ($action === 'test_text') {
        $r = aiGenerateText('ตอบสั้น ๆ 1-2 ประโยคว่า ดินที่มีค่า pH ต่ำกว่า 5.5 เรียกว่าดินอะไร และควรปรับปรุงอย่างไร', ['maxOutputTokens' => 200, 'temperature' => 0.2], 'test');
        $testResult = ['title' => 'ทดสอบโมเดลข้อความ (' . OLLAMA_TEXT_MODEL . ')', 'r' => $r];
    } elseif ($action === 'test_embed') {
        $r = aiEmbed('การปลูกพืชหมุนเวียนช่วยฟื้นฟูความอุดมสมบูรณ์ของดิน', 'test');
        $testResult = ['title' => 'ทดสอบโมเดล Embedding (' . OLLAMA_EMBED_MODEL . ')', 'r' => $r];
    } elseif ($action === 'test_vision') {
        $tmp = tempnam(sys_get_temp_dir(), 'aitest_');
        file_put_contents($tmp, base64_decode(AI_TEST_IMAGE_B64));
        $r = aiVision($tmp, 'ภาพนี้มีสีพื้นหลังเป็นสีอะไร และมีรูปร่างอะไรอยู่ตรงกลาง ตอบสั้น ๆ เป็นภาษาไทย', ['maxOutputTokens' => 150], 'test');
        @unlink($tmp);
        $testResult = ['title' => 'ทดสอบโมเดลวิเคราะห์ภาพ (' . OLLAMA_VISION_MODEL . ')', 'r' => $r];
    }
    if ($testResult) {
        $testResult['seconds'] = round(microtime(true) - $t0, 1);
    }
}

$status = ollamaStatus();
$usable = 0;
$stale = 0;
try {
    $usable = ragUsableChunkCount();
    $stale = ragStaleChunkCount();
} catch (Exception $e) {
    // ตาราง/คอลัมน์ยังไม่ได้อัปเดต (ยังไม่ได้รัน migration)
}
$allReady = $status['reachable'] && !in_array(false, array_column($status['roles'], 'installed'), true);

function humanSize(int $bytes): string
{
    return $bytes > 0 ? number_format($bytes / 1073741824, 2) . ' GB' : '-';
}

require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar_admin.php'; ?>
  <main class="main-content">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>

    <div class="ai-box mb-4">
      <div class="row align-items-center">
        <div class="col-md-8">
          <h6 class="fw-bold mb-1"><i class="bi bi-cpu text-success"></i> AI ในเครื่อง (Ollama)</h6>
          <p class="small text-muted mb-0">
            ระบบใช้โมเดลที่รันบนเครื่องผ่าน Ollama ทั้งหมด ไม่ต้องใช้ API Key ไม่ต้องต่ออินเทอร์เน็ตตอนใช้งาน และไม่มีค่าใช้จ่ายต่อ Token
            ค่าที่ใช้ตั้งไว้ในไฟล์ <code>config/config.php</code> (หรือ <code>config/local.php</code>)
          </p>
        </div>
        <div class="col-md-4 text-md-end mt-3 mt-md-0">
          <?php if ($allReady): ?>
            <span class="badge badge-soft-green fs-6"><i class="bi bi-check-circle"></i> พร้อมใช้งานครบ</span>
          <?php elseif ($status['reachable']): ?>
            <span class="badge badge-soft-amber fs-6"><i class="bi bi-exclamation-triangle"></i> เชื่อมต่อได้ แต่ยังขาดโมเดล</span>
          <?php else: ?>
            <span class="badge badge-soft-red fs-6"><i class="bi bi-x-circle"></i> เชื่อมต่อ Ollama ไม่ได้</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if (!$status['reachable']): ?>
      <div class="alert alert-danger">
        <b>เชื่อมต่อ Ollama ไม่ได้</b><br>
        <?= e($status['error']) ?><br>
        <span class="small">วิธีแก้: เปิดโปรแกรม Ollama (หรือรัน <code>ollama serve</code> ใน Terminal) แล้วรีเฟรชหน้านี้ ถ้า Ollama อยู่คนละเครื่อง ให้ตั้ง <code>OLLAMA_BASE_URL</code> ใน <code>config/local.php</code></span>
      </div>
    <?php endif; ?>

    <?php if ($testResult): ?>
      <div class="app-card p-4 mb-4 border-start border-4 <?= !empty($testResult['r']['success']) ? 'border-success' : 'border-danger' ?>">
        <h6 class="fw-bold"><?= e($testResult['title']) ?> <span class="small text-muted fw-normal">— ใช้เวลา <?= $testResult['seconds'] ?> วินาที (ครั้งแรกอาจนานเพราะต้องโหลดโมเดลเข้า RAM)</span></h6>
        <?php $r = $testResult['r']; ?>
        <?php if (!empty($r['success'])): ?>
          <?php if (isset($r['embedding'])): ?>
            <p class="mb-1 text-success"><i class="bi bi-check-circle"></i> สร้างเวกเตอร์สำเร็จ ขนาด <b><?= count($r['embedding']) ?></b> มิติ</p>
            <p class="small text-muted mb-0">ตัวอย่างค่า 5 ตัวแรก: <?= e(implode(', ', array_map(fn($v) => number_format($v, 4), array_slice($r['embedding'], 0, 5)))) ?></p>
          <?php else: ?>
            <p class="mb-1 text-success"><i class="bi bi-check-circle"></i> ตอบกลับสำเร็จ</p>
            <div class="p-3 rounded-3" style="background:#f0faf6;"><?= nl2br(e($r['text'])) ?></div>
          <?php endif; ?>
          <?php if (!empty($r['usage']['total'])): ?>
            <p class="small text-muted mt-2 mb-0">Token ที่ใช้: ป้อนเข้า <?= (int) $r['usage']['prompt'] ?> / ตอบ <?= (int) $r['usage']['completion'] ?> / รวม <?= (int) $r['usage']['total'] ?></p>
          <?php endif; ?>
        <?php else: ?>
          <p class="mb-0 text-danger"><i class="bi bi-x-circle"></i> <?= e($r['error'] ?? 'เกิดข้อผิดพลาด') ?></p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="app-card p-4 mb-4">
      <h6 class="fw-bold mb-3"><i class="bi bi-box-seam text-success"></i> โมเดลที่ระบบต้องใช้</h6>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead><tr><th>หน้าที่</th><th>โมเดล</th><th>สถานะ</th><th class="text-end">ทดสอบ</th></tr></thead>
          <tbody>
            <?php
            $tests = ['text' => 'test_text', 'vision' => 'test_vision', 'embed' => 'test_embed'];
            foreach ($status['roles'] as $key => $role): ?>
              <tr>
                <td><?= e($role['label']) ?></td>
                <td><code><?= e($role['model']) ?></code></td>
                <td>
                  <?php if ($role['installed']): ?>
                    <span class="badge badge-soft-green"><i class="bi bi-check-circle"></i> ติดตั้งแล้ว</span>
                  <?php elseif ($status['reachable']): ?>
                    <span class="badge badge-soft-red">ยังไม่ได้ติดตั้ง</span>
                    <div class="small text-muted mt-1">รัน: <code>ollama pull <?= e($role['model']) ?></code></div>
                  <?php else: ?>
                    <span class="badge badge-soft-amber">ตรวจสอบไม่ได้</span>
                  <?php endif; ?>
                </td>
                <td class="text-end">
                  <?php if ($role['installed']): ?>
                    <form method="post" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="<?= $tests[$key] ?>">
                      <button class="btn btn-sm btn-outline-app" onclick="this.innerHTML='กำลังทดสอบ...'; this.form.submit(); this.disabled=true; return false;"><i class="bi bi-play-circle"></i> ทดสอบ</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($status['reachable'] && $status['version'] !== ''): ?>
        <p class="small text-muted mt-3 mb-0">Ollama เวอร์ชัน <?= e($status['version']) ?> ที่ <?= e(OLLAMA_BASE_URL) ?></p>
      <?php endif; ?>
    </div>

    <div class="row g-4 mb-4">
      <div class="col-lg-6">
        <div class="app-card p-4 h-100">
          <h6 class="fw-bold mb-3"><i class="bi bi-hdd text-success"></i> โมเดลทั้งหมดที่ติดตั้งในเครื่อง</h6>
          <?php if ($status['models']): ?>
            <table class="table table-sm align-middle mb-0">
              <thead><tr><th>ชื่อ</th><th>ขนาดพารามิเตอร์</th><th class="text-end">ขนาดไฟล์</th></tr></thead>
              <tbody>
                <?php foreach ($status['models'] as $m): ?>
                  <tr>
                    <td><code><?= e($m['name']) ?></code></td>
                    <td class="small"><?= e($m['params'] ?: '-') ?> <?= e($m['quant']) ?></td>
                    <td class="text-end small"><?= humanSize($m['size']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php else: ?>
            <p class="text-muted mb-0"><?= $status['reachable'] ? 'ยังไม่มีโมเดลในเครื่อง' : 'ยังไม่ได้เชื่อมต่อ Ollama' ?></p>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="app-card p-4 h-100">
          <h6 class="fw-bold mb-3"><i class="bi bi-sliders text-success"></i> ค่าตั้งค่าที่ใช้อยู่</h6>
          <table class="table table-sm mb-0">
            <tbody>
              <tr><td>ที่อยู่ Ollama</td><td><code><?= e(OLLAMA_BASE_URL) ?></code></td></tr>
              <tr><td>Context window</td><td><?= number_format(OLLAMA_NUM_CTX) ?> โทเคน</td></tr>
              <tr><td>ตอบได้สูงสุดต่อครั้ง</td><td><?= number_format(OLLAMA_MAX_PREDICT) ?> โทเคน</td></tr>
              <tr><td>ค้างโมเดลใน RAM</td><td><?= e(OLLAMA_KEEP_ALIVE) ?></td></tr>
              <tr><td>เวลารอสูงสุด</td><td><?= (int) OLLAMA_TIMEOUT ?> วินาที</td></tr>
              <tr><td>RAG: Top-K / ความคล้ายขั้นต่ำ</td><td><?= (int) RAG_TOP_K ?> / <?= RAG_MIN_SIMILARITY ?></td></tr>
              <tr><td>RAG: ขนาด chunk / ทับซ้อน</td><td><?= (int) RAG_CHUNK_SIZE ?> / <?= (int) RAG_CHUNK_OVERLAP ?> ตัวอักษร</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="app-card p-4">
      <h6 class="fw-bold mb-2"><i class="bi bi-journal-richtext text-success"></i> สถานะดัชนีคลังความรู้ (RAG)</h6>
      <p class="mb-2">พร้อมใช้ค้นหา <b><?= $usable ?></b> chunks
        <?php if ($stale > 0): ?>
          <span class="badge badge-soft-amber ms-2">ต้องจัดทำใหม่ <?= $stale ?> chunks</span>
        <?php endif; ?>
      </p>
      <?php if ($stale > 0): ?>
        <p class="small text-muted">มีเอกสารที่สร้างดัชนีไว้ด้วยระบบ API เดิม ซึ่ง AI ในเครื่องค้นหาไม่ได้ ไปที่หน้าคลังความรู้แล้วกด "จัดทำดัชนีใหม่ทั้งหมด"</p>
      <?php endif; ?>
      <a href="knowledge_base.php" class="btn btn-outline-app btn-sm"><i class="bi bi-arrow-right-circle me-1"></i> ไปที่คลังความรู้ AI (RAG)</a>
    </div>
  </main>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
