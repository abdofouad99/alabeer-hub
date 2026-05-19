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



---

## 👤 نظام حسابات العملاء (v7.0 — جديد)

> أُضيف في فرع `feature/customer-accounts` لإتاحة تسجيل العميل وحفظ تقاريره عبر الجلسات.

### الفكرة الأساسية

- العميل **يسجّل مرة واحدة** أثناء أول تحليل (إيميل + كلمة مرور).
- يستطيع عمل **تحاليل غير محدودة** مجاناً، كلها تُحفظ في حسابه.
- لو خرج وعاد: يدخل عبر `login.html` ويرى كل تقاريره في `my-reports.html`.
- **التقارير القديمة** (التي أُنشئت قبل هذا التحديث) تبقى متاحة عبر الـ token كما هي.

### الجداول الجديدة

```sql
customers (id, email UNIQUE, password_hash, full_name, phone,
           email_verified, verify_token, reset_token, reset_expires,
           last_login_at, last_login_ip, created_at, updated_at)

leads.customer_id        INT NULL  -- مرجع للحساب
assessments.customer_id  INT NULL  -- مرجع للحساب
```

> Migration يعمل تلقائياً عبر `api/migrations/v7_customers.php` مع lock-file مستقل.
> لا يستخدم Foreign Key constraints لتجنّب كسر بيانات قديمة.

### الـ Endpoints الجديدة

| Endpoint | الميثود | الغرض |
|---|---|---|
| `api/customer/auth.php?action=check` | GET | فحص حالة الجلسة → `{authed, id, email, full_name}` |
| `api/customer/auth.php?action=password-check` | POST | فحص هل الإيميل مسجَّل → `{exists}` |
| `api/customer/auth.php?action=register` | POST | تسجيل حساب جديد (يبدأ session) |
| `api/customer/auth.php?action=login` | POST | تسجيل دخول (يبدأ session) |
| `api/customer/auth.php?action=logout` | POST | إنهاء الجلسة |
| `api/customer/reports.php` | GET | قائمة تقارير العميل |
| `api/customer/me.php` | GET / PATCH | بيانات العميل + إحصائيات / تحديث الاسم/الهاتف/كلمة المرور |

### الصفحات الجديدة

- **`login.html`** — صفحة تسجيل دخول العميل
- **`my-reports.html`** — لوحة "تقاريري" مع إحصائيات وبطاقات التقارير
- **`scan.html`** (مُعدَّلة) — يُلزم الآن بإيميل + كلمة مرور لإنشاء حساب أثناء التحليل

### الـ JavaScript الجديد

- `js/login-page.js` — منطق تسجيل الدخول
- `js/my-reports.js` — رسم البطاقات (XSS-safe بـ `textContent`)
- `js/header-auth.js` — شريط تنقل عائم يتغير حسب حالة الـ Auth (محقون في 5 صفحات)

### تدفق التسجيل/الدخول

```
[1] عميل جديد:
    scan.html → يملأ النموذج + كلمة مرور → submit.php
    → ينشئ customers row + leads row + assessments row → يبدأ session
    → analyzing.html → my-reports.html

[2] عميل عائد (نفس الجلسة):
    أي صفحة → CUSTSESSID cookie فعّال → header-auth.js يعرض اسمه
    → "تقاريري" يفتح my-reports.html مباشرة

[3] عميل عائد (جلسة منتهية):
    login.html → POST auth.php?action=login → بدء session جديدة
    → my-reports.html يجلب التقارير

[4] إيميل موجود مسبقاً عند submit:
    submit.php → password_verify ✗ → 401 + customer_exists:true
    → analyzing-page.js يعرض رسالة + رابط لـ login.html
```

### الأمان المُطبَّق

- **Password Hashing**: BCRYPT cost=12
- **Sessions**: HttpOnly + Secure (auto-detect HTTPS) + SameSite=Lax + session_regenerate_id ضد Session Fixation + UA hash check ضد Hijacking
- **Session Name منفصل** (`CUSTSESSID`) لمنع التداخل مع جلسات الأدمن
- **Rate Limiting**:
  - register: 3/IP/ساعة
  - login: 5/email + 10/IP لكل 15 دقيقة
  - password-check: 30/IP/10 دقائق
  - reports: 60/customer/دقيقة
  - me PATCH: 10/customer/دقيقة
- **Timing-safe verify** ضد User Enumeration (dummy hash)
- **Password Rehash** تلقائي عند تغيُّر cost في الإعدادات
- **CORS**: whitelist فقط (لا `*` في customer endpoints) + Allow-Credentials
- **Customer Endpoints محمية**: `requireCustomer()` يُلزم الجلسة
- **IDOR Protected**: استعلامات تقارير العميل بـ `WHERE customer_id = ?` فقط
- **No PII Leak**: لا تُرسل `$e->getMessage()` للعميل (logged فقط)
- **XSS-safe Frontend**: استخدام `textContent` بدلاً من `innerHTML` لبيانات API

### التشغيل بعد التحديث

```bash
# Migration يعمل تلقائياً عند أول request — لا حاجة لخطوة يدوية
# تأكد من صلاحية مجلد cache/ للكتابة:
chmod 755 cache/

# اختبار:
# 1) افتح scan.html → سجّل بإيميل جديد + كلمة مرور
# 2) أكمل التحليل
# 3) افتح my-reports.html → يجب أن تظهر التقارير
# 4) أغلق التبويب → افتح login.html → سجّل دخول
# 5) my-reports.html → نفس التقارير تظهر
```

### حالات لم تُنفَّذ في v7.0 (للمراحل القادمة)

- ❌ **نسيان كلمة المرور** (الحقول `reset_token` و `reset_expires` جاهزة في الـ schema، يلزم endpoint + إيميل SMTP)
- ❌ **تأكيد الإيميل** (الحقل `verify_token` جاهز)
- ❌ **OAuth (Google/Facebook login)**
- ❌ **حذف الحساب** (يُترك للأدمن حالياً)
- ❌ **2FA**

