-- ═══════════════════════════════════════════
-- جدول خريطة المنافسين — Competitor Map
-- قم بتنفيذ هذا الكود في phpMyAdmin
-- ═══════════════════════════════════════════

CREATE TABLE IF NOT EXISTS competitor_leads (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    client_name VARCHAR(255) NOT NULL,
    client_phone VARCHAR(50) NOT NULL,
    client_domain VARCHAR(255) NOT NULL,
    competitors_domains TEXT NOT NULL,
    scores_json TEXT,
    status_label VARCHAR(50) DEFAULT 'new',
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
