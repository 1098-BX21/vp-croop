<?php
/**
 * ============================================================
 * สถิติผู้ใช้งานตามจังหวัด + แผนที่ประเทศไทย (SVG) สำหรับหน้า Admin
 *
 * นับเฉพาะผู้ใช้บทบาท "เกษตรกร" (ผู้ดูแลระบบไม่ถูกนับ) จังหวัดมาจากที่ผู้ใช้เลือกตอนสมัคร/ในโปรไฟล์
 * ผู้ใช้เดิมที่ยังไม่ระบุจังหวัดแสดงแยกเป็น "ไม่ระบุจังหวัด" (ไม่ถูกนับลงจังหวัดใด)
 * ============================================================
 */

const USER_GEO_NONE = '__none';   // ค่า ?province= สำหรับ "ไม่ระบุจังหวัด"

/** ระดับสีของแผนที่ตามจำนวนผู้ใช้: [จำนวนขั้นต่ำ, สีพื้น, สีตัวเลข, ป้ายคำอธิบาย] */
const USER_GEO_BINS = [
    [0,  '#eef2f0', '#6c7a74', '0 คน'],
    [1,  '#cfeee3', '#0b3d2e', '1 คน'],
    [2,  '#9fdcc6', '#0b3d2e', '2–4 คน'],
    [5,  '#5cb99f', '#ffffff', '5–9 คน'],
    [10, '#35967c', '#ffffff', '10–24 คน'],
    [25, '#1f6f5a', '#ffffff', '25 คนขึ้นไป'],
];

function userGeoBin(int $n): array
{
    $pick = USER_GEO_BINS[0];
    foreach (USER_GEO_BINS as $b) {
        if ($n >= $b[0]) {
            $pick = $b;
        }
    }
    return $pick;
}

/**
 * ภาพรวมทั้งประเทศ
 * @return array total, with_province, without_province, province_count, counts[ชื่อจังหวัด=>n], unknown (จังหวัดในฐานข้อมูลที่ไม่ตรงรายชื่อ), regions[ภาค=>n], top[[ชื่อ,n],..]
 */
function userGeoSummary(PDO $pdo): array
{
    $total = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'farmer'")->fetchColumn();
    $none = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'farmer' AND (province IS NULL OR province = '')")->fetchColumn();
    $counts = [];
    $unknown = 0;
    $valid = thProvinces();
    foreach ($pdo->query("SELECT province, COUNT(*) n FROM users WHERE role = 'farmer' AND province IS NOT NULL AND province <> '' GROUP BY province")->fetchAll() as $r) {
        if (isset($valid[$r['province']])) {
            $counts[$r['province']] = (int) $r['n'];
        } else {
            $unknown += (int) $r['n'];
        }
    }
    $regions = array_fill_keys(array_keys(TH_REGION_TH), 0);
    foreach ($counts as $name => $n) {
        $regions[$valid[$name]['region']] += $n;
    }
    arsort($counts);
    $top = [];
    foreach ($counts as $name => $n) {
        $top[] = [$name, $n];
    }
    return [
        'total'            => $total,
        'with_province'    => array_sum($counts),
        'without_province' => $none,
        'province_count'   => count($counts),
        'counts'           => $counts,
        'unknown'          => $unknown,
        'regions'          => $regions,
        'top'              => $top,
    ];
}

/**
 * รายละเอียดของจังหวัดหนึ่ง: ผู้ใช้ทั้งหมด + พืชที่ปลูก (รายคนและรวมทั้งจังหวัด)
 * @param string $province ชื่อจังหวัด หรือ USER_GEO_NONE สำหรับผู้ใช้ที่ไม่ระบุจังหวัด
 */
function userGeoProvince(PDO $pdo, string $province): array
{
    $none = $province === USER_GEO_NONE;
    $where = $none ? "u.role = 'farmer' AND (u.province IS NULL OR u.province = '')" : "u.role = 'farmer' AND u.province = :prov";
    $params = $none ? [] : [':prov' => $province];

    $us = $pdo->prepare("
        SELECT u.user_id, u.full_name, u.email, u.phone, u.status, u.created_at,
               (SELECT COUNT(*) FROM farms f WHERE f.user_id = u.user_id) AS farms,
               (SELECT COUNT(*) FROM plots p JOIN farms f ON f.farm_id = p.farm_id WHERE f.user_id = u.user_id) AS plots
        FROM users u WHERE $where ORDER BY u.created_at DESC, u.user_id DESC");
    $us->execute($params);
    $users = $us->fetchAll();

    // พืชที่แต่ละคนปลูก (จากประวัติการปลูก ซึ่งแผนการปลูกจริงสร้างรายการไว้ที่นี่ด้วย) "ยังไม่เก็บเกี่ยว" = กำลังปลูกอยู่
    $cs = $pdo->prepare("
        SELECT f.user_id, c.crop_id, c.crop_name, COUNT(*) AS times, SUM(ch.harvest_date IS NULL) AS ongoing, COALESCE(SUM(ch.yield_amount), 0) AS total_yield
        FROM crop_history ch
        JOIN plots p ON p.plot_id = ch.plot_id JOIN farms f ON f.farm_id = p.farm_id
        JOIN crops c ON c.crop_id = ch.crop_id JOIN users u ON u.user_id = f.user_id
        WHERE $where GROUP BY f.user_id, c.crop_id, c.crop_name ORDER BY c.crop_name");
    $cs->execute($params);

    $byUser = [];
    $crops = [];   // crop_id => รวมทั้งจังหวัด
    foreach ($cs->fetchAll() as $r) {
        $byUser[(int) $r['user_id']][] = ['crop_name' => $r['crop_name'], 'times' => (int) $r['times'], 'ongoing' => (int) $r['ongoing']];
        $cid = (int) $r['crop_id'];
        $crops[$cid] ??= ['crop_name' => $r['crop_name'], 'users' => 0, 'times' => 0, 'ongoing' => 0, 'total_yield' => 0.0];
        $crops[$cid]['users']++;
        $crops[$cid]['times'] += (int) $r['times'];
        $crops[$cid]['ongoing'] += (int) $r['ongoing'];
        $crops[$cid]['total_yield'] += (float) $r['total_yield'];
    }
    foreach ($users as &$u) {
        $u['crops'] = $byUser[(int) $u['user_id']] ?? [];
    }
    unset($u);
    usort($crops, fn($a, $b) => [$b['users'], $b['times'], $a['crop_name']] <=> [$a['users'], $a['times'], $b['crop_name']]);

    return ['province' => $province, 'is_none' => $none, 'users' => $users, 'crops' => array_values($crops),
            'farms' => array_sum(array_column($users, 'farms')), 'plots' => array_sum(array_column($users, 'plots'))];
}

/**
 * วาดแผนที่ประเทศไทยเป็น SVG: ระบายสีตามจำนวนผู้ใช้ คลิกจังหวัดเพื่อดูรายละเอียด (ลิงก์ธรรมดา ไม่ใช้ JavaScript)
 * @param array $counts [ชื่อจังหวัด => จำนวนผู้ใช้]
 */
function userGeoMapSvg(array $counts, ?string $selected, string $page = 'user_geo.php'): string
{
    $m = thMapData();
    $w = (int) $m['width'];
    $h = (int) $m['height'];
    $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" class="th-map" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" role="img" aria-label="แผนที่ประเทศไทยแสดงจำนวนผู้ใช้งานรายจังหวัด">';

    $drawProvince = function (string $name, array $d) use ($counts, $selected, $page): string {
        $n = (int) ($counts[$name] ?? 0);
        $bin = userGeoBin($n);
        $href = $page . '?province=' . rawurlencode($name) . '#detail';
        return '<a href="' . e($href) . '" xlink:href="' . e($href) . '" class="th-prov-link' . ($name === $selected ? ' sel' : '') . '">'
            . '<title>' . e($name . ' — ' . $n . ' คน') . '</title>'
            . '<path class="th-prov" data-province="' . e($name) . '" d="' . e($d['path']) . '" fill="' . $bin[1] . '"/></a>';
    };
    // วาดจังหวัดที่เลือกไว้ทีหลังสุด เพื่อให้เส้นขอบไม่ถูกจังหวัดข้างเคียงทับ
    foreach ($m['provinces'] as $name => $d) {
        if ($name !== $selected) {
            $svg .= $drawProvince($name, $d);
        }
    }
    if ($selected !== null && isset($m['provinces'][$selected])) {
        $svg .= $drawProvince($selected, $m['provinces'][$selected]);
    }
    // ตัวเลขจำนวนผู้ใช้บนจังหวัดที่มีผู้ใช้ (วางบนสุด ไม่รับการคลิก)
    foreach ($m['provinces'] as $name => $d) {
        $n = (int) ($counts[$name] ?? 0);
        if ($n > 0) {
            $svg .= '<text x="' . $d['cx'] . '" y="' . ($d['cy'] + 3.5) . '" text-anchor="middle" font-size="11" font-weight="700" fill="' . userGeoBin($n)[2] . '" pointer-events="none">' . $n . '</text>';
        }
    }
    return $svg . '</svg>';
}
