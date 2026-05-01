<?php
require_once __DIR__ . '/middleware.php';
$db = getDB();

$total   = (int)$db->query("SELECT COUNT(*) FROM assessments")->fetchColumn();
$avg     = $total ? (int)$db->query("SELECT ROUND(AVG(score)) FROM assessments WHERE score IS NOT NULL")->fetchColumn() : 0;

$stages = $db->query("SELECT stage, COUNT(*) as cnt FROM assessments WHERE stage IS NOT NULL GROUP BY stage")->fetchAll();
$stageMap = [];
foreach ($stages as $s) $stageMap[$s['stage']] = (int)$s['cnt'];

$recent = $db->query("SELECT DATE(created_at) as d, COUNT(*) as cnt FROM assessments GROUP BY DATE(created_at) ORDER BY d DESC LIMIT 14")->fetchAll();

jsonOut([
    'total'    => $total,
    'avg'      => $avg,
    'stages'   => $stageMap,
    'timeline' => array_reverse($recent),
]);
