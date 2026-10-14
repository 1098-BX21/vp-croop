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

// Rate limit
if (!checkRateLimit($uid, 'ai_advice', RATE_LIMIT_ADVICE_PER_DAY)) {
    echo json_encode(['success' => false, 'message' => 'คุณขอคำแนะนำครบตามโควตาต่อวันแล้ว (' . RATE_LIMIT_ADVICE_PER_DAY . ' ครั้ง) กรุณาลองใหม่พรุ่งนี้']);
    exit;
}

$plotId = (int) ($_POST['plot_id'] ?? 0);

// ---- 1) โหลดข้อมูลแปลง + คำนวณคะแนนความเหมาะสมของพืชทุกชนิด (กฎในระบบ ไม่ใช้ AI) ----
$error = null;
$in = cropScoreLoadInput($pdo, $plotId, $uid, $error);
if (!$in) {
    echo json_encode(['success' => false, 'message' => $error]);
    exit;
}
$scored = cropScoreRank($in);
if (!$scored['ranking']) {
    echo json_encode(['success' => false, 'message' => 'ไม่มีพืชอื่นให้แนะนำ เพราะพืชทั้งหมดในระบบซ้ำกับรอบล่าสุด กรุณาให้ผู้ดูแลระบบเพิ่มข้อมูลพืช']);
    exit;
}
$ranking = $scored['ranking'];
$top = $ranking[0];
$plot = $in['plot'];
$soil = $in['soil_row'];

// ---- 2) เตรียมข้อมูลให้ AI เขียนคำอธิบาย (AI ห้ามเปลี่ยนอันดับ/ตัวเลข) ----
$fmt = fn($v) => $v === null ? 'ไม่ระบุ' : (string) $v;
$soilInfo = "pH {$soil['ph_value']}, ไนโตรเจน (N) " . $fmt($soil['nitrogen']) . ", ฟอสฟอรัส (P) " . $fmt($soil['phosphorus']) . ", โพแทสเซียม (K) " . $fmt($soil['potassium']) . " mg/kg";
if ($soil['soil_type']) {
    $soilInfo .= ", ประเภทดิน: {$soil['soil_type']}";
}
if ($soil['soil_vision_result']) {
    $soilInfo .= ", ผลวิเคราะห์ภาพดิน: {$soil['soil_vision_result']}";
}
$waterInfo = $plot['has_water_source'] ? 'มีแหล่งน้ำ (' . ($plot['water_source_types'] ?: 'ไม่ระบุประเภท') . ')' : 'ไม่มีแหล่งน้ำ';
$lastCropName = $scored['context']['last_crop'] ?? null;

$rankText = '';
foreach (array_slice($ranking, 0, 3) as $i => $item) {
    $rankText .= ($i + 1) . '. ' . $item['crop_name'] . ' คะแนน ' . number_format($item['total'], 1) . "/100\n";
    foreach ($item['parts'] as $key => $p) {
        $rankText .= '   - ' . cropScorePartLabel($key) . ': ' . adviceFmtScore((float) $p['score']) . ($key === 'actual' ? '' : '/' . adviceFmtScore((float) $p['max'])) . ' — ' . $p['note'] . "\n";
    }
}
foreach ($scored['excluded'] as $ex) {
    $rankText .= 'ไม่แนะนำ: ' . $ex['crop_name'] . ' — ' . $ex['reason'] . "\n";
}

$topCrop = null;
foreach ($in['crops'] as $c) {
    if ((int) $c['crop_id'] === (int) $top['crop_id']) {
        $topCrop = $c;
    }
}
$formula = ($topCrop['fertilizer_formula'] ?? '') !== '' && ($topCrop['fertilizer_formula'] ?? '-') !== '-' ? $topCrop['fertilizer_formula'] : 'ไม่ระบุ (พืชปุ๋ยสด/ไม่ต้องใส่ปุ๋ยเคมี)';
$recoveryNote = $topCrop['soil_recovery_note'] ?? '';

// RAG: ดึงบริบทจากคลังความรู้ที่เกี่ยวกับพืชอันดับ 1
// ใส่คำบอกสภาพดิน (กรด/ด่าง) ในคำค้น เพื่อให้ดึงเอกสารเรื่องการปรับปรุงดินที่ตรงสถานการณ์
$soilWord = (float) $soil['ph_value'] < 5.5 ? ' ดินกรด' : ((float) $soil['ph_value'] > 7.5 ? ' ดินด่าง' : '');
$ragContext = ragQueryContext("การปลูก{$top['crop_name']} การใส่ปุ๋ย การปรับปรุงดิน{$soilWord} pH {$soil['ph_value']}");
$ragBlock = $ragContext !== '' ? "\nข้อมูลอ้างอิงจากคลังความรู้วิชาการเกษตร (ใช้ประกอบถ้าเกี่ยวข้อง):\n{$ragContext}\n" : '';

$prompt = <<<PROMPT
คุณเป็นผู้เชี่ยวชาญด้านการเกษตรและการปลูกพืชหมุนเวียน ระบบได้คำนวณคะแนนความเหมาะสมของพืชแต่ละชนิดไว้แล้ว (เต็ม 100) งานของคุณคืออธิบายผลให้เกษตรกรเข้าใจง่าย

ข้อมูลแปลง: {$plot['farm_name']} - {$plot['plot_name']}
สภาพดินล่าสุด: {$soilInfo}
แหล่งน้ำ: {$waterInfo}
พืชที่ปลูกล่าสุด: {$lastCropName}

ผลจัดอันดับที่ระบบคำนวณ (ห้ามเปลี่ยนอันดับ ห้ามแต่งตัวเลขใหม่):
{$rankText}
ข้อมูลพืชอันดับ 1: สูตรปุ๋ยที่ใช้ทั่วไป {$formula}; หมายเหตุการฟื้นฟูดิน: {$recoveryNote}
{$ragBlock}
ให้เขียน 2 ส่วน:
- reason: อธิบาย 3-4 ประโยคว่าทำไมพืชอันดับ 1 จึงเหมาะสม โดยอ้างอิงเหตุผลจากที่มาของคะแนนด้านบน และบอกข้อควรระวังตามความจริง (ถ้า pH อยู่นอกช่วงเหมาะสม ให้แนะนำปรับปรุงดินก่อน เช่น ใส่ปูนขาวเมื่อดินเป็นกรด หรือเพิ่มอินทรียวัตถุเมื่อดินเป็นด่าง)
- fertilizer_plan: แผนปรับปรุงดินและใส่ปุ๋ยสำหรับพืชอันดับ 1 กระชับ 2-3 ประโยค

ห้ามแนะนำพืชที่ไม่อยู่ในรายการด้านบน ตอบเป็น JSON ตามรูปแบบ: {"reason": "...", "fertilizer_plan": "..."}
PROMPT;

$schema = [
    'type'       => 'object',
    'properties' => ['reason' => ['type' => 'string'], 'fertilizer_plan' => ['type' => 'string']],
    'required'   => ['reason', 'fertilizer_plan'],
];

// ---- 3) เรียก AI ในเครื่องให้เขียนคำอธิบาย (ถ้าไม่พร้อม ใช้คำอธิบายจากกฎแทน ไม่ทำให้ทั้งหน้าล้ม) ----
$ai = aiGenerateJSON($prompt, ['maxOutputTokens' => 900, 'temperature' => 0.3, 'jsonSchema' => $schema]);
$asText = fn($v) => is_array($v) ? implode(' ', array_map('strval', $v)) : trim((string) $v);
$aiWarning = null;
$explainedBy = null;
$raw = null;
$reason = '';
$fertilizerPlan = '';
if ($ai['success']) {
    $reason = $asText($ai['data']['reason'] ?? '');
    $fertilizerPlan = $asText($ai['data']['fertilizer_plan'] ?? '');
    $raw = $ai['raw'];
    $explainedBy = $ai['model'] ?? OLLAMA_TEXT_MODEL;
}
if (!$ai['success'] || $reason === '') {
    $aiWarning = $ai['success'] ? 'AI ไม่ได้ตอบคำอธิบายกลับมา' : $ai['error'];
    $explainedBy = null;
    $reason = cropScoreFallbackReason($top, $lastCropName);
    $fertilizerPlan = 'ใช้ปุ๋ยสูตร ' . $formula . ($recoveryNote !== '' ? ' — ' . $recoveryNote : '');
}

// ---- 4) บันทึกผลทั้งหมดลง DB (รวมที่มาของคะแนน เพื่อดูย้อนหลังได้) ----
$detail = [
    'version'      => 1,
    'generated_at' => date('c'),
    'context'      => $scored['context'],
    'ranking'      => array_slice($ranking, 0, 5),
    'excluded'     => $scored['excluded'],
];
$ins = $pdo->prepare('INSERT INTO ai_recommendations (plot_id, recommended_crop, reason, fertilizer_plan, raw_response, score_detail, explained_by) VALUES (?,?,?,?,?,?,?)');
$ins->execute([$plotId, $top['crop_name'], $reason, $fertilizerPlan, $raw, json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $explainedBy]);
$recommendId = (int) $pdo->lastInsertId();
logActivity($uid, 'ai_advice', 'plot#' . $plotId . ' -> ' . $top['crop_name'] . ' (' . number_format($top['total'], 1) . ')');

$row = $pdo->prepare('SELECT r.*, p.plot_name, f.farm_name FROM ai_recommendations r JOIN plots p ON p.plot_id = r.plot_id JOIN farms f ON f.farm_id = p.farm_id WHERE r.recommend_id = ?');
$row->execute([$recommendId]);

echo json_encode([
    'success'      => true,
    'recommend_id' => $recommendId,
    'ai_warning'   => $aiWarning,
    'html'         => adviceRenderCard($row->fetch(), ['fresh' => true, 'ai_warning' => $aiWarning]),
], JSON_UNESCAPED_UNICODE);
