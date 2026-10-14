<?php
/**
 * ============================================================
 * ฟังก์ชันช่วยเหลือทั่วไป
 * ============================================================
 */

/** หนี HTML (ป้องกัน XSS) */
function e(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * แปลงข้อความแชท (เช่นคำตอบจาก AI) เป็น HTML ที่ปลอดภัย
 * escape HTML ก่อนเสมอ (กัน XSS) แล้วค่อยแปลง markdown เบื้องต้น:
 * **ตัวหนา** -> <strong>, ขึ้นบรรทัดใหม่ -> <br>
 */
function formatChatMessage(?string $text): string
{
    $escaped = e($text);
    $escaped = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped);
    return nl2br($escaped);
}

/** redirect ไปยัง path แล้วหยุด */
function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/** flash message: set เมื่อส่ง $message, get เมื่อไม่ส่ง */
function flash(string $key, ?string $message = null)
{
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }
    if (isset($_SESSION['flash'][$key])) {
        $m = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $m;
    }
    return null;
}

/** บันทึกกิจกรรมลง activity_logs */
function logActivity(?int $userId, string $action, string $detail = ''): void
{
    try {
        $pdo = getDBConnection();
        $pdo->prepare('INSERT INTO activity_logs (user_id, action, detail) VALUES (?, ?, ?)')
            ->execute([$userId, $action, $detail]);
    } catch (Exception $e) {
        // เงียบไว้
    }
}

/**
 * บันทึกการใช้งาน AI ลง ai_usage_logs (สำหรับติดตาม Token จริง)
 * $usage คือ array ที่ได้จากผลลัพธ์ของ ai*() ฟังก์ชัน เช่น ['prompt'=>.., 'completion'=>.., 'total'=>..]
 */
function logAiUsage(?int $userId, string $provider, ?string $model, string $feature, array $usage = []): void
{
    try {
        $pdo = getDBConnection();
        $pdo->prepare('INSERT INTO ai_usage_logs (user_id, provider, model, feature, prompt_tokens, completion_tokens, total_tokens) VALUES (?,?,?,?,?,?,?)')
            ->execute([
                $userId,
                $provider,
                $model,
                $feature,
                (int) ($usage['prompt'] ?? 0),
                (int) ($usage['completion'] ?? 0),
                (int) ($usage['total'] ?? 0),
            ]);
    } catch (Exception $e) {
        // เงียบไว้ (เช่น ตาราง ai_usage_logs ยังไม่มี ถ้ายังไม่ได้รัน migration)
    }
}

/** แปลงวันที่เป็นรูปแบบไทย */
function formatDateThai(?string $date): string
{
    if (!$date) return '-';
    $months = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    $ts = strtotime($date);
    if (!$ts) return '-';
    $d = date('j', $ts);
    $m = (int) date('n', $ts);
    $y = (int) date('Y', $ts) + 543;
    return "$d {$months[$m]} $y";
}

/* ---------------- CSRF ---------------- */

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

/** ตรวจ CSRF สำหรับฟอร์มปกติ */
function requireCsrf(string $redirectTo = ''): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        flash('error', 'เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง');
        redirect($redirectTo !== '' ? $redirectTo : basename($_SERVER['PHP_SELF']));
    }
}

/** ตรวจ CSRF สำหรับ AJAX (ตอบ JSON) */
function requireCsrfAjax(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'เซสชันหมดอายุ กรุณาโหลดหน้าใหม่']);
        exit;
    }
}

/* ---------------- อัปโหลดไฟล์ (ตรวจ MIME จริง) ---------------- */

/** อัปโหลดรูปภาพ คืนชื่อไฟล์ หรือ null ถ้าล้มเหลว */
function uploadImageFile(array $file, string $destDir): ?string
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        return null;
    }
    $ext = $allowed[$mime];

    if (!is_dir($destDir)) {
        mkdir($destDir, 0775, true);
    }
    $name = uniqid('img_', true) . '.' . $ext;
    $target = rtrim($destDir, '/') . '/' . $name;
    if (move_uploaded_file($file['tmp_name'], $target)) {
        resizeImageIfLarge($target, 1200);
        return $name;
    }
    return null;
}

/** อัปโหลดเอกสาร PDF/DOCX/CSV/TXT/MD คืน [ชื่อไฟล์, ชนิด] หรือ null พร้อมเหตุผลถ้าล้มเหลว */
function uploadDocFile(array $file, string $destDir, ?string &$errorReason = null): ?array
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        $errCode = $file['error'] ?? -1;
        if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
            $errorReason = 'ไฟล์มีขนาดใหญ่เกินขีดจำกัดที่เซิร์ฟเวอร์อนุญาต (ตรวจสอบค่า upload_max_filesize และ post_max_size ใน php.ini) — ไฟล์ Word ที่มีรูปภาพฝังอยู่มักมีขนาดใหญ่ ลองบีบอัดหรือถอดรูปออกก่อนอัปโหลด';
        } else {
            $errorReason = 'การอัปโหลดไฟล์ล้มเหลว (รหัส error: ' . $errCode . ')';
        }
        return null;
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $extName = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    $type = null;
    if ($mime === 'application/pdf' && $extName === 'pdf') {
        $type = 'pdf';
    } elseif ($extName === 'docx' && in_array($mime, ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'], true)) {
        $type = 'docx';
    } elseif ($extName === 'csv' && in_array($mime, ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'], true)) {
        $type = 'csv';
    } elseif ($extName === 'txt' && in_array($mime, ['text/plain'], true)) {
        $type = 'txt';
    } elseif ($extName === 'md' && in_array($mime, ['text/plain', 'text/markdown'], true)) {
        $type = 'md';
    }
    if ($type === null) {
        $errorReason = "ไฟล์ชนิดนี้ไม่รองรับ (นามสกุลที่อัปโหลด: .{$extName}, ตรวจพบชนิดไฟล์จริง: {$mime}) รองรับเฉพาะ PDF, Word (.docx), CSV, TXT, Markdown (.md) เท่านั้น";
        return null;
    }

    if (!is_dir($destDir)) {
        mkdir($destDir, 0775, true);
    }
    if (!is_writable($destDir)) {
        $errorReason = "โฟลเดอร์ปลายทางเขียนไม่ได้ ({$destDir}) กรุณาตรวจสิทธิ์โฟลเดอร์ (chmod)";
        return null;
    }
    $name = uniqid('doc_', true) . '.' . $type;
    if (move_uploaded_file($file['tmp_name'], rtrim($destDir, '/') . '/' . $name)) {
        return [$name, $type];
    }
    $errorReason = 'ไม่สามารถย้ายไฟล์ไปยังปลายทางได้ (move_uploaded_file ล้มเหลว)';
    return null;
}

/* ---------------- Rate limit ---------------- */

function checkRateLimit(int $userId, string $action, int $maxPerDay): bool
{
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM activity_logs WHERE user_id=? AND action=? AND DATE(created_at)=CURDATE()');
        $stmt->execute([$userId, $action]);
        return (int) $stmt->fetchColumn() < $maxPerDay;
    } catch (Exception $e) {
        return true;
    }
}

/* ---------------- Pagination ---------------- */

function getPagination(int $perPage = 15): array
{
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $offset = ($page - 1) * $perPage;
    return [$perPage, $offset, $page];
}

function renderPagination(int $totalRows, int $perPage, int $currentPage): string
{
    $totalPages = (int) ceil($totalRows / $perPage);
    if ($totalPages <= 1) return '';
    $qs = $_GET;
    $html = '<nav><ul class="pagination pagination-sm justify-content-center mt-3">';
    for ($p = 1; $p <= $totalPages; $p++) {
        $qs['page'] = $p;
        $url = '?' . http_build_query($qs);
        $active = $p === $currentPage ? ' active' : '';
        $html .= '<li class="page-item' . $active . '"><a class="page-link" href="' . e($url) . '">' . $p . '</a></li>';
    }
    $html .= '</ul></nav>';
    return $html;
}

/**
 * จับคู่ชื่อพืชที่ AI ตอบมากับรายชื่อพืชในระบบ (โมเดลขนาดเล็กอาจสะกดคลาดเคลื่อนหรือมีคำเกิน)
 * ลำดับ: ตรงทุกตัว -> ข้อความหนึ่งอยู่ในอีกข้อความ (เลือกที่ความยาวใกล้เคียงที่สุด) -> คล้ายกัน >= 80% (เทียบระดับตัวอักษร)
 * @return string|null ชื่อพืชตามที่เก็บในระบบ หรือ null ถ้าไม่พบที่ใกล้เคียง
 */
function matchCropName(string $name, array $candidates): ?string
{
    $n = mb_strtolower(trim($name), 'UTF-8');
    if ($n === '') return null;

    foreach ($candidates as $c) {
        if (mb_strtolower($c, 'UTF-8') === $n) return $c;
    }

    $contain = [];
    foreach ($candidates as $c) {
        $cl = mb_strtolower($c, 'UTF-8');
        if ($cl !== '' && (mb_strpos($n, $cl, 0, 'UTF-8') !== false || mb_strpos($cl, $n, 0, 'UTF-8') !== false)) {
            $contain[$c] = abs(mb_strlen($cl, 'UTF-8') - mb_strlen($n, 'UTF-8'));
        }
    }
    if ($contain) {
        asort($contain);
        return (string) array_key_first($contain);
    }

    // ความคล้ายระดับตัวอักษร: แปลงแต่ละตัวอักษร Unicode เป็น 1 ไบต์ก่อน เพราะ similar_text() นับเป็นไบต์
    // (อักษรไทยทุกตัวขึ้นต้นด้วยไบต์ E0 B8/B9 เหมือนกัน ถ้านับไบต์ตรง ๆ ชื่อไม่เกี่ยวกันก็จะดูคล้ายกัน)
    $best = null;
    $bestPct = 0.0;
    foreach ($candidates as $c) {
        $cl = mb_strtolower($c, 'UTF-8');
        $chars = array_values(array_unique(array_merge(mb_str_split($cl, 1, 'UTF-8'), mb_str_split($n, 1, 'UTF-8'))));
        if (count($chars) > 250 || mb_strlen($cl, 'UTF-8') < 3 || mb_strlen($n, 'UTF-8') < 3) {
            continue;
        }
        $map = array_flip($chars);
        $enc = fn(string $str) => implode('', array_map(fn($ch) => chr($map[$ch] + 1), mb_str_split($str, 1, 'UTF-8')));
        similar_text($enc($cl), $enc($n), $pct);
        if ($pct > $bestPct) {
            $bestPct = $pct;
            $best = $c;
        }
    }
    return $bestPct >= 80.0 ? $best : null;
}

/* =========================================================
 * IMAGE RESIZE (ย่อขนาดรูปก่อนเก็บ ประหยัดพื้นที่)
 * ========================================================= */

/** ย่อรูปถ้ากว้างเกิน maxWidth (ใช้ GD) - แก้ไฟล์ในตำแหน่งเดิม */
function resizeImageIfLarge(string $filePath, int $maxWidth = 1200): void
{
    if (!extension_loaded('gd') || !file_exists($filePath)) return;
    $info = @getimagesize($filePath);
    if ($info === false) return;
    [$w, $h] = $info;
    if ($w <= $maxWidth) return;

    $mime = $info['mime'];
    switch ($mime) {
        case 'image/jpeg': $src = @imagecreatefromjpeg($filePath); break;
        case 'image/png':  $src = @imagecreatefrompng($filePath); break;
        case 'image/webp': $src = @imagecreatefromwebp($filePath); break;
        default: return;
    }
    if (!$src) return;

    $newH = (int) round($h * ($maxWidth / $w));
    $dst = imagecreatetruecolor($maxWidth, $newH);
    // รักษาความโปร่งใสสำหรับ png/webp
    if ($mime === 'image/png' || $mime === 'image/webp') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $maxWidth, $newH, $w, $h);

    switch ($mime) {
        case 'image/jpeg': imagejpeg($dst, $filePath, 85); break;
        case 'image/png':  imagepng($dst, $filePath, 6); break;
        case 'image/webp': imagewebp($dst, $filePath, 85); break;
    }
    imagedestroy($src);
    imagedestroy($dst);
}
