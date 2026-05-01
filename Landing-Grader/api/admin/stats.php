<?php
require_once __DIR__ . '/middleware.php';

try {
    // $pdo is available natively
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM landing_leads");
    $totalLeads = $stmt->fetchColumn();

    // متوسط التحويل
    $avgQuery = $pdo->query("SELECT AVG(lp_score) FROM landing_leads");
    $avgConversion = round($avgQuery->fetchColumn() ?: 0);

    // عملاء بتسريب مبيعات خطير (score <= 50)
    $dangerQuery = $pdo->query("SELECT COUNT(*) FROM landing_leads WHERE lp_score <= 50");
    $dangerLeads = $dangerQuery->fetchColumn();

    $stats = [
        'total_leads' => $totalLeads,
        'average_conversion' => $avgConversion,
        'danger_leads' => $dangerLeads
    ];
    echo json_encode([
        'success' => true, 
        'data' => $stats
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB Error']);
}
