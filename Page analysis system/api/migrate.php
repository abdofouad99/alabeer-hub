<?php
// ============================================================
// api/migrate.php — مهاجرة قاعدة البيانات الموحّدة
// تُشغَّل مرة واحدة فقط — أو عند تغيير إصدار الـ Schema
// الإصدار: 2.0 — مُدمج من submit.php + analyze.php + run.php
// ============================================================

// منع التشغيل المتكرر عبر lock file
// نُحدِّث الإصدار إلى v4_0 لأننا أضفنا CREATE TABLE IF NOT EXISTS
// لـ leads/assessments/answers/rate_limits (كانت مفقودة، فيفشل النشر النظيف).
$lockFile = __DIR__ . '/../cache/db_migrated_v4_0.lock';

if (file_exists($lockFile)) {
    // الـ Migration اكتمل مسبقاً — لا تفعل شيئاً
    return true;
}

// تأكد من وجود مجلد cache
if (!is_dir(__DIR__ . '/../cache')) {
    mkdir(__DIR__ . '/../cache', 0755, true);
}

try {
    require_once __DIR__ . '/db.php';
    $db = getDB();

    // ─── admin_users (يجب أن يُنشأ قبل أي شيء — لوحة التحكم تعتمد عليه) ─
    // ملاحظة: لا نُدرج أي حساب أدمن افتراضي هنا. أول حساب يُنشأ عبر api/setup.php
    // ليتأكد المسؤول من اختيار كلمة مرور قوية بنفسه.
    $db->exec("
        CREATE TABLE IF NOT EXISTS admin_users (
            id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            email         VARCHAR(191)  NOT NULL UNIQUE,
            password_hash VARCHAR(255)  NOT NULL,
            created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // ─── leads (CREATE قبل ALTER لضمان عمل النشر النظيف) ──────
    // المرجع: database/schema_mysql.sql. الترتيب مهم: leads قبل
    // assessments (FK)، assessments قبل answers (FK).
    $db->exec("
        CREATE TABLE IF NOT EXISTS leads (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            full_name       VARCHAR(191),
            phone           VARCHAR(60),
            email           VARCHAR(191),
            company_name    VARCHAR(191),
            objective       VARCHAR(100),
            target_audience VARCHAR(150),
            ad_budget       VARCHAR(60),
            project_type    VARCHAR(60),
            platform        VARCHAR(60),
            country         VARCHAR(100),
            city            VARCHAR(120),
            website_url     VARCHAR(500),
            instagram_url   VARCHAR(500),
            tiktok_url      VARCHAR(500),
            facebook_url    VARCHAR(500),
            twitter_url     VARCHAR(500),
            youtube_url     VARCHAR(500),
            status          VARCHAR(30)  NOT NULL DEFAULT 'new',
            notes           TEXT,
            source          VARCHAR(100) NOT NULL DEFAULT 'growth_fingerprint',
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // ─── assessments ──────────────────────────────────────────
    $db->exec("
        CREATE TABLE IF NOT EXISTS assessments (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            lead_id         INT UNSIGNED,
            status          VARCHAR(30)  NOT NULL DEFAULT 'submitted',
            score           TINYINT UNSIGNED,
            tier            VARCHAR(20),
            breakdown       LONGTEXT COMMENT 'JSON',
            summary         TEXT,
            strengths       LONGTEXT COMMENT 'JSON array',
            weaknesses      LONGTEXT COMMENT 'JSON array',
            recommendations LONGTEXT COMMENT 'JSON array',
            next_steps      LONGTEXT COMMENT 'JSON array',
            scan_result     LONGTEXT COMMENT 'JSON',
            scan_status     VARCHAR(20),
            scan_error      TEXT,
            report_token    VARCHAR(64)  NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY idx_lead_id (lead_id),
            KEY idx_report_token (report_token),
            CONSTRAINT fk_assessment_lead
                FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // ─── answers ──────────────────────────────────────────────
    $db->exec("
        CREATE TABLE IF NOT EXISTS answers (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            assessment_id INT UNSIGNED NOT NULL,
            question_key  VARCHAR(100) NOT NULL,
            answer        LONGTEXT     NOT NULL COMMENT 'JSON',
            PRIMARY KEY (id),
            KEY idx_assessment_id (assessment_id),
            CONSTRAINT fk_answer_assessment
                FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // ─── rate_limits ──────────────────────────────────────────
    $db->exec("
        CREATE TABLE IF NOT EXISTS rate_limits (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ip          VARCHAR(45)  NOT NULL,
            action      VARCHAR(100) NOT NULL DEFAULT 'api_request',
            user_agent  TEXT,
            PRIMARY KEY (id),
            KEY idx_ip_action (ip, action),
            KEY idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // ─── leads: ALTER لإضافة أعمدة ربما تكون ناقصة في DBs قديمة ─
    $existLeadCols = $db->query("SHOW COLUMNS FROM leads")->fetchAll(PDO::FETCH_COLUMN);
    $wantLeadCols  = [
        'email'          => 'VARCHAR(120)',
        'company_name'   => 'VARCHAR(150)',
        'objective'      => 'VARCHAR(100)',
        'target_audience'=> 'VARCHAR(150)',
        'ad_budget'      => 'VARCHAR(60)',
        'project_type'   => 'VARCHAR(60)',
        'platform'       => 'VARCHAR(40)',
        'country'        => 'VARCHAR(60)',
        'city'           => 'VARCHAR(120)',
        'website_url'    => 'VARCHAR(500)',
        'facebook_url'   => 'VARCHAR(500)',
        'instagram_url'  => 'VARCHAR(500)',
        'tiktok_url'     => 'VARCHAR(500)',
        'twitter_url'    => 'VARCHAR(500)',
        'youtube_url'    => 'VARCHAR(500)',
        'source'         => "VARCHAR(60) DEFAULT 'growth_fingerprint'",
    ];
    foreach ($wantLeadCols as $col => $def) {
        if (!in_array($col, $existLeadCols)) {
            $db->exec("ALTER TABLE leads ADD COLUMN `$col` $def NULL");
        }
    }

    // ─── assessments ──────────────────────────────────────────
    // مطابق لـ CREATE TABLE وَ database/schema_mysql.sql:48 — NOT NULL DEFAULT 'submitted'.
    // قبل هذا الإصلاح كان الـ ALTER يُسقط NOT NULL ويُغيّر القيمة الافتراضية إلى 'pending'.
    $db->exec("ALTER TABLE assessments MODIFY COLUMN status VARCHAR(30) NOT NULL DEFAULT 'submitted'");
    $existAsmCols = $db->query("SHOW COLUMNS FROM assessments")->fetchAll(PDO::FETCH_COLUMN);
    $wantAsmCols  = [
        'report_token'   => 'VARCHAR(64)',
        'breakdown'      => 'JSON',
        'summary'        => 'TEXT',
        'recommendations'=> 'JSON',
        'strengths'      => 'JSON',
        'weaknesses'     => 'JSON',
        'next_steps'     => 'JSON',
        'scan_result'    => 'JSON',
        'scan_status'    => 'VARCHAR(20)',
        'scan_error'     => 'TEXT',
        'ai_report'      => 'JSON',
        'scan_step'      => 'TINYINT(1) DEFAULT 0',
        'competitor_radar'=> 'JSON',
        'error'          => 'TEXT',
    ];
    foreach ($wantAsmCols as $col => $def) {
        if (!in_array($col, $existAsmCols)) {
            try { $db->exec("ALTER TABLE assessments ADD COLUMN `$col` $def NULL"); } catch (\Throwable $e) {}
        }
    }
    // tier: نُطابق database/schema_mysql.sql:50 — VARCHAR(20). كان سابقاً ENUM،
    // فيتعارض مع CREATE TABLE في النشر النظيف ويُسبّب اختلاف نوع العمود بين
    // قواعد البيانات القديمة والجديدة. مع الـ CHECK في الكود (نقبل red/yellow/green)
    // فالـ VARCHAR كافٍ.
    if (!in_array('tier', $existAsmCols)) {
        try { $db->exec("ALTER TABLE assessments ADD COLUMN `tier` VARCHAR(20) NULL"); } catch (\Throwable $e) {}
    }

    // ─── كتابة Lock File ──────────────────────────────────────
    file_put_contents($lockFile, date('Y-m-d H:i:s') . ' — Migration v4.0 completed');

    return true;

} catch (\Throwable $e) {
    error_log('[migrate.php] Migration failed: ' . $e->getMessage());
    return false;
}
