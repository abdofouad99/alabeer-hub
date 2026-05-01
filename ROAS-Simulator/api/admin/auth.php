<?php
require_once __DIR__ . '/../db.php';

$config = require __DIR__ . '/../config.php';
session_name($config['admin']['session_name']);
session_set_cookie_params($config['admin']['session_lifetime']);
session_start();

$data = json_decode(file_get_contents('php://input'), true);
$email = $data['email'] ?? '';
$password = $data['password'] ?? '';

if (!$email || !$password) {
    sendJson(['success' => false, 'message' => 'البيانات غير مكتملة'], 400);
}

try {
    $pdo = DB::getInstance();
    $stmt = $pdo->prepare("SELECT id, password_hash FROM admin_users WHERE email = :email");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id'] = $user['id'];
        
        sendJson(['success' => true]);
    } else {
        sendJson(['success' => false, 'message' => 'البريد أو كلمة المرور غير صحيحة'], 401);
    }
} catch (Exception $e) {
    sendJson(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
}
