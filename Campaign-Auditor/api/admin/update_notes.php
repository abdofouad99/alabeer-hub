<?php
require_once __DIR__ . '/middleware.php';
$data = json_decode(file_get_contents('php://input'), true);
$id = $data['id'] ?? 0; $notes = $data['notes'] ?? '';
if (!$id) { echo json_encode(['success' => false]); exit; }
try { $pdo->prepare("UPDATE campaign_leads SET notes = ? WHERE id = ?")->execute([$notes, $id]); echo json_encode(['success' => true]); }
catch (PDOException $e) { echo json_encode(['success' => false]); }
