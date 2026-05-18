# سياق مشروع Page Analysis System

> هذا الملف يُقرأ تلقائياً في كل جلسة Kiro جديدة لاستعادة السياق دون فقدان التفاصيل بين الجلسات.

---

## 1. هوية المشروع

- **المستودع:** `abdofouad99/alabeer-hub`
- **النظام الرئيسي:** `Page analysis system/` (PHP backend + HTML/JS frontend)
- **اللغة:** عربي RTL في الواجهة والتقارير
- **العميل النموذجي للاختبار:** `lead_id=309` → "حلمي للعسل اليمني" (helmehoney)

---

## 2. الـ Pull Requests السابقة (مدموجة)

### PR #57 — `fix(detailed-analysis): إصلاح 12 bug في تشريح التقرير` ✅ مدموج

**الملفات:**
- `Page analysis system/detailed-analysis.html`
- `Page analysis system/js/report-connect.js`

**الـ 12 bugs المُصلحة:**
| # | المشكلة | الحل |
|---|---------|------|
| 1 | Meta Title hardcoded | يقرأ من `wsData.title` مع تحقق طول 10-60 |
| 2 | قسم Twitter يفشل بصمت | رسالة خطأ نظيفة بدل crash |
| 3 | `metric-pages` يقرأ `pages_count` غير الموجود | يقرأ `pages_crawled` |
| 4 | `igLastPost` يخلط بين 0 و null | تمييز 0/1/null |
| 5 | falsy-zero في posts_per_week | عرض 0 = "لا ينشر" |
| 6 | igSaves يخلط `%` و عدد | تمييز `saves_rate` و `avg_saves` |
| 7 | 7 بطاقات معلقة بلا scraper | حذف من HTML |
| 8 | `badge-services` لا يقع على `all_services` | fallback أُضيف |
| 9 | progress bars className مباشر | `classList.remove/add` |
| 10 | `igHighlights` يخفي 0 | عرض 0 صريح |
| 11 | عدة عدادات تخلط 0/null | تمييز |
| 12 | كتلة `tt-likes` مكررة | حذف |

### PR #58 — `fix(ads-library): 6 bugs في مكتبة الإعلانات` ✅ مدموج

**Commits منفصلة:**
- `fix(ads): align ads actor input schema with selected actor` — اختيار `JJghSZmShuco4j9gJ` كـ primary
- `fix(ads): build ads library URL with view_all_page_id when given numeric ID` — حل `ID:12345` → URL صالح
- `fix(ads): pass facebook data through apifyFetchAdsEnhanced` — `$fbData` يُمرَّر صحيحاً
- `fix(ads): unify ad_active_status=ALL across scan paths` — توحيد فلتر الحالة
- `refactor(ads): centralize ad active status normalization` — `_normalizeAdActiveStatus()` helper
- `chore(ads): remove unused apifyFetchAds function` — حذف كود ميت

---

## 3. Apify Actors المُختارة (لا تغيّر بدون إذن صريح)

### Twitter (PR #57 — متابعة لاحقة في commit `e7081d8`)
- **Primary:** `nfp1fpt5gUlBwPcor`
  - schema: `searchTerms: ['from:username']`, `sort: 'Latest'`, `maxItems`
- **Fallback 1:** `apidojo/tweet-scraper`
- **Fallback 2:** `kaitoeasyapi/twitter-x-data-tweet-scraper-pay-per-result-cheapest`

### Ads Library (PR #58)
- **Primary:** `JJghSZmShuco4j9gJ`
  - schema: `startUrls[]` (camelCase), `resultsLimit`, `onlyTotal`, `includeAboutPage`, `isDetailsPerAd`, `activeStatus: ''`
  - يقبل: Page URL + Ads Library URL معاً
- **Fallback:** `OA5DWWrlPj3vhk8SV`
  - schema: `start_urls[]` (snake_case), `max_results_per_url`, `total_max_results`, `proxy_country`

### Competitors (لم يُصلَح بعد)
- **Actor:** `nWGjfqxH9vqmJN76s` (Google Search Scraper) — **سليم**، الإصلاحات مطلوبة في الكود فقط

### Instagram / Facebook / TikTok (موجودة في `config.example.php`)
- IG: `apify/instagram-profile-scraper`, `SbK00X0JYCPblD2wp` (comments), `apify/instagram-stories-scraper`
- FB: `apify/facebook-pages-scraper`, `us5srxAYnsrkgUv2v` (comments)
- TikTok: `clockworks/free-tiktok-scraper`

---

## 4. الملفات الحرجة (المعرفة بالكود)

| ملف | الدور |
|---|---|
| `Page analysis system/api/apify-scraper.php` | كل دوال scraping — `scrapeTwitter`, `scrapeAdsLibrary`, `scrapeCompetitorsViaGoogle`, `_parseAd`, `_normalizeAdActiveStatus`, `_parseTweet`, `_parseTwitterProfile` |
| `Page analysis system/api/analyze.php` | الموزّع الرئيسي — يستدعي كل scrapers ثم يجمّع `$scanResult` |
| `Page analysis system/api/page-scan.php` | الفحص الأولي — يكتشف الـ URL ويستدعي مسارات سريعة |
| `Page analysis system/api/ads-fetch.php` | force-refresh للإعلانات + AI deep analysis (OpenAI) — `apifyFetchAdsEnhanced`, `parseDeepReportToJson`, `buildFrontendPayload`, `callOpenAIDeepAnalysis` |
| `Page analysis system/api/ai-analyze.php` | تحليل AI شامل (Gemini agents) — يقرأ `ads_library.total_ads/active_ads/ads` |
| `Page analysis system/api/result.php` | يفك decode للـ `strengths`/`weaknesses` JSON strings |
| `Page analysis system/api/config.example.php` | كل المفاتيح والـ default actors |
| `Page analysis system/.env.example` | متغيرات البيئة |
| `Page analysis system/detailed-analysis.html` | صفحة تشريح التقرير الرئيسية |
| `Page analysis system/js/report-connect.js` | JS الواجهة — يقرأ JSON ويملأ HTML |

---

## 5. مبادئ العمل (الدروس المُستفادة)

### 🔴 لا تخمّن — اقرأ الكود الفعلي
المستخدم رفض تحليلاً مبنياً على افتراض من HTML/JSON ظاهري دون قراءة المصدر الفعلي:
> "تحليل دقيق ؟؟ فحصت كل شي قريت الملفات اكتشف الخطا فين"

**القاعدة:** قبل أي ادعاء عن bug، اقرأ:
1. الدالة المعنية في PHP
2. الاستدعاءات (`grep` للـ function name)
3. الـ schema الفعلي للمخرجات (من Apify Console أو DB)

### 🔴 لا تستخدم `git fetch origin main` للمقارنة في الـ sandbox
الـ auth يفشل (`Missing header field, please provide AuthToken`) → diff خاطئ يقول "4031 file added".

**البديل:** استخدم GitHub API مباشرة:
```
https://api.github.com/repos/abdofouad99/alabeer-hub/pulls/{n}
```
أو أعد clone عبر `mcp_sandbox_github_repo_set_up` بعد `rm -rf` للـ workspace.

### 🟡 fallback chains = حماية فقط
الـ fallback يحمي من:
- token منتهي
- Apify maintenance
- network timeouts

**لا يحمي من schema mismatch** لأن schema الـ primary يُؤخذ من Apify Console الرسمي → مستحيل أن يكون خاطئاً.

### 🟢 نمط commit واحد لكل bug
- رسالة بالعربي مع وصف تفصيلي
- صياغة `fix(scope): ...` أو `refactor(scope): ...` أو `chore(scope): ...`
- كل bug = commit مستقل لتسهيل revert إذا لزم

### 🟢 نمط FIND/REPLACE في الـ prompts للـ subagent_coder
الذي نجح مرتين:
- نص `FIND` كامل بـ whitespace صحيح
- نص `REPLACE WITH` كامل
- خطوات validation (`php -l`, `grep`)
- رسالة commit جاهزة

### 🟢 لا أوافق على دمج بدون "✅ approve" صريح
المستخدم يقول: "لم اقم بدمحه الا بعد تاكيدك" → الموافقة الصريحة شرط.

---

## 6. الـ schema الذي اكتشفناه (للرجوع السريع)

### Twitter `_parseTweet()` — مرن جداً
يتقبل:
- `text` / `fullText` / `full_text` / `content`
- `favoriteCount` / `likeCount` / `favouriteCount` / `favorites` / `likes`
- `retweetCount` / `retweets`
- `viewCount` / `views` / `impressionCount`
- `createdAt` / `created_at` / `date` / `timestamp`

### Ads `_parseAd()` — قد لا يلتقط schema الـ snapshot
**حالياً يبحث في:**
- `adCreativeBody`, `ad_creative_body`, `body`, `title`
- `snapshot.images[0].original_image_url`
- `startDate`, `start_date`, `ad_creation_time`

**قد لا يلتقط (مهمة قائمة):**
- `snapshot.body.text` (نمط Apify Meta Ad Library الشائع)
- `snapshot.title`
- `snapshot.cta_text`

---

## 7. المهام المتبقية / المعروفة

### 🟡 مفتوحة الآن
- **Ads analysis صفري رغم 37 إعلان مجلوب** — السبب الأرجح: `_parseAd()` لا يقرأ `snapshot.body.text` فيُرسل OpenAI سياق `"(بدون نص)"`.
  - **الإجراء التالي:** جمع عينة JSON واحدة من `JJghSZmShuco4j9gJ` لتحديد الـ schema بدقة، ثم تعديل `_parseAd()`.

### 🟡 لم يبدأ بعد
- **Competitors — 7 bugs** من تقرير المستخدم (التحقق منها تم وكلها حقيقية):
  - **COMP-B1 (حرج):** `$originalUrl` لا يُمرَّر في `analyze.php:994` و `page-scan.php:252` → الفلتر معطّل
  - **COMP-B2 (حرج):** `analyze.php:1048` يدوس على نتيجة page-scan بـ `null` بدون فحص
  - **COMP-B3 (مهم):** `enrichCompetitorsData` غير موصولة بمسار analyze (موصولة فقط في page-scan)
  - **COMP-B4 (مهم):** صياغة `"أهم منافسين"` تُرجع مقالات + `countryCode: 'SA'` ثابت
  - **COMP-B5 (متوسط):** لا retry عند نتائج < 3
  - **COMP-B6 (تنظيف):** `lightScanCompetitor` كود ميت
  - **COMP-B7 (متوسط):** `description` خام يتسرّب للـ AI كخصائص محققة
  - **القرار:** الأداة `nWGjfqxH9vqmJN76s` صحيحة، لا حاجة لاستبدال — الإصلاحات في الكود فقط

### 🟢 مكتملة
- ✅ Twitter integration (PR #57 commit `e7081d8`)
- ✅ Ads Library (PR #58)
- ✅ detailed-analysis.html bugs (PR #57 commit `490a078`)

---

## 8. إعدادات الـ Sandbox

- **Network mode:** `OPEN_INTERNET`
- **PHP:** 8.4 (استخدم `php -l <file>` للتحقق)
- **Node:** v22 (`node --check` للـ JS)
- **Git push:** يجب استخدام `mcp_sandbox_github_push_to_remote`، لا `git push` مباشر (auth يفشل)
- **Git fetch:** نفس المشكلة، استخدم `rm -rf` + `mcp_sandbox_github_repo_set_up` لتحديث الـ main المحلي

---

## 9. تنسيق التقارير المُفضَّل من المستخدم

(من المحادثة الأولى، طبّقه عند إعداد تقارير تشريح الواجهة)

### القسم 1: نص الصفحة الكامل المرئي (شجرة)
### القسم 2: مقارنة جدولية مع JSON (✓ ✗ ⚠️)
### القسم 3: كشف المشاكل
- أ. Hardcoded
- ب. حقول JSON غير معروضة
- ج. Falsy-Zero
- د. Schema Mismatch
- هـ. Placeholder الأبدية
- و. RTL/Bidi
- ز. XSS
### القسم 4: قوائم/جداول (متوقع vs ظاهر)
### القسم 5: لقطات بصرية حرجة
### القسم 6: ملخص تنفيذي 5 أسطر

**قواعد:** لا تخمين، لا اقتراح إصلاحات (في تقرير التشخيص)، نصوص حرفية، ✓ ✗ ⚠️ مرئية.

---

## 10. كيف تبدأ في جلسة جديدة

عندما يفتح المستخدم محادثة جديدة:
1. **اقرأ هذا الملف أولاً** قبل أي شيء آخر
2. **افهم أين توقفنا** من قسم 7 (المهام المتبقية)
3. **اسأل عن الـ context الجديد** المحدد للجلسة:
   - أي bug يريد البدء فيه؟
   - أي تقرير تشخيصي جديد؟
   - أي PR يحتاج مراجعة؟
4. **حافظ على نفس النمط** الذي نجح في PR #57 و #58
