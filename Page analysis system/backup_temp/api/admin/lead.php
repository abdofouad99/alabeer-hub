<?php
// ============================================================
// api/admin/lead.php — جلب/تعديل بيانات عميل
// GET  /api/admin/lead.php?id=123
// POST /api/admin/lead.php  { "id":123, "status":"contacted", "notes":"..." }
// ============================================================
require_once __DIR__ . '/middleware.php';
setCors();
requireAdmin();

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id) jsonError('Invalid lead id');

    $stmt = $db->prepare("SELECT * FROM leads WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Lead not found', 404);
    jsonOut($row);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) jsonError('Invalid lead id');

    $allowed = ['status', 'notes'];
    $sets = $vals = [];
    foreach ($allowed as $field) {
        if (array_key_exists($field, $body)) {
            $sets[] = "`$field` = ?";
            $vals[] = $body[$field];
        }
    }
    if (!$sets) jsonError('No valid fields to update');

    $vals[] = $id;
    $db->prepare("UPDATE leads SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
    jsonOut(['ok' => true]);
}

jsonError('Method not allowed', 405);
