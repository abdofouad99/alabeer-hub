# دليل المساهمة — نظام تحليل الصفحات

شكراً لاهتمامك بالمساهمة في نظام تحليل الصفحات! هذا الدليل يوضح كيفية المساهمة في المشروع.

## طرق المساهمة

### 🐛 الإبلاغ عن الأخطاء
1. تحقق من أن المشكلة غير مُبلغ عنها سابقاً
2. أنشئ issue جديد مع التفاصيل التالية:
   - وصف واضح للمشكلة
   - خطوات لإعادة إنتاج المشكلة
   - السلوك المتوقع والسلوك الفعلي
   - معلومات البيئة (PHP version, OS, etc.)

### ✨ اقتراح ميزات جديدة
1. تحقق من أن الميزة غير موجودة
2. أنشئ issue مع:
   - وصف الميزة المطلوبة
   - الحالة الاستخدامية
   - الفائدة المتوقعة

### 🔧 المساهمة بالكود
1. Fork المشروع
2. أنشئ branch جديد: `git checkout -b feature/amazing-feature`
3. اكتب الكود مع الاختبارات
4. تأكد من اجتياز جميع الاختبارات
5. Commit التغييرات: `git commit -m 'Add amazing feature'`
6. Push للـ branch: `git push origin feature/amazing-feature`
7. أنشئ Pull Request

## معايير الكود

### PHP Standards
```php
<?php
// استخدم namespaces
namespace Alabeer\Api;

// استخدم type hints
function processData(array $data): array
{
    // كود منظم ومُعلق
    return $processed;
}
```

### JavaScript Standards
```javascript
// استخدم const/let بدلاً من var
const processData = (data) => {
    // كود نظيف ومُعلق
    return processed;
};
```

### أسلوب Git Commits
```
type(scope): description

[optional body]

[optional footer]
```

أنواع الـ commits:
- `feat`: ميزة جديدة
- `fix`: إصلاح خطأ
- `docs`: توثيق
- `style`: تنسيق
- `refactor`: إعادة هيكلة
- `test`: اختبارات
- `chore`: مهام صيانة

## إعداد بيئة التطوير

### المتطلبات
- PHP 7.4+
- MySQL 5.7+
- Node.js 16+
- Composer
- Docker (اختياري)

### خطوات الإعداد
```bash
# استنساخ المشروع
git clone https://github.com/your-repo/alabeer-page-analysis.git
cd alabeer-page-analysis

# تثبيت dependencies
composer install
npm install

# إعداد قاعدة البيانات
cp .env.example .env
# عدل .env حسب إعداداتك

# تشغيل migrations
php api/migrate.php

# تشغيل الاختبارات
composer test
```

### استخدام Docker
```bash
# للتطوير
docker-compose up -d

# للإنتاج
docker-compose -f docker-compose.prod.yml up -d
```

## كتابة الاختبارات

### PHP Unit Tests
```php
<?php
use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    public function testExample()
    {
        $this->assertTrue(true);
    }
}
```

### تشغيل الاختبارات
```bash
# جميع الاختبارات
composer test

# اختبارات محددة
./vendor/bin/phpunit tests/CacheTest.php
```

## مراجعة الكود (Code Review)

### قائمة المراجعة
- [ ] الكود يتبع معايير PSR
- [ ] تمت كتابة اختبارات للوظائف الجديدة
- [ ] تم تحديث التوثيق
- [ ] لا توجد أخطاء syntax
- [ ] الكود آمن ولا يحتوي على ثغرات

### عملية المراجعة
1. المراجع الآلي (CI/CD)
2. مراجعة من قبل maintainer
3. اختبارات إضافية إذا لزم الأمر
4. دمج الكود

## التوثيق

### تحديث README
- أضف تعليمات جديدة في README.md
- حدث أمثلة الاستخدام
- أضف روابط للمراجع

### تحديث CHANGELOG
```markdown
## [1.2.0] - YYYY-MM-DD

### ✨ الميزات الجديدة
- إضافة ميزة رائعة

### 🔧 التحسينات
- تحسين الأداء

### 🐛 الإصلاحات
- إصلاح خطأ في X
```

## ⚠️ ممنوع: سكريبتات الإصلاح/الحقن أثناء التشغيل

**القاعدة:** لا تكتب سكريبت PHP يقرأ ملف إنتاج (مثل `report.html` أو `ai-analyze.php`) ويعدّله أثناء التشغيل لإصلاح خلل.

### لماذا؟
كل أنماط مثل:
```php
// ❌ ممنوع — مثال على سكريبت إنجكشن
$content = file_get_contents('../report.html');
$start = strpos($content, 'marker_string');
$end   = strpos($content, '</script>', $start) + strlen('</script>');
if ($start !== false && $end !== false) {  // ⚠️ false + 9 = 9
    file_put_contents($file, ...);
}
```

تعاني من **bugs خطيرة بطبيعتها:**
1. `strpos(...) + strlen(...)` ينتج `9` عندما `strpos` يُرجع `false` (لأن PHP تحوّل false → 0).
2. لا توجد آلية idempotent — تشغيل السكريبت مرتين يُضاعف المحتوى.
3. لا rollback عند الفشل الجزئي.
4. لا تحقّق من encoding، RTL، أو HTML validity.
5. تخريب صامت: قد يبدو السكريبت يعمل لكن النتيجة فاسدة.

### البديل الصحيح
- عدّل الملف المصدر مباشرة في محرر النصوص (VS Code).
- ارفع التعديل في commit + PR.
- لو احتجت تعديلاً ديناميكياً (مثل version cache busting)، استخدم build step (Webpack/Gulp) لا runtime PHP.

### ما يُحظَر تحديداً (مُدرَج في `.gitignore`)
- `fix-*.php`, `fix_*.php`, `api/fix.php`, `api/fix-*.php`
- `inject-*.php`, `api/inject*.php`
- `patch-*.php`, `repair-*.php`, `hotfix-*.php`
- `api/check_braces.php`, `api/check-*.php`

لو وجدت ملفاً منها على نظامك، **احذفه فوراً** (لا ترفعه).

## الترخيص

بمساهمتك، توافق على أن مساهمتك ستكون مرخصة تحت نفس الترخيص الخاص بالمشروع (MIT).

## الدعم

إذا كان لديك أسئلة:
- **Issues**: للأخطاء والميزات
- **Discussions**: للأسئلة العامة
- **Email**: dev@alabeer.com

شكراً مرة أخرى لمساهمتك! 🎉
