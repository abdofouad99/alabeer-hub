# 📘 Facebook Scan Specification (V3)

> الوثيقة المرجعية الكاملة لما يسحبه ويحلله نظام فحص Facebook بعد ترقية V3.
> هذا النظام مكافئ تماماً لـ Instagram V3 من حيث العمق والذكاء.

---

## 1) معمارية السحب

```
رابط Facebook (أو slug) ← scanFacebookPublic($url, $cfg)
        │
        ├─ enable_apify=true ────► scrapeFacebook()  [apify-scraper.php]
        │       │
        │       ├─ Apify actor: apify/facebook-pages-scraper (افتراضي)
        │       │   مع تفعيل: scrapeAbout, scrapeReviews, scrapeServices, scrapePosts
        │       │
        │       ├─ نموذج الصفحة + 50 منشور (قابل للتعديل عبر FB_MAX_POSTS)
        │       │
        │       ├─ facebook-deep.php (طبقة التحليل العميق)
        │       │   ├─ extractFBHashtagsFromPosts        — top 20 + counts
        │       │   ├─ extractFBMentionsAnalysis         — mentions + tagged pages
        │       │   ├─ calcFBContentDistribution         — types_percent + album avg
        │       │   ├─ calcFBPostingHeatmap (Asia/Riyadh) — 7×24 + best day/hour
        │       │   ├─ analyzeFBPageOptimization        — 0-100 + grade A-F
        │       │   ├─ calcFBPageHealthScore            — 0-100 + grade A-F
        │       │   ├─ detectFBPostsLanguageMix         — arabic/english/mixed
        │       │   ├─ extractFBLocations               — check-ins
        │       │   └─ calcFBSponsoredRatio             — paid partnerships %
        │       │
        │       ├─ enable_fb_comments=true ─► analyzeFBCommentsSentiment
        │       │       ├─ Apify actor us5srxAYnsrkgUv2v (تعليقات أفضل 5 منشورات)
        │       │       ├─ Heuristic AR/EN classifier
        │       │       └─ enable_fb_sentiment_ai=true ► OpenAI gpt-4o-mini
        │       │
        │       └─ enable_fb_vision=true ───► analyzeFBImagesVision
        │               └─ OpenAI gpt-4o-mini Vision على أفضل 5 صور
        │                   (description, tags, ocr_text, has_logo/price/offer)
        │
        ├─ Facebook Graph API (إذا توفّر FACEBOOK_ACCESS_TOKEN)
        │       └─ نموذج بسيط: name, fan_count, category, verification_status...
        │
        └─ Public Scraping (cURL + m.facebook.com) — fallback أخير
```

---

## 2) الحقول التي يُرجعها `scrapeFacebook` (V3)

### 2.1 Identity (8 حقل)

| المفتاح | النوع | الوصف |
|---------|-------|-------|
| `success` | bool | نجاح السحب |
| `source` | string | `apify` / `graph_api` / `public_scrape` |
| `platform` | string | دائماً `facebook` |
| `fb_version` | string | `v3` (علامة الإصدار) |
| `page_id` | string | Facebook Page ID |
| `page_name` | string | اسم الصفحة |
| `url` | string | رابط الصفحة |
| `creation_date` | string | تاريخ إنشاء الصفحة |

### 2.2 Profile Data (12 حقل)

| المفتاح | الوصف |
|---------|-------|
| `category` | تصنيف النشاط (مطعم، متجر، خدمات...) |
| `description` / `about` | نص About الكامل |
| `is_verified` | علامة التوثيق ✓ |
| `address` | العنوان (مطبّع من object إلى string) |
| `phone`, `whatsapp`, `email`, `website` | قنوات التواصل |
| `profile_pic`, `cover_photo` | الصور |
| `instagram_url` | رابط IG المرتبط |
| `rating`, `ratings_count` | متوسط التقييم وعدد المراجعات |

### 2.3 Engagement Aggregates (8 حقل)

| المفتاح | الوصف |
|---------|-------|
| `followers`, `likes` | عدد المتابعين والإعجابات |
| `posts_count` | عدد المنشورات المسحوبة |
| `avg_likes`, `avg_comments`, `avg_shares` | متوسطات حقيقية لكل منشور |
| `avg_video_views` | متوسط مشاهدات الفيديو |
| `avg_engagement` | (likes + comments + shares) / posts |
| `engagement_rate` | (avg_likes + avg_comments + avg_shares) / followers × 100 |
| `posts_per_week`, `last_post_days` | معدل النشاط |

### 2.4 Reactions Breakdown ⭐

`reactions_breakdown` (object): توزيع تفصيلي لكل المنشورات
```json
{ "like": 1240, "love": 320, "haha": 85, "wow": 22, "sad": 5, "angry": 8, "care": 14 }
```

### 2.5 Top Performers (2 حقل)

| المفتاح | الوصف |
|---------|-------|
| `top_post` | المنشور الأعلى تفاعلاً (object) |
| `top_5_posts` | أعلى 5 منشورات تفاعلاً (مع url للوصول لاحقاً للتعليقات) |

### 2.6 Deep Content Analytics (V3 — 9 طبقات) 🎯

| المفتاح | الشكل |
|---------|-------|
| `hashtags_analysis` | `{ unique_count, total_uses, top: [{tag, count}, ...] }` |
| `mentions_analysis` | `{ unique_mentions, top_mentions, unique_tagged, top_tagged }` |
| `content_distribution` | `{ counts, percent: {photo, video, reel, album, link, status, event, live}, avg_album_photos }` |
| `posting_heatmap` | `{ grid_engagement[day][hour], best_day, best_hour, timezone, hour_totals, day_totals }` |
| `language_mix` | `{ arabic_pct, english_pct, mixed_pct, dominant }` |
| `locations` | `{ unique_locations, top: [{name, count}] }` (check-ins) |
| `sponsored_ratio` | `{ sponsored_count, percent }` |
| `page_optimization` | `{ score, grade, has_phone, has_whatsapp, has_email, has_website, has_address, has_hours, has_services, has_cta, is_verified, strengths, issues }` |
| `page_health` | `{ score, grade, strengths, issues }` |

### 2.7 Reviews & Services & Hours

| المفتاح | الشكل |
|---------|-------|
| `reviews` | array of `{rating, text, author, date}` (max 20) |
| `reviews_summary` | `{count, avg_rating, distribution{1..5}, positive[], negative[]}` |
| `services` | array of `{name, description, price}` |
| `opening_hours` | `{monday: "09:00-22:00", tuesday: "...", ...}` |

### 2.8 Optional Layers

| المفتاح | الشرط |
|---------|-------|
| `comments_sentiment` | `enable_fb_comments=true` (افتراضياً مفعّل) |
| `vision_analysis` | `enable_fb_vision=true` + `OPENAI_KEY` |

#### `comments_sentiment` schema
```json
{
  "success": true,
  "total_comments": 87,
  "posts_sampled": 5,
  "positive_pct": 64,
  "negative_pct": 12,
  "neutral_pct": 24,
  "questions_pct": 18,
  "top_objections": ["..."],
  "top_praise": ["..."],
  "top_questions": ["..."],
  "samples": ["..."],
  "response_rate": 14.5,
  "ai_summary": {
    "overall": "positive|mixed|negative|neutral",
    "main_objections": ["..."],
    "main_praise": ["..."],
    "recommendations": ["..."]
  }
}
```

#### `vision_analysis` schema
```json
{
  "success": true,
  "analyzed_count": 5,
  "images": [{
    "image_url": "...",
    "description": "...",
    "tags": ["food","menu","restaurant"],
    "ocr_text": "نص داخل الصورة",
    "language": "arabic",
    "has_logo": true,
    "has_price": false,
    "has_offer": true,
    "image_quality": "high",
    "branding_consistency": "strong"
  }],
  "top_tags": ["food","..."],
  "logos_present": 4,
  "prices_present": 1,
  "offers_present": 3,
  "quality_distribution": {"high": 4, "medium": 1}
}
```

### 2.9 Deep Analysis (legacy compat)

`deep_analysis` يحتوي على المقاييس القديمة + الجديدة:
- `posts_analyzed`, `types_percent`, `top_hashtags`, `cta_percent`, `top_5_posts`
- `avg_shares`, `avg_video_views`, `reactions_total`, `best_hours`, `best_days`

---

## 3) إعدادات التشغيل

### `.env`
```bash
ENABLE_APIFY=true                 # مطلوب لـ V3
APIFY_TOKENS=xxx,yyy              # توكنات Apify (rotation)
APIFY_ACTOR_FB=apify/facebook-pages-scraper

# Facebook Deep
ENABLE_FB_COMMENTS=true           # تعليقات + sentiment
ENABLE_FB_VISION=false            # Vision AI (يكلف OpenAI)
ENABLE_FB_SENTIMENT_AI=true       # OpenAI overlay على المشاعر
FB_COMMENTS_TOP_POSTS=5
FB_VISION_TOP_IMAGES=5
FB_MAX_POSTS=50                   # عدد منشورات Facebook المسحوبة

APIFY_ACTOR_FB_COMMENTS=us5srxAYnsrkgUv2v

OPENAI_KEY=sk-xxxx                # مطلوب لـ Vision + Sentiment AI
OPENAI_MODEL=gpt-4o-mini
```

### تكاليف تقريبية لكل فحص صفحة

| المكوّن | التكلفة |
|--------|----------|
| Apify Facebook Scraper (50 منشور + reviews + services) | ~$0.005-0.02 |
| Apify FB Comments Actor (5 منشورات × 30 تعليق) | ~$0.005-0.01 |
| OpenAI Sentiment overlay (gpt-4o-mini) | ~$0.001 |
| OpenAI Vision (5 صور) | ~$0.01-0.03 |

**إجمالي للفحص الكامل (مع كل الميزات):** ~$0.02-0.07 لكل صفحة.

---

## 4) الواجهة الأمامية

القسم `#fbDeepInsight` في `report.html` يعرض كل البيانات.
الملف `js/fb-deep-insight.js` يستمع لحدث `reportDataReady` ثم يملأ:

- **8 KPI cards** (avg_likes, avg_comments, avg_shares, avg_video_views, posts_per_week, last_post_days, engagement_rate, reviews_summary)
- **Page Health gauge** (0-100 + grade) مع strengths/issues
- **Page Optimization gauge** (0-100 + grade) مع 12 flag
- **Content Distribution bars** (8 أنواع: photo/video/reel/album/link/live/status/event)
- **Posting Heatmap** (7×24 ملوّنة بالأزرق)
- **Reactions Breakdown** (👍❤️😂😮😢😠🤗 مع شرائط نسبية)
- **Hashtags Cloud** (top 20)
- **Mentions / Tagged Pages / Locations** rankings
- **Language Mix bars**
- **Top 5 Posts gallery** (قابلة للنقر)
- **Reviews summary** (نجوم + توزيع + إيجابي/سلبي)
- **Services grid** (الاسم + الوصف + السعر)
- **Opening Hours list**
- **Sentiment bar + Objections/Praise/Questions + AI summary**
- **Vision AI grid** (مع OCR)

كل قسم يظهر بشرط توفر البيانات، فلا تظهر بطاقات فارغة.

---

## 5) الاختبار

```bash
php "Page analysis system/tests/facebook-deep.test.php"
```

**44 اختبار يجب أن تنجح كلها** بدون الحاجة إلى توكن Apify أو شبكة:
- Hashtags / Mentions extraction
- Content distribution / Heatmap
- Page optimization (full + empty)
- Page health (healthy + empty)
- Language mix / Locations / Sponsored ratio
- Heuristic sentiment
- Edge cases (empty arrays)

---

## 6) ما الذي تغيّر مقارنة بالنسخة السابقة

### V2 (السابقة)
- ~25 حقل
- 1 طبقة تحليل (analyzeDeepContent — مشتركة)
- Reactions breakdown ✅
- Reviews + summary ✅
- Services + Hours ✅
- بدون: Hashtags Analysis منفصل، Mentions، Heatmap كامل، Page Optimization Score، Page Health Score، Language Mix، Locations، Sponsored Ratio، Comments Sentiment تلقائي، Vision AI

### V3 (الحالية) ⭐
- **45+ حقل**
- **9 طبقات تحليل عميقة جديدة** + 2 اختياريتان (Sentiment/Vision)
- يحافظ على كل ميزات V2 (Reactions, Reviews, Services, Hours)
- **مكافئ تماماً لـ Instagram V3** من حيث العمق
- اختبار شامل (44 test cases)
- توثيق كامل

---

## 7) المقارنة مع Instagram V3

| الميزة | 📘 Facebook V3 | 📸 Instagram V3 |
|--------|:---:|:---:|
| Profile/Page data | ✅ | ✅ |
| Hashtags Analysis (top 20) | ✅ | ✅ |
| Mentions + Tagged | ✅ | ✅ |
| Content Distribution | ✅ (8 أنواع) | ✅ (4 أنواع) |
| Posting Heatmap (7×24) | ✅ | ✅ |
| Optimization Score (0-100) | ✅ Page Opt | ✅ Bio Opt |
| Health Score (0-100) | ✅ Page Health | ✅ Account Health |
| Language Mix | ✅ | ✅ |
| Locations | ✅ Check-ins | ✅ Tags |
| Sponsored Ratio | ✅ | ✅ |
| Comments Sentiment | ✅ | ✅ |
| Vision AI | ✅ | ✅ |
| **Reactions Breakdown** | ✅ (7 أنواع) | ❌ (IG لا تدعم) |
| **Reviews + Summary** | ✅ | ❌ |
| **Services / Products** | ✅ | ❌ |
| **Opening Hours** | ✅ | ❌ |
| Stories / Highlights | ❌ (FB لا تدعم) | ✅ |
| Reels Performance | ✅ ضمن content_dist | ✅ منفصل |
| Related Profiles | ❌ | ✅ |

النتيجة: **Facebook V3 يفوق Instagram V3 في 4 ميزات** (Reactions, Reviews, Services, Hours) ويتساوى معه في 11 ميزة أخرى. كلاهما الآن في مستوى احترافي متكامل.
