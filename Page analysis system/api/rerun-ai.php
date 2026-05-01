<?php
/**
 * rerun-ai.php — إعادة تشغيل التحليل بالذكاء الاصطناعي لتقييم محدد
 * GET: http://alabeer.local:10004/alabeer-hub/Page analysis system/api/rerun-ai.php?id=204
 * 
 * يحذف ai_report القديم ثم يستدعي Gemini مباشرة وينتظر النتيجة
 */

// الوصول من localhost فقط
$clientIP = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!in_array($clientIP, ['127.0.0.1', '::1', 'localhost'])) {
    http_response_code(403);
    die(json_encode(['error' => 'Localhost only']));
}

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/ai-analyze.php';

/** @var PDO $db */
/** @var array $config */

header('Content-Type: application/json; charset=utf-8');
set_time_limit(300);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    die(json_encode(['error' => 'يجب تمرير ?id=XXX'], JSON_UNESCAPED_UNICODE));
}

// ── 1. جلب بيانات التقييم ───────────────────────────────────
$stmt = $db->prepare("
    SELECT a.*, l.full_name, l.company_name, l.project_type, l.country, 
           l.platform, l.website_url, l.facebook_url, l.instagram_url
    FROM assessments a 
    LEFT JOIN leads l ON a.lead_id = l.id 
    WHERE a.id = ? 
    LIMIT 1
");
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row) {
    die(json_encode(['error' => "لم يُعثر على التقييم #{$id}"], JSON_UNESCAPED_UNICODE));
}

// ── 2. فك ترميز scan_result وbreakdown ──────────────────────
$scanResult = is_string($row['scan_result']) ? json_decode($row['scan_result'], true) : ($row['scan_result'] ?? []);
$breakdown  = is_string($row['breakdown'])   ? json_decode($row['breakdown'], true)   : ($row['breakdown']  ?? []);

// ── 3. مسح الـ Cache القديم ──────────────────────────────────
$cacheDir = __DIR__ . '/../cache';
$deleted = 0;
if (is_dir($cacheDir)) {
    foreach (glob($cacheDir . '/*.cache') as $f) {
        if (unlink($f)) $deleted++;
    }
}

// ── 4. تجهيز البيانات للـ AI ─────────────────────────────────
$data = [
    'score'          => (int)($row['score'] ?? 0),
    'breakdown'      => $breakdown,
    'scan_result'    => $scanResult,
    'full_name'      => $row['full_name']    ?? $row['company_name'] ?? 'عميل',
    'company_name'   => $row['company_name'] ?? '',
    'project_type'   => $row['project_type'] ?? '',
    'country'        => $row['country']      ?? '',
    'platform'       => $row['platform']     ?? '',
    'objective'      => $row['objective']    ?? '',
    'target_audience'=> $row['target_audience'] ?? '',
    'ad_budget'      => $row['ad_budget']    ?? '',
    // ✅ إضافة URL والموقع لـ detectPageType
    'url'            => $row['website_url']  ?? $row['facebook_url'] ?? '',
    'website'        => $row['website_url']  ?? '',
    'description'    => $row['company_name'] ?? '', // للكشف عن الكلمات المفتاحية
];

// ── 5. استدعاء الـ AI (تجاوز الكاش دائماً عند rerun) ─────────
$startTime = microtime(true);
try {
    $aiResult = runGeminiAnalysis($data, $config, true); // ✅ forceRefresh=true

    $elapsed  = round(microtime(true) - $startTime, 2);

    // ── 6. حفظ النتيجة في DB (حتى لو كانت fallback) ─────────
    $saveStmt = $db->prepare("
        UPDATE assessments 
        SET ai_report = ?, status = 'analyzed'
        WHERE id = ?
    ");
    $saveStmt->execute([json_encode($aiResult, JSON_UNESCAPED_UNICODE), $id]);

    // ── 7. تحديث strengths/weaknesses المستقلة ────────────
    if (!empty($aiResult['strengths'])) {
        $db->prepare("UPDATE assessments SET strengths = ? WHERE id = ?")
           ->execute([json_encode($aiResult['strengths'], JSON_UNESCAPED_UNICODE), $id]);
    }
    if (!empty($aiResult['weaknesses'])) {
        $db->prepare("UPDATE assessments SET weaknesses = ? WHERE id = ?")
           ->execute([json_encode($aiResult['weaknesses'], JSON_UNESCAPED_UNICODE), $id]);
    }


    echo json_encode([
        'success'          => true,
        'assessment_id'    => $id,
        'elapsed_seconds'  => $elapsed,
        'ai_source'        => $aiResult['source'] ?? 'unknown',
        'cache_cleared'    => $deleted,
        'strengths_count'  => count($aiResult['strengths']  ?? []),
        'weaknesses_count' => count($aiResult['weaknesses'] ?? []),
        'has_content_analysis' => isset($aiResult['content_analysis']),
        'strengths_preview'    => array_slice($aiResult['strengths']  ?? [], 0, 2),
        'weaknesses_preview'   => array_slice($aiResult['weaknesses'] ?? [], 0, 2),
        'summary_preview'      => mb_substr($aiResult['summary'] ?? '', 0, 200),
        'message'          => '✅ تم إعادة التحليل بنجاح. أعد فتح التقرير الآن.',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    $elapsed = round(microtime(true) - $startTime, 2);
    echo json_encode([
        'success'  => false,
        'error'    => $e->getMessage(),
        'elapsed'  => $elapsed,
        'message'  => '❌ فشل التحليل — تحقق من مفاتيح AI في .env',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
