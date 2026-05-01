<?php
require_once __DIR__ . '/../db.php';
$config = require __DIR__ . '/../config.php';
session_name($config['admin']['session_name']); session_set_cookie_params($config['admin']['session_lifetime']); session_start();
$data = json_decode(file_get_contents('php://input'), true);
$email = $data['email'] ?? ''; $password = $data['password'] ?? '';
if (!$email || !$password) { echo json_encode(['success' => false, 'message' => 'البيانات غير مكتملة']); exit; }
try {
    $stmt = $pdo->prepare("SELECT id, password_hash FROM admin_users WHERE email = :email");
    $stmt->execute([':email' => $email]); $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['admin_logged_in'] = true; $_SESSION['admin_id'] = $user['id'];
        echo json_encode(['success' => true]);
    } else { http_response_code(401); echo json_encode(['success' => false, 'message' => 'البريد أو كلمة المرور غير صحيحة']); }
} catch (Exception $e) { http_response_code(500); echo json_encode(['success' => false, 'message' => 'Error']); }
