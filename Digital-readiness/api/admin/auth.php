<?php
require_once __DIR__ . '/../db.php';
$cfg = require __DIR__ . '/../config.php';
session_name($cfg['admin']['session_name']);
ini_set('session.gc_maxlifetime', $cfg['admin']['session_lifetime']);
session_start();
setCors();
$action = $_GET['action'] ?? 'login';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'check') {
    jsonOut(['authed' => !empty($_SESSION['admin_id'])]);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'logout') {
    session_destroy();
    jsonOut(['ok' => true]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$email    = trim($body['email']    ?? '');
$password = trim($body['password'] ?? '');
if (!$email || !$password) jsonError('البريد وكلمة المرور مطلوبان');

$db   = getDB();
$stmt = $db->prepare("SELECT id, password_hash FROM admin_users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    jsonError('بيانات الدخول غير صحيحة', 401);
}
session_regenerate_id(true);
$_SESSION['admin_id']    = $user['id'];
$_SESSION['admin_email'] = $email;
jsonOut(['ok' => true, 'email' => $email]);
