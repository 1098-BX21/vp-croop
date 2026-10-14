<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
$pageTitle = 'ผู้ช่วยแชท AI';
$uid = currentUser()['user_id'];
$pdo = getDBConnection();

$stmt = $pdo->prepare('SELECT role, message FROM chat_messages WHERE user_id=? ORDER BY message_id ASC LIMIT 100');
$stmt->execute([$uid]);
$chatHistory = $stmt->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar_user.php'; ?>
  <main class="main-content">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>

    <div class="app-card p-0 d-flex flex-column" style="height: 74vh;">
      <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
        <div>
          <h6 class="fw-bold mb-0"><i class="bi bi-robot text-success"></i> ผู้ช่วยเกษตร AI</h6>
          <p class="small text-muted mb-0">ถามได้ทุกเรื่องเกี่ยวกับพืชหมุนเวียน การจัดการดิน ปุ๋ย และโรคพืช — ตอบโดยอ้างอิงคลังความรู้ในระบบ (RAG)</p>
        </div>
        <button class="btn btn-sm btn-outline-danger" id="clearChatBtn"><i class="bi bi-trash"></i> ล้างประวัติ</button>
      </div>

      <div id="chatWindow" class="flex-grow-1 p-3 chat-window">
        <?php if (!$chatHistory): ?>
          <div class="text-center text-muted mt-5">
            <i class="bi bi-chat-heart fs-1"></i>
            <p class="mt-2">เริ่มสนทนาได้เลย เช่น "ดิน pH 5.5 ควรปลูกอะไรดี" หรือ "ปุ๋ยสูตรไหนเหมาะกับถั่วเขียว"</p>
          </div>
        <?php else: ?>
          <?php foreach ($chatHistory as $m): ?>
            <?php if ($m['role'] === 'user'): ?>
              <div class="d-flex justify-content-end mb-3"><div class="chat-bubble-user"><?= formatChatMessage($m['message']) ?></div></div>
            <?php else: ?>
              <div class="d-flex justify-content-start mb-3"><div class="chat-bubble-ai"><?= formatChatMessage($m['message']) ?></div></div>
            <?php endif; ?>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <form id="chatForm" class="p-3 border-top d-flex gap-2">
        <input type="text" id="chatInput" class="form-control" placeholder="พิมพ์คำถามของคุณที่นี่..." autocomplete="off" required>
        <button type="submit" id="chatSendBtn" class="btn btn-primary-app px-4"><i class="bi bi-send"></i></button>
      </form>
    </div>
  </main>
</div>

<script>
const chatWindow = document.getElementById('chatWindow');
const chatForm = document.getElementById('chatForm');
const chatInput = document.getElementById('chatInput');
const chatSendBtn = document.getElementById('chatSendBtn');
const clearChatBtn = document.getElementById('clearChatBtn');

function scrollBottom() { chatWindow.scrollTop = chatWindow.scrollHeight; }
scrollBottom();

// แปลงข้อความจาก AI ให้ปลอดภัย (escape HTML กัน injection) แล้วค่อยแปลง markdown เบื้องต้นเป็น HTML
// รองรับ: **ตัวหนา**, ขึ้นบรรทัดใหม่, และรายการเลข/บูลเลตแบบง่าย
function formatChatText(text) {
  var div = document.createElement('div');
  div.textContent = text; // escape HTML ทั้งหมดก่อน (กัน XSS)
  var escaped = div.innerHTML;

  // **ตัวหนา** -> <strong>
  escaped = escaped.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
  // ขึ้นบรรทัดใหม่ -> <br>
  escaped = escaped.replace(/\n/g, '<br>');
  return escaped;
}

function bubble(text, isUser, loading) {
  const wrap = document.createElement('div');
  wrap.className = 'd-flex mb-3 ' + (isUser ? 'justify-content-end' : 'justify-content-start');
  const b = document.createElement('div');
  b.className = isUser ? 'chat-bubble-user' : 'chat-bubble-ai';
  if (loading) { b.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>กำลังพิมพ์...'; b.id = 'loadingBubble'; }
  else { b.innerHTML = formatChatText(text); }
  wrap.appendChild(b); chatWindow.appendChild(wrap); scrollBottom(); return wrap;
}

chatForm.addEventListener('submit', async function (e) {
  e.preventDefault();
  const msg = chatInput.value.trim();
  if (!msg) return;
  bubble(msg, true, false);
  chatInput.value = ''; chatInput.disabled = true; chatSendBtn.disabled = true;
  const loading = bubble('', false, true);
  try {
    const body = new URLSearchParams();
    body.append('message', msg);
    body.append('csrf_token', window.CSRF_TOKEN);
    const resp = await fetch('chat_send.php', { method: 'POST', body });
    const data = await resp.json();
    loading.remove();
    bubble(data.success ? data.answer : ('ขออภัย: ' + (data.message || 'เกิดข้อผิดพลาด')), false, false);
  } catch (err) {
    loading.remove();
    bubble('ไม่สามารถเชื่อมต่อได้: ' + err.message, false, false);
  } finally {
    chatInput.disabled = false; chatSendBtn.disabled = false; chatInput.focus();
  }
});

clearChatBtn.addEventListener('click', async function () {
  if (!confirm('ยืนยันการล้างประวัติการสนทนาทั้งหมด?')) return;
  const body = new URLSearchParams();
  body.append('csrf_token', window.CSRF_TOKEN);
  await fetch('chat_clear.php', { method: 'POST', body });
  location.reload();
});
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
