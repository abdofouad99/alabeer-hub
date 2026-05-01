<?php
require_once __DIR__ . '/middleware.php';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!$id) jsonError('Missing lead id');
    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM leads WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $lead = $stmt->fetch();
    if (!$lead) jsonError('Lead not found', 404);
    jsonOut($lead);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $leadId = $body['id'] ?? $id;
    if (!$leadId) jsonError('Missing lead id');
    $updates = [];
    $vals    = [];
    foreach (['status','notes'] as $f) {
        if (isset($body[$f])) { $updates[] = "`$f`=?"; $vals[] = $body[$f]; }
    }
    if (!$updates) jsonError('Nothing to update');
    $vals[] = $leadId;
    $db = getDB();
    $db->prepare("UPDATE leads SET " . implode(',', $updates) . " WHERE id=?")->execute($vals);
    jsonOut(['ok' => true]);
}
jsonError('Method not allowed', 405);
