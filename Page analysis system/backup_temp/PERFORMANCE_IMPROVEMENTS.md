# تحسينات الأداء والأمان — نظام تحليل الصفحات

## نظرة عامة
تم إضافة ثلاث تحسينات رئيسية للنظام:

1. **طبقة التخزين المؤقت (Caching Layer)**
2. **تقييد المعدل (Rate Limiting)**
3. **تسجيل شامل (Comprehensive Logging)**

## 1. طبقة التخزين المؤقت

### الميزات
- **دعم متعدد الطبقات**: Redis (أولوية) ← APCu ← File-based
- **تخزين ذكي**: نتائج الفحص محفوظة لـ 6 ساعات
- **تحسين الأداء**: تقليل الاستعلامات المتكررة لنفس الروابط

### الإعدادات
```php
'cache' => [
    'enabled' => true,
    'driver'  => 'redis', // redis, file, apcu
    'ttl'     => 3600,   // ثانية
    'redis'   => [
        'url'   => 'https://...upstash.io',
        'token' => '...'
    ],
    'file' => [
        'path' => __DIR__ . '/../cache/'
    ]
]
```

### الاستخدام
```php
// تخزين بسيط
cacheSet('key', $data, 3600);

// استرجاع
$data = cacheGet('key');

// تخزين ذكي مع callback
$result = cacheRemember('scan_' . md5($url), function() {
    return runPageScan($url, $config);
}, 6 * 3600);
```

## 2. تقييد المعدل (Rate Limiting)

### الميزات
- **حماية من الإفراط**: حد أقصى 10/دقيقة، 50/ساعة، 200/يوم
- **تتبع بالـ IP**: منع الإساءة من عناوين محددة
- **headers معلوماتية**: X-RateLimit-* للعملاء
- **تنظيف تلقائي**: حذف السجلات القديمة

### الإعدادات
```php
'rate_limit' => [
    'max_per_minute' => 10,
    'max_per_hour'   => 50,
    'max_per_day'    => 200,
]
```

### الاستخدام
```php
// في بداية API endpoint
if (!checkApiRateLimit('submit_analysis')) {
    http_response_code(429);
    echo json_encode(['error' => 'تم تجاوز الحد المسموح']);
    exit;
}

// إضافة headers
$headers = getRateLimitHeaders('submit_analysis');
foreach ($headers as $h => $v) {
    header("$h: $v");
}
```

## 3. التسجيل الشامل

### الميزات
- **مستويات متعددة**: DEBUG, INFO, WARNING, ERROR
- **تدوير الملفات**: 10MB حد أقصى، 5 ملفات احتياطية
- **معلومات شاملة**: IP، User-Agent، Context
- **أداء عالي**: لا يؤثر على سرعة التطبيق

### الإعدادات
```php
'logging' => [
    'enabled'     => true,
    'level'       => 'INFO',
    'file_path'   => __DIR__ . '/../logs/app.log',
    'max_file_size' => 10 * 1024 * 1024,
    'max_files'   => 5,
]
```

### الاستخدام
```php
// تسجيل أحداث مهمة
logInfo('Analysis started', ['assessment_id' => $id, 'url' => $url]);

// تسجيل أخطاء
logError('API call failed', ['error' => $e->getMessage(), 'url' => $url]);

// تسجيل تحذيرات
logWarning('Rate limit exceeded', ['ip' => $_SERVER['REMOTE_ADDR']]);
```

## التثبيت والتشغيل

### 1. تشغيل Migration
```bash
cd api/
php migrate.php
```

### 2. التحقق من الأذونات
```bash
chmod 755 logs/
chmod 755 cache/
```

### 3. اختبار النظام
```bash
# اختبار caching
curl "http://your-domain/api/test-cache.php"

# اختبار logging
curl "http://your-domain/api/test-log.php"

# اختبار rate limiting
curl "http://your-domain/api/submit.php" -X POST -d '{"lead":{"full_name":"Test"}}'
```

## مراقبة النظام

### مراجعة السجلات
```bash
tail -f logs/app.log
```

### إحصائيات Rate Limiting
```php
$stats = getRateLimiter($db, $config, $logger)->getStats();
print_r($stats);
```

### تنظيف Cache
```php
$cache->clear();
```

## الأداء المتوقع

- **تحسين الاستجابة**: 60-80% للطلبات المكررة
- **توفير الموارد**: تقليل استدعاءات Apify
- **أمان أفضل**: حماية من الهجمات
- **تشخيص أسهل**: تتبع شامل للمشاكل

## استكشاف الأخطاء

### مشاكل Caching
- تأكد من إعدادات Redis
- تحقق من أذونات مجلد cache/
- استخدم fallback للـ file cache

### مشاكل Rate Limiting
- تحقق من جدول rate_limits في قاعدة البيانات
- تأكد من تنظيف السجلات القديمة

### مشاكل Logging
- تحقق من أذونات مجلد logs/
- راجع حجم الملفات
- استخدم logrotate للإدارة التلقائية

## النسخ الاحتياطي
- احفظ مجلد logs/ في النسخ الاحتياطية
- لا تحتاج cache/ للنسخ الاحتياطي (يمكن إعادة بنائها)
- احتفظ بسجلات rate_limits للتحليل