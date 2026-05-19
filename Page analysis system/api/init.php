<?php
// ============================================================
// api/init.php — تهيئة النظام والمكونات v1.0
// ============================================================

// تضمين الملفات الأساسية
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/rate_limit.php';

// تهيئة المتغيرات العامة
global $config, $logger, $cache, $db;

$config = require __DIR__ . '/config.php';
$db = getDB();
$logger = getLogger($config);
$cache = getCache($config);

// ── Migration: يشتغل مرة واحدة فقط (Lock File) ────────────
require_once __DIR__ . '/migrate.php';

// ── Migration v7.0: نظام حسابات العملاء (Lock File مستقل) ──
// آمن للتشغيل المتكرر — كل migration له lock خاص داخلياً
require_once __DIR__ . '/migrations/v7_customers.php';

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        echo json_encode([
            'error' => 'Server error occurred. يرجى المحاولة لاحقاً.',
            'details' => $error['message'] ?? 'Unknown error',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
});

// دالة للتحقق من Rate Limiting
function checkApiRateLimit(string $action = 'api_request'): bool {
    global $db, $config, $logger;
    return checkRateLimit($db, $config, $logger, $action);
}

// دالة للـ caching السريع
function cacheGet(string $key) {
    global $cache;
    return $cache->get($key);
}

function cacheSet(string $key, $value, int $ttl = null): bool {
    global $cache;
    return $cache->set($key, $value, $ttl);
}

function cacheRemember(string $key, callable $callback, int $ttl = null) {
    global $cache;
    return $cache->remember($key, $callback, $ttl);
}
?>