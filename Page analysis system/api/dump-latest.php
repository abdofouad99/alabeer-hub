<?php
require 'db.php';
$db = getDB();
$stmt = $db->query('SELECT * FROM assessments ORDER BY id DESC LIMIT 1');
$row = $stmt->fetch();
file_put_contents(__DIR__ . '/dump.txt', print_r($row, true));
echo "Dumped";
