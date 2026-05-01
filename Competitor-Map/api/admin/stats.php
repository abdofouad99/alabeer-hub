<?php
require_once __DIR__ . '/middleware.php';

try {
    // Total leads
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM competitor_leads");
    $totalLeads = $stmt->fetchColumn();

    // Today's leads
    $todayStmt = $pdo->query("SELECT COUNT(*) FROM competitor_leads WHERE DATE(created_at) = CURDATE()");
    $todayLeads = $todayStmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'data' => [
            'total_leads' => $totalLeads,
            'today_leads' => $todayLeads
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB Error']);
}
