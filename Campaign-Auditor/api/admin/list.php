<?php
require_once __DIR__ . '/middleware.php';
try {
    $stmt = $pdo->query("SELECT id, client_name, client_phone, platform, monthly_budget, campaign_score, problems_json, notes, created_at FROM campaign_leads ORDER BY id DESC");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
} catch (PDOException $e) { http_response_code(500); echo json_encode(['success' => false]); }
