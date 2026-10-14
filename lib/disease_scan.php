<?php
/**
 * ============================================================
 * วิเคราะห์โรคพืชจากภาพ (AI Vision: gemma3) + เก็บผลลง plant_disease_scans
 * แยกเป็นฟีเจอร์ของตัวเอง ไม่ปนกับ "คำแนะนำ AI" (การเลือกพืชปลูก)
 * ============================================================
 */

const DISEASE_STATUS_TH = ['diseased' => 'พบโรค/ศัตรูพืช', 'healthy' => 'ไม่พบความผิดปกติ', 'unclear' => 'ประเมินไม่ได้'];
const DISEASE_SEVERITY_TH = ['none' => 'ไม่มี', 'low' => 'เล็กน้อย', 'medium' => 'ปานกลาง', 'high' => 'รุนแรง'];
const DISEASE_CONFIDENCE_TH = ['low' => 'ต่ำ', 'medium' => 'ปานกลาง', 'high' => 'สูง'];

function diseaseScanSchema(): array
{
    $str = ['type' => 'string'];
    return [
        'type'       => 'object',
        'properties' => [
            'status'       => ['type' => 'string', 'enum' => ['diseased', 'healthy', 'unclear']],
            'disease_name' => $str,
            'confidence'   => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
            'severity'     => ['type' => 'string', 'enum' => ['none', 'low', 'medium', 'high']],
            'summary'      => $str,
            'symptoms'     => $str,
            'causes'       => $str,
            'treatment'    => $str,
            'prevention'   => $str,
        ],
        'required'   => ['status', 'disease_name', 'confidence', 'severity', 'summary', 'symptoms', 'causes', 'treatment', 'prevention'],
    ];
}

/**
 * วิเคราะห์ภาพพืช
 * @return array ['success'=>true,'data'=>[status,disease_name,...],'raw'=>string,'model'=>string] หรือ ['success'=>false,'error'=>string]
 */
function diseaseScanAnalyze(PDO $pdo, string $imagePath, string $plantName, string $note): array
{
    $extra = [];
    if ($plantName !== '') $extra[] = 'ชื่อพืช: ' . $plantName;
    if ($note !== '') $extra[] = 'อาการที่เกษตรกรสังเกตเห็น: ' . $note;
    $extraText = $extra ? implode("\n", $extra) : 'ไม่ได้ระบุเพิ่มเติม';

    // โรคที่ผู้ดูแลระบบบันทึกไว้ (ใช้เป็นแนวทางตั้งชื่อให้สอดคล้องกับข้อมูลในระบบ)
    $known = '';
    foreach ($pdo->query('SELECT disease_name, symptoms FROM plant_diseases ORDER BY disease_id LIMIT 15')->fetchAll() as $d) {
        $known .= '- ' . $d['disease_name'] . ($d['symptoms'] ? ' (อาการ: ' . mb_substr($d['symptoms'], 0, 100) . ')' : '') . "\n";
    }
    $knownBlock = $known !== '' ? "\nโรค/ศัตรูพืชที่มีบันทึกในระบบ (ถ้าตรงกับที่เห็นในภาพให้ใช้ชื่อนี้):\n{$known}" : '';

    $ragContext = ragQueryContext('โรคพืช อาการ การจัดการ การป้องกัน ' . trim($plantName . ' ' . $note));
    $ragBlock = $ragContext !== '' ? "\nข้อมูลอ้างอิงจากคลังความรู้ (ใช้ประกอบถ้าเกี่ยวข้อง):\n{$ragContext}\n" : '';

    $prompt = <<<PROMPT
คุณเป็นผู้เชี่ยวชาญด้านโรคพืชและแมลงศัตรูพืช ให้วิเคราะห์ภาพพืชที่แนบมา

ข้อมูลจากเกษตรกร:
{$extraText}
{$knownBlock}{$ragBlock}
หลักการ:
1. ดูเฉพาะสิ่งที่เห็นในภาพจริง อย่าเดาเกินหลักฐาน ถ้าภาพไม่ชัดหรือไม่ใช่ภาพพืช ให้ status = "unclear" และบอกใน summary ว่าควรถ่ายภาพใหม่อย่างไร
2. ถ้าไม่พบความผิดปกติ ให้ status = "healthy", severity = "none", disease_name = "ไม่พบความผิดปกติชัดเจน"
3. ถ้าพบความผิดปกติ ให้ status = "diseased" ระบุโรคหรือศัตรูพืชที่น่าจะเป็นมากที่สุดใน disease_name และตั้ง confidence ตามความมั่นใจจริง (ถ้าไม่แน่ใจให้ใช้ low)
4. summary: สรุปผลที่เห็นในภาพ 2-3 ประโยค | symptoms: อาการที่เห็น | causes: สาเหตุที่เป็นไปได้ | prevention: วิธีป้องกันไม่ให้เกิดซ้ำ
5. treatment: แนวทางจัดการเบื้องต้น เช่น ตัดส่วนที่เป็นโรค ปรับการระบายน้ำ ใช้ชีววิธี ห้ามระบุอัตราการใช้สารเคมีหรือยาฆ่าแมลงเป็นตัวเลข ให้แนะนำให้ปรึกษาเจ้าหน้าที่เกษตรหรืออ่านฉลากผลิตภัณฑ์ก่อนใช้
PROMPT;

    $r = aiVisionJSON($imagePath, $prompt, ['maxOutputTokens' => 1100, 'temperature' => 0.2, 'jsonSchema' => diseaseScanSchema()], 'disease');
    if (!$r['success']) {
        return $r;
    }

    $d = $r['data'];
    $s = fn($k) => trim(is_array($d[$k] ?? '') ? implode(' ', array_map('strval', $d[$k])) : (string) ($d[$k] ?? ''));
    $status = in_array($d['status'] ?? '', ['diseased', 'healthy', 'unclear'], true) ? $d['status'] : 'unclear';
    $severity = in_array($d['severity'] ?? '', ['none', 'low', 'medium', 'high'], true) ? $d['severity'] : null;
    $confidence = in_array($d['confidence'] ?? '', ['low', 'medium', 'high'], true) ? $d['confidence'] : null;
    if ($status === 'healthy') {
        $severity = 'none';
    }
    $name = $s('disease_name');
    if ($name === '') {
        $name = $status === 'healthy' ? 'ไม่พบความผิดปกติชัดเจน' : ($status === 'unclear' ? 'ประเมินไม่ได้' : 'ไม่ทราบชนิด');
    }

    return [
        'success' => true,
        'raw'     => $r['raw'],
        'model'   => $r['model'] ?? OLLAMA_VISION_MODEL,
        'data'    => [
            'status' => $status, 'disease_name' => $name, 'confidence' => $confidence, 'severity' => $severity,
            'summary' => $s('summary'), 'symptoms' => $s('symptoms'), 'causes' => $s('causes'),
            'treatment' => $s('treatment'), 'prevention' => $s('prevention'),
        ],
    ];
}

/** ป้ายสีของผลวิเคราะห์ (Bootstrap badge ธีมระบบ) */
function diseaseStatusBadge(string $status, ?string $severity = null): string
{
    $label = DISEASE_STATUS_TH[$status] ?? $status;
    $cls = $status === 'healthy' ? 'badge-soft-green' : ($status === 'diseased' ? ($severity === 'high' ? 'badge-soft-red' : 'badge-soft-amber') : 'badge-soft-blue');
    return '<span class="badge ' . $cls . '">' . e($label) . '</span>';
}

/** ลบไฟล์รูปในโฟลเดอร์โรคพืชอย่างปลอดภัย (ใช้เฉพาะชื่อไฟล์ ไม่รับ path) */
function diseaseScanDeleteImage(string $filename): void
{
    $safe = basename($filename);
    $path = UPLOAD_PATH_DISEASE . $safe;
    if ($safe !== '' && is_file($path)) {
        @unlink($path);
    }
}
