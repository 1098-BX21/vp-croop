<?php
/**
 * ============================================================
 * Ollama Client (Local AI) — PHP ล้วนผ่าน cURL
 * เชื่อมต่อ Ollama REST API ที่รันบนเครื่อง (ค่าเริ่มต้น http://127.0.0.1:11434)
 * ไม่ใช้อินเทอร์เน็ต ไม่ใช้ API Key ไม่มีค่าใช้จ่ายต่อ Token
 *
 * โมเดลที่ระบบใช้ (ตั้งค่าใน config/config.php):
 *   OLLAMA_TEXT_MODEL   = qwen3:4b   แชท / วิเคราะห์ / แนะนำพืช (JSON)
 *   OLLAMA_VISION_MODEL = gemma3:4b  วิเคราะห์ภาพ (ดิน / ใบพืช)
 *   OLLAMA_EMBED_MODEL  = bge-m3     สร้างเวกเตอร์สำหรับ RAG (1024 มิติ)
 *
 * Endpoint ที่ใช้: /api/chat  /api/embed  /api/tags  /api/version
 * ============================================================
 */

function ollamaBaseUrl(): string
{
    return rtrim(OLLAMA_BASE_URL, '/');
}

/**
 * เรียก Ollama REST API
 * @return array ['httpCode'=>int, 'data'=>array|null, 'raw'=>string|false, 'curlErr'=>string]
 */
function ollamaRequest(string $method, string $path, ?array $body = null, int $timeout = 0): array
{
    // โมเดลในเครื่องอาจใช้เวลานาน (โหลดโมเดลครั้งแรก/CPU) จึงปลดเพดานเวลาของ PHP
    @set_time_limit(0);

    $ch = curl_init(ollamaBaseUrl() . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => $timeout > 0 ? $timeout : OLLAMA_TIMEOUT,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode(
            $body ?? new stdClass(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    return [
        'httpCode' => $httpCode,
        'data'     => ($raw !== false && $raw !== '') ? json_decode($raw, true) : null,
        'raw'      => $raw,
        'curlErr'  => $curlErr,
    ];
}

/** แปลงความผิดพลาดของ Ollama เป็นข้อความไทยที่บอกวิธีแก้ */
function ollamaFriendlyError(array $res, string $model = ''): string
{
    if ($res['curlErr'] !== '') {
        $e = $res['curlErr'];
        if (stripos($e, 'timed out') !== false || stripos($e, 'timeout') !== false) {
            return 'Ollama ประมวลผลนานเกินกำหนด (' . OLLAMA_TIMEOUT . ' วินาที) อาจเป็นเพราะกำลังโหลดโมเดลครั้งแรกหรือเครื่องทำงานช้า '
                 . 'ลองใหม่อีกครั้ง หรือเพิ่มค่า OLLAMA_TIMEOUT ใน config';
        }
        return 'เชื่อมต่อ Ollama ไม่ได้ที่ ' . ollamaBaseUrl() . ' กรุณาตรวจว่า Ollama เปิดอยู่ (เปิดแอป Ollama หรือรันคำสั่ง ollama serve) (' . $e . ')';
    }

    $msg = is_array($res['data']) ? (string) ($res['data']['error'] ?? '') : '';
    if ($msg === '') {
        $msg = 'HTTP ' . $res['httpCode'];
    }
    $lower = strtolower($msg);

    if ($res['httpCode'] === 404 || (strpos($lower, 'model') !== false && strpos($lower, 'not found') !== false)) {
        $name = $model !== '' ? $model : 'ที่กำหนด';
        return 'ไม่พบโมเดล "' . $name . '" ในเครื่อง กรุณาติดตั้งด้วยคำสั่ง: ollama pull ' . ($model !== '' ? $model : '<ชื่อโมเดล>');
    }
    if (strpos($lower, 'memory') !== false) {
        return 'หน่วยความจำ (RAM/VRAM) ของเครื่องไม่พอสำหรับโมเดล ' . $model . ' ลองปิดโปรแกรมอื่น หรือลดค่า OLLAMA_NUM_CTX ใน config (' . $msg . ')';
    }
    if (strpos($lower, 'image') !== false || strpos($lower, 'vision') !== false || strpos($lower, 'multimodal') !== false) {
        return 'โมเดล ' . $model . ' ใช้วิเคราะห์ภาพไม่ได้ กรุณาตั้ง OLLAMA_VISION_MODEL เป็นโมเดลที่รองรับภาพ เช่น gemma3:4b (' . $msg . ')';
    }
    return 'Ollama ตอบกลับผิดพลาด: ' . $msg;
}

/** ข้อความต่อท้ายคำถามเมื่อต้องถามซ้ำเพราะโมเดลตอบเป็นภาษาอื่น */
const OLLAMA_THAI_RETRY_NOTE = '(สำคัญมาก: ตอบเป็นภาษาไทยเท่านั้น ห้ามมีภาษาอังกฤษ ห้ามเขียนขั้นตอนการคิดหรือการวิเคราะห์ ให้ตอบคำตอบสุดท้ายทันที)';

/**
 * ตัดขั้นตอนคิด (reasoning) ที่โมเดลอย่าง qwen3 อาจแทรกมาในคำตอบ ครอบคลุม 3 รูปแบบ:
 *   <think>...</think>คำตอบ        (แบบมีแท็กครบ)
 *   ...ขั้นตอนคิด...</think>คำตอบ   (เทมเพลตใส่ <think> ไว้ใน prompt แล้ว คำตอบเลยมีแต่แท็กปิด)
 *   <think>...                      (ถูกตัดกลางคันจนไม่มีแท็กปิด)
 */
function ollamaStripThink(string $text): string
{
    $text = preg_replace('/<think>.*?<\/think>/su', '', $text) ?? $text;
    $pos = strripos($text, '</think>');
    if ($pos !== false) {
        $text = substr($text, $pos + 8);
    }
    $pos = stripos($text, '<think>');
    if ($pos !== false) {
        $text = substr($text, 0, $pos);
    }
    return trim($text);
}

/** นับตัวอักษรไทย / ละติน ในข้อความ */
function ollamaLetterCounts(string $s): array
{
    return [(int) preg_match_all('/\p{Thai}/u', $s), (int) preg_match_all('/[A-Za-z]/', $s)];
}

/** ข้อความนี้เป็นภาษาไทยเป็นหลักหรือไม่ (ข้อความสั้นเกินไปจะไม่ตัดสิน) */
function ollamaIsThai(string $s, float $minRatio = 0.6): bool
{
    [$th, $la] = ollamaLetterCounts($s);
    if ($th + $la < 12) {
        return true;
    }
    return ($th / ($th + $la)) >= $minRatio;
}

/** บรรทัดนี้มีประโยคภาษาอังกฤษหรือไม่ (คำภาษาอังกฤษติดกัน 4 คำขึ้นไป เช่น "The first entry says") */
function ollamaHasEnglishSentence(string $line): bool
{
    return (bool) preg_match("/[A-Za-z][A-Za-z']*(?:[ ,;:.!?]+[A-Za-z][A-Za-z']*){3,}/", $line);
}

/**
 * กรองให้เหลือเฉพาะภาษาไทย
 * - ข้อความที่เป็นไทยเป็นหลักอยู่แล้ว (แม้มีศัพท์อังกฤษปน เช่น NPK, pH) คืนตามเดิม ไม่แตะต้อง
 * - ถ้ามีขั้นตอนคิดภาษาอังกฤษหลุดมา (ซึ่งมาก่อนคำตอบจริงเสมอ) จะตัดตั้งแต่ต้นถึงบรรทัดสุดท้ายที่เป็นประโยคอังกฤษ
 *   (รวมบรรทัดที่ยกข้อความไทยมาอ้างในประโยคอังกฤษ) เหลือคำตอบไทยที่ตามหลัง
 * - ถ้าประโยคอังกฤษอยู่ท้ายสุด ใช้วิธีตัดเฉพาะบรรทัดที่เป็นอังกฤษแทน
 * @return string ข้อความที่สะอาดแล้ว หรือ '' ถ้าไม่เหลือส่วนภาษาไทยที่ใช้ได้
 */
function ollamaThaiFilter(string $text): string
{
    $lines = preg_split('/\R/u', $text);
    $tidy = fn(array $ls) => trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $ls)));

    if (ollamaIsThai($text)) {
        // ภาพรวมเป็นไทยแล้ว แต่ยังตัดบรรทัดที่เป็นประโยคอังกฤษล้วนที่แทรกมา (เช่น "Hope this helps...")
        // บรรทัดไทยที่มีศัพท์อังกฤษปนไม่ถูกแตะ เพราะตัวอักษรไทยในบรรทัดนั้นมากกว่า
        $keep = [];
        foreach ($lines as $line) {
            [$th, $la] = ollamaLetterCounts($line);
            if (ollamaHasEnglishSentence($line) && $la >= 8 && $la > $th) {
                continue;
            }
            $keep[] = $line;
        }
        if (count($keep) === count($lines)) {
            return $text;
        }
        $clean = $tidy($keep);
        return ($clean !== '' && ollamaIsThai($clean)) ? $clean : $text;
    }

    // วิธีที่ 1: ตัดทุกอย่างจนถึงบรรทัดสุดท้ายที่เป็นประโยคอังกฤษ
    $last = -1;
    foreach ($lines as $i => $line) {
        if (ollamaHasEnglishSentence($line)) {
            $last = $i;
        }
    }
    if ($last >= 0) {
        $clean = $tidy(array_slice($lines, $last + 1));
        if ($clean !== '' && ollamaIsThai($clean) && ollamaLetterCounts($clean)[0] >= 10) {
            return $clean;
        }
    }

    // วิธีที่ 2: ตัดเฉพาะบรรทัดที่เป็นอังกฤษ (ประโยคอังกฤษ หรือตัวอังกฤษมากกว่าไทย)
    $keep = [];
    foreach ($lines as $line) {
        [$th, $la] = ollamaLetterCounts($line);
        if (ollamaHasEnglishSentence($line) || ($la >= 8 && $la > $th)) {
            continue;
        }
        $keep[] = $line;
    }
    $clean = $tidy($keep);
    return ($clean !== '' && ollamaIsThai($clean) && ollamaLetterCounts($clean)[0] >= 10) ? $clean : '';
}

/** โมเดลที่ต้องส่ง think:false เพื่อปิดโหมดคิด (ตอบเร็วขึ้นมากบนเครื่องส่วนตัว) */
function ollamaNeedsThinkOff(string $model): bool
{
    return (bool) preg_match(OLLAMA_NO_THINK_PATTERN, $model);
}

/**
 * เรียก /api/chat (ใช้ร่วมกันทั้งแชท วิเคราะห์ JSON และ Vision)
 * @param array $opts maxTokens, temperature, format ('json' หรือ JSON Schema), timeout
 */
function ollamaChatCompletion(string $model, array $messages, array $opts = []): array
{
    // qwen3: สวิตช์ /no_think ท้ายคำถามช่วยปิดโหมดคิด (ซ้ำซ้อนกับ think:false เผื่อ Ollama รุ่นที่ไม่รองรับ)
    if (ollamaNeedsThinkOff($model) && !empty($messages)) {
        $li = count($messages) - 1;
        if (($messages[$li]['role'] ?? '') === 'user' && strpos((string) $messages[$li]['content'], '/no_think') === false) {
            $messages[$li]['content'] .= "\n\n/no_think";
        }
    }
    $body = [
        'model'      => $model,
        'messages'   => $messages,
        'stream'     => false,
        'keep_alive' => OLLAMA_KEEP_ALIVE,
        'options'    => [
            'temperature' => $opts['temperature'] ?? 0.4,
            'num_predict' => min((int) ($opts['maxTokens'] ?? 1024), OLLAMA_MAX_PREDICT),
            'num_ctx'     => OLLAMA_NUM_CTX,
        ],
    ];
    if (isset($opts['format'])) {
        $body['format'] = $opts['format'];
    }
    $thinkSent = ollamaNeedsThinkOff($model);
    if ($thinkSent) {
        $body['think'] = false;
    }

    $res = ollamaRequest('POST', '/api/chat', $body, (int) ($opts['timeout'] ?? 0));

    // Ollama รุ่นเก่าอาจไม่รู้จัก think / format แบบ Schema → ถอยกลับทีละขั้นแล้วลองใหม่ (สูงสุด 2 ครั้ง)
    for ($attempt = 0; $attempt < 2; $attempt++) {
        if ($res['curlErr'] !== '' || $res['httpCode'] !== 400 || !is_array($res['data'])) {
            break;
        }
        $err = strtolower((string) ($res['data']['error'] ?? ''));
        $changed = false;
        if (isset($body['think']) && strpos($err, 'think') !== false) {
            unset($body['think']);
            $changed = true;
        }
        if (isset($body['format']) && is_array($body['format'])
            && (strpos($err, 'format') !== false || strpos($err, 'schema') !== false || strpos($err, 'unmarshal') !== false)) {
            $body['format'] = 'json';
            $changed = true;
        }
        if (!$changed) {
            break;
        }
        $res = ollamaRequest('POST', '/api/chat', $body, (int) ($opts['timeout'] ?? 0));
    }

    if ($res['curlErr'] !== '' || $res['httpCode'] >= 400 || !is_array($res['data'])) {
        return ['success' => false, 'error' => ollamaFriendlyError($res, $model)];
    }

    $data = $res['data'];
    $text = ollamaStripThink((string) ($data['message']['content'] ?? ''));
    if ($text === '') {
        return ['success' => false, 'error' => 'โมเดล ' . $model . ' ไม่ได้ส่งข้อความกลับมา (ถ้าใช้โมเดลแบบ reasoning อาจใช้โทเคนหมดไปกับขั้นตอนคิด ให้ลองเพิ่ม OLLAMA_MAX_PREDICT)'];
    }

    $prompt = (int) ($data['prompt_eval_count'] ?? 0);
    $completion = (int) ($data['eval_count'] ?? 0);

    if (!empty($opts['thaiOnly'])) {
        $thai = ollamaThaiFilter($text);
        if ($thai === '') {
            if (!empty($opts['_retry'])) {
                return ['success' => false, 'error' => 'AI ตอบกลับเป็นภาษาอื่นแทนภาษาไทย กรุณาลองถามใหม่อีกครั้ง'];
            }
            // โมเดลตอบเป็นอังกฤษทั้งหมด -> ถามซ้ำ 1 ครั้งพร้อมย้ำให้ตอบไทย
            $retryMsgs = $messages;
            $retryMsgs[count($retryMsgs) - 1]['content'] .= "\n\n" . OLLAMA_THAI_RETRY_NOTE;
            $r2 = ollamaChatCompletion($model, $retryMsgs, array_merge($opts, ['_retry' => true]));
            if ($r2['success']) {
                $r2['usage'] = [
                    'prompt'     => $r2['usage']['prompt'] + $prompt,
                    'completion' => $r2['usage']['completion'] + $completion,
                    'total'      => $r2['usage']['total'] + $prompt + $completion,
                ];
            }
            return $r2;
        }
        $text = $thai;
    }

    return [
        'success' => true,
        'text'    => $text,
        'usage'   => ['prompt' => $prompt, 'completion' => $completion, 'total' => $prompt + $completion],
        'model'   => $model,
    ];
}

/** วิเคราะห์ภาพด้วยโมเดล Vision (ส่งภาพเป็น base64 ใน message.images) */
function ollamaVision(string $imagePath, string $prompt, array $opts = []): array
{
    if (!is_file($imagePath)) {
        return ['success' => false, 'error' => 'ไม่พบไฟล์ภาพ'];
    }
    $bytes = file_get_contents($imagePath);
    if ($bytes === false || $bytes === '') {
        return ['success' => false, 'error' => 'อ่านไฟล์ภาพไม่ได้'];
    }
    $messages = [[
        'role'    => 'user',
        'content' => $prompt,
        'images'  => [base64_encode($bytes)],
    ]];
    $fmt = $opts['format'] ?? null;
    $o = [
        'maxTokens'   => $opts['maxOutputTokens'] ?? 600,
        'temperature' => $opts['temperature'] ?? 0.2,
        'timeout'     => $opts['timeout'] ?? 0,
        'thaiOnly'    => $fmt === null,   // โหมด JSON ตรวจภาษาที่ชั้นบน (ai_provider) เพราะมี key ภาษาอังกฤษ
    ];
    if ($fmt !== null) {
        $o['format'] = $fmt;
    }
    return ollamaChatCompletion(OLLAMA_VISION_MODEL, $messages, $o);
}

/**
 * สร้าง Embedding หลายข้อความในครั้งเดียว (เร็วกว่าเรียกทีละข้อความมาก)
 * @param string[] $texts
 * @return array ['success'=>true,'embeddings'=>[[...],...],'usage'=>[...],'model'=>string]
 */
function ollamaEmbedMany(array $texts): array
{
    $texts = array_values($texts);
    if (empty($texts)) {
        return ['success' => false, 'error' => 'ไม่มีข้อความสำหรับสร้าง Embedding'];
    }
    $model = OLLAMA_EMBED_MODEL;

    $res = ollamaRequest('POST', '/api/embed', [
        'model'      => $model,
        'input'      => $texts,
        'truncate'   => true,
        'keep_alive' => OLLAMA_KEEP_ALIVE,
    ]);

    // Ollama รุ่นเก่า (ก่อน 0.1.26) ไม่มี /api/embed → ใช้ /api/embeddings ทีละข้อความ
    if ($res['curlErr'] === '' && $res['httpCode'] === 404 && !is_array($res['data'])) {
        $vectors = [];
        foreach ($texts as $t) {
            $r = ollamaRequest('POST', '/api/embeddings', ['model' => $model, 'prompt' => $t, 'keep_alive' => OLLAMA_KEEP_ALIVE]);
            if ($r['curlErr'] !== '' || $r['httpCode'] >= 400 || empty($r['data']['embedding'])) {
                return ['success' => false, 'error' => ollamaFriendlyError($r, $model)];
            }
            $vectors[] = $r['data']['embedding'];
        }
        return ['success' => true, 'embeddings' => $vectors, 'usage' => ['prompt' => 0, 'completion' => 0, 'total' => 0], 'model' => $model];
    }

    if ($res['curlErr'] !== '' || $res['httpCode'] >= 400 || !is_array($res['data'])) {
        return ['success' => false, 'error' => ollamaFriendlyError($res, $model)];
    }

    $vectors = $res['data']['embeddings'] ?? null;
    if (!is_array($vectors) || count($vectors) !== count($texts) || empty($vectors[0])) {
        return ['success' => false, 'error' => 'ไม่ได้รับเวกเตอร์ Embedding ครบตามจำนวนข้อความ'];
    }
    $prompt = (int) ($res['data']['prompt_eval_count'] ?? 0);
    return [
        'success'    => true,
        'embeddings' => $vectors,
        'usage'      => ['prompt' => $prompt, 'completion' => 0, 'total' => $prompt],
        'model'      => $model,
    ];
}

/* =========================================================
 * สถานะ / ตรวจสอบการติดตั้ง (ใช้ในหน้า Admin)
 * ========================================================= */

/** ตรวจว่าโมเดลที่ต้องการติดตั้งอยู่แล้วหรือไม่ ("bge-m3" จะตรงกับ "bge-m3:latest") */
function ollamaModelInstalled(string $wanted, array $installedNames): bool
{
    $wanted = strtolower(trim($wanted));
    foreach ($installedNames as $name) {
        $name = strtolower($name);
        if ($name === $wanted) return true;
        if (strpos($wanted, ':') === false && $name === $wanted . ':latest') return true;
    }
    return false;
}

/**
 * สถานะการเชื่อมต่อ Ollama และโมเดลที่ติดตั้ง
 * @return array ['reachable'=>bool,'error'=>string,'version'=>string,'models'=>[...],'roles'=>[...]]
 */
function ollamaStatus(): array
{
    $tags = ollamaRequest('GET', '/api/tags', null, 6);
    $roles = [
        'text'   => ['label' => 'แชท / วิเคราะห์ / แนะนำพืช', 'model' => OLLAMA_TEXT_MODEL,   'installed' => false],
        'vision' => ['label' => 'วิเคราะห์ภาพ (Vision)',      'model' => OLLAMA_VISION_MODEL, 'installed' => false],
        'embed'  => ['label' => 'Embedding สำหรับ RAG',       'model' => OLLAMA_EMBED_MODEL,  'installed' => false],
    ];
    if ($tags['curlErr'] !== '' || $tags['httpCode'] >= 400 || !is_array($tags['data'])) {
        return ['reachable' => false, 'error' => ollamaFriendlyError($tags), 'version' => '', 'models' => [], 'roles' => $roles];
    }

    $models = [];
    $names = [];
    foreach (($tags['data']['models'] ?? []) as $m) {
        $names[] = (string) ($m['name'] ?? '');
        $models[] = [
            'name'   => (string) ($m['name'] ?? ''),
            'size'   => (int) ($m['size'] ?? 0),
            'params' => (string) ($m['details']['parameter_size'] ?? ''),
            'quant'  => (string) ($m['details']['quantization_level'] ?? ''),
        ];
    }
    foreach ($roles as $k => $r) {
        $roles[$k]['installed'] = ollamaModelInstalled($r['model'], $names);
    }

    $ver = ollamaRequest('GET', '/api/version', null, 4);
    return [
        'reachable' => true,
        'error'     => '',
        'version'   => is_array($ver['data']) ? (string) ($ver['data']['version'] ?? '') : '',
        'models'    => $models,
        'roles'     => $roles,
    ];
}
