<?php
// ============================================================
// api/scan.php — HTTP endpoint لفحص URL
// GET  /api/scan.php?url=https://...
// POST /api/scan.php   body: {"url":"https://..."}
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/page-scan.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// قبول GET أو POST
$url = '';
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $url = trim($_GET['url'] ?? '');
} else {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);
    $url  = trim($body['url'] ?? $_POST['url'] ?? '');
}

if (!$url) {
    http_response_code(400);
    echo json_encode(['error' => 'أدخل رابط الصفحة', 'success' => false]);
    exit;
}

$cfg    = require __DIR__ . '/config.php';
$result = runPageScan($url, $cfg);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
