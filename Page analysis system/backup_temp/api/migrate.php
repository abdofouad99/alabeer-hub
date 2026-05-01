<?php
// ============================================================
// api/migrate.php — مهاجرة قاعدة البيانات الموحّدة
// تُشغَّل مرة واحدة فقط — أو عند تغيير إصدار الـ Schema
// الإصدار: 2.0 — مُدمج من submit.php + analyze.php + run.php
// ============================================================

// منع التشغيل المتكرر عبر lock file
$lockFile = __DIR__ . '/../cache/db_migrated_v2.lock';

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

    // ─── leads ────────────────────────────────────────────────
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
    $db->exec("ALTER TABLE assessments MODIFY COLUMN status VARCHAR(30) DEFAULT 'pending'");
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
    ];
    foreach ($wantAsmCols as $col => $def) {
        if (!in_array($col, $existAsmCols)) {
            try { $db->exec("ALTER TABLE assessments ADD COLUMN `$col` $def NULL"); } catch (\Throwable $e) {}
        }
    }
    // ENUM يحتاج معالجة خاصة
    if (!in_array('tier', $existAsmCols)) {
        try { $db->exec("ALTER TABLE assessments ADD COLUMN `tier` ENUM('red','yellow','green') NULL"); } catch (\Throwable $e) {}
    }

    // ─── كتابة Lock File ──────────────────────────────────────
    file_put_contents($lockFile, date('Y-m-d H:i:s') . ' — Migration v2.0 completed');

    return true;

} catch (\Throwable $e) {
    error_log('[migrate.php] Migration failed: ' . $e->getMessage());
    return false;
}