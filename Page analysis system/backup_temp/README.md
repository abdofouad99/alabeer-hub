# نظام تحليل الصفحات — Alabeer Hub

نظام شامل لتحليل صفحات الويب وتقييمها باستخدام الذكاء الاصطناعي والتحليل المتقدم.

## الميزات الرئيسية

### 🔍 تحليل شامل للصفحات
- استخراج المحتوى والمعلومات الأساسية
- تحليل SEO وأداء الصفحة
- تقييم جودة المحتوى والتصميم
- مقارنة مع المنافسين

### 🤖 ذكاء اصطناعي متقدم
- تحليل محتوى باستخدام GPT
- تقييم نقاط القوة والضعف
- اقتراحات تحسين مخصصة
- تحليل الجمهور المستهدف

### 📊 لوحة تحكم إدارية
- إدارة شاملة للطلبات
- إحصائيات مفصلة
- مراقبة الأداء
- إدارة المستخدمين

### ⚡ أداء محسّن
- **طبقة تخزين مؤقت**: Redis/File cache للنتائج
- **تقييد المعدل**: حماية من الإفراط في الاستخدام
- **تسجيل شامل**: تتبع وتشخيص الأحداث

## متطلبات النظام

- **PHP**: 7.4 أو أحدث
- **MySQL**: 5.7 أو أحدث
- **Redis**: 6.0 أو أحدث (اختياري)
- **Apache/Nginx**: مع دعم .htaccess

## التثبيت والإعداد

### 1. تحميل المشروع
```bash
git clone <repository-url>
cd "Page analysis system"
```

### 2. إعداد قاعدة البيانات
```bash
# إنشاء قاعدة البيانات
mysql -u root -p < database/schema_mysql.sql

# تشغيل migration للتحسينات الجديدة
cd api/
php migrate.php
```

### 3. إعداد المتغيرات البيئية
```bash
cp .env.example .env
# عدل الإعدادات في .env حسب البيئة
```

### 4. إعداد الأذونات
```bash
chmod 755 logs/
chmod 755 cache/
chmod 644 api/*.php
```

### 5. اختبار النظام
```bash
# اختبار التخزين المؤقت
curl "http://localhost/api/test-cache.php"

# اختبار التسجيل
curl "http://localhost/api/test-log.php"

# اختبار تقييد المعدل
curl "http://localhost/api/test-rate-limit.php"
```

## استخدام Docker (اختياري)

### تشغيل البيئة المحلية
```bash
docker-compose up -d
```

### إيقاف البيئة
```bash
docker-compose down
```

## API Endpoints

### إرسال طلب تحليل
```http
POST /api/submit.php
Content-Type: application/json

{
  "lead": {
    "full_name": "اسم العميل",
    "email": "email@example.com",
    "phone": "1234567890"
  },
  "url": "https://example.com"
}
```

### الحصول على حالة التحليل
```http
GET /api/status.php?id={assessment_id}
```

### الحصول على النتائج
```http
GET /api/result.php?id={assessment_id}
```

## إعدادات الأمان

### Rate Limiting
- **لكل دقيقة**: 10 طلبات
- **لكل ساعة**: 50 طلب
- **لكل يوم**: 200 طلب

### Headers المُرسلة
```
X-RateLimit-Limit: 10
X-RateLimit-Remaining: 9
X-RateLimit-Reset: 1640995200
```

## مراقبة النظام

### مراجعة السجلات
```bash
tail -f logs/app.log
```

### إحصائيات Rate Limiting
```bash
# في لوحة التحكم الإدارية
# أو عبر API endpoint مخصص
```

### تنظيف Cache
```php
// في PHP
$cache->clear();
```

## استكشاف الأخطاء

### مشاكل شائعة

#### خطأ في الاتصال بقاعدة البيانات
- تحقق من إعدادات `config.php`
- تأكد من تشغيل MySQL
- تحقق من أذونات المستخدم

#### مشاكل في التخزين المؤقت
- تأكد من إعدادات Redis
- تحقق من مجلد `cache/`
- استخدم file cache كبديل

#### مشاكل في Rate Limiting
- تحقق من جدول `rate_limits`
- راجع السجلات للأخطاء
- تحقق من الـ IP address

### ملفات السجل
- **app.log**: السجلات العامة للتطبيق
- **error.log**: أخطاء النظام
- **cache.log**: عمليات التخزين المؤقت

## التطوير والمساهمة

### هيكل المشروع
```
├── api/              # API endpoints
│   ├── config.php    # إعدادات النظام
│   ├── init.php      # تهيئة النظام
│   ├── analyze.php   # محرك التحليل
│   └── ...
├── admin/            # لوحة التحكم الإدارية
├── css/              # ملفات التصميم
├── js/               # JavaScript files
├── database/         # مخطط قاعدة البيانات
└── logs/             # ملفات السجل
```

### إضافة ميزات جديدة
1. أضف الكود في المجلد المناسب
2. حدث `config.php` إذا لزم الأمر
3. أضف اختبارات في `api/test-*.php`
4. حدث هذا الملف README

## الترخيص

هذا المشروع محمي بحقوق الطبع والنشر © 2024 Alabeer Hub.

## الدعم

للدعم الفني أو الاستفسارات:
- البريد الإلكتروني: support@alabeer.com
- التوثيق: [رابط التوثيق]

---

**ملاحظة**: تأكد من مراجعة `PERFORMANCE_IMPROVEMENTS.md` لتفاصيل التحسينات الأخيرة.