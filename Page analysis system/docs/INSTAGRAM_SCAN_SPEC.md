# 📸 Instagram Scan Specification (V3)

> الوثيقة المرجعية الكاملة لما يسحبه ويحلله نظام فحص Instagram بعد ترقية V3.

---

## 1) معمارية السحب

```
رابط Instagram (أو username) ← scanInstagramPublic($url, $cfg)
        │
        ├─ enable_apify=true ────► scrapeInstagram()  [apify-scraper.php]
        │       │
        │       ├─ Apify actor: apify/instagram-scraper (الافتراضي)
        │       ├─ يدعم أيضاً: apify~instagram-profile-scraper, shu8hvrXbJbY3Eb9W
        │       │
        │       ├─ نموذج البروفايل + 100 منشور
        │       │
        │       ├─ instagram-deep.php (طبقة التحليل العميق)
        │       │   ├─ extractHashtagsFromPosts
        │       │   ├─ extractMentionsFromPosts
        │       │   ├─ calcContentTypeDistribution
        │       │   ├─ calcPostingHeatmap (Asia/Riyadh)
        │       │   ├─ analyzeBioOptimization (0-100 + grade A-F)
        │       │   ├─ calcAccountHealthScore (0-100 + grade A-F)
        │       │   ├─ detectPostsLanguageMix
        │       │   ├─ extractTopLocations
        │       │   ├─ calcSponsoredRatio
        │       │   └─ analyzeReelsPerformance
        │       │
        │       ├─ enable_ig_comments=true ─► analyzeIGCommentsSentiment
        │       │       ├─ Apify actor SbK00X0JYCPblD2wp (تعليقات أفضل 5 منشورات)
        │       │       ├─ Heuristic AR/EN classifier
        │       │       └─ enable_ig_sentiment_ai=true ► OpenAI gpt-4o-mini
        │       │
        │       ├─ enable_ig_vision=true ───► analyzeIGImagesVision
        │       │       └─ OpenAI gpt-4o-mini Vision على أفضل 5 صور
        │       │           (description, tags, ocr_text, has_logo/price/offer)
        │       │
        │       └─ enable_ig_stories=true ──► scrapeIGStoriesAndHighlights
        │               └─ Actor: apify/instagram-stories-scraper
        │
        ├─ Public web_profile_info API (بدون توكن) ─► نموذج مبسّط
        │       └─ analyzeBioOptimization + calcAccountHealthScore يعملان عليه
        │
        └─ HTML regex fallback ─► نموذج موحّد (نفس المفاتيح، قيم null)
```

---

## 2) الحقول التي يُرجعها `scanInstagramPublic`

> النموذج موحّد. كل المفاتيح موجودة دائماً (قد تكون `null` في المسار العام)
> لتسهيل الواجهة الأمامية بدون فحوص شرطية.

### 2.1 Identity

| المفتاح | النوع | الوصف |
|---------|-------|-------|
| `success` | bool | نجاح السحب |
| `source` | string | `apify_ig_v3` / `web_profile_info` / `html_regex` |
| `platform` | string | دائماً `instagram` |
| `id` | string | Instagram user ID |
| `username` | string | اليوزر |
| `full_name` | string | الاسم المعروض |
| `profile_url` | string | رابط البروفايل |
| `profile_pic` | string | صورة البروفايل |
| `profile_pic_hd` | string | صورة البروفايل HD |

### 2.2 Profile Data

| المفتاح | النوع | الوصف |
|---------|-------|-------|
| `bio` | string | نص البايو الكامل |
| `bio_length` | int | طول البايو بالأحرف |
| `website` | string | الرابط الخارجي |
| `has_link` | bool | يوجد رابط في البايو |
| `followers` | int | عدد المتابعين |
| `following` | int | عدد المتابَعين |
| `posts_count` | int | إجمالي المنشورات |
| `highlight_reel_count` | int | عدد Highlights |
| `highlights` | int | alias لـ highlight_reel_count |
| `is_verified` | bool | موثق ✓ |
| `is_business` | bool | حساب أعمال |
| `business_category` | string | اسم تصنيف النشاط |
| `private` | bool | حساب خاص |
| `joined_recently` | bool | حساب جديد |
| `related_profiles` | array | حسابات مشابهة (تقترحها انستجرام) — V3 فقط |

### 2.3 Engagement Aggregates

| المفتاح | الوصف |
|---------|-------|
| `avg_likes` / `avg_comments` / `avg_saves` | متوسط من كل المنشورات |
| `avg_video_views` / `avg_video_plays` | متوسط مشاهدات الفيديو |
| `reels_count` / `has_reels` | عدد Reels |
| `engagement_rate` | نسبة التفاعل (% للمتابعين) |
| `followers_following_ratio` | نسبة المتابعين/المتابَعين |
| `posts_per_week` / `last_post_days` | نشاط النشر |

### 2.4 Top performers

| المفتاح | الوصف |
|---------|-------|
| `top_post` | المنشور الأعلى تفاعلاً (object) |
| `top_5_posts` | أعلى 5 منشورات تفاعلاً (مع كل تفاصيل المنشور) |

### 2.5 Deep Content Analytics (V3 فقط)

| المفتاح | الشكل |
|---------|-------|
| `hashtags_analysis` | `{ unique_count, total_uses, top: [{tag, count}, ...] }` |
| `mentions_analysis` | `{ unique_mentions, top_mentions, unique_tagged, top_tagged }` |
| `content_distribution` | `{ counts, percent: {image, carousel, video, reel}, avg_carousel_slides }` |
| `posting_heatmap` | `{ grid_engagement[day][hour], best_day, best_hour, timezone }` |
| `language_mix` | `{ arabic_pct, english_pct, mixed_pct, dominant }` |
| `locations` | `{ unique_locations, top: [{name, count}] }` |
| `sponsored_ratio` | `{ sponsored_count, percent }` |
| `reels_performance` | `{ count, avg_plays, avg_views, avg_duration_sec, engagement_per_play }` |
| `bio_optimization` | `{ score, grade, has_link, has_cta, has_phone, has_email, has_whatsapp, has_emoji, strengths, issues }` |
| `account_health` | `{ score, grade, strengths, issues }` |

### 2.6 Optional Layers

| المفتاح | الشرط |
|---------|-------|
| `comments_sentiment` | `enable_ig_comments=true` |
| `vision_analysis` | `enable_ig_vision=true` + `OPENAI_KEY` |
| `stories_data` | `enable_ig_stories=true` |

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
    "overall": "positive",
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

### 2.7 Per-Post Schema (داخل `top_5_posts` و `latest_posts`)

```json
{
  "id": "...",
  "shortCode": "...",
  "url": "https://www.instagram.com/p/.../",
  "type": "reel|video|sidecar|image",
  "isReel": false,
  "caption": "...",
  "hashtags": ["..."],
  "mentions": ["..."],
  "likesCount": 0,
  "commentsCount": 0,
  "savesCount": 0,
  "videoViewCount": 0,
  "videoPlayCount": 0,
  "videoDuration": 30,
  "videoUrl": "...",
  "displayUrl": "...",
  "images": ["...", "..."],
  "alt": "Image may contain: ...",
  "locationName": "...",
  "locationId": "...",
  "isSponsored": false,
  "taggedUsers": [{ "username": "...", "full_name": "..." }],
  "latestComments": [{ "text": "...", "ownerUsername": "...", "likesCount": 0 }],
  "timestamp": "2025-05-15T10:00:00Z",
  "ownerUsername": "..."
}
```

---

## 3) إعدادات التشغيل

### `.env` (أو متغيرات بيئة)
```bash
ENABLE_APIFY=true                 # يفعل Apify scrapers (مطلوب لـ V3)
APIFY_TOKENS=xxx,yyy              # توكنات Apify (rotation)
APIFY_ACTOR_IG=apify/instagram-scraper

# Instagram Deep
ENABLE_IG_COMMENTS=true           # تعليقات + sentiment
ENABLE_IG_VISION=false            # Vision AI (يكلف OpenAI)
ENABLE_IG_STORIES=false           # Stories + Highlights
ENABLE_IG_SENTIMENT_AI=true       # OpenAI overlay على المشاعر
IG_COMMENTS_TOP_POSTS=5
IG_VISION_TOP_IMAGES=5

APIFY_ACTOR_IG_COMMENTS=SbK00X0JYCPblD2wp
APIFY_ACTOR_IG_STORIES=apify/instagram-stories-scraper

OPENAI_KEY=sk-xxxx                # مطلوب لـ Vision + Sentiment AI
OPENAI_MODEL=gpt-4o-mini
```

### تكاليف تقريبية لكل فحص حساب

| المكوّن | التكلفة |
|--------|----------|
| Apify Instagram Scraper (100 منشور) | ~$0.005-0.02 |
| Apify Comments Actor (5 منشورات × 30 تعليق) | ~$0.005-0.01 |
| Apify Stories Actor (اختياري) | ~$0.005 |
| OpenAI Sentiment overlay (gpt-4o-mini) | ~$0.001 |
| OpenAI Vision (5 صور) | ~$0.01-0.03 |

**إجمالي للفحص الكامل (مع كل الميزات):** ~$0.02-0.07 لكل حساب.

---

## 4) الواجهة الأمامية

القسم `#igDeepInsight` في `report.html` يعرض كل البيانات أعلاه. الملف
`js/ig-deep-insight.js` يستمع لحدث `reportDataReady` ثم يملأ:

- 8 KPI cards
- Account Health gauge + Bio Optimization gauge
- Content Distribution bars
- Posting Heatmap (7×24 ملوّنة)
- Hashtags Cloud
- Mentions / Tagged / Locations rankings
- Language Mix bars
- Top 5 Posts gallery (كل واحد قابل للنقر)
- Reels Performance KPIs
- Sentiment bar + Objections/Praise/Questions + AI summary
- Vision AI grid (مع OCR وعلامات)
- Highlights grid
- Related Profiles grid

كل قسم يظهر بشرط توفر البيانات، فلا تظهر بطاقات فارغة.

---

## 5) الاختبار

```bash
php "Page analysis system/tests/instagram-deep.test.php"
```

**33 اختبار يجب أن تنجح كلها** بدون الحاجة إلى توكن Apify أو شبكة:
- Hashtags / Mentions extraction
- Content distribution / Heatmap
- Bio score / Account health
- Language mix / Locations / Sponsored ratio
- Reels performance
- Heuristic sentiment

---

## 6) ما الذي تغيّر مقارنة بالنسخة السابقة

### V2 (السابقة)
- يسحب 100 منشور لكن يستخدم `owner*` فقط من أول منشور
- 18 حقل output
- لا hashtags analysis منفصل
- لا heatmap
- لا bio score
- لا account health
- لا sentiment
- لا vision
- لا stories
- يهمل: `id`, `businessCategoryName`, `joinedRecently`, `relatedProfiles`,
  `taggedUsers`, `isSponsored`, `latestComments`, `alt`, `locationName`,
  `videoPlayCount`, `videoUrl`, `images[]`

### V3 (الحالية)
- يسحب نفس 100 منشور
- **50+ حقل output**
- 12 طبقة تحليل عميقة (hashtags, mentions, distribution, heatmap, bio,
  health, language, locations, sponsored, reels, sentiment, vision)
- يستخرج كل الحقول الغنية (id, taggedUsers, latestComments, alt, ...)
- مرونة في schemas Apify المختلفة
- مسار عام (web_profile_info) أيضاً يعطي bio_optimization و account_health
- اختبار شامل (33 test cases)
