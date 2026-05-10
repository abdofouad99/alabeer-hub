<?php
// ============================================================
// api/logger.php — نظام Logging شامل v1.0
// ============================================================

class Logger {
    private $config;
    private $logFile;
    private $maxFileSize;
    private $maxFiles;

    public function __construct(array $config) {
        $this->config = $config['logging'];
        $this->logFile = $this->config['file_path'];
        $this->maxFileSize = $this->config['max_file_size'] ?? 10 * 1024 * 1024;
        $this->maxFiles = $this->config['max_files'] ?? 5;

        // التأكد من وجود المجلد
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }

    public function log(string $level, string $message, array $context = []): void {
        if (!$this->config['enabled']) return;

        $levels = ['DEBUG', 'INFO', 'WARNING', 'ERROR'];
        if (!in_array(strtoupper($level), $levels)) {
            $level = 'INFO';
        }

        // التحقق من مستوى الـ logging
        $currentLevel = array_search(strtoupper($this->config['level']), $levels);
        $messageLevel = array_search(strtoupper($level), $levels);
        if ($messageLevel < $currentLevel) return;

        $timestamp = date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

        $contextStr = empty($context) ? '' : ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        $logEntry = "[$timestamp] [$level] [$ip] $message$contextStr" . PHP_EOL;

        // تدوير الملفات إذا لزم الأمر
        $this->rotateLogFile();

        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    public function debug(string $message, array $context = []): void {
        $this->log('DEBUG', $message, $context);
    }

    public function info(string $message, array $context = []): void {
        $this->log('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void {
        $this->log('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void {
        $this->log('ERROR', $message, $context);
    }

    private function rotateLogFile(): void {
        if (!file_exists($this->logFile)) return;

        $fileSize = filesize($this->logFile);
        if ($fileSize < $this->maxFileSize) return;

        // تدوير الملفات
        for ($i = $this->maxFiles - 1; $i >= 1; $i--) {
            $oldFile = $this->logFile . '.' . $i;
            $newFile = $this->logFile . '.' . ($i + 1);
            if (file_exists($oldFile)) {
                rename($oldFile, $newFile);
            }
        }

        // نقل الملف الحالي
        rename($this->logFile, $this->logFile . '.1');
    }

    public function getRecentLogs(int $lines = 100): array {
        if (!file_exists($this->logFile)) return [];

        $logs = [];
        $file = new SplFileObject($this->logFile, 'r');
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key();

        $startLine = max(0, $totalLines - $lines);
        $file->seek($startLine);

        while (!$file->eof()) {
            $line = trim($file->current());
            if (!empty($line)) {
                $logs[] = $line;
            }
            $file->next();
        }

        return array_reverse($logs);
    }
}

// Helper function للحصول على instance
function getLogger(array $config): Logger {
    static $logger = null;
    if ($logger === null) {
        $logger = new Logger($config);
    }
    return $logger;
}

// ── Helper functions للسهولة ───
if (!function_exists('logInfo')) {
    function logInfo(string $message, array $context = []): void {
        static $cfg = null;
        if ($cfg === null) {
            $cfgFile = __DIR__ . '/config.php';
            $cfg = file_exists($cfgFile) ? require $cfgFile : ['logging'=>['enabled'=>true,'level'=>'INFO','file_path'=>__DIR__.'/../logs/app.log']];
        }
        try {
            getLogger($cfg)->info($message, $context);
        } catch (\Throwable $e) {
            error_log("[INFO] $message");
        }
    }
}

if (!function_exists('logError')) {
    function logError(string $message, array $context = []): void {
        static $cfg = null;
        if ($cfg === null) {
            $cfgFile = __DIR__ . '/config.php';
            $cfg = file_exists($cfgFile) ? require $cfgFile : ['logging'=>['enabled'=>true,'level'=>'ERROR','file_path'=>__DIR__.'/../logs/app.log']];
        }
        try {
            getLogger($cfg)->error($message, $context);
        } catch (\Throwable $e) {
            error_log("[ERROR] $message");
        }
    }
}

if (!function_exists('logWarning')) {
    function logWarning(string $message, array $context = []): void {
        static $cfg = null;
        if ($cfg === null) {
            $cfgFile = __DIR__ . '/config.php';
            $cfg = file_exists($cfgFile) ? require $cfgFile : ['logging'=>['enabled'=>true,'level'=>'WARNING','file_path'=>__DIR__.'/../logs/app.log']];
        }
        try {
            getLogger($cfg)->warning($message, $context);
        } catch (\Throwable $e) {
            error_log("[WARNING] $message");
        }
    }
}

if (!function_exists('logDebug')) {
    function logDebug(string $message, array $context = []): void {
        static $cfg = null;
        if ($cfg === null) {
            $cfgFile = __DIR__ . '/config.php';
            $cfg = file_exists($cfgFile) ? require $cfgFile : ['logging'=>['enabled'=>true,'level'=>'DEBUG','file_path'=>__DIR__.'/../logs/app.log']];
        }
        try {
            getLogger($cfg)->debug($message, $context);
        } catch (\Throwable $e) {
            error_log("[DEBUG] $message");
        }
    }
}
?>