<?php
// إعداد وبناء قاعدة بيانات مقياس الثقة (النظام 3)
// يمكن حذفه بعد التشغيل لضمان الأمان

require_once 'api/config.php';

try {
    $config = require 'api/config.php';
    $dsn = "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset={$config['db']['charset']}";
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // 1. إنشاء جدول المستخدمين لإدارة الأنظمة إن لم يكن موجوداً
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `admin_users` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `email` VARCHAR(255) NOT NULL UNIQUE,
            `password_hash` VARCHAR(255) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 2. إنشاء جدول ثقة العلامة (Brand Trust) الأساسي
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `trust_leads` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `client_name` varchar(255) NOT NULL,
            `client_email` varchar(255) NOT NULL,
            `client_phone` varchar(50) DEFAULT NULL,
            `trust_score` int(11) NOT NULL DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 3. إضافة مستخدم الإدارة الافتراضي (admin@system.com / password) إذا لم يكن موجوداً
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE email = :email");
    $stmt->execute([':email' => 'admin@system.com']);
    $userCount = $stmt->fetchColumn();

    if ($userCount == 0) {
        $defaultPassword = 'password';
        $hashedPass = password_hash($defaultPassword, PASSWORD_DEFAULT);
        $insertStmt = $pdo->prepare("INSERT INTO admin_users (email, password_hash) VALUES (:email, :pass)");
        $insertStmt->execute([':email' => 'admin@system.com', ':pass' => $hashedPass]);
        echo "<h3>تمت إضافة مسؤول افتراضي: admin@system.com بنجاح.</h3>";
    }

    echo "<h2>✅ تم إعداد قاعدة بيانات (النظام 3: مقياس الثقة) بنجاح!</h2>";
    echo "<p>يرجى مسح هذا الملف (setup.php) من الاستضافة لضمان الأمان.</p>";
    echo "<a href='admin/'>الذهاب للوحة التحكم</a>";

} catch (PDOException $e) {
    die("خطأ في الاتصال بقاعدة البيانات: " . $e->getMessage());
}
