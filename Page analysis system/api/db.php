<?php
// ============================================================
// api/db.php — PDO Connection (Singleton) - Compatible with Local by Flywheel
// ============================================================
function getDB(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $cfg = require __DIR__ . '/config.php';
    $d   = $cfg['db'];

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
        // 2. Standard localhost
        "mysql:host=localhost;dbname={$d['name']};charset={$d['charset']}",
        // 3. TCP with port
        "mysql:host=127.0.0.1;port={$port};dbname={$d['name']};charset={$d['charset']}",
        // 4. Default port
        "mysql:host=127.0.0.1;port=3306;dbname={$d['name']};charset={$d['charset']}",
    ];

    $lastError = '';
    foreach ($strategies as $dsn) {
        try {
            $pdo = new PDO($dsn, $d['user'], $d['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]);
            return $pdo; // Connected successfully
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
    global $cfg;
    $allowed = $cfg['app']['cors']['allowed_origins'] ?? [];
    $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin && in_array($origin, $allowed, true)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: true');
    }
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-Requested-With');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
