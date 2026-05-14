<?php
// ============================================================
// api/status.php — حالة التحليل اللحظية (للـ Polling)
// GET /api/status.php?id=123
// يرجع: status, scan_step, score, tier, scan_error
// ============================================================
require_once __DIR__ . '/db.php';
setCors();
header('Content-Type: application/json; charset=utf-8');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$token = trim((string)($_GET['token'] ?? ''));
if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'معرّف غير صالح']);
    exit;
}
if ($token === '') {
    http_response_code(404);
    echo json_encode(['error' => 'لم يُعثر على التقييم']);
    exit;
}

$db   = getDB();
$stmt = $db->prepare("
    SELECT a.id, a.status, a.score, a.tier, a.scan_status, a.scan_error,
           a.scan_step,
           l.full_name, l.company_name
    FROM assessments a
    LEFT JOIN leads l ON a.lead_id = l.id
    WHERE a.id = ? AND a.report_token = ?
    LIMIT 1
");
$stmt->execute([$id, $token]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'لم يُعثر على التقييم']);
    exit;
}

// map DB status to frontend-friendly status
$status = 'pending';
if ($row['status'] === 'running')   $status = 'running';
if ($row['status'] === 'analyzed')  $status = 'analyzed';
if ($row['status'] === 'failed')    $status = 'failed';

// scan_step: يُعرِّف الخطوة الحالية في التحليل (0-6)
// يُحدَّث من analyze.php أثناء التنفيذ
$scanStep = (int)($row['scan_step'] ?? 0);

$out = [
    'id'         => (int)$row['id'],
    'status'     => $status,
    'scan_step'  => $scanStep,
    'score'      => $row['score'] !== null ? (int)$row['score'] : null,
    'tier'       => $row['tier']  ?? null,
    'scan_error' => $row['scan_error'] ?? null,
    'name'       => $row['full_name'] ?? $row['company_name'] ?? '',
];

echo json_encode($out, JSON_UNESCAPED_UNICODE);
