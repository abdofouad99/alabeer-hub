<?php
require_once __DIR__ . '/middleware.php';
try {
    $total = $pdo->query("SELECT COUNT(*) FROM campaign_leads")->fetchColumn();
    $today = $pdo->query("SELECT COUNT(*) FROM campaign_leads WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    echo json_encode(['success' => true, 'data' => ['total_leads' => $total, 'today_leads' => $today]]);
} catch (Exception $e) { http_response_code(500); echo json_encode(['success' => false]); }
