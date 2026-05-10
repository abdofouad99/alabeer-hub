<?php
// ============================================================
// api/admin/auth.php — تسجيل دخول الأدمن وإدارة الجلسة
// ============================================================
require_once __DIR__ . '/../db.php';

require_once __DIR__ . '/../init.php';

$cfg = require __DIR__ . '/../config.php';
session_name($cfg['admin']['session_name']);
ini_set('session.gc_maxlifetime', $cfg['admin']['session_lifetime']);
session_start();

setCors();
$action = $_GET['action'] ?? 'login';

// ── GET check ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'check') {
    jsonOut(['authed' => !empty($_SESSION['admin_id'])]);
}

// ── POST logout ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'logout') {
    session_destroy();
    jsonOut(['ok' => true]);
}

// ── POST login ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$email    = trim($body['email']    ?? '');
$password = trim($body['password'] ?? '');

if (!$email || !$password) jsonError('البريد وكلمة المرور مطلوبان');

if (!checkApiRateLimit('admin_login_' . $email, 5, 300)) {
    jsonError('تم تجاوز عدد المحاولات. حاول بعد 5 دقائق', 429);
}

$db   = getDB();
$stmt = $db->prepare("SELECT id, password_hash FROM admin_users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    jsonError('بيانات الدخول غير صحيحة', 401);
}

// تجديد الجلسة دائماً بعد الدخول
session_regenerate_id(true);
$_SESSION['admin_id']    = $user['id'];
$_SESSION['admin_email'] = $email;

jsonOut(['ok' => true, 'email' => $email]);
