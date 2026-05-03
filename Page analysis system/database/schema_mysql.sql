-- ============================================================
-- بصمة النمو | قاعدة بيانات MySQL (Hostinger)
-- ============================================================
-- تشغيل هذا الملف من phpMyAdmin > Import

SET NAMES utf8mb4;
SET time_zone = '+03:00';

-- ── جدول المدراء ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id`           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `email`        VARCHAR(191)     NOT NULL UNIQUE,
  `password_hash`VARCHAR(255)     NOT NULL,
  `created_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── جدول العملاء (Leads) ────────────────────────────────────
CREATE TABLE IF NOT EXISTS `leads` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `full_name`     VARCHAR(191),
  `phone`         VARCHAR(60),
  `email`         VARCHAR(191),
  `company_name`  VARCHAR(191),
  `objective`     VARCHAR(100),
  `target_audience` VARCHAR(150),
  `ad_budget`     VARCHAR(60),
  `project_type`  VARCHAR(60),
  `platform`      VARCHAR(60),
  `country`       VARCHAR(100),
  `website_url`   VARCHAR(500),
  `instagram_url` VARCHAR(500),
  `tiktok_url`    VARCHAR(500),
  `facebook_url`  VARCHAR(500),
  `youtube_url`   VARCHAR(500),
  `status`        VARCHAR(30)  NOT NULL DEFAULT 'new',
  `notes`         TEXT,
  `source`        VARCHAR(100) NOT NULL DEFAULT 'growth_fingerprint',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── جدول التقييمات ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `assessments` (
  `id`             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `lead_id`        INT UNSIGNED,
  `status`         VARCHAR(30)      NOT NULL DEFAULT 'submitted',
  `score`          TINYINT UNSIGNED,
  `tier`           VARCHAR(20),
  `breakdown`      LONGTEXT COMMENT 'JSON',
  `summary`        TEXT,
  `strengths`      LONGTEXT COMMENT 'JSON array',
  `weaknesses`     LONGTEXT COMMENT 'JSON array',
  `recommendations`LONGTEXT COMMENT 'JSON array',
  `next_steps`     LONGTEXT COMMENT 'JSON array',
  `scan_result`    LONGTEXT COMMENT 'JSON',
  `scan_status`    VARCHAR(20),
  `scan_error`     TEXT,
  `report_token`   VARCHAR(64)      NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_lead_id` (`lead_id`),
  KEY `idx_report_token` (`report_token`),
  CONSTRAINT `fk_assessment_lead`
    FOREIGN KEY (`lead_id`) REFERENCES `leads`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── جدول الإجابات ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `answers` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `assessment_id` INT UNSIGNED NOT NULL,
  `question_key`  VARCHAR(100) NOT NULL,
  `answer`        LONGTEXT     NOT NULL COMMENT 'JSON',
  PRIMARY KEY (`id`),
  KEY `idx_assessment_id` (`assessment_id`),
  CONSTRAINT `fk_answer_assessment`
    FOREIGN KEY (`assessment_id`) REFERENCES `assessments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── جدول Rate Limiting ────────────────────────────────────
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip`            VARCHAR(45)      NOT NULL,
  `action`        VARCHAR(100)     NOT NULL DEFAULT 'api_request',
  `user_agent`    TEXT,
  PRIMARY KEY (`id`),
  KEY `idx_ip_action` (`ip`, `action`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ⚠️ ملاحظة أمنية مهمة:
-- لم نعد نُدرج مستخدم أدمن افتراضي مع كلمة مرور ثابتة.
-- بعد استيراد هذا الملف، شغّل `api/setup.php` مرة واحدة من المتصفح
-- لإنشاء أول حساب أدمن بكلمة مرور قوية يختارها المسؤول.
--
-- لا تُضِف INSERT لحساب أدمن في هذا الملف لأي سبب — كلمات المرور الثابتة
-- في الـ git history تُعدّ تسريباً أمنياً دائماً حتى بعد حذفها لاحقاً.
