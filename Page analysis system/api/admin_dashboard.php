<?php
// ============================================================
// api/admin_dashboard.php — جلب بيانات لوحة القيادة
// ⚠️ لا يُستدعى إلا بعد تسجيل دخول الأدمن (session-based).
// ============================================================

// ── 1) Headers: CORS مُحكَمة (whitelist origins فقط) ─────────
// ملاحظة مهمة: نضع CORS + OPTIONS handler قبل auth gate
// لأن preflight (OPTIONS) لا يحمل cookies بحكم المواصفات،
// فلو requireAdmin() ركضت قبله ستردّ 401 وتُحظر الـ preflight
// كاملاً مما يُعطّل أي طلب cross-origin مشروع.
header('Content-Type: application/json; charset=utf-8');
header('Vary: Origin');

// نقبل origin إذا كان من نفس الموقع أو من قائمة بيضاء.
// نعكس الـ Origin مباشرة فقط بعد التحقق منه (لا نعكس قيمة عشوائية).
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$host   = $_SERVER['HTTP_HOST']   ?? '';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$selfOrigin = $scheme . '://' . $host;

$allowedOrigins = [
    $selfOrigin,
    'http://localhost',
    'http://127.0.0.1',
];

if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

// CORS preflight ينتهي هنا قبل أي auth check.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── 2) Auth: بقية الـ HTTP methods (GET/POST) محمية بـ session ──
require_once __DIR__ . '/admin/middleware.php';
requireAdmin();   // ترجع 401 وتنهي التنفيذ لو لا توجد جلسة

require_once __DIR__ . '/db.php';

try {
    $db = getDB();

    // 1. الإحصائيات السريعة (KPIs)
    $stmtLeads = $db->query("SELECT COUNT(*) FROM leads");
    $totalLeads = (int) $stmtLeads->fetchColumn();

    $stmtScans = $db->query("SELECT COUNT(*) FROM assessments");
    $totalScans = (int) $stmtScans->fetchColumn();

    // الأرقام الحقيقية فقط
    $kpis = [
        'visitors'  => $totalLeads, // من leads حالياً كبديل تقريبي
        'signups'   => $totalLeads,
        'analyses'  => $totalScans,
    ];

    // 2. قمع المبيعات (Funnel) — مراحل مرتبطة فعلاً بـ DB
    $funnel = [
        'visitors' => null,         // يحتاج analytics (GA4/Plausible)
        'signups'  => $totalLeads,
        'started'  => $totalScans,
        'bought'   => null,         // يحتاج جدول orders
        'vip'      => null,         // يحتاج جدول vip_requests
    ];

    // 3. العملاء الساخنين (Hot Leads) - آخر العملاء الذين أجروا الفحص
    $stmtHotLeads = $db->query("
        SELECT
            l.full_name,
            l.email,
            l.phone,
            a.score,
            a.summary,
            a.created_at
        FROM leads l
        JOIN assessments a ON l.id = a.lead_id
        ORDER BY a.created_at DESC
        LIMIT 10
    ");

    $hotLeads = [];
    while ($row = $stmtHotLeads->fetch(PDO::FETCH_ASSOC)) {
        $problem = "ضعف في مسار التحويل";
        if ($row['score'] < 50) {
            $problem = "مشاكل حرجة في الثقة والعرض";
        } elseif ($row['score'] < 70) {
            $problem = "ضعف في كتابة المحتوى البيعي (Copywriting)";
        }

        $hotLeads[] = [
            'name'    => $row['full_name'] ?: 'عميل غير معروف',
            'email'   => $row['email'] ?: $row['phone'],
            'score'   => $row['score'] !== null ? (int)$row['score'] : null,
            'problem' => $row['summary'] ?: $problem,
            'time'    => date('Y-m-d H:i', strtotime($row['created_at'])),
        ];
    }

    echo json_encode([
        'ok'        => true,
        'kpis'      => $kpis,
        'funnel'    => $funnel,
        'hot_leads' => $hotLeads,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('[admin_dashboard] ' . $e->getMessage());
    http_response_code(500);
    // لا نسرّب التفاصيل في الـ response
    echo json_encode(['ok' => false, 'error' => 'Internal server error']);
}
