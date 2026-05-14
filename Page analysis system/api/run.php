<?php
// ============================================================
// api/run.php
// يقوم بتشغيل التحليل الطويل بشكل منفصل لتجنب مشاكل Timeout
// يتم استدعاؤه من المتصفح (analyzing.html) مباشرة بعد submit
// ============================================================
$cfg = require __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/analyze.php';

ignore_user_abort(true);
set_time_limit(0);
ini_set('max_execution_time', '0');

setCors();

// ✅ Register shutdown handler to catch fatal errors/timeouts
$GLOBALS['analysis_id'] = null;
register_shutdown_function(function() {
    $id = $GLOBALS['analysis_id'] ?? null;
    if (!$id) return;

    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log("[api/run.php] Fatal shutdown for id $id: " . $error['message']);
        try {
            $db = getDB();
            $db->prepare("UPDATE assessments SET status='failed', scan_error=? WHERE id=?")
               ->execute(['Fatal error: ' . $error['message'], $id]);
        } catch (\Throwable $e) {
            error_log("[api/run.php] Failed to update status on shutdown: " . $e->getMessage());
        }
    }
});

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    jsonError('معرّف غير صالح');
}

// يمكن إضافة آلية لمنع التشغيل المزدوج إذا لزم الأمر،
// لكن الدالة runAnalysis تتحقق إن كان status = 'running'

$db = getDB();

// Set global for shutdown handler
$GLOBALS['analysis_id'] = $id;

try {
    $result = runAnalysis((int)$id);
    jsonOut($result);
} catch (\Throwable $e) {
    error_log("[api/run.php] Fatal Error for id $id: " . $e->getMessage());

    // ✅ Update status to 'failed' so polling can stop
    try {
        $db->prepare("UPDATE assessments SET status='failed', scan_error=? WHERE id=?")
           ->execute([$e->getMessage(), $id]);
    } catch (\Throwable $dbErr) {
        error_log("[api/run.php] Failed to update status to failed: " . $dbErr->getMessage());
    }

    jsonError('حدث خطأ أثناء الفحص العميق: ' . $e->getMessage(), 500);
}
