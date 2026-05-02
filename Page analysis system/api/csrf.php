<?php
// ============================================================
// api/csrf.php — توليد CSRF token وتخزينه في الـ session
// GET /api/csrf.php
//
// الفكرة: js/quiz.js يستدعي هذا الـ endpoint قبل POST /api/submit.php،
// فيستلم token يضعه في X-CSRF-Token. الـ submit.php يقارنه بـ
// $_SESSION['csrf_token']. الـ Same-Origin Policy يضمن أن المواقع
// الخارجية لا تستطيع قراءة الـ token (الاستجابة JSON تُحجَب CORS).
// ============================================================

require_once __DIR__ . '/db.php';
setCors();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// نبدأ session فقط لو لم تكن بدأت — تجنّب تحذيرات headers_sent
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
echo json_encode([
    'csrf_token' => $_SESSION['csrf_token'],
], JSON_UNESCAPED_UNICODE);
