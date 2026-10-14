<?php
/**
 * ============================================================
 * ไฟล์ตั้งค่าหลักของระบบ (แก้ไขค่าตรงนี้ให้ตรงกับเครื่องของคุณ)
 * ============================================================
 */

// ---------- โหมดการทำงาน ----------
// 'development' = แสดง error ละเอียด | 'production' = ซ่อน error
define('APP_ENV', 'development');

// ---------- ฐานข้อมูล MySQL (สลับอัตโนมัติ: เครื่อง local ↔ เซิร์ฟเวอร์จริง) ----------
// ตรวจจากชื่อโฮสต์ที่เรียกเว็บเข้ามา: ถ้าเป็น localhost/127.0.0.1 หรือรันผ่าน CLI
// ถือว่าเป็นเครื่องพัฒนา (XAMPP) ใช้ root รหัสผ่านว่างตามค่าเริ่มต้น
// นอกเหนือจากนั้นถือว่าเป็นเซิร์ฟเวอร์จริง ใช้บัญชีฐานข้อมูลที่โฮสต์จัดให้
$isLocalDev = php_sapi_name() === 'cli'
    || in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'], true)
    || str_ends_with($_SERVER['SERVER_NAME'] ?? '', '.local');

if ($isLocalDev) {
    // ---- เครื่อง local (XAMPP) ----
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'crop_rotation_258');
    define('DB_USER', 'root');
    define('DB_PASS', '');            // XAMPP ปกติรหัสผ่านว่าง
} else {
    // ---- เซิร์ฟเวอร์จริง ----
    define('DB_HOST', 'localhost');   // ปกติ DB อยู่เครื่องเดียวกับเว็บบนโฮสติ้งทั่วไป เปลี่ยนได้ถ้า DB แยกเครื่อง
    define('DB_NAME', 'db258');
    define('DB_USER', 'usr258');
    define('DB_PASS', 'pwdproject123');
}
define('DB_CHARSET', 'utf8mb4');

// ---------- AI ภายในเครื่อง (Ollama) ----------
// ระบบใช้โมเดลที่รันบนเครื่องผ่าน Ollama (ไม่ใช้ API Key ไม่ต้องต่ออินเทอร์เน็ตตอนใช้งาน)
// ติดตั้งโมเดลที่ต้องใช้ (ทำครั้งเดียว):
//     ollama pull qwen3:4b      (แชท / วิเคราะห์ / แนะนำพืช)
//     ollama pull gemma3:4b     (วิเคราะห์ภาพดิน / ใบพืช)
//     ollama pull bge-m3        (Embedding สำหรับ RAG)
//
// ถ้าต้องการเปลี่ยนค่า (เช่น Ollama อยู่คนละเครื่องกับเว็บ) ให้คัดลอก config/local.example.php
// เป็น config/local.php แล้วแก้เฉพาะค่าที่ต้องการ โดยไม่ต้องแก้ไฟล์นี้
if (file_exists(__DIR__ . '/local.php')) {
    require __DIR__ . '/local.php';
}
$ollamaDefaults = [
    'OLLAMA_BASE_URL'        => 'http://127.0.0.1:11434', // ที่อยู่ Ollama (ใช้ 127.0.0.1 ไม่ใช่ localhost เพื่อเลี่ยงปัญหา IPv6 บน Windows)
    'OLLAMA_TEXT_MODEL'      => 'qwen3:4b',               // โมเดลข้อความ
    'OLLAMA_VISION_MODEL'    => 'gemma3:4b',              // โมเดลวิเคราะห์ภาพ
    'OLLAMA_EMBED_MODEL'     => 'bge-m3',                 // โมเดล Embedding (1024 มิติ)
    'OLLAMA_NUM_CTX'         => 8192,                     // ขนาด context window (โทเคน) ถ้า RAM น้อยลดเป็น 4096
    'OLLAMA_MAX_PREDICT'     => 2048,                     // เพดานจำนวนโทเคนที่โมเดลตอบได้ต่อครั้ง
    'OLLAMA_KEEP_ALIVE'      => '30m',                    // ค้างโมเดลไว้ใน RAM นานเท่าไรหลังใช้งาน (ลดเวลาโหลดครั้งต่อไป)
    'OLLAMA_TIMEOUT'         => 300,                      // เวลารอสูงสุดต่อคำขอ (วินาที) เครื่องช้าให้เพิ่ม
    'OLLAMA_NO_THINK_PATTERN'=> '/^qwen3/i',              // โมเดลที่ต้องปิดโหมดคิด (think) เพื่อให้ตอบเร็ว
];
foreach ($ollamaDefaults as $k => $v) {
    if (!defined($k)) {
        define($k, $v);
    }
}

// ---------- แอปพลิเคชัน ----------
define('APP_NAME', 'ระบบวิเคราะห์การปลูกพืชหมุนเวียนด้วย AI');
define('APP_NAME_SHORT', 'Smart Farm Advisory');

// ---------- Rate limit (จำกัดการเรียก AI ต่อผู้ใช้ต่อวัน) ----------
define('RATE_LIMIT_ADVICE_PER_DAY', 30);
define('RATE_LIMIT_CHAT_PER_DAY', 100);
define('RATE_LIMIT_DISEASE_PER_DAY', 20);   // วิเคราะห์โรคพืชจากภาพ ต่อผู้ใช้ต่อวัน

// ---------- RAG ----------
// (แก้ที่นี่ หรือกำหนดค่าเดียวกันใน config/local.php เพื่อไม่ต้องแก้ไฟล์นี้)
$ragDefaults = [
    'RAG_CHUNK_SIZE'     => 800,   // ความยาวตัวอักษรต่อ chunk
    'RAG_CHUNK_OVERLAP'  => 120,   // ตัวอักษรที่ทับซ้อนระหว่าง chunk
    'RAG_TOP_K'          => 3,     // จำนวน chunk ที่ดึงมาเป็น context
    'RAG_MIN_SIMILARITY' => 0.40,  // คะแนนความคล้ายขั้นต่ำ (ปรับตามข้อมูลจริงด้วยกล่อง "ทดสอบการค้นหา" ในหน้าคลังความรู้ AI)
    'RAG_EMBED_BATCH'    => 8,     // จำนวน chunk ที่ส่งสร้างเวกเตอร์ต่อครั้งตอน index เอกสาร
];
foreach ($ragDefaults as $k => $v) {
    if (!defined($k)) {
        define($k, $v);
    }
}

// ============================================================
// ส่วนด้านล่างนี้ไม่ต้องแก้
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

error_reporting(E_ALL);
ini_set('display_errors', APP_ENV === 'development' ? '1' : '0');
date_default_timezone_set('Asia/Bangkok');

// คำนวณ BASE_URL อัตโนมัติจากตำแหน่งที่วางโปรเจกต์
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$parts = explode('/', trim($scriptDir, '/'));
$rootParts = [];
foreach ($parts as $p) {
    if (in_array($p, ['public', 'user', 'admin', 'api'], true)) {
        break;
    }
    $rootParts[] = $p;
}
define('BASE_URL', '/' . implode('/', $rootParts));

// path สำหรับไฟล์อัปโหลด
define('UPLOAD_PATH_SOIL', __DIR__ . '/../uploads/soil/');
define('UPLOAD_PATH_DISEASE', __DIR__ . '/../uploads/disease/');
define('UPLOAD_PATH_KNOWLEDGE', __DIR__ . '/../uploads/knowledge/');
define('UPLOAD_PATH_AVATAR', __DIR__ . '/../uploads/avatars/');
define('UPLOAD_PATH_FEEDBACK', __DIR__ . '/../uploads/feedback/');
define('UPLOAD_PATH_CASE', __DIR__ . '/../uploads/case_studies/');

// เรียกไฟล์หลักที่ใช้ร่วมกัน
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/ollama.php';
require_once __DIR__ . '/../lib/ai_provider.php';
require_once __DIR__ . '/../lib/rag.php';
require_once __DIR__ . '/../lib/crop_score.php';
require_once __DIR__ . '/../lib/advice_render.php';
require_once __DIR__ . '/../lib/disease_scan.php';
require_once __DIR__ . '/../lib/grow_plan.php';
require_once __DIR__ . '/../lib/farm_report.php';
require_once __DIR__ . '/../lib/provinces.php';
require_once __DIR__ . '/../lib/user_geo.php';
require_once __DIR__ . '/../lib/case_studies.php';
