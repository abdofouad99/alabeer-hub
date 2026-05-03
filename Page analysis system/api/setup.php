<?php
// ============================================================
// api/setup.php — إنشاء أول حساب أدمن (one-time bootstrap)
//
// 🚦 السلوك:
//   - لو جدول admin_users يحوي أي صف   → 403 (نُغلق الـ endpoint نهائياً).
//   - GET  /api/setup.php?token=XXX     → نموذج HTML بسيط لإدخال البيانات.
//   - POST /api/setup.php?token=XXX     → ينشئ الحساب ويعيد توجيه إلى login.
//
// 🔐 الـ token:
//   عند أول تشغيل، setup.php يولّد ملف cache/setup_token.txt يحوي توكن عشوائي.
//   لا يمكن استخدام setup.php بدونه (يمنع أي زائر من إنشاء أدمن).
//   المسؤول يقرأ التوكن من الـ shell ثم يستخدمه في الـ URL.
//
// 🧹 بعد إنشاء أول حساب: ملف الـ token يُحذف ويُنشأ ملف cache/setup_done.lock
//   فيُعطّل setup.php كلياً.
// ============================================================

require_once __DIR__ . '/db.php';

header('Content-Type: text/html; charset=utf-8');

$cacheDir = __DIR__ . '/../cache';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}
$tokenFile = $cacheDir . '/setup_token.txt';
$lockFile  = $cacheDir . '/setup_done.lock';

// ── 0) إذا كان الـ setup قد اكتمل سابقاً، نُغلق الـ endpoint ─
if (is_file($lockFile)) {
    http_response_code(403);
    echo '<!DOCTYPE html><meta charset="utf-8"><h1>Setup already completed</h1>';
    echo '<p>تمّ إنشاء أول حساب أدمن سابقاً. لإنشاء حساب آخر استخدم لوحة التحكم.</p>';
    exit;
}

// ── 1) تأكد من جاهزية الجدول ─────────────────────────────────
try {
    $db = getDB();
    $db->query("SELECT 1 FROM admin_users LIMIT 1");
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>قاعدة البيانات غير جاهزة</h1>';
    echo '<p>شغّل <code>api/migrate.php</code> أو استورد <code>database/schema_mysql.sql</code> أولاً.</p>';
    error_log('[setup] DB not ready: ' . $e->getMessage());
    exit;
}

// ── 2) إذا الجدول يحوي حسابات بالفعل، نقفل ───────────────────
$stmt = $db->query("SELECT COUNT(*) FROM admin_users");
$existing = (int) $stmt->fetchColumn();
if ($existing > 0) {
    @file_put_contents($lockFile, gmdate('c'));
    @unlink($tokenFile);
    http_response_code(403);
    echo '<h1>Setup already completed</h1>';
    echo '<p>يوجد حساب أدمن موجود مسبقاً (' . (int)$existing . '). استخدم لوحة التحكم لإدارة الحسابات.</p>';
    exit;
}

// ── 3) إنشاء token لو غير موجود + عرضه في الـ error_log ─────
if (!is_file($tokenFile)) {
    $newToken = bin2hex(random_bytes(24));
    @file_put_contents($tokenFile, $newToken, LOCK_EX);
    @chmod($tokenFile, 0600);

    error_log('[setup] Generated one-time setup token. Read it from cache/setup_token.txt and pass as ?token=...');

    // لا نطبع التوكن في المتصفح — فقط نطلب من المستخدم قراءته من الملف
    http_response_code(401);
    echo '<!DOCTYPE html><meta charset="utf-8"><div style="font-family:Cairo,Tahoma,sans-serif;direction:rtl;max-width:640px;margin:40px auto;padding:24px;border:1px solid #ddd;border-radius:8px;">';
    echo '<h1>إعداد أول حساب أدمن</h1>';
    echo '<p>تمّ إنشاء توكن إعداد لمرة واحدة. اقرأه من السيرفر:</p>';
    echo '<pre style="background:#f6f8fa;padding:12px;border-radius:6px;direction:ltr;text-align:left;">cat cache/setup_token.txt</pre>';
    echo '<p>ثم افتح:</p>';
    echo '<pre style="background:#f6f8fa;padding:12px;border-radius:6px;direction:ltr;text-align:left;">/api/setup.php?token=&lt;المحتوى&gt;</pre>';
    echo '<p style="color:#888;font-size:13px;">السبب: نضمن أن من يُنشئ أول أدمن يملك وصولاً للـ shell، ليس مجرد زائر للموقع.</p>';
    echo '</div>';
    exit;
}

// ── 4) التحقق من token في الـ query ──────────────────────────
$expected = trim((string) @file_get_contents($tokenFile));
$got      = trim((string) ($_GET['token'] ?? ''));
if ($expected === '' || $got === '' || !hash_equals($expected, $got)) {
    http_response_code(401);
    echo '<!DOCTYPE html><meta charset="utf-8"><div style="font-family:Cairo,Tahoma,sans-serif;direction:rtl;max-width:640px;margin:40px auto;padding:24px;border:1px solid #f5c2c7;background:#f8d7da;border-radius:8px;color:#842029;">';
    echo '<h1>توكن غير صالح</h1>';
    echo '<p>اقرأ التوكن من <code style="direction:ltr;">cache/setup_token.txt</code> ومرّره في الـ URL كـ <code>?token=...</code></p>';
    echo '</div>';
    exit;
}

// ── 5) عرض/معالجة النموذج ───────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    $email    = trim((string)($_POST['email']    ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm']  ?? '');

    $errors = [];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'البريد الإلكتروني غير صالح.';
    }
    if (strlen($password) < 12) {
        $errors[] = 'كلمة المرور يجب أن تكون 12 حرفاً على الأقل.';
    }
    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) ||
        !preg_match('/\d/', $password)    || !preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'كلمة المرور يجب أن تحتوي على أحرف كبيرة + صغيرة + رقم + رمز.';
    }
    if ($password !== $confirm) {
        $errors[] = 'كلمتا المرور غير متطابقتين.';
    }

    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $db->prepare("INSERT INTO admin_users (email, password_hash) VALUES (?, ?)");
        $stmt->execute([$email, $hash]);

        // إنهاء عملية الـ setup
        @unlink($tokenFile);
        @file_put_contents($lockFile, gmdate('c'));

        echo '<!DOCTYPE html><meta charset="utf-8"><div style="font-family:Cairo,Tahoma,sans-serif;direction:rtl;max-width:640px;margin:40px auto;padding:24px;background:#d1e7dd;color:#0f5132;border-radius:8px;">';
        echo '<h1>تمّ إنشاء الحساب بنجاح</h1>';
        echo '<p>سيتم تحويلك إلى صفحة تسجيل الدخول خلال 3 ثوانٍ...</p>';
        echo '<meta http-equiv="refresh" content="3;url=../admin/login.html">';
        echo '<p><a href="../admin/login.html">اذهب الآن →</a></p>';
        echo '</div>';
        exit;
    }
} else {
    $errors = [];
    $email = '';
}

// ── 6) عرض النموذج ──────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>إعداد أول حساب أدمن</title>
<style>
  body { font-family: Cairo, Tahoma, sans-serif; background:#f6f8fa; margin:0; padding:40px; }
  .card { max-width:520px; margin:0 auto; background:#fff; border:1px solid #d0d7de; border-radius:12px; padding:32px; }
  h1 { margin-top:0; }
  label { display:block; margin-top:14px; font-weight:600; }
  input { width:100%; padding:10px 12px; margin-top:6px; border:1px solid #d0d7de; border-radius:6px; font-size:15px; box-sizing:border-box; }
  button { margin-top:20px; width:100%; padding:12px; background:#1f883d; color:#fff; border:0; border-radius:6px; font-size:16px; font-weight:700; cursor:pointer; }
  .err { background:#f8d7da; color:#842029; border:1px solid #f5c2c7; padding:10px 14px; border-radius:6px; margin-top:12px; }
  .hint { color:#666; font-size:13px; margin-top:6px; }
</style>
</head>
<body>
<div class="card">
  <h1>إعداد أول حساب أدمن</h1>
  <p class="hint">سيُنشأ هذا الحساب مرة واحدة فقط. بعد ذلك يُغلق هذا الـ endpoint كلياً.</p>

  <?php if (!empty($errors)): ?>
    <?php foreach ($errors as $err): ?>
      <div class="err"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endforeach; ?>
  <?php endif; ?>

  <form method="POST" action="?token=<?= htmlspecialchars($got, ENT_QUOTES, 'UTF-8') ?>">
    <label>البريد الإلكتروني</label>
    <input type="email" name="email" required value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>">

    <label>كلمة المرور (12 حرف على الأقل، أحرف + أرقام + رمز)</label>
    <input type="password" name="password" required minlength="12">

    <label>تأكيد كلمة المرور</label>
    <input type="password" name="confirm" required minlength="12">

    <button type="submit">إنشاء الحساب</button>
  </form>
</div>
</body>
</html>
