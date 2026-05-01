<?php
// ============================================================
// api/rate_limit.php — نظام Rate Limiting v1.0
// ============================================================

class RateLimiter {
    private $db;
    private $config;
    private $logger;

    private $initialized = false;

    public function __construct($db, array $config, $logger = null) {
        $this->db = $db;
        $this->config = $config['rate_limit'];
        $this->logger = $logger;
        $this->initializeTable();
    }

    private function initializeTable(): void {
        if ($this->initialized) {
            return;
        }

        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS `rate_limits` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `ip` VARCHAR(45) NOT NULL,
              `action` VARCHAR(100) NOT NULL DEFAULT 'api_request',
              `user_agent` TEXT,
              PRIMARY KEY (`id`),
              KEY `idx_ip_action` (`ip`, `action`),
              KEY `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
            $this->initialized = true;
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Failed to initialize rate_limits table: ' . $e->getMessage());
            }
            $this->initialized = false;
        }
    }

    public function check(string $identifier, string $action = 'api_request'): bool {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // تنظيف البيانات القديمة
        $this->cleanup();

        // التحقق من الحدود
        $limits = [
            'minute' => ['max' => $this->config['max_per_minute'], 'window' => 60],
            'hour' => ['max' => $this->config['max_per_hour'], 'window' => 3600],
            'day' => ['max' => $this->config['max_per_day'], 'window' => 86400],
        ];

        foreach ($limits as $period => $limit) {
            $count = $this->getRequestCount($ip, $action, $limit['window']);
            if ($count >= $limit['max']) {
                if ($this->logger) {
                    $this->logger->warning("Rate limit exceeded for IP: $ip, Action: $action, Period: $period", [
                        'count' => $count,
                        'limit' => $limit['max'],
                        'ip' => $ip,
                        'user_agent' => $userAgent
                    ]);
                }
                return false;
            }
        }

        // تسجيل الطلب
        $this->recordRequest($ip, $action);

        return true;
    }

    public function getRemainingRequests(string $identifier, string $action = 'api_request'): array {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        $limits = [
            'minute' => ['max' => $this->config['max_per_minute'], 'window' => 60],
            'hour' => ['max' => $this->config['max_per_hour'], 'window' => 3600],
            'day' => ['max' => $this->config['max_per_day'], 'window' => 86400],
        ];

        $remaining = [];
        foreach ($limits as $period => $limit) {
            $count = $this->getRequestCount($ip, $action, $limit['window']);
            $remaining[$period] = max(0, $limit['max'] - $count);
        }

        return $remaining;
    }

    private function getRequestCount(string $ip, string $action, int $windowSeconds): int {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count
                FROM rate_limits
                WHERE ip = ? AND action = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
            ");
            $stmt->execute([$ip, $action, $windowSeconds]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($result['count'] ?? 0);
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Failed to get request count: " . $e->getMessage());
            }
            return 0;
        }
    }

    private function recordRequest(string $ip, string $action): void {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO rate_limits (ip, action, user_agent, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([
                $ip,
                $action,
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Failed to record request: " . $e->getMessage());
            }
        }
    }

    private function cleanup(): void {
        try {
            // حذف السجلات القديمة (أقدم من يوم)
            $this->db->exec("DELETE FROM rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Failed to cleanup rate limits: " . $e->getMessage());
            }
        }
    }

    public function getStats(string $ip = null): array {
        try {
            $where = $ip ? "WHERE ip = ?" : "";
            $params = $ip ? [$ip] : [];

            $stmt = $this->db->prepare("
                SELECT
                    ip,
                    action,
                    COUNT(*) as total_requests,
                    COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN 1 END) as last_hour,
                    COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 END) as last_day,
                    MAX(created_at) as last_request
                FROM rate_limits
                $where
                GROUP BY ip, action
                ORDER BY last_request DESC
                LIMIT 100
            ");
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Failed to get rate limit stats: " . $e->getMessage());
            }
            return [];
        }
    }
}

// Helper function
function getRateLimiter($db, array $config, $logger = null): RateLimiter {
    static $rateLimiter = null;
    if ($rateLimiter === null) {
        $rateLimiter = new RateLimiter($db, $config, $logger);
    }
    return $rateLimiter;
}

// Middleware function للـ API endpoints
function checkRateLimit($db, array $config, $logger = null, string $action = 'api_request'): bool {
    $rateLimiter = getRateLimiter($db, $config, $logger);
    return $rateLimiter->check($_SERVER['REMOTE_ADDR'] ?? 'unknown', $action);
}

function getRateLimitHeaders($db, array $config, $logger = null, string $action = 'api_request'): array {
    $rateLimiter = getRateLimiter($db, $config, $logger);
    $remaining = $rateLimiter->getRemainingRequests($_SERVER['REMOTE_ADDR'] ?? 'unknown', $action);

    return [
        'X-RateLimit-Remaining-Minute' => $remaining['minute'],
        'X-RateLimit-Remaining-Hour' => $remaining['hour'],
        'X-RateLimit-Remaining-Day' => $remaining['day'],
        'X-RateLimit-Limit-Minute' => $config['rate_limit']['max_per_minute'],
        'X-RateLimit-Limit-Hour' => $config['rate_limit']['max_per_hour'],
        'X-RateLimit-Limit-Day' => $config['rate_limit']['max_per_day'],
    ];
}
?>