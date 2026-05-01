<?php
require_once __DIR__ . '/middleware.php';

try {
    $pdo = getDbConnection();
    // جلب أحدث العملاء
    $stmt = $pdo->query("SELECT * FROM trust_leads ORDER BY created_at DESC");
    $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $leads]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB Error: ' . $e->getMessage()]);
}
