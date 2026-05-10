<?php
// مسح كل ملفات الـ cache دون استثناء
header('Content-Type: application/json; charset=utf-8');
$cacheDir = __DIR__ . '/../cache/';
$deleted = $errors = [];

foreach (glob($cacheDir . '*.cache') as $file) {
    if (@unlink($file)) $deleted[] = basename($file);
    else $errors[] = basename($file);
}

echo json_encode([
    'status'  => 'done',
    'deleted' => count($deleted),
    'errors'  => $errors,
    'files'   => $deleted,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
