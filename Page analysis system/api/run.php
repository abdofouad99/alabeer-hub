<?php
// ============================================================
// api/run.php
// يقوم بتشغيل التحليل الطويل بشكل منفصل لتجنب مشاكل Timeout
// يتم استدعاؤه من المتصفح (analyzing.html) مباشرة بعد submit
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/analyze.php';

ignore_user_abort(true);
set_time_limit(0);
ini_set('max_execution_time', '0');

setCors();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    jsonError('معرّف غير صالح');
}

// يمكن إضافة آلية لمنع التشغيل المزدوج إذا لزم الأمر،
// لكن الدالة runAnalysis تتحقق إن كان status = 'running'

try {
    $result = runAnalysis((int)$id);
    jsonOut($result);
} catch (\Throwable $e) {
    error_log("[api/run.php] Fatal Error for id $id: " . $e->getMessage());
    jsonError('حدث خطأ أثناء الفحص العميق', 500);
}
