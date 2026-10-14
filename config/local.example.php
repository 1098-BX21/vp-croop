<?php
/**
 * ตัวอย่างไฟล์ตั้งค่าเฉพาะเครื่อง
 * คัดลอกไฟล์นี้เป็น  config/local.php  แล้วแก้เฉพาะค่าที่ต้องการ
 * (ไฟล์ local.php จะถูกอ่านก่อนค่าเริ่มต้นใน config.php และโฟลเดอร์ config ถูกปิดไม่ให้เข้าผ่านเว็บ)
 */

// ตัวอย่าง: Ollama รันอยู่อีกเครื่องหนึ่งในเครือข่ายเดียวกัน
// (เครื่องที่รัน Ollama ต้องตั้ง OLLAMA_HOST=0.0.0.0 และเปิดพอร์ต 11434 เฉพาะในเครือข่ายที่เชื่อถือได้)
// define('OLLAMA_BASE_URL', 'http://192.168.1.50:11434');

// ตัวอย่าง: เปลี่ยนโมเดล
// define('OLLAMA_TEXT_MODEL', 'qwen3:8b');
// define('OLLAMA_VISION_MODEL', 'gemma3:4b');
// define('OLLAMA_EMBED_MODEL', 'bge-m3');

// ตัวอย่าง: เครื่อง RAM น้อย ลด context window
// define('OLLAMA_NUM_CTX', 4096);

// ตัวอย่าง: เครื่องช้า เพิ่มเวลารอ
// define('OLLAMA_TIMEOUT', 600);
