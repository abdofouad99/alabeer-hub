-- ========================================================
-- System 2: ROAS Simulator & Ad Profitability (Neon Tech)
-- ========================================================

SET NAMES utf8mb4;
SET time_zone = '+03:00';

-- 1. جدول مدراء النظام (نفس هيكل النظام الأول للاستقلالية)
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(191) UNIQUE NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. جدول بيانات محاكاة العملاء (Simulations)
-- يحفظ البيانات المدخلة وحالة النزيف المالي للعميل
CREATE TABLE IF NOT EXISTS `simulations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name` VARCHAR(191) NOT NULL,
  `phone` VARCHAR(60) NOT NULL,
  `company_name` VARCHAR(191),
  `website_url` VARCHAR(500),
  `monthly_budget` DECIMAL(10,2) NOT NULL,      -- الميزانية الإعلانية
  `product_price` DECIMAL(10,2) NOT NULL,       -- سعر المنتج
  `profit_margin` DECIMAL(5,2) NOT NULL,        -- هامش الربح %
  `current_cpa` DECIMAL(10,2) NOT NULL,         -- تكلفة الاستحواذ الحالية
  `current_profit` DECIMAL(12,2) NOT NULL,      -- أرباحه الحالية (قد تكون بالسالب!)
  `potential_profit` DECIMAL(12,2) NOT NULL,    -- الأرباح المتوقعة بعد التحسين
  `financial_status` VARCHAR(50) NOT NULL,      -- 'bleeding' (نزيف/خاسر), 'breakeven' (تعادل), 'profitable' (رابح)
  `lead_status` VARCHAR(30) NOT NULL DEFAULT 'new', -- حالة التواصل معه
  `notes` TEXT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
