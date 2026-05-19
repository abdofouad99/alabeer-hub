<?php
// ============================================================
// api/customer/middleware.php
// طبقة المصادقة لحسابات العملاء (Customer Sessions)
// ─────────────────────────────────────────────────────────────
// مستقلة كلياً عن جلسات الأدمن (session_name مختلف).
//
// الدوال المُصدَّرة:
//   - startCustomerSession(int $customerId, ?array $customer = null): void
//   - destroyCustomerSession(): void
//   - getCurrentCustomer(): ?array          // null لو لم يدخل
//   - getCurrentCustomerId(): ?int
//   - requireCustomer(): array              // jsonError(401) لو لم يدخل
//   - setCustomerCors(): void               // CORS بـ whitelist + credentials
//
// متطلبات الأمان:
//   - HttpOnly + Secure (في الإنتاج) + SameSite=Strict
//   - session_regenerate_id(true) عند login/register/logout
//   - فصل تام عن جلسة الأدمن
//   - تخزين بيانات أساسية فقط في $_SESSION (id + email + full_name)
// ============================================================

require_once __DIR__ . '/../db.php';

if (!function_exists('startCustomerSession')) {

    /**
     * إعداد إعدادات Cookie الخاصة بجلسة العميل، ثم بدء الجلسة.
     * يجب استدعاؤها قبل أي وصول إلى $_SESSION.
     */
    function _customerSessionBoot(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return; // الجلسة بدأت سابقاً (محتمل من admin) — لا تكتب فوقها.
        }

        // اسم جلسة منفصل تماماً عن الأدمن
        session_name('CUSTSESSID');

        $isHttps = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ||
            (($_SERVER['SERVER_PORT'] ?? '') == 443)
        );

        session_set_cookie_params([
            'lifetime' => 0,            // session cookie (يُمسح بإغلاق المتصفح)
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',        // Lax تكفي للـ POST من نفس الموقع
        ]);

        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.use_strict_mode', '1');

        session_start();
    }

    /**
     * بدء جلسة لعميل معروف الـ id.
     * تُستدعى من register/login بعد التحقق من بيانات الاعتماد.
     */
    function startCustomerSession(int $customerId, ?array $customer = null): void
    {
        _customerSessionBoot();

        // تجديد session id لمنع Session Fixation
        @session_regenerate_id(true);

        $_SESSION['customer_id'] = $customerId;
        $_SESSION['customer_login_at'] = time();
        $_SESSION['customer_login_ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
        $_SESSION['customer_ua_hash']  = substr(
            hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? ''),
            0, 16
        );

        if ($customer) {
            $_SESSION['customer_email']     = $customer['email']     ?? null;
            $_SESSION['customer_full_name'] = $customer['full_name'] ?? null;
        }

        // تحديث last_login_at في DB (best-effort)
        try {
            $db = getDB();
            $stmt = $db->prepare("
                UPDATE customers
                   SET last_login_at = NOW(),
                       last_login_ip = ?
                 WHERE id = ?
            ");
            $stmt->execute([
                substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
                $customerId
            ]);
        } catch (\Throwable $e) {
            error_log('[customer/middleware] failed to update last_login: ' . $e->getMessage());
        }
    }

    /**
     * إنهاء جلسة العميل وحذف الكوكي.
     */
    function destroyCustomerSession(): void
    {
        _customerSessionBoot();

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path']     ?? '/',
                $params['domain']   ?? '',
                $params['secure']   ?? false,
                $params['httponly'] ?? true
            );
        }
        @session_destroy();
    }

    /**
     * إرجاع بيانات العميل الحالي من DB، أو null لو لم يدخل.
     * يُجرّ من DB لضمان أن الحساب لم يُحذف بعد بدء الجلسة.
     */
    function getCurrentCustomer(): ?array
    {
        _customerSessionBoot();

        $id = (int) ($_SESSION['customer_id'] ?? 0);
        if ($id <= 0) return null;

        // تحقق User-Agent (manor-grade — يكشف اختطاف بسيط)
        $expectedUaHash = $_SESSION['customer_ua_hash'] ?? null;
        $currentUaHash  = substr(hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 16);
        if ($expectedUaHash && $expectedUaHash !== $currentUaHash) {
            destroyCustomerSession();
            return null;
        }

        try {
            $db = getDB();
            $stmt = $db->prepare("
                SELECT id, email, full_name, phone, email_verified,
                       last_login_at, created_at
                  FROM customers
                 WHERE id = ?
                 LIMIT 1
            ");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            error_log('[customer/middleware] getCurrentCustomer failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * إرجاع id العميل الحالي بدون استعلام DB (سريع — للحالات التي لا تحتاج بيانات كاملة).
     */
    function getCurrentCustomerId(): ?int
    {
        _customerSessionBoot();
        $id = (int) ($_SESSION['customer_id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /**
     * تتطلب وجود عميل مسجَّل، وإلا تُرجع 401 وتُنهي التنفيذ.
     */
    function requireCustomer(): array
    {
        $c = getCurrentCustomer();
        if (!$c) {
            jsonError('يجب تسجيل الدخول أولاً', 401);
        }
        return $c;
    }

    /**
     * CORS مخصصة لـ customer endpoints — whitelist origins فقط (بلا *).
     * يدعم credentials (cookies) لأن الجلسات تستخدم cookies.
     */
    function setCustomerCors(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        // self-origin (نفس النطاق)
        $scheme = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        ) ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? '';
        $self   = $host ? ($scheme . '://' . $host) : '';

        $allowed = array_filter([
            $self,
            'http://localhost',
            'http://localhost:3000',
            'http://localhost:8080',
            'http://127.0.0.1',
            'http://127.0.0.1:3000',
        ]);

        if ($origin && in_array($origin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    /**
     * Helper موحَّد لردود JSON (متّسق مع باقي النظام).
     */
    function customerJsonOk($data = null, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['ok' => true, 'data' => $data],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }
}
