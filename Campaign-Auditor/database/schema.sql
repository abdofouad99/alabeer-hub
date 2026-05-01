-- ═══════════════════════════════════════════
-- جدول مدقق الحملة الإعلانية — Campaign Auditor
-- نفّذ هذا الكود في phpMyAdmin
-- ═══════════════════════════════════════════

CREATE TABLE IF NOT EXISTS campaign_leads (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    client_name VARCHAR(255) NOT NULL,
    client_phone VARCHAR(50) NOT NULL,
    platform VARCHAR(50) DEFAULT NULL,
    monthly_budget VARCHAR(100) DEFAULT NULL,
    campaign_score INT(11) DEFAULT 0,
    problems_json TEXT DEFAULT NULL,
    answers_json TEXT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
