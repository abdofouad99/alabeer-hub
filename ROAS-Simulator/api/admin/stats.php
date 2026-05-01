<?php
require_once __DIR__ . '/middleware.php';

try {
    $pdo = DB::getInstance();
    
    // إجمالي الذي تم تشخيصهم
    $total_stmt = $pdo->query("SELECT COUNT(*) as total FROM simulations");
    $total = $total_stmt->fetch()['total'];

    // إجمالي الميزانيات المُدارة
    $budget_stmt = $pdo->query("SELECT SUM(monthly_budget) as total_budget FROM simulations");
    $total_budget = $budget_stmt->fetch()['total_budget'] ?? 0;

    // إحصائيات الحالة المالية
    $status_stmt = $pdo->query("SELECT financial_status, COUNT(*) as count FROM simulations GROUP BY financial_status");
    $statuses = $status_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    sendJson([
        'success' => true, 
        'data' => [
            'total_leads' => $total,
            'total_ad_budget' => $total_budget,
            'bleeding' => $statuses['bleeding'] ?? 0,
            'breakeven' => $statuses['breakeven'] ?? 0,
            'profitable' => $statuses['profitable'] ?? 0
        ]
    ]);
} catch (Exception $e) {
    sendJson(['success' => false, 'message' => 'DB Error'], 500);
}
