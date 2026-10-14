<?php
/**
 * ============================================================
 * RAG Library (PHP ล้วน) - แทนที่ LangChain + ChromaDB
 * รองรับไฟล์: PDF, Word (.docx), CSV, TXT, Markdown (.md)
 *
 * ปรับปรุงความเร็ว (แก้ปัญหาตอบช้า):
 * - Pre-filter ด้วย keyword ก่อน embed (ลดจำนวน chunk ที่ต้องคำนวณ cosine)
 * - รองรับภาษาไทยเขียนติดกันด้วย n-gram
 * - Cache query embedding ภายใน request เดียวกัน
 * ============================================================
 */

/* =========================================================
 * ส่วนสกัดข้อความจากไฟล์ชนิดต่าง ๆ
 * ========================================================= */

/** สกัดข้อความจาก PDF แบบ pure-PHP (FlateDecode + Tj/TJ) */
function ragExtractPdfText(string $filePath): string
{
    $data = @file_get_contents($filePath);
    if ($data === false) return '';

    $text = '';
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $data, $streams)) {
        foreach ($streams[1] as $stream) {
            $decoded = @gzuncompress($stream);
            if ($decoded === false) $decoded = @gzinflate(substr($stream, 2));
            if ($decoded === false) $decoded = $stream;

            if (preg_match_all('/\((?:[^()\\\\]|\\\\.)*\)\s*Tj/s', $decoded, $m1)) {
                foreach ($m1[0] as $seg) $text .= ragPdfDecodeParen($seg) . ' ';
            }
            if (preg_match_all('/\[(.*?)\]\s*TJ/s', $decoded, $m2)) {
                foreach ($m2[1] as $arr) {
                    if (preg_match_all('/\((?:[^()\\\\]|\\\\.)*\)/s', $arr, $inner)) {
                        foreach ($inner[0] as $seg) $text .= ragPdfDecodeParen($seg) . ' ';
                    }
                }
            }
        }
    }
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
}

/** ถอดข้อความในวงเล็บ PDF */
function ragPdfDecodeParen(string $seg): string
{
    if (preg_match('/\((.*)\)/s', $seg, $m)) {
        $s = $m[1];
        $s = str_replace(['\\(', '\\)', '\\\\', '\\n', '\\r', '\\t'], ['(', ')', '\\', "\n", "\r", "\t"], $s);
        return $s;
    }
    return '';
}

/**
 * สกัดข้อความจาก Word (.docx) — อ่านจาก word/document.xml ในไฟล์ zip
 * ใช้ ZipArchive ถ้ามี (เร็วกว่า) ถ้าไม่มีให้ใช้ตัวอ่าน ZIP แบบ PHP ล้วนแทน
 * (ไฟล์ .docx คือ zip ที่บรรจุ XML ไว้ข้างใน)
 */
function ragExtractDocxText(string $filePath): string
{
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($filePath) === true) {
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            if ($xml !== false) {
                return ragDocxXmlToText($xml);
            }
        }
    }

    // Fallback: ไม่มี ext-zip หรือ ZipArchive เปิดไฟล์ไม่สำเร็จ -> อ่าน ZIP เองด้วย PHP ล้วน
    $data = @file_get_contents($filePath);
    if ($data === false) return '';
    $xml = ragUnzipSingleFile($data, 'word/document.xml');
    if ($xml === null) return '';
    return ragDocxXmlToText($xml);
}

/** แปลง XML ของ Word ให้เป็นข้อความอ่านง่าย (ใช้ร่วมกันทั้ง 2 วิธี) */
function ragDocxXmlToText(string $xml): string
{
    $xml = str_replace(['</w:p>', '<w:tab/>', '<w:br/>'], ["\n", "\t", "\n"], $xml);
    $text = strip_tags($xml);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $text = preg_replace('/[ \t]+/u', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
}

/**
 * ตัวอ่านไฟล์ ZIP แบบ PHP ล้วน (ไม่ต้องพึ่ง extension ext-zip)
 * อ่านเฉพาะไฟล์เดียวที่ต้องการจากภายใน ZIP โดยอ่าน Central Directory
 * แล้วดึงเฉพาะ entry ที่ตรงชื่อ รองรับทั้งแบบไม่บีบอัด (stored) และ Deflate
 * @return string|null เนื้อหาไฟล์ที่ถอดรหัสแล้ว หรือ null ถ้าไม่พบ/อ่านไม่ได้
 */
function ragUnzipSingleFile(string $data, string $innerName): ?string
{
    $len = strlen($data);
    if ($len < 22) return null;

    // หา End Of Central Directory (EOCD) จากท้ายไฟล์
    // (comment ของ zip มีได้สูงสุด 65535 ไบต์ จึงค้นย้อนจากท้ายไฟล์ในระยะนั้น)
    $eocdSig = "\x50\x4b\x05\x06";
    $searchStart = max(0, $len - 65557);
    $searchArea = substr($data, $searchStart);
    $eocdPosRel = strrpos($searchArea, $eocdSig);
    if ($eocdPosRel === false) return null;
    $eocdPos = $searchStart + $eocdPosRel;

    $eocd = substr($data, $eocdPos, 22);
    if (strlen($eocd) < 22) return null;
    $cdOffset = unpack('V', substr($eocd, 16, 4))[1];
    $cdCount  = unpack('v', substr($eocd, 10, 2))[1];

    $pos = $cdOffset;
    for ($i = 0; $i < $cdCount; $i++) {
        if (substr($data, $pos, 4) !== "\x50\x4b\x01\x02") break; // central dir signature

        $compMethod = unpack('v', substr($data, $pos + 10, 2))[1];
        $compSize   = unpack('V', substr($data, $pos + 20, 4))[1];
        $nameLen    = unpack('v', substr($data, $pos + 28, 2))[1];
        $extraLen   = unpack('v', substr($data, $pos + 30, 2))[1];
        $commentLen = unpack('v', substr($data, $pos + 32, 2))[1];
        $localOffset = unpack('V', substr($data, $pos + 42, 4))[1];
        $name = substr($data, $pos + 46, $nameLen);

        if ($name === $innerName) {
            // อ่าน local file header เพื่อหาจุดเริ่มข้อมูลจริง (extra field อาจยาวไม่เท่า central dir)
            if (substr($data, $localOffset, 4) !== "\x50\x4b\x03\x04") return null;
            $lfhNameLen  = unpack('v', substr($data, $localOffset + 26, 2))[1];
            $lfhExtraLen = unpack('v', substr($data, $localOffset + 28, 2))[1];
            $dataStart = $localOffset + 30 + $lfhNameLen + $lfhExtraLen;
            $compData = substr($data, $dataStart, $compSize);

            if ($compMethod === 0) {
                return $compData; // ไม่บีบอัด
            }
            if ($compMethod === 8) {
                $out = @gzinflate($compData); // ZIP ใช้ raw DEFLATE ตรงกับ gzinflate
                return $out !== false ? $out : null;
            }
            return null; // วิธีบีบอัดอื่นที่ไม่รองรับ (พบน้อยมากใน .docx)
        }

        $pos += 46 + $nameLen + $extraLen + $commentLen;
    }
    return null;
}

/** สกัดข้อความจาก CSV */
function ragExtractCsvText(string $filePath): string
{
    $text = '';
    if (($h = @fopen($filePath, 'r')) !== false) {
        while (($row = fgetcsv($h)) !== false) {
            $text .= implode(' | ', $row) . "\n";
        }
        fclose($h);
    }
    return trim($text);
}

/** สกัดข้อความตามชนิดไฟล์ */
function ragExtractText(string $filePath, string $fileType): string
{
    switch ($fileType) {
        case 'pdf':  return ragExtractPdfText($filePath);
        case 'docx': return ragExtractDocxText($filePath);
        case 'csv':  return ragExtractCsvText($filePath);
        case 'txt':
        case 'md':
        default:     return trim(@file_get_contents($filePath) ?: '');
    }
}

/* =========================================================
 * ส่วนหั่น chunk
 * ========================================================= */

/** หั่นข้อความเป็น chunk (พยายามตัดตามย่อหน้า/ประโยคก่อน) */
function ragChunkText(string $text): array
{
    $text = trim($text);
    if ($text === '') return [];

    $size = RAG_CHUNK_SIZE;
    $overlap = RAG_CHUNK_OVERLAP;
    $chunks = [];
    $len = mb_strlen($text, 'UTF-8');
    $start = 0;

    while ($start < $len) {
        $chunk = mb_substr($text, $start, $size, 'UTF-8');

        if ($start + $size < $len) {
            $lastBreak = max(
                mb_strrpos($chunk, "\n", 0, 'UTF-8') ?: 0,
                mb_strrpos($chunk, '. ', 0, 'UTF-8') ?: 0,
                mb_strrpos($chunk, '。', 0, 'UTF-8') ?: 0
            );
            if ($lastBreak > $size * 0.5) {
                $chunk = mb_substr($chunk, 0, $lastBreak + 1, 'UTF-8');
            }
        }

        $chunkTrim = trim($chunk);
        if ($chunkTrim !== '') $chunks[] = $chunkTrim;

        $advance = mb_strlen($chunk, 'UTF-8') - $overlap;
        if ($advance < 1) $advance = mb_strlen($chunk, 'UTF-8');
        $start += $advance;
    }
    return $chunks;
}

/* =========================================================
 * ส่วน index เอกสาร
 * ========================================================= */

function ragIndexDocument(int $docId, string $filePath, string $fileType): array
{
    $pdo = getDBConnection();

    $rawText = ragExtractText($filePath, $fileType);
    if ($rawText === '') {
        if ($fileType === 'docx') {
            return [
                'success' => false, 'chunk_count' => 0,
                'error' => 'ไม่สามารถอ่านไฟล์ Word นี้ได้ ไฟล์อาจเสียหาย ถูกป้องกันด้วยรหัสผ่าน หรือไม่ใช่ไฟล์ .docx จริง (เช่น ไฟล์ .doc รุ่นเก่า) กรุณาเปิดไฟล์ด้วย Word แล้วบันทึกใหม่เป็น .docx อีกครั้ง หรือลองแปลงเป็น .txt แทน'
            ];
        }
        return ['success' => false, 'chunk_count' => 0, 'error' => 'ไม่พบข้อความในเอกสาร (อาจเป็นไฟล์ภาพสแกนที่ไม่มี text layer หรือไฟล์เสียหาย)'];
    }

    if ($fileType === 'pdf') {
        $textLen = mb_strlen(trim($rawText), 'UTF-8');
        $fileSize = @filesize($filePath) ?: 0;
        if ($textLen < 50 || ($fileSize > 50000 && $textLen < 100)) {
            return [
                'success' => false, 'chunk_count' => 0,
                'error' => 'ไม่สามารถสกัดข้อความจาก PDF นี้ได้ (อาจเป็นไฟล์สแกน หรือใช้ฟอนต์ไทยแบบฝังตัว) แนะนำให้แปลงเป็นไฟล์ Word (.docx) หรือ .txt แล้วอัปโหลดใหม่'
            ];
        }
    }

    $chunks = ragChunkText($rawText);
    if (empty($chunks)) {
        return ['success' => false, 'chunk_count' => 0, 'error' => 'ไม่สามารถหั่นข้อความได้'];
    }

    // ---- สร้างเวกเตอร์ทั้งหมดก่อน (ส่งเป็น batch เพื่อความเร็ว) ----
    // ถ้าสร้างไม่สำเร็จ (เช่น Ollama ปิดอยู่) จะไม่แตะดัชนีเดิมของเอกสารนี้เลย
    @set_time_limit(0);
    $embedModel = OLLAMA_EMBED_MODEL;
    $vectors = [];
    foreach (array_chunk($chunks, max(1, (int) RAG_EMBED_BATCH), true) as $batch) {
        $texts = array_values($batch);
        $r = aiEmbedMany($texts);
        if (!$r['success']) {
            return ['success' => false, 'chunk_count' => 0, 'error' => $r['error']];
        }
        $i = 0;
        foreach ($batch as $idx => $text) {
            $vectors[$idx] = $r['embeddings'][$i++];
        }
    }

    // ---- แทนที่ดัชนีเดิมของเอกสารนี้ (ทำใน transaction ให้สำเร็จทั้งหมดหรือไม่เปลี่ยนเลย) ----
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM knowledge_chunks WHERE doc_id = ?')->execute([$docId]);
        $stmt = $pdo->prepare('INSERT INTO knowledge_chunks (doc_id, chunk_index, content, embedding, embed_model) VALUES (?, ?, ?, ?, ?)');
        foreach ($chunks as $i => $chunk) {
            $stmt->execute([$docId, $i, $chunk, json_encode($vectors[$i]), $embedModel]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'chunk_count' => 0, 'error' => 'บันทึกดัชนีลงฐานข้อมูลไม่สำเร็จ: ' . $e->getMessage()];
    }

    return ['success' => true, 'chunk_count' => count($chunks), 'error' => ''];
}

/** ข้อความผิดพลาดล่าสุดของการค้นคืน (เช่น Ollama ปิดอยู่) ว่างถ้าปกติ */
function ragLastError(): string
{
    return (string) ($GLOBALS['rag_last_error'] ?? '');
}

/** จำนวน chunk ที่ใช้ค้นได้ (สร้างด้วยโมเดล Embedding ที่ใช้อยู่ตอนนี้) */
function ragUsableChunkCount(): int
{
    $stmt = getDBConnection()->prepare('SELECT COUNT(*) FROM knowledge_chunks WHERE embed_model = ?');
    $stmt->execute([OLLAMA_EMBED_MODEL]);
    return (int) $stmt->fetchColumn();
}

/** จำนวน chunk ที่สร้างด้วยโมเดลอื่น/ระบบเดิม (ใช้ค้นไม่ได้จนกว่าจะจัดทำดัชนีใหม่) */
function ragStaleChunkCount(): int
{
    $stmt = getDBConnection()->prepare('SELECT COUNT(*) FROM knowledge_chunks WHERE embed_model IS NULL OR embed_model <> ?');
    $stmt->execute([OLLAMA_EMBED_MODEL]);
    return (int) $stmt->fetchColumn();
}

/* =========================================================
 * ส่วนค้นหา (ปรับปรุงความเร็ว)
 * ========================================================= */

function ragCosineSimilarity(array $a, array $b): float
{
    $dot = 0.0; $normA = 0.0; $normB = 0.0;
    $n = min(count($a), count($b));
    for ($i = 0; $i < $n; $i++) {
        $dot += $a[$i] * $b[$i];
        $normA += $a[$i] * $a[$i];
        $normB += $b[$i] * $b[$i];
    }
    if ($normA == 0.0 || $normB == 0.0) return 0.0;
    return $dot / (sqrt($normA) * sqrt($normB));
}

/**
 * แยกคำสำคัญจากข้อความ (สำหรับ pre-filter)
 * รองรับภาษาไทยที่เขียนติดกัน โดยสร้าง n-gram ของตัวอักษรไทยด้วย
 */
function ragExtractKeywords(string $text): array
{
    $stop = ['และ','หรือ','ที่','ใน','การ','ของ','เป็น','มี','ให้','กับ','ไป','มา','ได้','จะ','ว่า','คือ','อยู่','ก็','นี้','นั้น','อย่าง','แล้ว','ด้วย','จาก','ถึง','เพื่อ','ทำ','ไหม','อะไร','ยัง','a','an','the','is','are','of','in','to','and','or','for','how','what','do','i'];
    $words = preg_split('/[\s,\.\?\!\(\)\[\]"\'\/]+/u', mb_strtolower($text, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);

    $keywords = [];
    foreach ($words as $w) {
        $wlen = mb_strlen($w, 'UTF-8');
        if ($wlen < 2) continue;
        if (in_array($w, $stop, true)) continue;

        if ($wlen > 8 && preg_match('/[\x{0E00}-\x{0E7F}]/u', $w)) {
            for ($i = 0; $i + 3 <= $wlen; $i += 2) {
                $gram = mb_substr($w, $i, 4, 'UTF-8');
                if (mb_strlen($gram, 'UTF-8') >= 3) {
                    $keywords[] = $gram;
                }
            }
        } else {
            $keywords[] = $w;
        }
    }
    return array_slice(array_unique($keywords), 0, 20);
}

/**
 * ดึง context จากคลังความรู้ (คืน array มี content + similarity)
 * @return array [['content'=>..., 'title'=>..., 'sim'=>float], ...]
 */
function ragRetrieve(string $query, ?float $minSim = null, ?int $topK = null): array
{
    static $cache = [];
    $minSim = $minSim ?? (float) RAG_MIN_SIMILARITY;
    $topK = $topK ?? (int) RAG_TOP_K;
    $ck = $query . '|' . $minSim . '|' . $topK;
    if (isset($cache[$ck])) return $cache[$ck];

    $pdo = getDBConnection();

    // ใช้เฉพาะ chunk ที่สร้างด้วยโมเดล Embedding ตัวเดียวกับที่ใช้ตอนนี้ (เวกเตอร์ต่างโมเดลเทียบกันไม่ได้)
    $embedModel = OLLAMA_EMBED_MODEL;
    $count = ragUsableChunkCount();
    if ($count === 0) { $cache[$ck] = []; return []; }

    // ---- ขั้นที่ 1: Pre-filter ด้วย keyword (เร็ว ไม่ต้องเรียก AI) ----
    $keywords = ragExtractKeywords($query);
    $candidateIds = [];
    if (!empty($keywords)) {
        $likeClauses = [];
        $params = [];
        foreach ($keywords as $kw) {
            $likeClauses[] = 'content LIKE ?';
            $params[] = '%' . $kw . '%';
        }
        $sql = 'SELECT chunk_id FROM knowledge_chunks WHERE embed_model = ? AND (' . implode(' OR ', $likeClauses) . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$embedModel], $params));
        $candidateIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // ---- ขั้นที่ 2: สร้าง embedding ของคำถาม (เรียก bge-m3 ในเครื่อง 1 ครั้ง) ----
    $qEmbed = aiEmbed($query);
    $GLOBALS['rag_last_error'] = $qEmbed['success'] ? '' : (string) $qEmbed['error'];
    if (!$qEmbed['success']) {
        if (!empty($candidateIds)) {
            $result = ragKeywordOnlyRetrieve($candidateIds);
            $cache[$ck] = $result;
            return $result;
        }
        $cache[$ck] = [];
        return [];
    }
    $queryVec = $qEmbed['embedding'];
    $queryDim = count($queryVec);

    // ---- ขั้นที่ 3: ดึงเฉพาะ chunk ที่ผ่าน pre-filter มาคำนวณ cosine ----
    $useCandidate = !empty($candidateIds)
        && count($candidateIds) >= 3
        && count($candidateIds) < $count;

    if ($useCandidate) {
        $placeholders = implode(',', array_fill(0, count($candidateIds), '?'));
        $sql = "SELECT kc.content, kc.embedding, kd.title
                FROM knowledge_chunks kc JOIN knowledge_docs kd ON kd.doc_id = kc.doc_id
                WHERE kc.chunk_id IN ($placeholders)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($candidateIds);
    } else {
        $stmt = $pdo->prepare('SELECT kc.content, kc.embedding, kd.title
                               FROM knowledge_chunks kc JOIN knowledge_docs kd ON kd.doc_id = kc.doc_id
                               WHERE kc.embed_model = ?');
        $stmt->execute([$embedModel]);
    }

    $scored = [];
    while ($row = $stmt->fetch()) {
        $vec = json_decode($row['embedding'], true);
        if (!is_array($vec) || count($vec) !== $queryDim) continue;
        $sim = ragCosineSimilarity($queryVec, $vec);
        if ($sim >= $minSim) {
            $scored[] = ['sim' => $sim, 'content' => $row['content'], 'title' => $row['title']];
        }
    }

    usort($scored, fn($x, $y) => $y['sim'] <=> $x['sim']);
    $top = array_slice($scored, 0, $topK);
    $cache[$ck] = $top;
    return $top;
}

/** fallback: คืน chunk จาก keyword ล้วน (กรณี embed ล่ม) */
function ragKeywordOnlyRetrieve(array $chunkIds): array
{
    if (empty($chunkIds)) return [];
    $pdo = getDBConnection();
    $ids = array_slice($chunkIds, 0, RAG_TOP_K);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT kc.content, kd.title FROM knowledge_chunks kc
                           JOIN knowledge_docs kd ON kd.doc_id = kc.doc_id
                           WHERE kc.chunk_id IN ($placeholders)");
    $stmt->execute($ids);
    $out = [];
    while ($row = $stmt->fetch()) {
        $out[] = ['sim' => 0.5, 'content' => $row['content'], 'title' => $row['title']];
    }
    return $out;
}

/**
 * คืน context เป็นข้อความ (สำหรับใส่ใน prompt)
 * @return string ว่างถ้าไม่มีข้อมูลเกี่ยวข้อง
 */
function ragQueryContext(string $query): string
{
    $results = ragRetrieve($query);
    if (empty($results)) return '';

    $blocks = [];
    foreach ($results as $t) {
        $blocks[] = '[อ้างอิงจาก: ' . $t['title'] . "]\n" . $t['content'];
    }
    return implode("\n\n---\n\n", $blocks);
}

/** มีข้อมูลใน RAG ที่เกี่ยวข้องกับคำถามหรือไม่ */
function ragHasContext(string $query): bool
{
    return !empty(ragRetrieve($query));
}
