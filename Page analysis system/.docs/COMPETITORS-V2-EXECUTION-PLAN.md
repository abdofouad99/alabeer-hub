# 🎯 تقرير تنفيذي شامل: نظام تحليل المنافسين v2

## معلومات أساسية

- **المستودع:** `abdofouad99/alabeer-hub`
- **المسار:** `Page analysis system/`
- **الفرع المقترح:** `feature/competitors-v2`
- **الفلسفة:** صفر بيانات وهمية. كل قيمة لها مصدر مرجعي. لو ناقصة → `null`.
- **الميزانية:** Apify > $200/شهر (Tier 3 الكامل مفعّل افتراضياً)
- **القاعدة الذهبية:** كل النظام مبني على قاعدة الإصلاحات السابقة (PR #58 الذي دمج 6 إصلاحات لمكتبة الإعلانات).

## هيكل التقرير

| Sprint | الملف | المحتوى |
|---|---|---|
| Sprint 1 | تم ✅ | الإصلاحات الأساسية (PR #58 + الإصلاحات السابقة) |
| Sprint 2 | `SPRINT-2-DISCOVERY.md` | اكتشاف المنافسين الحقيقيين |
| Sprint 3 | `SPRINT-3-ENRICHMENT.md` | سحب بيانات كل منافس بعمق |
| Sprint 4 | `SPRINT-4-AI-AND-UI.md` | تحليل AI + واجهة المستخدم |
| Sprint 5 | `SPRINT-5-ADS-DEEP-DIVE.md` | (لاحقاً) تحليل عميق لإعلانات منافس بضغطة زر |

---

## 📊 المعمارية العامة

```
[المدخلات]
  url العميل + اسم النشاط + targetAudience + audience description
        │
        ▼
┌─────────────────────────────────────────────────────────┐
│  STEP 0: Profile Detection (محلي - 0$)                  │
│  أوتوماتيكي: local | digital | hybrid                    │
└─────────────────────────────────────────────────────────┘
        │
        ▼
┌─────────────────────────────────────────────────────────┐
│  STEP 1: Discovery (متعدد المصادر)                       │
│  Google Maps + Google Search + Facebook Pages Search     │
└─────────────────────────────────────────────────────────┘
        │ (30-60 مرشح خام)
        ▼
┌─────────────────────────────────────────────────────────┐
│  STEP 2: Validation & Scoring (محلي - 0$)               │
│  ترتيب → أخذ أفضل 5 منافسين                              │
└─────────────────────────────────────────────────────────┘
        │ (5 منافسين)
        ▼
┌─────────────────────────────────────────────────────────┐
│  STEP 3: Cross-Platform Discovery لكل منافس              │
│  استخراج روابط FB/IG/TT/X من موقع كل منافس              │
└─────────────────────────────────────────────────────────┘
        │
        ▼
┌─────────────────────────────────────────────────────────┐
│  STEP 4: Enrichment (Tier 3)                            │
│  FB + IG + TT + X + Web + Reviews + Ads (مجاني)         │
└─────────────────────────────────────────────────────────┘
        │
        ▼
┌─────────────────────────────────────────────────────────┐
│  STEP 5: Per-Competitor AI Analysis (Anti-Hallucination)│
│  9 حقول لكل منافس + validation صارم للردود              │
└─────────────────────────────────────────────────────────┘
        │
        ▼
┌─────────────────────────────────────────────────────────┐
│  STEP 6: Market Summary                                  │
│  ترتيب العميل + متوسطات السوق + Blue Ocean              │
└─────────────────────────────────────────────────────────┘
        │
        ▼
   [حفظ في DB + عرض في competitors.html]
```

---

## 🛠 الأدوات المعتمدة (Apify Actors)

### Discovery
| الاسم | Actor ID | المصدر |
|---|---|---|
| Google Places Crawler | `LmLOOMYKuCUrYsda2` | جديد ⭐ |
| Google Search Scraper | `YNcgn7yiLc72ayYeB` | جديد |
| Google Search (بديل) | `V8SFJw3gKgULelpok` | احتياطي |
| Facebook Pages Search | `YAg3YuPbbASz7JzWG` | جديد |
| Facebook Pages Search (بديل) | `HBdQuY0Qwd2bDGM4a` | احتياطي |

### Enrichment (موجود في النظام)
| الدالة | Actor المعتمد | الموقع |
|---|---|---|
| `scrapeFacebook` | `apify/facebook-pages-scraper` | `apify-scraper.php:496` |
| `scrapeInstagram` | `apify/instagram-scraper` | `apify-scraper.php:1090` |
| `scrapeTikTok` | `clockworks/tiktok-scraper` | `apify-scraper.php:1452` |
| `scrapeTwitter` | `apidojo/tweet-scraper` | `apify-scraper.php:1853` |
| `scrapeWebsiteApify` | `apify/website-content-crawler` | `apify-scraper.php:2564` |
| Google Maps Reviews | `Xb8osYTtOjlsgI6k9` | جديد |

### Bonus
| الاسم | Actor ID |
|---|---|
| SEO Audit | `UFSUQD7pWNwN3jExC` |
| Web Scraper Puppeteer | `moJRLRc85AitArpNN` |

### Ads Library
- **مجاني (افتراضي):** يأتي ضمن `pageAdLibrary` من `scrapeFacebook` لكل منافس
- **عميق (Sprint 5):** زر اختياري لكل منافس → استخدام نفس `scrapeAdsLibrary` المُصلَح في PR #58

---

## ⚙️ متغيرات البيئة الجديدة

```env
# ═══════════════════════════════════════════════════════
# Competitor Analysis System v2
# ═══════════════════════════════════════════════════════

# --- Discovery ---
COMPETITOR_DISCOVERY_MODE=auto              # auto | local | digital | hybrid
COMPETITOR_MAX_CANDIDATES=30                # عدد المرشحين قبل الفلترة
COMPETITOR_TOP_N=5                          # عدد المنافسين النهائي
APIFY_ACTOR_GOOGLE_PLACES=LmLOOMYKuCUrYsda2
APIFY_ACTOR_GOOGLE_SEARCH=YNcgn7yiLc72ayYeB
APIFY_ACTOR_GOOGLE_SEARCH_FALLBACK=V8SFJw3gKgULelpok
APIFY_ACTOR_FB_PAGES_SEARCH=YAg3YuPbbASz7JzWG
APIFY_ACTOR_FB_PAGES_SEARCH_FALLBACK=HBdQuY0Qwd2bDGM4a
APIFY_ACTOR_GOOGLE_MAPS_REVIEWS=Xb8osYTtOjlsgI6k9

# --- Enrichment ---
COMPETITOR_ENRICH_TIER=3                    # 1=basic | 2=client-platform | 3=full
COMPETITOR_INCLUDE_REVIEWS=true             # سحب مراجعات Google
COMPETITOR_PARALLEL_ENRICH=true             # متوازي vs تتابعي
COMPETITOR_CACHE_HOURS=6                    # cache نتائج المنافس
COMPETITOR_MAX_REVIEWS_PER_COMP=20

# --- AI Analysis ---
COMPETITOR_AI_STRICT_MODE=true              # رفض ردود فيها أرقام مخترعة
COMPETITOR_AI_PROVIDER=openai               # openai | gemini
COMPETITOR_AI_MODEL=gpt-4o-mini
COMPETITOR_AI_TEMPERATURE=0.2               # منخفض = أقل خيال

# --- Quality Gates ---
COMPETITOR_MIN_VALIDATION_SCORE=40
COMPETITOR_REQUIRE_CATEGORY_MATCH=false     # متساهل لتغطية أكثر
COMPETITOR_RETRY_IF_LESS_THAN=3             # أعد المحاولة لو نتج < 3

# --- Phase 2: Deep Ads (Sprint 5) ---
COMPETITOR_DEEP_ADS_ENABLED=true
COMPETITOR_DEEP_ADS_MAX_PER_DAY=20          # حد يومي للحماية من استنفاد الحصة
```

---

## 📁 هيكل الملفات الجديد

```
Page analysis system/
├── api/
│   ├── competitors/                          ⭐ مجلد جديد كامل
│   │   ├── orchestrator.php                  المُوصِّل الرئيسي
│   │   ├── profile-detector.php              STEP 0
│   │   ├── discovery-google-places.php       STEP 1A
│   │   ├── discovery-google-search.php       STEP 1B
│   │   ├── discovery-fb-pages.php            STEP 1C
│   │   ├── candidates-merger.php             STEP 2
│   │   ├── cross-platform-discovery.php      STEP 3
│   │   ├── enrichment.php                    STEP 4
│   │   ├── google-reviews.php                STEP 4 (sub)
│   │   ├── ai-analysis.php                   STEP 5
│   │   ├── market-summary.php                STEP 6
│   │   ├── ai-validator.php                  Anti-hallucination
│   │   ├── cache.php                         Caching layer
│   │   └── helpers.php                       دوال مساعدة
│   │
│   ├── competitor-deep-ads.php               ⭐ Sprint 5: تحليل إعلانات منافس
│   ├── apify-scraper.php                     (موجود — لا تعديل)
│   ├── analyze.php                           (تعديل بسيط: استدعاء orchestrator)
│   ├── page-scan.php                         (تعديل بسيط: استدعاء orchestrator)
│   └── ai-analyze.php                        (تعديل: prompts)
│
├── competitors.html                          (تحديث UI كامل)
└── js/
    ├── report-connect.js                     (تعديل: عرض البيانات الجديدة)
    └── competitor-deep-ads.js                ⭐ Sprint 5: زر التحليل العميق
```

---

## 🚦 Quality Gates (نقاط التحقق)

### Gate 1 — بعد Discovery
- لو `count(validatedCompetitors) < COMPETITOR_RETRY_IF_LESS_THAN` → أعد المحاولة بصياغة بديلة
- لو لا يزال < 3 بعد المحاولة الثانية → سجّل warning وأكمل بما هو موجود

### Gate 2 — بعد Enrichment
- لكل منافس، احسب `data_completeness` (0-100%):
  - +20 لو followers موجود
  - +20 لو engagement_rate موجود
  - +15 لو posts/recent_activity
  - +15 لو website + tech_stack
  - +15 لو reviews/rating
  - +15 لو ads info
- لو < 30% → ضع `comp._warning = 'بيانات شحيحة'`

### Gate 3 — بعد AI Analysis
- استخدم `validateAIResponseAgainstData()`
- لو AI ذكر رقم لا يطابق البيانات الفعلية → ارفض الجزء وأعد الاستدعاء

---

## 🔒 ضمانات صفر بيانات وهمية

### 1. على مستوى الكود
```php
// ❌ ممنوع
'followers' => $data['followers'] ?? 0,    // 0 يبدو كرقم حقيقي
'rating'    => $data['rating']    ?? 'غير معروف',

// ✅ مطلوب
'followers' => $data['followers'] ?? null,
'rating'    => $data['rating']    ?? null,

// مع متاداتا المصدر
'_meta' => [
    'followers_source' => isset($data['followers']) ? 'instagram_apify' : null,
    'rating_source'    => isset($data['rating'])    ? 'google_maps'     : null,
    'fetched_at'       => date('c'),
]
```

### 2. على مستوى UI
```javascript
// ❌ ممنوع
`<span>المتابعون: ${comp.followers || 0}</span>`

// ✅ مطلوب
if (comp.followers !== null && comp.followers !== undefined) {
    html += `<span>المتابعون: ${formatNumber(comp.followers)}</span>`;
}
// لو null → أخفِ السطر بالكامل، لا تكتب "0" أو "غير متوفر"
```

### 3. على مستوى AI
```
قواعد صارمة في الـ prompt:
1. ممنوع اختراع أرقام لم ترد في البيانات
2. لو حقل = null → اكتب null في إجابتك
3. كل ادعاء يجب أن يستند على رقم محدد
4. ممنوع كلمات: "غالباً، يبدو، ربما، عادة"
5. ممنوع قوالب: "وجود قوي"، "خدمة بطيئة" بدون أرقام
6. لو بيانات ناقصة → اكتب reason في "data_gaps"
```

---

## 🧪 خطة الاختبار النهائية

### مرحلة 1: اختبارات الوحدة (Unit)
- اختبار `detectClientProfile` بـ 5 سيناريوهات (مطعم، تطبيق، عيادة، B2B، Hybrid)
- اختبار scoring algorithm بـ 10 مرشحين معروفين
- اختبار `validateAIResponseAgainstData` بـ ردود فيها hallucinations

### مرحلة 2: اختبارات حقيقية (Live)
- 3 عملاء حقيقيين من DB:
  - مطعم في الرياض → توقع: 5 مطاعم منافسة جغرافياً
  - تطبيق رقمي → توقع: 5 تطبيقات بنفس الفئة
  - عيادة → توقع: 5 عيادات في نفس المنطقة

### مرحلة 3: اختبارات regression
- تأكد أن `scrapeFacebook/Instagram/TikTok/Twitter` للعميل لم تتأثر
- تأكد أن مكتبة الإعلانات للعميل ما زالت تعمل (PR #58)
- تأكد أن `report.html` و `competitors.html` يعرضان البيانات

### مرحلة 4: مراقبة التكلفة
```bash
# مراقبة استهلاك Apify خلال أول 24 ساعة
tail -f logs/apify-usage.log
# يجب: متوسط ~$0.50 لكل فحص عميل
```

---

## 📋 الفهرس السريع للـ Sprints

اقرأ كل Sprint بالترتيب:

1. **`SPRINT-2-DISCOVERY.md`** — اكتشاف 5 منافسين حقيقيين
2. **`SPRINT-3-ENRICHMENT.md`** — سحب بيانات كل منافس
3. **`SPRINT-4-AI-AND-UI.md`** — تحليل AI + واجهة المستخدم
4. **`SPRINT-5-ADS-DEEP-DIVE.md`** — زر تحليل إعلانات منافس عميق

كل sprint مستقل ومُختبر بشكل منفصل، مع PR منفصل، لكن كلها تبني على بعض.

---

## ✅ معايير القبول النهائية

عند اكتمال كل Sprints:

1. ✅ نظام يكتشف 5 منافسين حقيقيين 100% (لا مدوّنات / يوتيوب / موقع العميل نفسه)
2. ✅ كل منافس له بيانات كمية: متابعون، تفاعل، نشر، إعلانات، مراجعات
3. ✅ كل منافس له تحليل AI من 9 حقول مبني على أرقامه الفعلية
4. ✅ ملخص سوقي يظهر ترتيب العميل ومتوسطات السوق
5. ✅ زر "تحليل عميق لإعلانات منافس" يعمل بضغطة واحدة
6. ✅ صفر "غير متوفر" أو "0" مزيف في UI
7. ✅ تكلفة Apify ≤ $0.50 لكل فحص عميل (Tier 3)
8. ✅ كل البيانات قابلة للتدقيق (`_meta` لكل حقل)

