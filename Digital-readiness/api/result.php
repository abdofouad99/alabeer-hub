<?php
// ============================================================
// api/result.php — جلب نتيجة تقييم معين
// GET /api/result.php?id=123
// ============================================================
require_once __DIR__ . '/db.php';
setCors();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) jsonError('Invalid assessment id');

$db   = getDB();
$stmt = $db->prepare("SELECT * FROM assessments WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$row  = $stmt->fetch();

if (!$row) jsonError('Assessment not found', 404);

// Decode JSON fields
foreach (['breakdown','strengths','weaknesses','roadmap','quick_wins','monthly_plan','tools_suggested'] as $f) {
    if (!empty($row[$f])) $row[$f] = json_decode($row[$f], true);
}

jsonOut($row);
