<?php
// ============================================================
// api/admin/dashboard.php — KPIs + funnel + hot leads للوحة التحكم
// GET /api/admin/dashboard.php?days=30
//
// 🔒 محمي بـ session-based admin auth (requireAdmin).
// 🔌 شكل الاستجابة متوافق مع admin/dashboard-new.html (الجديد)
//    وكذلك مع admin/dashboard.html (القديم) كي لا نكسر الـ UI الموجود.
// ============================================================

require_once __DIR__ . '/middleware.php';
setCors();
requireAdmin();

$db = getDB();

// مرشح زمني (اختياري) — 0 = اليوم، 7/30/365 = آخر N يوم
$days = (int) ($_GET['days'] ?? 30);
if ($days < 0 || $days > 3650) $days = 30;

$dateFilter = '';
if ($days > 0) {
    $dateFilter = " WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)";
} elseif ($days === 0) {
    // اليوم فقط
    $dateFilter = " WHERE DATE(created_at) = CURDATE()";
}

// 1) عدّادات الـ KPIs الأساسية
$totalLeads = (int) $db->query("SELECT COUNT(*) FROM leads" . $dateFilter)->fetchColumn();
$totalScans = (int) $db->query("SELECT COUNT(*) FROM assessments" . $dateFilter)->fetchColumn();

// ⚠️ لا نُرجع أرقاماً مفبركة. الـ visitors / sales / revenue / avg_value
//    تحتاج تكاملاً مع تحليلات ويب وبوابة دفع — حتى ذلك الحين null.
$kpis = [
    'visitors'  => null,
    'signups'   => $totalLeads,
    'analyses'  => $totalScans,
    'sales'     => null,
    'revenue'   => null,
    'avg_value' => null,
];

// 2) قمع المبيعات — مراحل لها مصدر فعلي في DB فقط
$funnel = [
    'visitors' => null,
    'signups'  => $totalLeads,
    'started'  => $totalScans,
    'bought'   => null,
    'vip'      => null,
];

// 3) Hot Leads — آخر 10 عملاء أنهوا تحليلاً
$stmt = $db->query("
    SELECT l.id, l.full_name, l.email, l.phone,
           a.id AS assessment_id, a.score, a.tier, a.summary, a.created_at
    FROM leads l
    JOIN assessments a ON l.id = a.lead_id
    ORDER BY a.created_at DESC
    LIMIT 10
");

$hotLeads = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $score = $row['score'] !== null ? (int) $row['score'] : null;
    $hotLeads[] = [
        'id'            => (int) $row['id'],
        'assessment_id' => (int) $row['assessment_id'],
        'name'          => $row['full_name'] ?: 'عميل غير معروف',
        'email'         => $row['email'] ?: '',
        'phone'         => $row['phone'] ?: '',
        'score'         => $score,
        'tier'          => $row['tier'] ?: '',
        'problem'       => $row['summary'] ?: '',
        'time'          => $row['created_at'] ? date('Y-m-d H:i', strtotime($row['created_at'])) : '',
    ];
}

// 4) توزيع أنواع المشاريع (insights)
$projects = [];
try {
    $rows = $db->query("
        SELECT project_type, COUNT(*) AS cnt
        FROM leads
        WHERE project_type IS NOT NULL AND project_type != ''
        GROUP BY project_type
        ORDER BY cnt DESC
        LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $projects[] = [
            'project_type' => $r['project_type'],
            'count'        => (int) $r['cnt'],
        ];
    }
} catch (Throwable $e) {
    // العمود قد يكون غير موجود في قواعد بيانات قديمة
    $projects = [];
}

jsonOut([
    'ok'        => true,
    'days'      => $days,
    'kpis'      => $kpis,
    'funnel'    => $funnel,
    'hot_leads' => $hotLeads,
    'insights'  => [
        'projects' => $projects,
    ],
]);
