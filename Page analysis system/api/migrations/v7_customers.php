<?php
// ============================================================
// api/migrations/v7_customers.php
// Migration v7.0 — إضافة نظام حسابات العملاء (Customer Accounts)
// يُضيف:
//   1) جدول customers
//   2) عمود leads.customer_id + index
//   3) عمود assessments.customer_id + index
//
// متطلبات:
//   - يُستدعى من migrate.php (lock-file pattern مستقل)
//   - idempotent: آمن لإعادة التشغيل
//   - لا يكسر بيانات قديمة (يستخدم NULL للأعمدة الجديدة، بلا FK constraints)
// ============================================================

return (function (): bool {
    $lockFile = __DIR__ . '/../../cache/db_migrated_v7_customers.lock';

    if (file_exists($lockFile)) {
        return true; // اكتمل سابقاً
    }

    if (!is_dir(dirname($lockFile))) {
        @mkdir(dirname($lockFile), 0755, true);
    }

    try {
        require_once __DIR__ . '/../db.php';
        $db = getDB();

        // ─── 1) جدول customers ──────────────────────────────────
        $db->exec("
            CREATE TABLE IF NOT EXISTS `customers` (
                `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `email`           VARCHAR(190) NOT NULL,
                `password_hash`   VARCHAR(255) NOT NULL,
                `full_name`       VARCHAR(120) NULL,
                `phone`           VARCHAR(40)  NULL,
                `email_verified`  TINYINT(1)   NOT NULL DEFAULT 0,
                `verify_token`    VARCHAR(64)  NULL,
                `reset_token`     VARCHAR(64)  NULL,
                `reset_expires`   DATETIME     NULL,
                `last_login_at`   DATETIME     NULL,
                `last_login_ip`   VARCHAR(45)  NULL,
                `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_email` (`email`),
                KEY `idx_created` (`created_at`),
                KEY `idx_reset_token` (`reset_token`),
                KEY `idx_verify_token` (`verify_token`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ─── 2) leads.customer_id ───────────────────────────────
        $existLeadCols = $db->query("SHOW COLUMNS FROM `leads`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('customer_id', $existLeadCols, true)) {
            $db->exec("ALTER TABLE `leads` ADD COLUMN `customer_id` INT UNSIGNED NULL AFTER `id`");
        }

        // فهرس
        $existLeadIndexes = array_column(
            $db->query("SHOW INDEX FROM `leads`")->fetchAll(PDO::FETCH_ASSOC),
            'Key_name'
        );
        if (!in_array('idx_customer', $existLeadIndexes, true)) {
            try {
                $db->exec("ALTER TABLE `leads` ADD INDEX `idx_customer` (`customer_id`)");
            } catch (\Throwable $e) {
                // فهرس موجود بصيغة أخرى — تجاهل
            }
        }

        // ─── 3) assessments.customer_id ─────────────────────────
        $existAsmCols = $db->query("SHOW COLUMNS FROM `assessments`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('customer_id', $existAsmCols, true)) {
            $db->exec("ALTER TABLE `assessments` ADD COLUMN `customer_id` INT UNSIGNED NULL AFTER `lead_id`");
        }

        $existAsmIndexes = array_column(
            $db->query("SHOW INDEX FROM `assessments`")->fetchAll(PDO::FETCH_ASSOC),
            'Key_name'
        );
        if (!in_array('idx_customer', $existAsmIndexes, true)) {
            try {
                $db->exec("ALTER TABLE `assessments` ADD INDEX `idx_customer` (`customer_id`)");
            } catch (\Throwable $e) {
                // تجاهل
            }
        }

        // ─── كتابة Lock File ────────────────────────────────────
        @file_put_contents(
            $lockFile,
            'v7_customers — completed at ' . gmdate('c')
        );

        error_log('[migrate v7_customers] Migration completed successfully');
        return true;

    } catch (\Throwable $e) {
        error_log('[migrate v7_customers] Migration FAILED: ' . $e->getMessage());
        return false;
    }
})();
