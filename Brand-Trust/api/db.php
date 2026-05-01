<?php
require_once __DIR__ . '/config.php';

function getDbConnection() {
    $config = require __DIR__ . '/config.php';
    try {
        $dsn = "mysql:host=" . $config['db']['host'] . ";dbname=" . $config['db']['name'] . ";charset=" . $config['db']['charset'];
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        return new PDO($dsn, $config['db']['user'], $config['db']['pass'], $options);
    } catch (PDOException $e) {
        error_log($e->getMessage());
        return null;
    }
}
