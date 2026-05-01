<?php
require_once __DIR__ . '/middleware.php';

try {
    $stmt = $pdo->query("SELECT id, client_name, client_phone, client_domain, competitors_domains, scores_json, status_label, notes, created_at FROM competitor_leads ORDER BY id DESC");
    $leads = $stmt->fetchAll();

    echo json_encode(['success' => true, 'data' => $leads]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB Error']);
}
