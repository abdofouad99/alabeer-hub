<?php
require_once __DIR__ . '/middleware.php';

$data = json_decode(file_get_contents('php://input'), true);
$id = $data['id'] ?? 0;
$notes = $data['notes'] ?? '';

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Missing ID']);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE competitor_leads SET notes = ? WHERE id = ?");
    $stmt->execute([$notes, $id]);
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB Error']);
}
