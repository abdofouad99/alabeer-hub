<?php
require_once __DIR__ . '/middleware.php';

try {
    $pdo = DB::getInstance();
    $stmt = $pdo->query("SELECT * FROM simulations ORDER BY created_at DESC");
    $simulations = $stmt->fetchAll();
    
    sendJson(['success' => true, 'data' => $simulations]);
} catch (Exception $e) {
    sendJson(['success' => false, 'message' => 'DB Error: ' . $e->getMessage()], 500);
}
