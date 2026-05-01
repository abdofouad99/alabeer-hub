<?php
// ============================================================
// api/db-migrate.php — الترحيل الآلي لقاعدة البيانات (Auto-Migration)
// ============================================================
require_once __DIR__ . '/db.php';

function runMigrations() {
    $db = getDB();

    // 1. جدول المستخدمين (users)
    $db->exec("CREATE TABLE IF NOT EXISTS `users` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `full_name` VARCHAR(150) NOT NULL,
        `email` VARCHAR(120) NOT NULL UNIQUE,
        `phone` VARCHAR(30) NULL,
        `password_hash` VARCHAR(255) NOT NULL,
        `role` ENUM('user', 'admin') DEFAULT 'user',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // 2. جدول الباقات (packages)
    $db->exec("CREATE TABLE IF NOT EXISTS `packages` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(100) NOT NULL,
        `slug` VARCHAR(50) NOT NULL UNIQUE,
        `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `features` JSON NULL,
        `is_active` TINYINT(1) DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // إدخال الباقات الافتراضية إذا لم تكن موجودة
    $stmt = $db->query("SELECT COUNT(*) FROM `packages`");
    if ($stmt->fetchColumn() == 0) {
        $db->exec("INSERT INTO `packages` (`name`, `slug`, `price`, `features`) VALUES
            ('الباقة المجانية', 'free', 0.00, '[\"تحليل سريع\", \"الدرجة العامة\", \"المشكلة الرئيسية\", \"نقطة قوة واحدة\", \"توصية عامة واحدة\"]'),
            ('الباقة الاحترافية', 'pro', 10.00, '[\"تقرير كامل PDF\", \"تحليل شامل لكل المحاور\", \"نقاط القوة والضعف\", \"رحلة العميل\", \"توصيات فورية\", \"خطة تحسين أولية\"]'),
            ('الباقة الشاملة', 'comprehensive', 50.00, '[\"كل ما في باقة احترافية\", \"استشارة خاصة (60 دقيقة)\", \"خطة نمو 30 يوم\", \"خطة إعلانات مقترحة\", \"ترتيب الأولويات\", \"متابعة عبر الإيميل\"]'),
            ('باقة VIP', 'vip', 0.00, '[\"كل ما في باقة شاملة\", \"إدارة وتنفيذ كامل للخطة\", \"إدارة الحساب والمحتوى\", \"إدارة الإعلانات الممولة\", \"تقارير دورية ومتابعة\", \"دعم مخصص 24/7\"]')
        ");
    }

    // 3. جدول الطلبات/المدفوعات (orders)
    $db->exec("CREATE TABLE IF NOT EXISTS `orders` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NULL,
        `assessment_id` INT NOT NULL,
        `package_id` INT NOT NULL,
        `amount` DECIMAL(10,2) NOT NULL,
        `status` ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'pending',
        `payment_method` VARCHAR(50) NULL,
        `transaction_id` VARCHAR(100) NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`package_id`) REFERENCES `packages`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // 4. جدول الكوبونات (coupons)
    $db->exec("CREATE TABLE IF NOT EXISTS `coupons` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `code` VARCHAR(50) NOT NULL UNIQUE,
        `discount_percent` INT NOT NULL,
        `valid_until` DATETIME NULL,
        `usage_limit` INT NULL,
        `times_used` INT DEFAULT 0,
        `is_active` TINYINT(1) DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // 5. تحديث جدول assessments (إذا كان موجوداً)
    try {
        $existAsmCols = $db->query("SHOW COLUMNS FROM `assessments`")->fetchAll(PDO::FETCH_COLUMN);
        $wantAsmCols  = [
            'user_id'          => 'INT NULL',
            'customer_journey' => 'JSON NULL',
            'bottleneck'       => 'VARCHAR(255) NULL',
            'bottleneck_desc'  => 'TEXT NULL',
            'pdf_path'         => 'VARCHAR(255) NULL',
            'is_unlocked'      => 'TINYINT(1) DEFAULT 0'
        ];
        foreach ($wantAsmCols as $col => $def) {
            if (!in_array($col, $existAsmCols)) {
                $db->exec("ALTER TABLE `assessments` ADD COLUMN `$col` $def");
            }
        }
    } catch (\Throwable $e) {
        // الجدول قد لا يكون موجوداً بعد
    }

    // 6. تحديث جدول leads
    try {
        $existLeadCols = $db->query("SHOW COLUMNS FROM `leads`")->fetchAll(PDO::FETCH_COLUMN);
        $wantLeadCols  = [
            'city'        => 'VARCHAR(60) NULL', // إضافة عمود المدينة
            'twitter_url' => 'VARCHAR(500) NULL' // إضافة عمود تويتر
        ];
        foreach ($wantLeadCols as $col => $def) {
            if (!in_array($col, $existLeadCols)) {
                $db->exec("ALTER TABLE `leads` ADD COLUMN `$col` $def");
            }
        }
    } catch (\Throwable $e) {
        // الجدول قد لا يكون موجوداً بعد
    }
}

// يمكن استدعاء الدالة مباشرة عند تضمين الملف
runMigrations();
