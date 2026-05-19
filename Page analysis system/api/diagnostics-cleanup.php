<?php
/**
 * ════════════════════════════════════════════════════════════════
 * Diagnostics Cleanup — يحذف diagnostics_log الأقدم من 30 يوم
 * ────────────────────────────────────────────────────────────────
 * تشغيل يدوي:    php api/diagnostics-cleanup.php
 * تشغيل cron:    0 3 * * * /usr/bin/php /path/to/api/diagnostics-cleanup.php
 *
 * يضع NULL في العمود (لا يحذف الفحص نفسه — فقط الـ log).
 */

require_once __DIR__ . '/db.php';

try {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        "UPDATE assessments
         SET diagnostics_log = NULL
         WHERE diagnostics_log IS NOT NULL
           AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    $stmt->execute();
    $affected = $stmt->rowCount();
    echo "[diagnostics-cleanup] Cleared diagnostics_log for {$affected} old assessments (>30 days).\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "[diagnostics-cleanup] ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
