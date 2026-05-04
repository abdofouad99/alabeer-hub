<?php
// ============================================================
// api/db.php — PDO Connection (Singleton) - Compatible with Local by Flywheel
// ============================================================
function getDB(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $cfg = require __DIR__ . '/config.php';
    $d   = $cfg['db'];

    // Cache the last working DSN in APCu (or session-less file) to skip the retry
    // loop on subsequent requests. Reduces status.php latency from ~20s to <50ms
    // when the first strategies fail (e.g. on Local by Flywheel custom ports).
    $cacheFile = sys_get_temp_dir() . '/.alabeer_db_dsn_' . md5($d['host'] . '|' . $d['name']);
    $cachedDsn = (is_readable($cacheFile) && (time() - filemtime($cacheFile) < 3600))
        ? @file_get_contents($cacheFile)
        : null;

    // Parse host and port from config (e.g. "localhost:10005")
    $hostFull = $d['host'];            // "localhost:10005"
    $port     = '3306';

    if (strpos($hostFull, ':') !== false) {
        [$hostPart, $port] = explode(':', $hostFull, 2);
    } else {
        $hostPart = $hostFull;
    }

    // Try multiple connection strategies — Local by Flywheel on Windows
    // uses Named Pipe with "localhost", NOT TCP with "127.0.0.1"
    $strategies = [
        // 1. Exact same way WordPress wp-config.php connects (recommended)
        "mysql:host={$hostPart};port={$port};dbname={$d['name']};charset={$d['charset']}",
        // 2. TCP with explicit port (skip "localhost" without port — named-pipe
        //    risk on Windows, hangs for full timeout when MySQL doesn't expose it)
        "mysql:host=127.0.0.1;port={$port};dbname={$d['name']};charset={$d['charset']}",
        // 3. Default port fallback
        "mysql:host=127.0.0.1;port=3306;dbname={$d['name']};charset={$d['charset']}",
        // 4. Last resort — named pipe / Unix socket via "localhost" (slow, may hang)
        "mysql:host=localhost;dbname={$d['name']};charset={$d['charset']}",
    ];

    // Try cached DSN first if available (fast path)
    if ($cachedDsn && in_array($cachedDsn, $strategies, true)) {
        array_unshift($strategies, $cachedDsn);
        $strategies = array_values(array_unique($strategies));
    }

    $pdoOptions = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        // 2s connect timeout — localhost should respond in <100ms.
        // If it can't connect within 2s the host/port is wrong — fail fast and
        // try the next strategy.
        PDO::ATTR_TIMEOUT            => 2,
        // Persistent connections reuse the TCP socket across PHP requests,
        // dramatically cutting status.php overhead during analysis polling.
        PDO::ATTR_PERSISTENT         => true,
    ];

    $lastError = '';
    foreach ($strategies as $dsn) {
        try {
            $pdo = new PDO($dsn, $d['user'], $d['pass'], $pdoOptions);
            // Cache the working DSN for next request (best-effort, ignore errors)
            @file_put_contents($cacheFile, $dsn, LOCK_EX);
            return $pdo;
        } catch (PDOException $e) {
            $lastError = $e->getMessage();
            $pdo = null;
            continue;
        }
    }

    // All strategies failed:
    //  - log التفاصيل الكاملة في error_log (يقرأها admin server-side فقط)
    //  - أرجِع للعميل رسالة عامة (بدون كشف user/db/host)
    error_log('[DB] Connection failed after all strategies: ' . $lastError);

    $isDebug = ($cfg['app']['debug'] ?? false) === true;
    $clientMsg = $isDebug
        ? ('Database connection failed (debug): ' . $lastError)
        : 'Database temporarily unavailable. Please try again in a moment.';

    jsonError($clientMsg, 503);
}


// ── JSON helpers ────────────────────────────────────────────
function jsonOut($data, int $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonError(string $msg, int $code = 400) {
    jsonOut(['error' => $msg], $code);
}

// ── CORS ────────────────────────────────────────────────────
function setCors(): void {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
}
