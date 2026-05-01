-- ============================================================
-- فحص الجاهزية الرقمية | قاعدة بيانات MySQL (Hostinger)
-- ============================================================
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
  `industry`      VARCHAR(100),
  `employees`     VARCHAR(30),
  `country`       VARCHAR(100),
  `website_url`   VARCHAR(500),
  `status`        VARCHAR(30)  NOT NULL DEFAULT 'new',
  `notes`         TEXT,
  `source`        VARCHAR(100) NOT NULL DEFAULT 'digital_readiness',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── جدول التقييمات ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `assessments` (
  `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `lead_id`         INT UNSIGNED,
  `status`          VARCHAR(30)      NOT NULL DEFAULT 'submitted',
  `score`           TINYINT UNSIGNED,
  `stage`           VARCHAR(30),
  `breakdown`       LONGTEXT COMMENT 'JSON',
  `summary`         TEXT,
  `strengths`       LONGTEXT COMMENT 'JSON array',
  `weaknesses`      LONGTEXT COMMENT 'JSON array',
  `roadmap`         LONGTEXT COMMENT 'JSON array',
  `quick_wins`      LONGTEXT COMMENT 'JSON array',
  `monthly_plan`    LONGTEXT COMMENT 'JSON array',
  `tools_suggested` LONGTEXT COMMENT 'JSON array',
  `report_token`    VARCHAR(64)      NOT NULL DEFAULT '',
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
