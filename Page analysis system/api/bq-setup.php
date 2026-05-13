<?php
// إعداد قاعدة بيانات BigQuery والجداول الأساسية
require_once __DIR__ . '/../vendor/autoload.php';
use Google\Cloud\BigQuery\BigQueryClient;

// جلب المفتاح (ندعم الاسمين للحيطة)
$keyPath = __DIR__ . '/../bq-key.json.json';
if (!file_exists($keyPath)) {
    $keyPath = __DIR__ . '/../bq-key.json';
}

if (!file_exists($keyPath)) {
    die("❌ خطأ: ملف المفتاح (bq-key.json) غير موجود في مجلد المشروع الأساسي.");
}

try {
    $bigQuery = new BigQueryClient([
        'keyFilePath' => $keyPath
    ]);

    $datasetId = 'alabeer_analytics';
    $dataset = $bigQuery->dataset($datasetId);

    // إنشاء قاعدة البيانات إذا لم تكن موجودة
    if (!$dataset->exists()) {
        $bigQuery->createDataset($datasetId);
        echo "✅ تم إنشاء Dataset: $datasetId بنجاح.<br>";
    } else {
        echo "ℹ️ الـ Dataset: $datasetId موجودة مسبقاً.<br>";
    }

    $tableId = 'pages_snapshots';
    $table = $dataset->table($tableId);

    // إنشاء الجدول الأساسي للمتابعة التاريخية
    if (!$table->exists()) {
        $schema = [
            'fields' => [
                ['name' => 'assessment_id', 'type' => 'INTEGER'],
                ['name' => 'url', 'type' => 'STRING'],
                ['name' => 'analyzed_at', 'type' => 'TIMESTAMP'],
                ['name' => 'package_tier', 'type' => 'STRING'],
                ['name' => 'strengths_count', 'type' => 'INTEGER'],
                ['name' => 'weaknesses_count', 'type' => 'INTEGER'],
                ['name' => 'recommendations_count', 'type' => 'INTEGER'],
                ['name' => 'ads_raw_count', 'type' => 'INTEGER']
            ]
        ];
        $dataset->createTable($tableId, ['schema' => $schema]);
        echo "✅ تم إنشاء الجدول: $tableId بنجاح.<br>";
    } else {
        echo "ℹ️ الجدول: $tableId موجود مسبقاً.<br>";
    }

    echo "<br>🚀 <b>ممتاز! تم إعداد BigQuery بنجاح، يمكنك الآن تجربة المشروع.</b>";

} catch (\Exception $e) {
    echo "❌ حدث خطأ أثناء الاتصال: " . $e->getMessage();
}
