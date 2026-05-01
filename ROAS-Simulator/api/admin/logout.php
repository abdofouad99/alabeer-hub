<?php
$config = require __DIR__ . '/../config.php';
session_name($config['admin']['session_name']);
session_start();
session_destroy();
setcookie($config['admin']['session_name'], '', time() - 3600, '/');
echo json_encode(['success' => true]);
