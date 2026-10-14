<?php
/**
 * ============================================================
 * AI Layer — ชั้นกลางที่ทั้งระบบเรียกใช้
 * ใช้ AI ภายในเครื่องผ่าน Ollama เท่านั้น (ไม่ใช้ API Key / ไม่เรียกบริการคลาวด์)
 *
 * ฟังก์ชันที่ระบบเรียกใช้ (ชื่อและพารามิเตอร์คงเดิมจากเวอร์ชัน API
 * เพื่อให้หน้าอื่น ๆ ไม่ต้องแก้):
 *   aiGenerateText(), aiGenerateJSON(), aiVision(),
 *   aiEmbed(), aiEmbedMany(), aiChat()
 *
 * ทุกครั้งที่เรียก AI สำเร็จ จะบันทึกจำนวน Token ลง ai_usage_logs อัตโนมัติ
 * (ตัวเลขมาจาก prompt_eval_count / eval_count ที่ Ollama รายงานกลับมา)
 * ============================================================
 */

/**
 * กฎภาษา: ระบบนี้ให้ AI ตอบเป็นภาษาไทยเท่านั้น และห้ามแสดงขั้นตอนการคิด
 * ใส่ไว้ 2 ที่ (system prompt + ท้ายคำถาม) เพราะโมเดลขนาดเล็กทำตามได้ดีขึ้นเมื่อย้ำท้ายคำถามอีกครั้ง
 * และมีตัวกรองใน ollama.php ตรวจซ้ำอีกชั้น ถ้ายังหลุดเป็นภาษาอังกฤษ
 */
const AI_THAI_RULE = 'กฎภาษา: ตอบเป็นภาษาไทยเท่านั้น ห้ามใช้ภาษาอังกฤษในคำตอบ (ยกเว้นชื่อเฉพาะหรือสัญลักษณ์ เช่น pH, N-P-K) และห้ามเขียนขั้นตอนการคิดหรือการวิเคราะห์ของคุณ ให้ตอบเฉพาะคำตอบสุดท้ายทันที';
const AI_THAI_SUFFIX = '(ตอบเป็นภาษาไทยเท่านั้น ตอบคำตอบสุดท้ายทันที ไม่ต้องอธิบายขั้นตอนการคิด)';

/** ข้อมูลการตั้งค่า AI ที่ใช้อยู่ (ใช้แสดงผล) */
function aiGetActiveProvider(): array
{
    return [
        'provider'     => 'ollama',
        'base_url'     => OLLAMA_BASE_URL,
        'text_model'   => OLLAMA_TEXT_MODEL,
        'vision_model' => OLLAMA_VISION_MODEL,
        'embed_model'  => OLLAMA_EMBED_MODEL,
    ];
}

function aiActiveProviderName(): string
{
    return 'Ollama (AI ในเครื่อง)';
}

/** แปลงข้อความ JSON จากโมเดล (รองรับ ```json fence, <think> และข้อความนำหน้า/ตามหลัง) */
function aiParseJson(string $text): ?array
{
    $cleaned = ollamaStripThink($text);
    if (strpos($cleaned, '```') !== false) {
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
        $cleaned = preg_replace('/\s*```$/', '', $cleaned);
        $cleaned = trim($cleaned);
    }
    $parsed = json_decode($cleaned, true);
    if (is_array($parsed)) {
        return $parsed;
    }
    $start = strpos($cleaned, '{');
    $end = strrpos($cleaned, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $parsed = json_decode(substr($cleaned, $start, $end - $start + 1), true);
        if (is_array($parsed)) {
            return $parsed;
        }
    }
    return null;
}

/** บันทึกสถิติ Token ลง ai_usage_logs เมื่อเรียกสำเร็จ */
function aiLogIfSuccess(array $result, string $feature): void
{
    if (empty($result['success'])) {
        return; // เรียกไม่สำเร็จ ไม่มี token ให้บันทึก
    }
    $uid = null;
    if (function_exists('currentUser')) {
        $u = currentUser();
        $uid = $u['user_id'] ?? null;
    }
    logAiUsage($uid, 'ollama', $result['model'] ?? null, $feature, $result['usage'] ?? []);
}

/* =========================================================
 * ฟังก์ชันสาธารณะ
 * ========================================================= */

function aiGenerateText(string $prompt, array $opts = [], string $feature = 'advice'): array
{
    $r = ollamaChatCompletion(OLLAMA_TEXT_MODEL, [
        ['role' => 'system', 'content' => AI_THAI_RULE],
        ['role' => 'user', 'content' => $prompt . "\n\n" . AI_THAI_SUFFIX],
    ], [
        'maxTokens'   => $opts['maxOutputTokens'] ?? 1024,
        'temperature' => $opts['temperature'] ?? 0.4,
        'thaiOnly'    => true,
    ]);
    aiLogIfSuccess($r, $feature);
    return $r;
}

/**
 * ให้ AI ตอบเป็น JSON
 * $opts['jsonSchema'] (ไม่บังคับ) = JSON Schema เพื่อบังคับโครงสร้าง/ค่าที่ตอบได้ (Structured Output)
 *   ช่วยให้โมเดลเล็กตอบถูกรูปแบบเสมอ ถ้า Ollama รุ่นเก่าไม่รองรับ จะถอยกลับเป็นโหมด json ปกติอัตโนมัติ
 */
function aiGenerateJSON(string $prompt, array $opts = [], string $feature = 'advice'): array
{
    $messages = [
        ['role' => 'system', 'content' => 'คุณต้องตอบกลับเป็น JSON ที่ถูกต้องเท่านั้น ห้ามมีข้อความอื่นนอก JSON และข้อความทุกช่องที่เป็นคำอธิบายต้องเขียนเป็นภาษาไทย (ชื่อ key คงเป็นภาษาอังกฤษตามที่กำหนด)'],
        ['role' => 'user', 'content' => $prompt],
    ];
    $r = ollamaChatCompletion(OLLAMA_TEXT_MODEL, $messages, [
        'maxTokens'   => $opts['maxOutputTokens'] ?? 1024,
        'temperature' => $opts['temperature'] ?? 0.3,
        'format'      => $opts['jsonSchema'] ?? 'json',
    ]);
    aiLogIfSuccess($r, $feature); // เรียก AI สำเร็จแล้วนับ token เสมอ แม้แปลง JSON ไม่ได้
    if (!$r['success']) {
        return $r;
    }

    $parsed = aiParseJson($r['text']);
    if ($parsed === null) {
        return ['success' => false, 'error' => 'AI ตอบกลับไม่อยู่ในรูปแบบ JSON ที่อ่านได้ กรุณาลองใหม่อีกครั้ง', 'raw' => $r['text']];
    }

    // ตรวจว่าข้อความอธิบายทุกช่องใน JSON (รวมที่ซ้อนอยู่ในอาร์เรย์) เป็นภาษาไทย ถ้าไม่ใช่ ถามซ้ำ 1 ครั้ง
    $prose = aiCollectProse($parsed);
    if ($prose !== '' && !ollamaIsThai($prose) && empty($opts['_retry'])) {
        $retry = aiGenerateJSON($prompt . "\n\n" . OLLAMA_THAI_RETRY_NOTE, array_merge($opts, ['_retry' => true]), $feature);
        if ($retry['success']) {
            return $retry;
        }
    }
    return ['success' => true, 'data' => $parsed, 'raw' => $r['text'], 'usage' => $r['usage'], 'model' => $r['model']];
}

function aiVision(string $imagePath, string $prompt, array $opts = [], string $feature = 'vision'): array
{
    $r = ollamaVision($imagePath, $prompt . "\n\n" . AI_THAI_SUFFIX, $opts);
    aiLogIfSuccess($r, $feature);
    return $r;
}

/** รวมข้อความอธิบายยาว (>= 20 ตัวอักษร) จากทุกช่องใน JSON ที่ซ้อนกันกี่ชั้นก็ได้ ไว้ตรวจว่าเป็นภาษาไทย */
function aiCollectProse($data): string
{
    if (is_string($data)) {
        return mb_strlen($data) >= 20 ? ' ' . $data : '';
    }
    $out = '';
    if (is_array($data)) {
        foreach ($data as $v) {
            $out .= aiCollectProse($v);
        }
    }
    return $out;
}

/**
 * วิเคราะห์ภาพแล้วให้ตอบเป็น JSON ตามโครงสร้างที่กำหนด ($opts['jsonSchema'])
 * ตรวจว่าข้อความอธิบายเป็นภาษาไทย ถ้าไม่ใช่จะถามซ้ำ 1 ครั้ง
 */
function aiVisionJSON(string $imagePath, string $prompt, array $opts = [], string $feature = 'disease'): array
{
    $r = ollamaVision($imagePath, $prompt . "\n\n(ตอบเป็น JSON เท่านั้น และข้อความอธิบายทุกช่องเป็นภาษาไทย)", $opts + [
        'format' => $opts['jsonSchema'] ?? 'json',
    ]);
    aiLogIfSuccess($r, $feature);
    if (!$r['success']) {
        return $r;
    }
    $parsed = aiParseJson($r['text']);
    if ($parsed === null) {
        return ['success' => false, 'error' => 'AI ตอบกลับไม่อยู่ในรูปแบบที่อ่านได้ กรุณาลองถ่ายภาพใหม่หรือลองอีกครั้ง', 'raw' => $r['text']];
    }
    $prose = '';
    foreach ($parsed as $v) {
        if (is_string($v) && mb_strlen($v) > 15) {
            $prose .= ' ' . $v;
        }
    }
    if ($prose !== '' && !ollamaIsThai($prose) && empty($opts['_retry'])) {
        $retry = aiVisionJSON($imagePath, $prompt . "\n\n" . OLLAMA_THAI_RETRY_NOTE, array_merge($opts, ['_retry' => true]), $feature);
        if ($retry['success']) {
            return $retry;
        }
    }
    return ['success' => true, 'data' => $parsed, 'raw' => $r['text'], 'usage' => $r['usage'], 'model' => $r['model']];
}

/** สร้างเวกเตอร์ 1 ข้อความ */
function aiEmbed(string $text, string $feature = 'embed'): array
{
    $r = aiEmbedMany([$text], $feature);
    if (!$r['success']) {
        return $r;
    }
    return ['success' => true, 'embedding' => $r['embeddings'][0], 'usage' => $r['usage'], 'model' => $r['model']];
}

/** สร้างเวกเตอร์หลายข้อความในการเรียกเดียว */
function aiEmbedMany(array $texts, string $feature = 'embed'): array
{
    $r = ollamaEmbedMany($texts);
    aiLogIfSuccess($r, $feature);
    return $r;
}

function aiChat(array $history, string $userMessage, string $systemInstruction = '', array $opts = [], string $feature = 'chat'): array
{
    $messages = [['role' => 'system', 'content' => AI_THAI_RULE . ($systemInstruction !== '' ? "\n\n" . $systemInstruction : '')]];
    foreach ($history as $h) {
        $messages[] = ['role' => ($h['role'] === 'assistant') ? 'assistant' : 'user', 'content' => $h['message']];
    }
    // ย้ำกฎภาษาท้ายคำถามเฉพาะในคำขอนี้ (ประวัติแชทที่บันทึกลง DB ไม่ถูกแก้)
    $messages[] = ['role' => 'user', 'content' => $userMessage . "\n\n" . AI_THAI_SUFFIX];

    $r = ollamaChatCompletion(OLLAMA_TEXT_MODEL, $messages, [
        'maxTokens'   => $opts['maxOutputTokens'] ?? 1024,
        'temperature' => $opts['temperature'] ?? 0.6,
        'thaiOnly'    => true,
    ]);
    aiLogIfSuccess($r, $feature);
    return $r;
}
