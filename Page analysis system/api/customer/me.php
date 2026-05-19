<?php
// ============================================================
// api/customer/me.php
// إدارة بيانات العميل الحالي
// ─────────────────────────────────────────────────────────────
// GET   → بيانات العميل + إحصائيات (عدد التقارير، آخر تحليل)
// PATCH → تحديث الاسم/الهاتف/كلمة المرور
//         body: { full_name?, phone?, old_password?, new_password? }
//
// متطلبات الأمان:
//   - requireCustomer() — يُلزم الجلسة
//   - تغيير كلمة المرور يتطلب old_password صحيحة (حماية من session-takeover)
//   - sanitize للاسم والهاتف
//   - prepared statements
//   - rate-limit للـ PATCH (10/دقيقة)
// ============================================================

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/middleware.php';

setCustomerCors();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$customer = requireCustomer();
$customerId = (int) $customer['id'];

// ─── Helpers ───────────────────────────────────────────────
function _meReadJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function _meSanitizeName(?string $name): ?string
{
    if ($name === null) return null;
    $name = trim($name);
    if ($name === '') return null;
    $name = preg_replace('/[\x00-\x1F\x7F<>]/', '', $name);
    return mb_substr($name, 0, 120);
}

function _meSanitizePhone(?string $phone): ?string
{
    if ($phone === null) return null;
    $phone = trim($phone);
    if ($phone === '') return null;
    $phone = preg_replace('/[^0-9+\-()\s]/', '', $phone);
    return mb_substr($phone, 0, 40);
}

// ─── GET: بيانات العميل + إحصائيات ──────────────────────────
if ($method === 'GET') {
    try {
        // إحصائيات أساسية
        $stmtStats = $db->prepare("
            SELECT
                COUNT(*)                     AS total_reports,
                MAX(created_at)              AS last_report_at,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_reports,
                SUM(CASE WHEN status IN ('submitted','running') THEN 1 ELSE 0 END) AS pending_reports,
                AVG(score)                   AS avg_score
            FROM assessments
            WHERE customer_id = ?
        ");
        $stmtStats->execute([$customerId]);
        $stats = $stmtStats->fetch(PDO::FETCH_ASSOC) ?: [];

        customerJsonOk([
            'customer' => [
                'id'             => (int) $customer['id'],
                'email'          => $customer['email'],
                'full_name'      => $customer['full_name'],
                'phone'          => $customer['phone'],
                'email_verified' => (bool) ($customer['email_verified'] ?? 0),
                'created_at'     => $customer['created_at']    ?? null,
                'last_login_at'  => $customer['last_login_at'] ?? null,
            ],
            'stats' => [
                'total_reports'     => (int) ($stats['total_reports']     ?? 0),
                'completed_reports' => (int) ($stats['completed_reports'] ?? 0),
                'pending_reports'   => (int) ($stats['pending_reports']   ?? 0),
                'last_report_at'    => $stats['last_report_at'] ?? null,
                'avg_score'         => isset($stats['avg_score']) && $stats['avg_score'] !== null
                                        ? round((float) $stats['avg_score'], 1)
                                        : null,
            ],
        ]);
    } catch (\Throwable $e) {
        error_log('[customer/me GET] ' . $e->getMessage());
        jsonError('تعذّر تحميل بياناتك، حاول لاحقاً', 500);
    }
    exit;
}

// ─── PATCH: تحديث بيانات العميل ─────────────────────────────
if ($method === 'PATCH' || $method === 'POST') {
    // rate-limit: 10 تحديثات/دقيقة لكل عميل
    if (function_exists('checkRateLimit')) {
        try {
            global $config, $logger;
            @checkRateLimit($db, $config, $logger, 'customer_me_patch_' . $customerId, 10, 60);
        } catch (\Throwable $e) { /* fail-open */ }
    }

    $body = _meReadJsonBody();

    $updates = [];
    $params  = [];

    // ── تحديث الاسم ──
    if (array_key_exists('full_name', $body)) {
        $name = _meSanitizeName($body['full_name'] ?? null);
        $updates[] = '`full_name` = ?';
        $params[]  = $name;
    }

    // ── تحديث الهاتف ──
    if (array_key_exists('phone', $body)) {
        $phone = _meSanitizePhone($body['phone'] ?? null);
        $updates[] = '`phone` = ?';
        $params[]  = $phone;
    }

    // ── تحديث كلمة المرور (يتطلب old_password) ──
    $changingPassword = isset($body['new_password']) && $body['new_password'] !== '';
    if ($changingPassword) {
        $oldPassword = (string) ($body['old_password'] ?? '');
        $newPassword = (string) ($body['new_password'] ?? '');

        if ($oldPassword === '') {
            jsonError('يجب إدخال كلمة المرور الحالية لتغييرها', 400);
        }
        if (strlen($newPassword) < 8) {
            jsonError('كلمة المرور الجديدة يجب أن تكون 8 حروف على الأقل', 400);
        }
        if (strlen($newPassword) > 200) {
            jsonError('كلمة المرور طويلة جداً', 400);
        }

        try {
            $stmt = $db->prepare("SELECT password_hash FROM customers WHERE id = ? LIMIT 1");
            $stmt->execute([$customerId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row || !password_verify($oldPassword, $row['password_hash'])) {
                jsonError('كلمة المرور الحالية غير صحيحة', 401);
            }

            $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
            $updates[] = '`password_hash` = ?';
            $params[]  = $newHash;
        } catch (\Throwable $e) {
            error_log('[customer/me password-change] ' . $e->getMessage());
            jsonError('تعذّر تغيير كلمة المرور', 500);
        }
    }

    if (empty($updates)) {
        jsonError('لا توجد بيانات للتحديث', 400);
    }

    try {
        $sql = "UPDATE customers SET " . implode(', ', $updates) . " WHERE id = ?";
        $params[] = $customerId;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        // إعادة قراءة البيانات بعد التحديث
        $stmt = $db->prepare("
            SELECT id, email, full_name, phone, email_verified, last_login_at, created_at
              FROM customers
             WHERE id = ?
             LIMIT 1
        ");
        $stmt->execute([$customerId]);
        $fresh = $stmt->fetch(PDO::FETCH_ASSOC);

        customerJsonOk([
            'customer' => [
                'id'             => (int) $fresh['id'],
                'email'          => $fresh['email'],
                'full_name'      => $fresh['full_name'],
                'phone'          => $fresh['phone'],
                'email_verified' => (bool) ($fresh['email_verified'] ?? 0),
                'created_at'     => $fresh['created_at']    ?? null,
                'last_login_at'  => $fresh['last_login_at'] ?? null,
            ],
            'message'           => 'تم التحديث بنجاح',
            'password_changed'  => $changingPassword,
        ]);
    } catch (\Throwable $e) {
        error_log('[customer/me PATCH] ' . $e->getMessage());
        jsonError('تعذّر تحديث البيانات، حاول لاحقاً', 500);
    }
    exit;
}

jsonError('Method not allowed', 405);
