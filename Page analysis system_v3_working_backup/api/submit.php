<?php
// ============================================================
// api/submit.php — استقبال الاستبيان + تشغيل التحليل
// POST /api/submit.php
// ============================================================

// ─── منع أي إخراج HTML من PHP يُفسد الـ JSON ──────────────
ob_start();                         // التقط كل إخراج عشوائي
ini_set('display_errors', 0);      // لا تطبع أخطاء PHP في الاستجابة
ini_set('log_errors', 1);          // سجّلها في error_log فقط
error_reporting(E_ALL);            // لا تخفي الأخطاء — فقط لا تعرضها
set_time_limit(120);               // 2 دقيقة — كافية لأطول استجابة Apify
ignore_user_abort(true);           // لا تتوقف إذا أغلق المتصفح الاتصال

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/db.php';

// ── قراءة الـ body ───────────────────────────────────────────
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!$body || !is_array($body)) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

$leadData    = $body['lead']    ?? [];
$answersData = $body['answers'] ?? [];

// تحقق من الحد الأدنى
if (empty($leadData['full_name']) || empty($leadData['phone'])) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['error' => 'الاسم والهاتف مطلوبان']);
    exit;
}

try {
    $db = getDB();
} catch (\Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['error' => 'DB Error: ' . $e->getMessage()]);
    exit;
}

// ── auto-migration شامل ──────────────────────────────────────
try {
    // leads columns
    $existLeadCols = $db->query("SHOW COLUMNS FROM leads")->fetchAll(PDO::FETCH_COLUMN);
    $wantLeadCols  = [
        'email'         => 'VARCHAR(120)',
        'company_name'  => 'VARCHAR(150)',
        'project_type'  => 'VARCHAR(60)',
        'platform'      => 'VARCHAR(40)',
        'country'       => 'VARCHAR(60)',
        'website_url'   => 'VARCHAR(500)',
        'facebook_url'  => 'VARCHAR(500)',
        'instagram_url' => 'VARCHAR(500)',
        'tiktok_url'    => 'VARCHAR(500)',
        'youtube_url'   => 'VARCHAR(500)',
        'source'        => "VARCHAR(60) DEFAULT 'growth_fingerprint'",
    ];
    foreach ($wantLeadCols as $col => $def) {
        if (!in_array($col, $existLeadCols)) {
            $db->exec("ALTER TABLE leads ADD COLUMN `$col` $def NULL");
        }
    }

    // assessments columns
    $existAsmCols = $db->query("SHOW COLUMNS FROM assessments")->fetchAll(PDO::FETCH_COLUMN);
    $wantAsmCols  = [
        'report_token'    => 'VARCHAR(64)',
        'breakdown'       => 'JSON',
        'summary'         => 'TEXT',
        'recommendations' => 'JSON',
        'strengths'       => 'JSON',
        'weaknesses'      => 'JSON',
        'next_steps'      => 'JSON',
        'scan_result'     => 'JSON',
        'scan_status'     => 'VARCHAR(20)',
        'scan_error'      => 'TEXT',
    ];
    foreach ($wantAsmCols as $col => $def) {
        if (!in_array($col, $existAsmCols)) {
            $db->exec("ALTER TABLE assessments ADD COLUMN `$col` $def NULL");
        }
    }
    // tier column ENUM needs special handling
    if (!in_array('tier', $existAsmCols)) {
        $db->exec("ALTER TABLE assessments ADD COLUMN `tier` ENUM('red','yellow','green') NULL");
    }
} catch (\Throwable $e) {
    // migration failed — نكمل على أي حال
}

// ── 1) INSERT lead — فقط الأعمدة الموجودة ───────────────────
$safeLeadFields = ['full_name','phone','email','company_name','project_type',
    'platform','country','website_url','facebook_url','instagram_url',
    'tiktok_url','youtube_url'];

$insertCols = [];
$insertVals = [];
foreach ($safeLeadFields as $f) {
    if (array_key_exists($f, $leadData) && $leadData[$f] !== '') {
        $insertCols[] = "`$f`";
        $insertVals[] = (string)$leadData[$f];
    }
}
// source دائماً
$insertCols[] = '`source`';
$insertVals[] = 'growth_fingerprint';

$placeholders = implode(',', array_fill(0, count($insertVals), '?'));
$colsList     = implode(',', $insertCols);

try {
    $db->prepare("INSERT INTO leads ($colsList) VALUES ($placeholders)")
       ->execute($insertVals);
    $leadId = (int)$db->lastInsertId();
} catch (\Throwable $e) {
    // إذا فشل بسبب أعمدة مفقودة — insert بالحد الأدنى
    $db->prepare("INSERT INTO leads (full_name, phone) VALUES (?,?)")
       ->execute([$leadData['full_name'], $leadData['phone']]);
    $leadId = (int)$db->lastInsertId();
}

// ── 2) INSERT assessment ──────────────────────────────────────
$token = bin2hex(random_bytes(16));
try {
    $db->prepare("INSERT INTO assessments (lead_id, status, report_token) VALUES (?,?,?)")
       ->execute([$leadId, 'submitted', $token]);
} catch (\Throwable $e) {
    $db->prepare("INSERT INTO assessments (lead_id, status) VALUES (?,?)")
       ->execute([$leadId, 'submitted']);
}
$assessmentId = (int)$db->lastInsertId();

// ── 3) INSERT answers ─────────────────────────────────────────
try {
    $insStmt = $db->prepare("INSERT INTO answers (assessment_id, question_key, answer) VALUES (?,?,?)");
    foreach ($answersData as $key => $value) {
        $insStmt->execute([$assessmentId, $key, json_encode($value, JSON_UNESCAPED_UNICODE)]);
    }
} catch (\Throwable $e) {
    // تخطّى أخطاء الإجابات
}

// ── 4) تشغيل التحليل ─────────────────────────────────────────
$analysis = ['ok' => false];
try {
    require_once __DIR__ . '/analyze.php';
    $analysis = runAnalysis($assessmentId);
} catch (\Throwable $e) {
    $analysis = ['ok' => false, 'error' => $e->getMessage()];
}

// ── 5) الرد — نظّف الـ Buffer قبل الـ JSON ──────────────────
ob_end_clean();   // ← أهم سطر: يحذف أي تحذير PHP طبع قبلنا
http_response_code(200);
echo json_encode([
    'ok'            => true,
    'assessment_id' => $assessmentId,
    'score'         => $analysis['score'] ?? null,
    'tier'          => $analysis['tier']  ?? null,
    'token'         => $token,
], JSON_UNESCAPED_UNICODE);
