<?php
require_once __DIR__ . '/middleware.php';

try {
    $pdo = getDbConnection();
    
    // إجمالي العملاء
    $total_stmt = $pdo->query("SELECT COUNT(*) as total FROM trust_leads");
    $total = $total_stmt->fetch()['total'] ?? 0;

    // متوسط مستوى الثقة للعميلات
    $avg_stmt = $pdo->query("SELECT AVG(trust_score) as avg_score FROM trust_leads");
    $avg_score = round($avg_stmt->fetch()['avg_score'] ?? 0);

    // العملاء بخطر الثقة (دليل قوي للبيع) (0 - 40%)
    $danger_stmt = $pdo->query("SELECT COUNT(*) as danger_count FROM trust_leads WHERE trust_score <= 40");
    $danger_count = $danger_stmt->fetch()['danger_count'] ?? 0;

    echo json_encode([
        'success' => true, 
        'data' => [
            'total_leads' => $total,
            'average_trust' => $avg_score,
            'danger_leads' => $danger_count
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB Error']);
}
