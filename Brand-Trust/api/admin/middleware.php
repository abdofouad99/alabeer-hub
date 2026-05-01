<?php
require_once __DIR__ . '/../db.php';
$config = require __DIR__ . '/../config.php';

session_name($config['admin']['session_name']);
session_set_cookie_params($config['admin']['session_lifetime']);
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
