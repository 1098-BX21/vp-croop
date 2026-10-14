<?php
require_once __DIR__ . '/../config/config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบ']);
    exit;
}
requireCsrfAjax();

$uid = currentUser()['user_id'];
$pdo = getDBConnection();

$message = trim($_POST['message'] ?? '');
if ($message === '') {
    echo json_encode(['success' => false, 'message' => 'กรุณาพิมพ์ข้อความ']);
    exit;
}

// Rate limit
if (!checkRateLimit($uid, 'chatbot', RATE_LIMIT_CHAT_PER_DAY)) {
    echo json_encode(['success' => false, 'message' => 'คุณใช้แชทครบโควตาต่อวันแล้ว (' . RATE_LIMIT_CHAT_PER_DAY . ' ข้อความ) กรุณาลองใหม่พรุ่งนี้']);
    exit;
}

// ประวัติสนทนา 6 ข้อความล่าสุด (พอให้ต่อเนื่อง แต่ไม่ยาวจนกิน token)
$hStmt = $pdo->prepare('SELECT role, message FROM chat_messages WHERE user_id=? ORDER BY message_id DESC LIMIT 6');
$hStmt->execute([$uid]);
$history = array_reverse($hStmt->fetchAll());

// บันทึกข้อความผู้ใช้
$pdo->prepare('INSERT INTO chat_messages (user_id, role, message) VALUES (?, "user", ?)')->execute([$uid, $message]);

// ---- ดึง context จาก RAG ----
$ragContext = ragQueryContext($message);

if ($ragContext !== '') {
    // ===== โหมด RAG: บังคับตอบจากคลังความรู้เท่านั้น (กันตอบมั่ว) =====
    $systemInstruction = <<<SYS
คุณคือผู้ช่วย AI ด้านการเกษตรของระบบ "Smart Farm Advisory"

หน้าที่ของคุณ: ตอบคำถามของเกษตรกรโดยใช้ข้อมูลจาก "คลังความรู้" ที่ให้มาเท่านั้น

กฎเหล็ก (ห้ามฝ่าฝืน):
1. ตอบโดยอ้างอิงจาก "ข้อมูลคลังความรู้" ที่ให้มาเท่านั้น ห้ามแต่งเติมหรือใช้ความรู้นอกเหนือจากนี้
2. หากข้อมูลในคลังความรู้ไม่เพียงพอที่จะตอบคำถาม ให้ตอบว่า "ขออภัย ข้อมูลในคลังความรู้ยังไม่ครอบคลุมคำถามนี้" อย่างชัดเจน ห้ามเดาคำตอบ
3. เรียบเรียงคำตอบให้ครบถ้วน เข้าใจง่าย เป็นภาษาไทย จัดระเบียบเนื้อหาให้อ่านง่าย
4. หากมีหลายประเด็นในคลังความรู้ ให้สรุปรวมให้ครบทุกประเด็นที่เกี่ยวข้อง
SYS;

    $userMessage = <<<MSG
ข้อมูลคลังความรู้ (ใช้ตอบเท่านั้น):
════════════════════════
{$ragContext}
════════════════════════

คำถามของเกษตรกร: {$message}

กรุณาตอบโดยใช้เฉพาะข้อมูลคลังความรู้ด้านบน เรียบเรียงให้ครบถ้วนและเข้าใจง่าย
MSG;

    // temperature ต่ำ = คำตอบนิ่ง ไม่แกว่ง ไม่มั่ว / 1024 โทเคนพอสำหรับคำตอบที่ครบถ้วน และไม่นานเกินไปบนโมเดลในเครื่อง
    $result = aiChat($history, $userMessage, $systemInstruction, ['maxOutputTokens' => 1024, 'temperature' => 0.2]);
    $usedRag = true;

} else {
    // ===== ไม่พบข้อมูลใน RAG =====
    $usableChunks = ragUsableChunkCount();
    $staleChunks = ragStaleChunkCount();

    if ($usableChunks === 0 && $staleChunks > 0) {
        // มีเอกสารเก่าที่สร้างดัชนีไว้ด้วยโมเดลอื่น (ก่อนเปลี่ยนเป็น AI ในเครื่อง) ยังค้นไม่ได้จนกว่าผู้ดูแลจะจัดทำดัชนีใหม่
        $answer = 'ขออภัยครับ ขณะนี้คลังความรู้ของระบบอยู่ระหว่างปรับปรุงให้ใช้กับ AI ตัวใหม่ จึงยังค้นข้อมูลไม่ได้ '
                . 'กรุณาแจ้งผู้ดูแลระบบให้จัดทำดัชนีเอกสารใหม่ในเมนู "คลังความรู้ AI (RAG)"';
        $pdo->prepare('INSERT INTO chat_messages (user_id, role, message) VALUES (?, "assistant", ?)')->execute([$uid, $answer]);
        logActivity($uid, 'chatbot', 'index_needs_rebuild');
        echo json_encode(['success' => true, 'answer' => $answer, 'from_rag' => false], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $hasDocs = $usableChunks > 0;

    if ($hasDocs) {
        // ถ้าค้นไม่ได้เพราะ AI ในเครื่องไม่พร้อม ให้บอกสาเหตุจริง ไม่ใช่ตอบว่า "ไม่พบข้อมูล"
        if (ragLastError() !== '') {
            echo json_encode(['success' => false, 'message' => ragLastError()], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // มีเอกสารแต่ไม่เกี่ยวกับคำถามนี้ -> แจ้งตรง ๆ ไม่เรียก AI เลย (ประหยัดเวลาประมวลผล + ไม่มั่ว)
        $answer = 'ขออภัยครับ ไม่พบข้อมูลที่เกี่ยวข้องกับคำถามนี้ในคลังความรู้ของระบบ '
                . 'กรุณาลองถามใหม่ด้วยคำที่เฉพาะเจาะจงขึ้น หรือติดต่อผู้ดูแลระบบเพื่อเพิ่มเอกสารความรู้ในหัวข้อนี้';
        $pdo->prepare('INSERT INTO chat_messages (user_id, role, message) VALUES (?, "assistant", ?)')->execute([$uid, $answer]);
        logActivity($uid, 'chatbot', 'no_context: ' . mb_substr($message, 0, 80));
        echo json_encode(['success' => true, 'answer' => $answer, 'from_rag' => false], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ยังไม่มีเอกสารในคลังเลย -> ตอบจากความรู้ทั่วไป (พร้อมแจ้งว่าเป็นความรู้ทั่วไป)
    $systemInstruction = <<<SYS
คุณคือผู้ช่วย AI ด้านการเกษตรของระบบ "Smart Farm Advisory" ตอบคำถามเกษตรกรไทยเกี่ยวกับการปลูกพืชหมุนเวียน การจัดการดิน ปุ๋ย โรคพืช ด้วยภาษาที่เข้าใจง่าย

หมายเหตุ: ขณะนี้คลังความรู้ของระบบยังไม่มีเอกสาร คุณจึงตอบจากความรู้ทั่วไปด้านการเกษตรได้ แต่ต้องขึ้นต้นคำตอบด้วย "(ตอบจากความรู้ทั่วไป) " เสมอ และตอบเฉพาะเรื่องการเกษตรเท่านั้น
SYS;
    $result = aiChat($history, $message, $systemInstruction, ['maxOutputTokens' => 1024, 'temperature' => 0.4]);
    $usedRag = false;
}

if (!$result['success']) {
    echo json_encode(['success' => false, 'message' => $result['error']]);
    exit;
}

$answer = $result['text'];

// บันทึกคำตอบ AI
$pdo->prepare('INSERT INTO chat_messages (user_id, role, message) VALUES (?, "assistant", ?)')->execute([$uid, $answer]);
logActivity($uid, 'chatbot', mb_substr($message, 0, 100));

echo json_encode(['success' => true, 'answer' => $answer, 'from_rag' => $usedRag], JSON_UNESCAPED_UNICODE);
