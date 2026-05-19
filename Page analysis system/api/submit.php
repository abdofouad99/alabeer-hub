<?php
// ============================================================
// api/submit.php — استقبال الاستبيان + تشغيل التحليل
// POST /api/submit.php
// ============================================================

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/customer/middleware.php';

/** @var array $config */
/** @var PDO $db */
/** @var object $logger */

// ─── منع أي إخراج HTML من PHP يُفسد الـ JSON ──────────────
ob_start();                         // التقط كل إخراج عشوائي
ini_set('display_errors', 0);      // لا تطبع أخطاء PHP في الاستجابة
ini_set('log_errors', 1);          // سجّلها في error_log فقط
error_reporting(E_ALL);            // لا تخفي الأخطاء — فقط لا تعرضها
set_time_limit(0);                 // بدون حد أقصى — لعدم تقييد عملية التحليل وبطء Apify
ignore_user_abort(true);           // لا تتوقف إذا أغلق المتصفح الاتصال

setCors();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/db.php';

// ── التحقق من Rate Limiting ────────────────────────────────
if (!checkApiRateLimit('submit_analysis')) {
    logWarning('Rate limit exceeded for submit analysis', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
    ob_end_clean();
    http_response_code(429);
    echo json_encode(['error' => 'تم تجاوز الحد المسموح من الطلبات. يرجى المحاولة لاحقاً.']);
    exit;
}

// إضافة headers الـ rate limit
$rateHeaders = getRateLimitHeaders($db, $config, $logger, 'submit_analysis');
foreach ($rateHeaders as $header => $value) {
    header("$header: $value");
}

// ── قراءة الـ body ───────────────────────────────────────────
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!$body || !is_array($body)) {
    logError('Invalid JSON body received', ['raw' => substr($raw, 0, 100)]);
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

$leadData    = $body['lead']    ?? [];
$answersData = $body['answers'] ?? [];

// ── معالجة البيانات الجديدة ────────────────────────────────

// معالجة الجمهور المستهدف إذا كان مصفوفة (من Pills المتعددة)
if (isset($leadData['target_audience']) && is_array($leadData['target_audience'])) {
    $leadData['target_audience'] = implode(', ', $leadData['target_audience']);
}

// معالجة الأهداف إذا كانت مصفوفة
if (isset($leadData['objective']) && is_array($leadData['objective'])) {
    $leadData['objective'] = implode(', ', $leadData['objective']);
}

// معالجة مجال العمل إذا كان "أخرى" مع تحديد يدوي
if (
    isset($leadData['project_type']) &&
    $leadData['project_type'] === 'أخرى' &&
    !empty($leadData['custom_project_type'])
) {
    $leadData['project_type'] = $leadData['custom_project_type'];
    unset($leadData['custom_project_type']);
}

// ── تحويل حقل 'url' العام إلى العمود الصحيح (Fallback للـ legacy) ─
if (
    !empty($leadData['url']) &&
    empty($leadData['website_url']) &&
    empty($leadData['facebook_url']) &&
    empty($leadData['instagram_url']) &&
    empty($leadData['tiktok_url']) &&
    empty($leadData['twitter_url'])
) {
    $rawUrl = trim($leadData['url']);
    if (str_contains($rawUrl, 'instagram.com')) {
        $leadData['instagram_url'] = $rawUrl;
    } elseif (str_contains($rawUrl, 'facebook.com') || str_contains($rawUrl, 'fb.com')) {
        $leadData['facebook_url'] = $rawUrl;
    } elseif (str_contains($rawUrl, 'tiktok.com')) {
        $leadData['tiktok_url'] = $rawUrl;
    } elseif (str_contains($rawUrl, 'twitter.com') || str_contains($rawUrl, 'x.com')) {
        $leadData['twitter_url'] = $rawUrl;
    } else {
        $leadData['website_url'] = $rawUrl;
    }
}

// ── التحقق من الحقول المطلوبة ─────────────────────────────
$requiredFields = ['full_name', 'phone', 'project_type', 'country', 'city'];
foreach ($requiredFields as $field) {
    if (empty($leadData[$field])) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['error' => "حقل {$field} مطلوب"]);
        exit;
    }
}

// ── منطق حساب العميل (Customer Account) ────────────────────
// إذا أُدخل email + password → نُنشئ حساب جديد أو ندخل الموجود
// (الإيميل بدون كلمة مرور = lead عابر بدون حساب — للتوافق مع التدفق القديم)
$customerId = null;
$leadEmail    = isset($leadData['email'])    ? trim((string) $leadData['email'])    : '';
$leadPassword = isset($leadData['password']) ? (string)       $leadData['password'] : '';

// تحقق إن كان العميل مسجَّل دخول مسبقاً (session) — أولوية للجلسة
$sessionCustomerId = getCurrentCustomerId();
if ($sessionCustomerId) {
    $customerId = $sessionCustomerId;
}

// لو لا توجد جلسة، ولكن أُدخلت بيانات اعتماد → سجّل/أنشئ
if ($customerId === null && $leadEmail !== '' && $leadPassword !== '') {
    if (!filter_var($leadEmail, FILTER_VALIDATE_EMAIL) || strlen($leadEmail) > 190) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['error' => 'بريد إلكتروني غير صالح']);
        exit;
    }
    if (strlen($leadPassword) < 8 || strlen($leadPassword) > 200) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['error' => 'كلمة المرور يجب أن تكون 8 حروف على الأقل']);
        exit;
    }

    try {
        $stmt = $db->prepare("SELECT id, password_hash FROM customers WHERE email = ? LIMIT 1");
        $stmt->execute([$leadEmail]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // إيميل موجود → فحص كلمة المرور
            if (!password_verify($leadPassword, $existing['password_hash'])) {
                logWarning('Customer login attempt with wrong password during submit', [
                    'email' => $leadEmail,
                    'ip'    => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);
                ob_end_clean();
                http_response_code(401);
                echo json_encode([
                    'error'           => 'هذا البريد مسجَّل، الرجاء إدخال كلمة المرور الصحيحة',
                    'customer_exists' => true,
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $customerId = (int) $existing['id'];
            startCustomerSession($customerId, [
                'email'     => $leadEmail,
                'full_name' => $leadData['full_name'] ?? null,
            ]);
            logInfo('Existing customer signed in via submit', [
                'customer_id' => $customerId,
                'email'       => $leadEmail,
            ]);
        } else {
            // إنشاء حساب جديد
            $hash = password_hash($leadPassword, PASSWORD_BCRYPT, ['cost' => 12]);
            $ins  = $db->prepare("
                INSERT INTO customers (email, password_hash, full_name, phone, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $ins->execute([
                $leadEmail,
                $hash,
                $leadData['full_name'] ?? null,
                $leadData['phone']     ?? null,
            ]);
            $customerId = (int) $db->lastInsertId();

            startCustomerSession($customerId, [
                'email'     => $leadEmail,
                'full_name' => $leadData['full_name'] ?? null,
            ]);
            logInfo('New customer registered via submit', [
                'customer_id' => $customerId,
                'email'       => $leadEmail,
            ]);
        }
    } catch (\Throwable $e) {
        logError('Customer create/login during submit failed: ' . $e->getMessage());
        // لا نُفشل عملية التحليل إذا فشل ربط الحساب — نتابع كزائر
        $customerId = null;
    }
}

// لا نمرّر password إلى INSERT في leads — هذا ليس عموداً في الجدول
unset($leadData['password']);

// التحقق من وجود رابط منصة واحد على الأقل
$urlFields = ['website_url', 'facebook_url', 'instagram_url', 'tiktok_url', 'twitter_url', 'youtube_url'];
$hasUrl = false;
foreach ($urlFields as $field) {
    if (!empty($leadData[$field])) {
        $hasUrl = true;
        break;
    }
}

if (!$hasUrl) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['error' => 'يجب إدخال رابط واحد على الأقل للمنصات']);
    exit;
}

// تسجيل بدء العملية
logInfo('Analysis submission started', [
    'ip'        => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'lead_name' => $leadData['full_name'] ?? 'unknown',
    'country'   => $leadData['country']   ?? '',
    'city'      => $leadData['city']      ?? '',
]);

try {
    $db = getDB();
} catch (\Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['error' => 'DB Error: ' . $e->getMessage()]);
    exit;
}

// ── DB Migration: مُعالج مركزياً في migrate.php ─────────────
// (يشتغل تلقائياً مرة واحدة عبر init.php)

// ── 1) INSERT lead — فقط الأعمدة الموجودة ───────────────────
$safeLeadFields = [
    'full_name', 'phone', 'email', 'company_name',
    'objective', 'target_audience', 'ad_budget',
    'project_type', 'platform', 'country', 'city',
    'website_url', 'facebook_url', 'instagram_url',
    'tiktok_url', 'twitter_url', 'youtube_url', 'maps_url'
];

$insertCols = [];
$insertVals = [];
foreach ($safeLeadFields as $f) {
    if (array_key_exists($f, $leadData) && $leadData[$f] !== '') {
        $insertCols[] = "`$f`";
        $insertVals[] = (string)$leadData[$f]; // ✅ تم تصحيح: $leadData وليس $Data
    }
}
// source دائماً
$insertCols[] = '`source`';
$insertVals[] = 'growth_fingerprint';

// ربط customer_id إن وُجد
if ($customerId !== null) {
    $insertCols[] = '`customer_id`';
    $insertVals[] = (string) $customerId;
}

$placeholders = implode(',', array_fill(0, count($insertVals), '?'));
$colsList     = implode(',', $insertCols);

try {
    $db->prepare("INSERT INTO leads ($colsList) VALUES ($placeholders)")
       ->execute($insertVals);
    $leadId = (int)$db->lastInsertId();
} catch (\Throwable $e) {
    logError('Failed to insert lead with all fields: ' . $e->getMessage());
    // Fallback: insert بالحقول الأساسية فقط
    if ($customerId !== null) {
        $db->prepare("INSERT INTO leads (full_name, phone, project_type, country, city, customer_id) VALUES (?,?,?,?,?,?)")
           ->execute([
               $leadData['full_name'],
               $leadData['phone'],
               $leadData['project_type'],
               $leadData['country'],
               $leadData['city'],
               $customerId,
           ]);
    } else {
        $db->prepare("INSERT INTO leads (full_name, phone, project_type, country, city) VALUES (?,?,?,?,?)")
           ->execute([
               $leadData['full_name'],
               $leadData['phone'],
               $leadData['project_type'],
               $leadData['country'],
               $leadData['city'],
           ]);
    }
    $leadId = (int)$db->lastInsertId();
}

// ── 2) INSERT assessment ──────────────────────────────────────
$token = bin2hex(random_bytes(16));
try {
    if ($customerId !== null) {
        $db->prepare("INSERT INTO assessments (lead_id, customer_id, status, report_token) VALUES (?,?,?,?)")
           ->execute([$leadId, $customerId, 'submitted', $token]);
    } else {
        $db->prepare("INSERT INTO assessments (lead_id, status, report_token) VALUES (?,?,?)")
           ->execute([$leadId, 'submitted', $token]);
    }
} catch (\Throwable $e) {
    // Fallback لو customer_id غير موجود في الـ schema بعد
    try {
        $db->prepare("INSERT INTO assessments (lead_id, status, report_token) VALUES (?,?,?)")
           ->execute([$leadId, 'submitted', $token]);
    } catch (\Throwable $e2) {
        $db->prepare("INSERT INTO assessments (lead_id, status) VALUES (?,?)")
           ->execute([$leadId, 'submitted']);
    }
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

// ── 4) الرد للـ Frontend لإنهاء الطلب (Non-blocking) ─────────
logInfo('Analysis submission completed successfully', [
    'assessment_id' => $assessmentId,
    'lead_id'       => $leadId,
    'url'           => (
        $leadData['website_url']   ??
        $leadData['facebook_url']  ??
        $leadData['instagram_url'] ??
        $leadData['tiktok_url']    ??
        $leadData['twitter_url']   ?? ''
    )
]);

ob_end_clean();
ob_start();
http_response_code(200);
header('Content-Type: application/json');
echo json_encode([
    'ok'            => true,
    'assessment_id' => $assessmentId,
    'score'         => null,
    'tier'          => null,
    'token'         => $token,
    'customer_id'   => $customerId,
    'authed'        => $customerId !== null,
], JSON_UNESCAPED_UNICODE);

$size = ob_get_length();
header("Content-Length: $size");
header("Connection: close");
ob_end_flush();
if (ob_get_level() > 0) ob_flush();
flush();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
// لا نقوم بتشغيل التحليل هنا لمنع تعليق المتصفح.
// واجهة المستخدم (analyzing.html) ستقوم بطلب api/run.php بشكل منفصل.
