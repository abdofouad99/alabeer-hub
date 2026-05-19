<?php
// ============================================================
// api/customer/auth.php
// نقطة دخول موحَّدة لعمليات مصادقة العملاء
// ─────────────────────────────────────────────────────────────
// الإجراءات (action):
//   GET  ?action=check          → { authed: bool, email?, full_name?, id? }
//   POST ?action=register       → body: {email, password, full_name?, phone?}
//   POST ?action=login          → body: {email, password}
//   POST ?action=logout         → ينهي الجلسة
//   POST ?action=password-check → body: {email}  → { exists: bool }
//
// متطلبات الأمان (مُطبَّقة):
//   - password_hash(BCRYPT, cost=12)
//   - timing-safe password_verify
//   - rate limiting: login 5/email/15min + 10/IP/15min, register 3/IP/hour
//   - validation شامل (email + password >=8 + full_name مطهَّر)
//   - session_regenerate_id(true) عند login/register
//   - CORS بـ whitelist + credentials (لا *)
//   - رد JSON موحَّد {ok, data?, error?}
//   - logging لكل عملية
//   - لا تسريب لـ $e->getMessage() للعميل
// ============================================================

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/middleware.php';

setCustomerCors();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ─── Helpers ───────────────────────────────────────────────
function _readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function _validEmail(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 190;
}

function _sanitizeName(?string $name): ?string
{
    if ($name === null) return null;
    $name = trim($name);
    if ($name === '') return null;
    // إزالة أحرف التحكم و HTML المحتمل
    $name = preg_replace('/[\x00-\x1F\x7F<>]/', '', $name);
    return mb_substr($name, 0, 120);
}

function _sanitizePhone(?string $phone): ?string
{
    if ($phone === null) return null;
    $phone = trim($phone);
    if ($phone === '') return null;
    // فقط أرقام + علامات + - ( ) و فراغات
    $phone = preg_replace('/[^0-9+\-()\s]/', '', $phone);
    return mb_substr($phone, 0, 40);
}

function _logAuthEvent(string $event, array $context = []): void
{
    if (function_exists('logInfo')) {
        logInfo('[customer-auth] ' . $event, $context);
    } else {
        error_log('[customer-auth] ' . $event . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE));
    }
}

function _clientIp(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}

// ─── Rate Limiting Helpers ──────────────────────────────────
/**
 * يفحص حد المعدل لإجراء معيّن. يعتمد على RateLimiter الموجود في النظام.
 * لو الإجراء تجاوز الحد، يُرجع false (الاستدعاء في الكود سيُغلق التنفيذ).
 */
function _checkAuthRateLimit(string $key, int $maxAttempts, int $windowSec): bool
{
    global $db, $config, $logger;
    if (!function_exists('checkRateLimit')) return true; // fallback إذا غير موجود

    try {
        // checkRateLimit يستخدم action key — نضمّن المفتاح في الـ action
        $result = checkRateLimit($db, $config, $logger, 'customer_auth_' . $key, $maxAttempts, $windowSec);
        return (bool) $result;
    } catch (\Throwable $e) {
        error_log('[customer-auth] rate-limit check failed: ' . $e->getMessage());
        return true; // fail-open للأمان البصري — يُسجَّل فقط
    }
}

// ─── Action: check (هل العميل مسجَّل؟) ─────────────────────
if ($action === 'check' && $method === 'GET') {
    $c = getCurrentCustomer();
    if ($c) {
        customerJsonOk([
            'authed'    => true,
            'id'        => (int) $c['id'],
            'email'     => $c['email'],
            'full_name' => $c['full_name'],
            'phone'     => $c['phone'],
        ]);
    } else {
        customerJsonOk(['authed' => false]);
    }
    exit;
}

// ─── Action: password-check (هل الإيميل موجود؟) ─────────────
// مفيد قبل عرض النموذج — لكي نعرف إن كان login أو register
if ($action === 'password-check' && $method === 'POST') {
    $body  = _readJsonBody();
    $email = trim((string) ($body['email'] ?? ''));

    if (!_validEmail($email)) {
        jsonError('بريد إلكتروني غير صالح', 400);
    }

    // rate limit بسيط للحماية من enumeration
    if (!_checkAuthRateLimit('check_' . _clientIp(), 30, 600)) {
        jsonError('تجاوزت عدد الطلبات المسموح، حاول لاحقاً', 429);
    }

    try {
        $stmt = $db->prepare("SELECT id FROM customers WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $exists = (bool) $stmt->fetchColumn();
    } catch (\Throwable $e) {
        error_log('[customer-auth password-check] ' . $e->getMessage());
        jsonError('خطأ في الخادم، حاول لاحقاً', 500);
    }

    customerJsonOk(['exists' => $exists]);
    exit;
}

// ─── Action: register ───────────────────────────────────────
if ($action === 'register' && $method === 'POST') {
    // rate-limit by IP: 3 تسجيلات/ساعة
    if (!_checkAuthRateLimit('register_' . _clientIp(), 3, 3600)) {
        jsonError('تجاوزت عدد محاولات التسجيل، حاول بعد ساعة', 429);
    }

    $body      = _readJsonBody();
    $email     = trim((string) ($body['email']    ?? ''));
    $password  = (string) ($body['password']      ?? '');
    $fullName  = _sanitizeName($body['full_name'] ?? null);
    $phone     = _sanitizePhone($body['phone']    ?? null);

    if (!_validEmail($email)) {
        jsonError('بريد إلكتروني غير صالح', 400);
    }
    if (strlen($password) < 8) {
        jsonError('كلمة المرور يجب أن تكون 8 حروف على الأقل', 400);
    }
    if (strlen($password) > 200) {
        jsonError('كلمة المرور طويلة جداً', 400);
    }

    try {
        // تحقق وجود مسبق
        $stmt = $db->prepare("SELECT id FROM customers WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn()) {
            // إيميل موجود — لا تكشف ذلك بشكل قاطع لتقليل enumeration،
            // لكن في سياق التسجيل المتعمَّد نقول صراحةً ليُوجَّه للـ login.
            jsonError('هذا البريد مسجَّل مسبقاً، يرجى تسجيل الدخول', 409);
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $stmt = $db->prepare("
            INSERT INTO customers (email, password_hash, full_name, phone, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$email, $hash, $fullName, $phone]);
        $newId = (int) $db->lastInsertId();

        startCustomerSession($newId, [
            'email'     => $email,
            'full_name' => $fullName,
        ]);

        _logAuthEvent('register success', ['customer_id' => $newId, 'email' => $email]);

        customerJsonOk([
            'authed'    => true,
            'id'        => $newId,
            'email'     => $email,
            'full_name' => $fullName,
        ], 201);
    } catch (\Throwable $e) {
        error_log('[customer-auth register] ' . $e->getMessage());
        jsonError('تعذّر إنشاء الحساب، يرجى المحاولة لاحقاً', 500);
    }
    exit;
}

// ─── Action: login ──────────────────────────────────────────
if ($action === 'login' && $method === 'POST') {
    $body     = _readJsonBody();
    $email    = trim((string) ($body['email']    ?? ''));
    $password = (string) ($body['password']      ?? '');

    if (!_validEmail($email) || $password === '') {
        jsonError('البريد أو كلمة المرور غير صحيحة', 400);
    }

    // rate-limit مزدوج: per-email + per-IP
    if (!_checkAuthRateLimit('login_email_' . mb_strtolower($email), 5, 900)) {
        jsonError('عدد كبير من محاولات الدخول، حاول بعد 15 دقيقة', 429);
    }
    if (!_checkAuthRateLimit('login_ip_' . _clientIp(), 10, 900)) {
        jsonError('عدد كبير من محاولات الدخول من هذا العنوان', 429);
    }

    try {
        $stmt = $db->prepare("
            SELECT id, email, password_hash, full_name, phone
              FROM customers
             WHERE email = ?
             LIMIT 1
        ");
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // رد موحَّد للوقاية من user enumeration:
        // dummy hash يُجبر password_verify على وقت ثابت تقريباً
        $dummyHash = '$2y$12$dummmydummydummydummydumW9gwrI7m9qJUZEaC6f4zHbnfO/hFKsa';
        $hashToCheck = $row['password_hash'] ?? $dummyHash;
        $valid = password_verify($password, $hashToCheck);

        if (!$row || !$valid) {
            _logAuthEvent('login failed', ['email' => $email, 'ip' => _clientIp()]);
            jsonError('البريد أو كلمة المرور غير صحيحة', 401);
        }

        // re-hash لو كان cost قديماً (سياسة آمنة)
        if (password_needs_rehash($row['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
            try {
                $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $upd = $db->prepare("UPDATE customers SET password_hash = ? WHERE id = ?");
                $upd->execute([$newHash, (int) $row['id']]);
            } catch (\Throwable $e) {
                // لا تُعطّل الـ login بسبب فشل rehash
            }
        }

        startCustomerSession((int) $row['id'], [
            'email'     => $row['email'],
            'full_name' => $row['full_name'],
        ]);

        _logAuthEvent('login success', ['customer_id' => $row['id'], 'email' => $email]);

        customerJsonOk([
            'authed'    => true,
            'id'        => (int) $row['id'],
            'email'     => $row['email'],
            'full_name' => $row['full_name'],
            'phone'     => $row['phone'],
        ]);
    } catch (\Throwable $e) {
        error_log('[customer-auth login] ' . $e->getMessage());
        jsonError('خطأ في الخادم، يرجى المحاولة لاحقاً', 500);
    }
    exit;
}

// ─── Action: logout ─────────────────────────────────────────
if ($action === 'logout' && ($method === 'POST' || $method === 'GET')) {
    $cid = getCurrentCustomerId();
    destroyCustomerSession();
    if ($cid) {
        _logAuthEvent('logout', ['customer_id' => $cid]);
    }
    customerJsonOk(['authed' => false]);
    exit;
}

// ─── Action غير معروف ───────────────────────────────────────
jsonError('Action غير صالح', 400);
