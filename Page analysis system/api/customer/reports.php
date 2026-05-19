<?php
// ============================================================
// api/customer/reports.php
// إرجاع قائمة تقارير العميل الحالي (مرتبة من الأحدث للأقدم)
// ─────────────────────────────────────────────────────────────
// GET → { ok: true, data: { count, reports: [...] } }
//
// متطلبات الأمان:
//   - requireCustomer() — يُلزم تسجيل الدخول
//   - فلترة WHERE customer_id = ? فقط (لا تسريب لتقارير غيره)
//   - LIMIT 100 (حد أعلى آمن)
//   - rate-limit: 60 طلب/دقيقة لكل عميل
//   - prepared statements
//   - لا تكشف $e->getMessage() للعميل
// ============================================================

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/middleware.php';

setCustomerCors();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    jsonError('Method not allowed', 405);
}

$customer = requireCustomer();
$customerId = (int) $customer['id'];

// rate-limit بسيط
if (function_exists('checkRateLimit')) {
    try {
        global $db, $config, $logger;
        @checkRateLimit($db, $config, $logger, 'customer_reports_' . $customerId, 60, 60);
    } catch (\Throwable $e) {
        // fail-open
    }
}

try {
    $stmt = $db->prepare("
        SELECT
            a.id              AS assessment_id,
            a.score,
            a.tier,
            a.status,
            a.created_at,
            a.updated_at,
            l.full_name,
            l.company_name,
            l.website_url,
            l.instagram_url,
            l.facebook_url,
            l.tiktok_url
        FROM assessments a
        LEFT JOIN leads l ON l.id = a.lead_id
        WHERE a.customer_id = ?
        ORDER BY a.created_at DESC
        LIMIT 100
    ");
    $stmt->execute([$customerId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    error_log('[customer/reports] query failed: ' . $e->getMessage());
    jsonError('تعذّر تحميل التقارير، حاول لاحقاً', 500);
}

// تطبيع البيانات للواجهة (تجنُّب null في الـ JSON)
$reports = array_map(function (array $r): array {
    $url = $r['website_url']
        ?: $r['instagram_url']
        ?: $r['facebook_url']
        ?: $r['tiktok_url']
        ?: '';

    return [
        'id'           => (int) $r['assessment_id'],
        'score'        => isset($r['score']) ? (int) $r['score'] : null,
        'tier'         => $r['tier'] ?: null,
        'status'       => $r['status'] ?: 'submitted',
        'company_name' => $r['company_name'] ?: ($r['full_name'] ?: '—'),
        'main_url'     => $url,
        'created_at'   => $r['created_at'],
        'updated_at'   => $r['updated_at'] ?? null,
    ];
}, $rows);

customerJsonOk([
    'count'   => count($reports),
    'reports' => $reports,
]);
