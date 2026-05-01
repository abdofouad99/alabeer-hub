<?php
// ============================================================
// api/submit.php — استقبال إجابات فحص الجاهزية الرقمية
// POST /api/submit.php   Body: { "lead": {...}, "answers": {...} }
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/analyze.php';
setCors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) jsonError('Invalid JSON body');

$leadData    = $body['lead']    ?? [];
$answersData = $body['answers'] ?? [];

$db = getDB();

// ── 1) إدخال Lead ────────────────────────────────────────────
$leadFields = [
    'full_name','phone','email','company_name',
    'industry','employees','country','website_url',
];
$leadCols = $leadVals = [];
foreach ($leadFields as $f) {
    if (isset($leadData[$f]) && $leadData[$f] !== '') {
        $leadCols[] = "`$f`";
        $leadVals[] = $leadData[$f];
    }
}
$leadCols[] = '`source`'; $leadVals[] = 'digital_readiness';

$sql = 'INSERT INTO leads (' . implode(',', $leadCols) . ') VALUES (' . implode(',', array_fill(0, count($leadVals), '?')) . ')';
$db->prepare($sql)->execute($leadVals);
$leadId = (int)$db->lastInsertId();

// ── 2) إدخال Assessment ──────────────────────────────────────
$token = bin2hex(random_bytes(16));
$db->prepare("INSERT INTO assessments (lead_id, status, report_token) VALUES (?,?,?)")
   ->execute([$leadId, 'submitted', $token]);
$assessmentId = (int)$db->lastInsertId();

// ── 3) إدخال الإجابات ────────────────────────────────────────
$insStmt = $db->prepare("INSERT INTO answers (assessment_id, question_key, answer) VALUES (?,?,?)");
foreach ($answersData as $key => $value) {
    $insStmt->execute([$assessmentId, $key, json_encode($value, JSON_UNESCAPED_UNICODE)]);
}

// ── 4) تشغيل التحليل ─────────────────────────────────────────
try {
    $analysis = runAnalysis($assessmentId);
} catch (\Throwable $e) {
    $analysis = ['error' => $e->getMessage()];
}

jsonOut([
    'assessment_id' => $assessmentId,
    'analysis'      => $analysis,
]);
