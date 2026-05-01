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
$stmt = $db->prepare("SELECT a.*, l.full_name, l.company_name, l.project_type, l.country, l.platform, l.website_url, l.facebook_url, l.instagram_url, l.tiktok_url, l.twitter_url FROM assessments a LEFT JOIN leads l ON a.lead_id=l.id WHERE a.id = ? LIMIT 1");
$stmt->execute([$id]);
$row  = $stmt->fetch();

if (!$row) jsonError('لم يُعثر على التقييم', 404);

// إذا كان التحليل لم ينتهِ بعد — أرجع حالة pending
if ($row['status'] === 'submitted' || $row['status'] === 'running') {
    jsonOut([
        'status'  => 'pending',
        'id'      => (int)$id,
        'message' => 'التحليل جارٍ... يرجى الانتظار أو إعادة المحاولة بعد لحظات.',
    ], 202);
}

// Decode JSON fields
$jsonFields = ['breakdown','strengths','weaknesses','recommendations','scan_result','next_steps','ai_report'];
foreach ($jsonFields as $f) {
    if (!empty($row[$f]) && is_string($row[$f])) {
        $row[$f] = json_decode($row[$f], true);
    }
}

// تعيين action_week من next_steps (التي تم حفظها من الذكاء الاصطناعي)
if (!empty($row['next_steps']) && is_array($row['next_steps'])) {
    $row['action_week'] = $row['next_steps'];
}

// إضافة action_week من scan كبديل إذا لم يرجع الذكاء الاصطناعي شيئاً
if (!empty($row['scan_result']) && is_array($row['scan_result']) && empty($row['action_week'])) {
    $row['action_week'] = [];
    $scan = $row['scan_result'];
    if (!($scan['hasPixel'] ?? false)) $row['action_week'][] = 'تركيب Meta Pixel على الموقع.';
    if (!($scan['hasGA'] ?? false))    $row['action_week'][] = 'إعداد Google Analytics 4.';
    if (!($scan['hasSSL'] ?? true))    $row['action_week'][] = 'تفعيل HTTPS من لوحة الاستضافة.';
    $hasWA = ($scan['hasWhatsApp'] ?? false)
           || ($scan['has_whatsapp'] ?? false)
           || ($scan['facebook']['has_whatsapp'] ?? false)
           || (!empty($scan['facebook']['whatsapp']))
           || ($scan['social']['has_whatsapp'] ?? false)
           || ($scan['website_scan']['has_whatsapp'] ?? false);
    if (!$hasWA) $row['action_week'][] = 'إضافة زر واتساب للموقع.';
    $row['action_week'][] = 'مراجعة Bio على جميع المنصات وإضافة CTA.';
}

// ── إضافة حقول مُوحَّدة لتسهيل القراءة في الـ Frontend ────
$row['url']        = $row['website_url'] ?: $row['facebook_url'] ?: $row['instagram_url'] ?: $row['tiktok_url'] ?: $row['twitter_url'] ?: '';
$row['full_name']  = $row['full_name']   ?: $row['company_name'] ?: '';

jsonOut($row);
