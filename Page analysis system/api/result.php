<?php
// ============================================================
// api/result.php — جلب نتيجة تقييم معين (v4.0)
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

// ── Decode JSON fields ──────────────────────────────────────
$jsonFields = ['breakdown','strengths','weaknesses','recommendations','scan_result','next_steps','ai_report'];
foreach ($jsonFields as $f) {
    if (!empty($row[$f]) && is_string($row[$f])) {
        $row[$f] = json_decode($row[$f], true);
    }
}

// ── استخراج بيانات الذكاء الاصطناعي من ai_report ──────────
// ai_report يحتوي على كامل مخرجات الذكاء الاصطناعي
$aiReport = is_array($row['ai_report']) ? $row['ai_report'] : [];

// أولوية: strengths/weaknesses من ai_report (الذكاء الاصطناعي الحقيقي)
// وإلا من الحقول المستقلة (التي حفظها analyze.php)
if (!empty($aiReport['strengths'])) {
    $row['ai_report']['strengths']  = $aiReport['strengths'];
}
if (!empty($aiReport['weaknesses'])) {
    $row['ai_report']['weaknesses'] = $aiReport['weaknesses'];
}

// ── إذا كانت strengths/weaknesses فارغة في ai_report، ابحث في جذر الـ row ──
if (empty($row['ai_report']['strengths']) && !empty($row['strengths'])) {
    $row['ai_report']['strengths']  = is_array($row['strengths']) ? $row['strengths'] : json_decode($row['strengths'], true) ?? [];
}
if (empty($row['ai_report']['weaknesses']) && !empty($row['weaknesses'])) {
    $row['ai_report']['weaknesses'] = is_array($row['weaknesses']) ? $row['weaknesses'] : json_decode($row['weaknesses'], true) ?? [];
}

// ── content_analysis ─────────────────────────────────────────
if (empty($row['ai_report']['content_analysis']) && !empty($aiReport['content_analysis'])) {
    $row['ai_report']['content_analysis'] = $aiReport['content_analysis'];
}

// ── action_week من next_steps ────────────────────────────────
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

// ── نقل recommendations من ai_report للجذر إذا لم تكن موجودة ──
// JS يقرأ data.recommendations (جذر الـ row)
if (empty($row['recommendations']) && !empty($row['ai_report']['recommendations'])) {
    $row['recommendations'] = $row['ai_report']['recommendations'];
}
// وإذا كانت في الجذر فقط — انقلها للـ ai_report أيضاً للاتساق
if (!empty($row['recommendations']) && empty($row['ai_report']['recommendations'])) {
    $row['ai_report']['recommendations'] = $row['recommendations'];
}

// ── حقول موحدة للـ Frontend ──────────────────────────────────
$row['url']       = $row['website_url'] ?: $row['facebook_url'] ?: $row['instagram_url'] ?: $row['tiktok_url'] ?: $row['twitter_url'] ?: '';
$row['full_name'] = $row['full_name']   ?: $row['company_name'] ?: '';

// ── package_tier: مجاني (3 توصيات) أم مدفوع (القائمة الكاملة) ──
// المصدر: assessments.is_unlocked (TINYINT(1)) — يُضبط على 1 من قِبل الإدارة
// عند فتح التقرير للعميل بعد دفع باقة. يُستهلك في الواجهة (report-connect.js)
// لتطبيق slice(0, 3) على قسم التوصيات في الباقة المجانية.
$row['package_tier'] = !empty($row['is_unlocked']) ? 'paid' : 'free';

// ── DEBUG: أضف مؤشر المصدر لتسهيل التشخيص ──────────────────
$row['_debug'] = [
    'ai_report_source'        => !empty($row['ai_report']) ? 'DB:ai_report' : 'EMPTY',
    'strengths_count'         => count($row['ai_report']['strengths']       ?? []),
    'weaknesses_count'        => count($row['ai_report']['weaknesses']      ?? []),
    'recommendations_count'   => count($row['recommendations']              ?? []),
    'has_high_priority_rec'   => count(array_filter($row['recommendations'] ?? [], fn($r) => ($r['priority'] ?? '') === 'high')),
    'has_content_analysis'    => !empty($row['ai_report']['content_analysis']),
];

jsonOut($row);
