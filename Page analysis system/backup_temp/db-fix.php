<?php
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/init.php';

try {
    $db = getDB();
    echo "Connected successfully to database.\n";

    // 1. Create leads table if not exists
    $db->exec("CREATE TABLE IF NOT EXISTS `leads` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `full_name` VARCHAR(150),
        `phone` VARCHAR(30),
        `email` VARCHAR(120),
        `company_name` VARCHAR(150),
        `objective` VARCHAR(100),
        `target_audience` VARCHAR(150),
        `ad_budget` VARCHAR(60),
        `project_type` VARCHAR(60),
        `platform` VARCHAR(40),
        `country` VARCHAR(60),
        `website_url` VARCHAR(500),
        `facebook_url` VARCHAR(500),
        `instagram_url` VARCHAR(500),
        `tiktok_url` VARCHAR(500),
        `twitter_url` VARCHAR(500),
        `youtube_url` VARCHAR(500),
        `source` VARCHAR(60) DEFAULT 'growth_fingerprint',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "Leads table checked.\n";

    // 2. Create assessments table if not exists
    $db->exec("CREATE TABLE IF NOT EXISTS `assessments` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `lead_id` INT,
        `status` VARCHAR(30) DEFAULT 'pending',
        `score` INT NULL,
        `tier` ENUM('red', 'yellow', 'green') NULL,
        `breakdown` JSON NULL,
        `summary` TEXT NULL,
        `recommendations` JSON NULL,
        `strengths` JSON NULL,
        `weaknesses` JSON NULL,
        `next_steps` JSON NULL,
        `scan_result` JSON NULL,
        `scan_status` VARCHAR(20) NULL,
        `scan_error` TEXT NULL,
        `scan_step` TINYINT(1) DEFAULT 0,
        `report_token` VARCHAR(64) NULL,
        `ai_report` JSON NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`lead_id`) REFERENCES `leads`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "Assessments table checked.\n";

    // 3. Create rate_limits table if not exists
    $db->exec("CREATE TABLE IF NOT EXISTS `rate_limits` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `ip` VARCHAR(45) NOT NULL,
        `action` VARCHAR(100) NOT NULL DEFAULT 'api_request',
        `user_agent` TEXT,
        PRIMARY KEY (`id`),
        KEY `idx_ip_action` (`ip`, `action`),
        KEY `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "Rate_limits table checked.\n";

    // 4. Check for missing columns in assessments (some were added later)
    $asmCols = $db->query("SHOW COLUMNS FROM assessments")->fetchAll(PDO::FETCH_COLUMN);
    $wanted = [
        'scan_step' => 'TINYINT(1) DEFAULT 0',
        'report_token' => 'VARCHAR(64)',
        'ai_report' => 'JSON',
        'status' => 'VARCHAR(30) DEFAULT "pending"'
    ];
    foreach ($wanted as $col => $def) {
        if (!in_array($col, $asmCols)) {
            $db->exec("ALTER TABLE assessments ADD COLUMN `$col` $def");
            echo "Added column $col to assessments.\n";
        }
    }

    echo "Database maintenance completed.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
