<?php
// clear-cache.php — حذف كل ملفات الـ Cache لإجبار الـ AI على إعادة التحليل
require_once __DIR__ . '/db.php';

$cacheDir = __DIR__ . '/../cache';
$deleted = 0;

if (is_dir($cacheDir)) {
    foreach (glob($cacheDir . '/*.cache') as $file) {
        if (unlink($file)) $deleted++;
    }
}

// حذف ai_result من آخر assessment لإجباره على إعادة التحليل
$db = getDB();
$stmt = $db->query('SELECT id FROM assessments ORDER BY id DESC LIMIT 5');
$ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

$updated = 0;
foreach ($ids as $id) {
    $stmt = $db->prepare('UPDATE assessments SET ai_result = NULL, ai_status = NULL WHERE id = ?');
    $stmt->execute([$id]);
    $updated += $stmt->rowCount();
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'cache_files_deleted' => $deleted,
    'assessments_reset' => $updated,
    'message' => "تم مسح $deleted ملف cache وإعادة تعيين $updated تقييم. الآن أعد فتح التقرير."
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
