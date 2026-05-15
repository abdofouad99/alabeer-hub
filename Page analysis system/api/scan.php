<?php
// ============================================================
// api/scan.php — HTTP endpoint لفحص URL
// GET  /api/scan.php?url=https://...
// POST /api/scan.php   body: {"url":"https://..."}
// ============================================================
set_time_limit(300); // FB-3 FIX: 5 دقائق لضمان اكتمال جميع Apify actors
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/page-scan.php';

header('Content-Type: application/json; charset=utf-8');

// ── CORS: تحديد Origins المسموحة بدلاً من * ──
$allowedOrigins = [
    'https://yourdomain.com',
    'https://www.yourdomain.com',
    'http://localhost',
    'http://localhost:3000',
    'http://localhost:8080',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: https://yourdomain.com');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Rate Limiting: حد 10 طلبات لكل IP في الدقيقة ──
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rateFile = sys_get_temp_dir() . '/rate_' . md5($ip) . '_' . date('YmdHi');
$count = file_exists($rateFile) ? (int)file_get_contents($rateFile) : 0;
if ($count >= 10) {
    http_response_code(429);
    echo json_encode(['error' => 'تم تجاوز الحد المسموح. حاول بعد دقيقة.', 'success' => false]);
    exit;
}
file_put_contents($rateFile, $count + 1);

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
