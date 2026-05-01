<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/db.php';

$id = intval($_GET['id'] ?? 110);
$db = getDB();

// Check actual columns
$leadCols = $db->query("SHOW COLUMNS FROM leads")->fetchAll(PDO::FETCH_COLUMN);
$asmCols  = $db->query("SHOW COLUMNS FROM assessments")->fetchAll(PDO::FETCH_COLUMN);

// Get assessment data
$stmt = $db->prepare("SELECT a.*, l.full_name, l.phone FROM assessments a LEFT JOIN leads l ON a.lead_id=l.id WHERE a.id=?");
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row) {
    echo json_encode(['error' => 'لا يوجد تقييم', 'id' => $id, 'leads_columns' => $leadCols, 'assessment_columns' => $asmCols]);
    exit;
}

echo json_encode([
    'leads_columns'       => $leadCols,
    'assessment_columns'  => $asmCols,
    'id'                  => $row['id'],
    'status'              => $row['status'],
    'score'               => $row['score'],
    'tier'                => $row['tier'],
    'full_name'           => $row['full_name'],
    'breakdown_raw'       => $row['breakdown'] ?? null,
    'strengths_raw'       => $row['strengths'] ?? null,
    'weaknesses_raw'      => $row['weaknesses'] ?? null,
    'recommendations_raw' => $row['recommendations'] ?? null,
    'bottleneck'          => $row['bottleneck'] ?? null,
    'created_at'          => $row['created_at'],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
