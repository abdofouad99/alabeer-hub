<?php
// ============================================================
// api/result.php — جلب نتيجة تقييم معين (v3.0)
// GET /api/result.php?id=123
// ============================================================
require_once __DIR__ . '/db.php';
setCors();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) jsonError('معرّف التقييم غير صالح');

$db   = getDB();
$stmt = $db->prepare("SELECT a.*, l.full_name, l.company_name, l.project_type, l.country, l.platform, l.website_url, l.facebook_url, l.instagram_url FROM assessments a LEFT JOIN leads l ON a.lead_id=l.id WHERE a.id = ? LIMIT 1");
$stmt->execute([$id]);
$row  = $stmt->fetch();

if (!$row) jsonError('لم يُعثر على التقييم', 404);

// إذا كان التحليل لم ينتهِ بعد، نُعيد محاولة
if ($row['status'] === 'submitted') {
    try {
        require_once __DIR__ . '/analyze.php';
        runAnalysis($id);
        // أعد الجلب
        $stmt->execute([$id]);
        $row = $stmt->fetch();
    } catch (\Throwable $e) {
        jsonError('فشل التحليل: ' . $e->getMessage(), 500);
    }
}

// Decode JSON fields
$jsonFields = ['breakdown','strengths','weaknesses','recommendations','scan_result','next_steps'];
foreach ($jsonFields as $f) {
    if (!empty($row[$f]) && is_string($row[$f])) {
        $row[$f] = json_decode($row[$f], true);
    }
}

// إضافة action_week من scan
if (!empty($row['scan_result']) && empty($row['action_week'])) {
    $row['action_week'] = [];
    $scan = $row['scan_result'];
    if (!($scan['hasPixel'] ?? false)) $row['action_week'][] = 'تركيب Meta Pixel على الموقع.';
    if (!($scan['hasGA'] ?? false))    $row['action_week'][] = 'إعداد Google Analytics 4.';
    if (!($scan['hasSSL'] ?? true))    $row['action_week'][] = 'تفعيل HTTPS من لوحة الاستضافة.';
    if (!($scan['hasWhatsApp'] ?? false)) $row['action_week'][] = 'إضافة زر واتساب للموقع.';
    $row['action_week'][] = 'مراجعة Bio على جميع المنصات وإضافة CTA.';
}

jsonOut($row);
