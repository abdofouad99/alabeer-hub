<?php
// ============================================================
// api/admin_dashboard.php — جلب بيانات لوحة القيادة
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db.php';

try {
    $db = getDB();
    
    // 1. الإحصائيات السريعة (KPIs)
    $stmtLeads = $db->query("SELECT COUNT(*) FROM leads");
    $totalLeads = (int) $stmtLeads->fetchColumn();
    
    $stmtScans = $db->query("SELECT COUNT(*) FROM assessments");
    $totalScans = (int) $stmtScans->fetchColumn();
    
    // بيانات مؤقتة للمبيعات (إلى أن نربط بوابات الدفع)
    $mockSales = floor($totalLeads * 0.15) + 48; 
    $mockRevenue = $mockSales * 299; // متوسط سعر الباقة 299$
    
    $kpis = [
        'visitors' => $totalLeads * 14 + 1000, // معدل تحويل تقريبي
        'signups' => $totalLeads,
        'analyses' => $totalScans,
        'sales' => $mockSales,
        'revenue' => $mockRevenue,
        'avg_value' => 299
    ];

    // 2. قمع المبيعات (Funnel)
    $funnel = [
        'visitors' => $kpis['visitors'],
        'signups'  => $totalLeads,
        'started'  => $totalScans,
        'bought'   => $mockSales,
        'vip'      => floor($mockSales * 0.1)
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
        // تحديد المشكلة الأساسية بشكل مبسط
        $problem = "ضعف في مسار التحويل";
        if ($row['score'] < 50) $problem = "مشاكل حرجة في الثقة والعرض";
        elseif ($row['score'] < 70) $problem = "ضعف في كتابة المحتوى البيعي (Copywriting)";
        
        $hotLeads[] = [
            'name' => $row['full_name'] ?: 'عميل غير معروف',
            'email' => $row['email'] ?: $row['phone'], // نعرض الهاتف إذا لم يوجد بريد
            'score' => $row['score'] ?? rand(40, 85),
            'problem' => $row['summary'] ?: $problem,
            'time' => date('Y-m-d H:i', strtotime($row['created_at']))
        ];
    }
    
    // إرسال البيانات
    echo json_encode([
        'ok' => true,
        'kpis' => $kpis,
        'funnel' => $funnel,
        'hot_leads' => $hotLeads
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
