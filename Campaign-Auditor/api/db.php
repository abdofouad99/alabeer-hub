<?php
$config = require __DIR__ . '/config.php';
try {
    $dsn = "mysql:host=" . $config['db']['host'] . ";dbname=" . $config['db']['name'] . ";charset=" . $config['db']['charset'];
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (\PDOException $e) { echo json_encode(['success' => false, 'error' => 'Connection failed.']); exit; }
