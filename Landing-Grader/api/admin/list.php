<?php
require_once __DIR__ . '/middleware.php';

try {
    // $pdo is already included
    // جلب أحدث العملاء
    $stmt = $pdo->query("SELECT id, client_name, client_phone, website_url, lp_score, created_at FROM landing_leads ORDER BY id DESC");
    $leads = $stmt->fetchAll();
    
    echo json_encode(['success' => true, 'data' => $leads]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB Error: ' . $e->getMessage()]);
}
