<?php
/**
 * ============================================================
 * จังหวัดของไทย (77 จังหวัด) — ใช้ในฟอร์มสมัครสมาชิก/โปรไฟล์ และหน้า Admin "ผู้ใช้งานตามจังหวัด" (แผนที่)
 *
 * ข้อมูลรายชื่อและรูปร่างจังหวัดมาจากชุดข้อมูลเดียวกัน (lib/data/) ชื่อจึงตรงกันเสมอ
 * ที่มา: chingchai/OpenGISData-Thailand ใบอนุญาต CC BY 4.0 อ้างอิงข้อมูลขอบเขตการปกครอง
 *        ของกรมการปกครอง กระทรวงมหาดไทย (มีการลดจำนวนจุดของรูปร่างเพื่อให้เว็บโหลดเร็ว)
 * ============================================================
 */

const TH_REGION_TH = [
    'North' => 'เหนือ', 'Northeast' => 'ตะวันออกเฉียงเหนือ', 'Central' => 'กลาง',
    'East' => 'ตะวันออก', 'West' => 'ตะวันตก', 'South' => 'ใต้',
];

/** ['ชื่อไทย' => ['code'=>'44','en'=>'Maha Sarakham','region'=>'Northeast'], ...] เรียงตามชื่อ */
function thProvinces(): array
{
    static $data = null;
    if ($data === null) {
        $data = require __DIR__ . '/data/provinces_data.php';
        // เรียงแบบพจนานุกรมไทย: ไม่นับสระหน้า (เ แ โ ใ ไ) เช่น "เชียงราย" อยู่หมวด ช "เลย" อยู่หมวด ล
        $key = fn($n) => preg_replace('/^[เแโใไ]/u', '', $n);
        uksort($data, fn($a, $b) => strcmp($key($a), $key($b)) ?: strcmp($a, $b));
    }
    return $data;
}

function thProvinceNames(): array
{
    return array_keys(thProvinces());
}

/** ชื่อจังหวัดนี้อยู่ในรายชื่อ 77 จังหวัดหรือไม่ (ตรงตัวอักษร) */
function thProvinceValid(?string $name): bool
{
    return $name !== null && isset(thProvinces()[$name]);
}

function thRegionLabel(string $regionKey): string
{
    return 'ภาค' . (TH_REGION_TH[$regionKey] ?? $regionKey);
}

/** รูปร่างจังหวัดสำหรับวาด SVG (ไฟล์ใหญ่ โหลดเฉพาะหน้าที่ใช้แผนที่) */
function thMapData(): array
{
    static $map = null;
    if ($map === null) {
        $map = require __DIR__ . '/data/thailand_map_data.php';
    }
    return $map;
}

/** <option> ของจังหวัดทั้งหมด (ใช้ในฟอร์มสมัคร/โปรไฟล์) */
function thProvinceOptions(?string $selected = null, string $placeholder = '— เลือกจังหวัด —'): string
{
    $h = '<option value="">' . e($placeholder) . '</option>';
    foreach (thProvinceNames() as $n) {
        $h .= '<option value="' . e($n) . '"' . ($n === $selected ? ' selected' : '') . '>' . e($n) . '</option>';
    }
    return $h;
}
