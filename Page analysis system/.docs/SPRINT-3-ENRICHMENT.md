# 📥 Sprint 3: Enrichment — سحب بيانات كل منافس

## الهدف
لكل منافس من الـ 5، نسحب بياناته الكاملة من المنصات المتاحة باستخدام **الـ scrapers الموجودة** في النظام (FB, IG, TT, X, Web) + Google Reviews.

## المخرجات النهائية
- `api/competitors/cross-platform-discovery.php` — استخراج روابط منصات المنافس
- `api/competitors/enrichment.php` — سحب بيانات كل منصة
- `api/competitors/google-reviews.php` — سحب مراجعات Google
- `api/competitors/cache.php` — caching layer
- `api/competitors/helpers.php` — دوال مساعدة
- تعديل `orchestrator.php` ليشغّل Enrichment

## مدة العمل المتوقعة
4-5 أيام

---

# 📁 الملفات الجديدة

## 1. `api/competitors/cache.php`

### الوظيفة
caching layer لتجنب سحب نفس URL مرتين خلال 6 ساعات.

### الكود الكامل

```php
<?php
/**
 * Competitor Cache Layer
 * يخزّن نتائج enrichment لمدة قابلة للتحكم لتقليل تكلفة Apify
 */

declare(strict_types=1);

class CompetitorCache {
    private string $cacheDir;
    private int    $ttlSeconds;

    public function __construct(array $cfg) {
        $this->cacheDir   = sys_get_temp_dir() . '/alabeer_competitor_cache';
        $this->ttlSeconds = (int)($cfg['analysis']['competitor_cache_hours'] ?? 6) * 3600;

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * @param string $key URL أو معرّف فريد
     * @return array|null البيانات المخزّنة أو null لو منتهية
     */
    public function get(string $key): ?array {
        $file = $this->getFilePath($key);
        if (!file_exists($file)) return null;

        // فحص TTL
        $mtime = @filemtime($file);
        if ($mtime === false || (time() - $mtime) > $this->ttlSeconds) {
            @unlink($file);
            return null;
        }

        $content = @file_get_contents($file);
        if ($content === false) return null;

        $data = json_decode($content, true);
        if (!is_array($data)) return null;

        return $data;
    }

    public function set(string $key, array $data): bool {
        $file = $this->getFilePath($key);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return @file_put_contents($file, $json) !== false;
    }

    public function delete(string $key): bool {
        $file = $this->getFilePath($key);
        return @unlink($file);
    }

    public function clear(): int {
        $deleted = 0;
        $files = glob($this->cacheDir . '/*.json');
        if (is_array($files)) {
            foreach ($files as $f) {
                if (@unlink($f)) $deleted++;
            }
        }
        return $deleted;
    }

    private function getFilePath(string $key): string {
        // hash للأمان + قابلية للقراءة
        $safe = preg_replace('/[^a-z0-9]/i', '_', mb_substr($key, 0, 30));
        $hash = substr(md5($key), 0, 12);
        return $this->cacheDir . '/' . $safe . '_' . $hash . '.json';
    }
}
```

---

## 2. `api/competitors/cross-platform-discovery.php`

### الوظيفة
لكل منافس، استخرج روابط FB/IG/TT/X من موقعه (cURL محلي، 0 Apify).

### الكود الكامل

```php
<?php
/**
 * Cross-Platform Discovery for Competitors
 * يستخرج روابط FB/IG/TT/X من موقع المنافس عبر cURL محلي
 *
 * المنطق مشابه لـ runPageScan لكن بدون استدعاء Apify
 */

declare(strict_types=1);

require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/helpers.php';

/**
 * @param array $competitor المنافس من Discovery
 * @return array منافس مُحدَّث مع:
 *   - social.facebook (إن لم يكن موجود)
 *   - social.instagram
 *   - social.tiktok
 *   - social.twitter
 *   - website_html (cached)
 *   - has_ssl, has_pixel, has_ga, has_cta (سريع)
 */
function discoverCompetitorSocialLinks(array $competitor, array $cfg): array {
    // ── 1. لو الـ social موجود من Google Places، نُكمّل فقط الناقص ──
    $existingSocial = $competitor['social'] ?? [];

    // ── 2. اجلب website (لو موجود) ──
    $website = $competitor['website'] ?? '';
    if (empty($website)) {
        // محاولة استنباط الموقع من Facebook page (لو متوفر)
        $fbUrl = $existingSocial['facebook'] ?? '';
        if (empty($fbUrl)) {
            // لا موقع ولا FB — ما يمكننا فعل شيء
            return $competitor;
        }
        // سنحاول استخراج الموقع من FB في step منفصل (Sprint 4)
    }

    if (empty($website)) return $competitor;

    // ── 3. cURL سريع ──
    $html = _fetchHtmlForCompetitor($website);
    if (empty($html)) {
        $competitor['_warnings'][] = 'تعذر جلب موقع المنافس';
        return $competitor;
    }

    // ── 4. استخراج روابط social ──
    $extracted = _extractSocialFromHtml($html, $website);

    // دمج مع الموجود (existing له الأولوية لأنه أدق)
    $merged = $existingSocial;
    foreach ($extracted as $platform => $url) {
        if (empty($merged[$platform])) {
            $merged[$platform] = $url;
        }
    }
    $competitor['social'] = $merged;

    // ── 5. تحليلات سريعة (0 Apify) ──
    $competitor['quick_analysis'] = _quickAnalyzeWebsite($html, $website);

    // حفظ html مؤقتاً للـ enrichment لاحقاً
    $competitor['_html_size'] = strlen($html);

    return $competitor;
}

/**
 * cURL سريع مع timeout قصير
 */
function _fetchHtmlForCompetitor(string $url): ?string {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10, // أقصر من العميل لأننا نسحب 5 منافسين
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml',
            'Accept-Language: ar,en-US;q=0.9',
        ],
        CURLOPT_ENCODING       => '',
    ]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$html || $code >= 400) return null;
    return $html;
}

/**
 * استخراج روابط FB/IG/TT/X من HTML
 */
function _extractSocialFromHtml(string $html, string $baseUrl): array {
    $social = [];

    // Facebook
    if (preg_match('/https?:\/\/(?:www\.)?facebook\.com\/([a-zA-Z0-9._\-]+)/i', $html, $m)) {
        $slug = $m[1];
        // استبعاد روابط عامة
        if (!in_array($slug, ['sharer','share','login','tr','plugins','dialog','pages'], true)) {
            $social['facebook'] = 'https://www.facebook.com/' . $slug;
        }
    }

    // Instagram
    if (preg_match('/https?:\/\/(?:www\.)?instagram\.com\/([a-zA-Z0-9._]+)/i', $html, $m)) {
        $slug = $m[1];
        if (!in_array($slug, ['p','explore','reel','tv','accounts'], true)) {
            $social['instagram'] = 'https://www.instagram.com/' . $slug . '/';
        }
    }

    // TikTok
    if (preg_match('/https?:\/\/(?:www\.)?tiktok\.com\/@([a-zA-Z0-9._]+)/i', $html, $m)) {
        $social['tiktok'] = 'https://www.tiktok.com/@' . $m[1];
    }

    // Twitter / X
    if (preg_match('/https?:\/\/(?:www\.)?(?:twitter|x)\.com\/([a-zA-Z0-9_]+)/i', $html, $m)) {
        $slug = $m[1];
        if (!in_array(mb_strtolower($slug), ['intent','share','home','login','signup'], true)) {
            $social['twitter'] = 'https://twitter.com/' . $slug;
        }
    }

    // YouTube
    if (preg_match('/https?:\/\/(?:www\.)?youtube\.com\/(?:c\/|channel\/|@)([a-zA-Z0-9_\-]+)/i', $html, $m)) {
        $social['youtube'] = $m[0];
    }

    // LinkedIn
    if (preg_match('/https?:\/\/(?:www\.)?linkedin\.com\/(?:company|in)\/([a-zA-Z0-9_\-]+)/i', $html, $m)) {
        $social['linkedin'] = $m[0];
    }

    return $social;
}

/**
 * تحليل سريع للموقع (0 Apify)
 */
function _quickAnalyzeWebsite(string $html, string $url): array {
    $isHttps   = str_starts_with($url, 'https://');
    $hasPixel  = (bool)preg_match('/connect\.facebook\.net|fbq\(/i', $html);
    $hasGA     = (bool)preg_match('/google-analytics|gtag\(|GoogleAnalyticsObject/i', $html);
    $hasGtm    = (bool)preg_match('/googletagmanager\.com/i', $html);
    $hasWhats  = (bool)preg_match('/wa\.me|api\.whatsapp\.com/i', $html);
    $hasSchema = (bool)preg_match('/application\/ld\+json/i', $html);
    $hasOpenGraph = (bool)preg_match('/og:title|og:image/i', $html);

    // CTA detection
    $ctaPatterns = ['اطلب','احجز','اشترك','تواصل','اتصل','جرب','انضم',
                    'order','book','subscribe','contact','call','try','join','buy'];
    $hasCta = false;
    foreach ($ctaPatterns as $cta) {
        if (preg_match('/\b' . preg_quote($cta, '/') . '\b/iu', $html)) {
            $hasCta = true;
            break;
        }
    }

    // Tech stack detection
    $techStack = [];
    if (preg_match('/wp-content|wordpress/i', $html))       $techStack[] = 'WordPress';
    if (preg_match('/cdn\.shopify\.com|shopify/i', $html))  $techStack[] = 'Shopify';
    if (preg_match('/wix\.com|wixstatic/i', $html))         $techStack[] = 'Wix';
    if (preg_match('/squarespace/i', $html))                $techStack[] = 'Squarespace';
    if (preg_match('/__next|_next\/static/i', $html))       $techStack[] = 'Next.js';
    if (preg_match('/tailwindcss|tailwind/i', $html))       $techStack[] = 'Tailwind';

    // عنوان وصف الصفحة
    $title = '';
    if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $m)) {
        $title = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
    }

    return [
        'has_ssl'        => $isHttps,
        'has_fb_pixel'   => $hasPixel,
        'has_ga'         => $hasGA,
        'has_gtm'        => $hasGtm,
        'has_whatsapp'   => $hasWhats,
        'has_schema'     => $hasSchema,
        'has_open_graph' => $hasOpenGraph,
        'has_cta'        => $hasCta,
        'tech_stack'     => $techStack,
        'title'          => mb_substr($title, 0, 200),
        'html_size_kb'   => round(strlen($html) / 1024, 1),
    ];
}
```

---

## 3. `api/competitors/google-reviews.php`

### الوظيفة
سحب مراجعات Google لكل منافس له `place_id`، باستخدام Actor: `Xb8osYTtOjlsgI6k9`.

### الكود الكامل

```php
<?php
/**
 * Google Maps Reviews for Competitors
 * Actor: Xb8osYTtOjlsgI6k9
 * Schema:
 *   { startUrls?: [{url}], placeIds?: [], maxReviews, reviewsSort }
 */

declare(strict_types=1);

require_once __DIR__ . '/../apify-scraper.php';
require_once __DIR__ . '/../logger.php';

/**
 * @param string $placeId Google Place ID
 * @param string $placeUrl URL Google Maps (بديل)
 * @return array {
 *   success: bool,
 *   reviews: array[],
 *   summary: { avg_rating, total, by_stars, sentiment }
 * }
 */
function fetchCompetitorGoogleReviews(
    string $placeId,
    string $placeUrl,
    array  $cfg,
    int    $maxReviews = 20
): array {
    $token = function_exists('getValidApifyToken') ? getValidApifyToken($cfg) : '';
    if (!$token) {
        return ['success' => false, 'error' => 'No Apify token', 'reviews' => []];
    }

    $actorId = $cfg['apis']['apify_actor_google_maps_reviews']
            ?? 'Xb8osYTtOjlsgI6k9';

    if (empty($placeId) && empty($placeUrl)) {
        return ['success' => false, 'error' => 'No placeId or URL', 'reviews' => []];
    }

    // ── Schema الفعلي ──
    $input = [
        'maxReviews'      => max(1, min(100, $maxReviews)),
        'reviewsSort'     => 'newest',
        'language'        => 'ar',
        'reviewsOrigin'   => 'all',
        'personalData'    => false,
    ];

    if (!empty($placeId)) {
        $input['placeIds'] = [$placeId];
    } elseif (!empty($placeUrl)) {
        $input['startUrls'] = [['url' => $placeUrl]];
    }

    logInfo('Google Reviews fetch start', [
        'place_id' => $placeId,
        'max'      => $maxReviews,
    ]);

    $runId = _apifyStartRun($actorId, json_encode($input), $token);
    if (!$runId) {
        return ['success' => false, 'error' => 'Failed to start actor', 'reviews' => []];
    }

    $items = _apifyWaitAndFetch($runId, $token, 90, $maxReviews);
    if ($items === null || empty($items)) {
        return ['success' => true, 'reviews' => [], 'summary' => null];
    }

    // ── تطبيع المراجعات ──
    $reviews = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;

        // الـ actor قد يُرجع مراجعة واحدة في item أو مصفوفة reviews داخل item
        $reviewsList = $item['reviews'] ?? null;
        if (is_array($reviewsList)) {
            foreach ($reviewsList as $r) {
                $normalized = _normalizeGoogleReview($r);
                if ($normalized) $reviews[] = $normalized;
            }
        } else {
            $normalized = _normalizeGoogleReview($item);
            if ($normalized) $reviews[] = $normalized;
        }
    }

    // ── حساب summary ──
    $summary = _summarizeReviews($reviews);

    logInfo('Google Reviews fetch complete', [
        'count'      => count($reviews),
        'avg_rating' => $summary['avg_rating'],
    ]);

    return [
        'success' => true,
        'reviews' => $reviews,
        'summary' => $summary,
    ];
}

function _normalizeGoogleReview(array $r): ?array {
    $rating = $r['rating'] ?? $r['stars'] ?? null;
    $text   = $r['text'] ?? $r['snippet'] ?? '';

    if (!is_numeric($rating)) return null;

    return [
        'rating'    => (float)$rating,
        'text'      => trim((string)$text),
        'date'      => $r['publishedAtDate'] ?? $r['publishAt'] ?? $r['date'] ?? null,
        'reviewer'  => $r['name'] ?? $r['reviewerName'] ?? '',
        'lang'      => $r['language'] ?? null,
    ];
}

function _summarizeReviews(array $reviews): array {
    $count = count($reviews);
    if ($count === 0) {
        return ['avg_rating' => null, 'total' => 0, 'by_stars' => [], 'sentiment' => null];
    }

    $sum = 0;
    $byStars = [1=>0, 2=>0, 3=>0, 4=>0, 5=>0];
    foreach ($reviews as $r) {
        $sum += $r['rating'];
        $star = (int)round($r['rating']);
        if (isset($byStars[$star])) $byStars[$star]++;
    }

    $avg = round($sum / $count, 2);

    // sentiment heuristic
    $positive = $byStars[4] + $byStars[5];
    $negative = $byStars[1] + $byStars[2];
    $neutral  = $byStars[3];

    $sentiment = null;
    if ($count > 0) {
        $sentiment = [
            'positive_pct' => round(($positive / $count) * 100, 1),
            'negative_pct' => round(($negative / $count) * 100, 1),
            'neutral_pct'  => round(($neutral / $count) * 100, 1),
            'overall'      => $positive > $negative * 2 ? 'positive'
                            : ($negative > $positive * 2 ? 'negative' : 'mixed'),
        ];
    }

    return [
        'avg_rating' => $avg,
        'total'      => $count,
        'by_stars'   => $byStars,
        'sentiment'  => $sentiment,
    ];
}
```

---

## 4. `api/competitors/helpers.php`

### الوظيفة
دوال مساعدة عامة.

### الكود الكامل

```php
<?php
/**
 * Helper functions for Competitors v2
 */

declare(strict_types=1);

/**
 * توليد cache key من URL منافس
 */
function buildCompetitorCacheKey(string $url, string $platform): string {
    $domain = parse_url($url, PHP_URL_HOST) ?? $url;
    $domain = preg_replace('/^www\./', '', mb_strtolower($domain));
    return "comp_{$platform}_{$domain}";
}

/**
 * حساب مدى اكتمال البيانات (0-100%)
 *
 * @param array $comp المنافس بعد enrichment
 */
function calculateDataCompleteness(array $comp): int {
    $score = 0;

    // followers من أي منصة
    $hasFollowers = false;
    foreach (['facebook','instagram','tiktok','twitter'] as $p) {
        if (!empty($comp['platforms'][$p]['followers'])) {
            $hasFollowers = true;
            break;
        }
    }
    if ($hasFollowers) $score += 20;

    // engagement
    $hasEngagement = false;
    foreach (['facebook','instagram','tiktok','twitter'] as $p) {
        if (!empty($comp['platforms'][$p]['engagement_rate'])) {
            $hasEngagement = true;
            break;
        }
    }
    if ($hasEngagement) $score += 20;

    // posts/recent activity
    $hasActivity = false;
    foreach (['facebook','instagram','tiktok','twitter'] as $p) {
        if (!empty($comp['platforms'][$p]['posts_per_week'])) {
            $hasActivity = true;
            break;
        }
    }
    if ($hasActivity) $score += 15;

    // website + tech_stack
    if (!empty($comp['quick_analysis']['has_ssl'])) $score += 8;
    if (!empty($comp['quick_analysis']['tech_stack'])) $score += 7;

    // reviews/rating
    if (!empty($comp['rating'])) $score += 8;
    if (!empty($comp['reviews_summary']['total'])) $score += 7;

    // ads info
    if (isset($comp['platforms']['facebook']['ads_running'])) $score += 8;
    if (isset($comp['platforms']['facebook']['ads_count'])) $score += 7;

    return min(100, $score);
}

/**
 * استخراج username من URL منصة
 */
function extractUsernameFromUrl(string $url, string $platform): string {
    switch ($platform) {
        case 'facebook':
            if (preg_match('/facebook\.com\/([a-zA-Z0-9._\-]+)/i', $url, $m)) {
                return $m[1];
            }
            break;
        case 'instagram':
            if (preg_match('/instagram\.com\/([a-zA-Z0-9._]+)/i', $url, $m)) {
                return $m[1];
            }
            break;
        case 'tiktok':
            if (preg_match('/tiktok\.com\/@([a-zA-Z0-9._]+)/i', $url, $m)) {
                return $m[1];
            }
            break;
        case 'twitter':
            if (preg_match('/(?:twitter|x)\.com\/([a-zA-Z0-9_]+)/i', $url, $m)) {
                return $m[1];
            }
            break;
    }
    return '';
}

/**
 * بناء meta data لكل قيمة (track مصدرها)
 */
function buildMeta(array $sourceMap): array {
    $meta = ['fetched_at' => date('c')];
    foreach ($sourceMap as $field => $source) {
        $meta[$field . '_source'] = $source;
    }
    return $meta;
}
```

---

## 5. `api/competitors/enrichment.php`

### الوظيفة
الدالة الرئيسية للـ enrichment. تستدعي `scrapeFacebook`, `scrapeInstagram` إلخ من النظام الحالي.

### الكود الكامل

```php
<?php
/**
 * Competitor Enrichment — STEP 4
 * يسحب بيانات كل منافس من المنصات المتاحة باستخدام:
 *   - scrapeFacebook (موجود في apify-scraper.php)
 *   - scrapeInstagram (موجود)
 *   - scrapeTikTok (موجود)
 *   - scrapeTwitter (موجود)
 *   - fetchCompetitorGoogleReviews (جديد)
 *
 * Tier system:
 *   Tier 1: Quick analysis only (cURL، 0 Apify)
 *   Tier 2: منصات السوشيال للعميل فقط
 *   Tier 3: كل المنصات + Reviews
 */

declare(strict_types=1);

require_once __DIR__ . '/../apify-scraper.php';
require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/cross-platform-discovery.php';
require_once __DIR__ . '/google-reviews.php';

/**
 * @param array $competitor المنافس بعد cross-platform discovery
 * @param array $clientProfile للمعرفة أي منصات يهتم بها العميل
 * @param array $cfg الإعدادات
 * @return array منافس مُحدَّث مع كامل البيانات
 */
function enrichCompetitor(
    array $competitor,
    array $clientProfile,
    array $cfg
): array {
    $tier = (int)($cfg['analysis']['competitor_enrich_tier'] ?? 3);
    $cache = new CompetitorCache($cfg);

    $competitor['platforms'] = $competitor['platforms'] ?? [];
    $competitor['_meta']     = $competitor['_meta']     ?? ['fetched_at' => date('c')];

    // ── Tier 1: دائماً (مجاني) ──
    // الـ quick_analysis تم في cross-platform-discovery
    // هنا نتأكد منه
    if (empty($competitor['quick_analysis'])) {
        $competitor = discoverCompetitorSocialLinks($competitor, $cfg);
    }

    if ($tier <= 1) {
        $competitor['_meta']['tier_used'] = 1;
        $competitor['_meta']['data_completeness'] = calculateDataCompleteness($competitor);
        return $competitor;
    }

    // ── تحديد المنصات المُستهدفة ──
    $targetPlatforms = _determineTargetPlatforms($competitor, $clientProfile, $tier);

    logInfo('Enriching competitor', [
        'name'      => $competitor['name'] ?? '?',
        'tier'      => $tier,
        'platforms' => $targetPlatforms,
    ]);

    // ── سحب من كل منصة ──
    $token = function_exists('getValidApifyToken') ? getValidApifyToken($cfg) : '';

    foreach ($targetPlatforms as $platform) {
        $url = $competitor['social'][$platform] ?? '';
        if (empty($url)) continue;

        $cacheKey = buildCompetitorCacheKey($url, $platform);
        $cached = $cache->get($cacheKey);

        if ($cached !== null) {
            $competitor['platforms'][$platform] = $cached;
            $competitor['_meta'][$platform . '_source'] = 'cache';
            continue;
        }

        try {
            $data = _scrapePlatformForCompetitor($platform, $url, $token, $cfg);
            if (!empty($data) && ($data['success'] ?? false)) {
                $simplified = _simplifyPlatformData($platform, $data);
                $competitor['platforms'][$platform] = $simplified;
                $competitor['_meta'][$platform . '_source'] = 'apify_' . $platform;

                $cache->set($cacheKey, $simplified);
            } else {
                $competitor['_warnings'][] = "فشل سحب {$platform}: " . ($data['error'] ?? 'unknown');
            }
        } catch (\Throwable $e) {
            logError('Competitor platform scrape exception', [
                'platform' => $platform,
                'url'      => $url,
                'error'    => $e->getMessage(),
            ]);
            $competitor['_warnings'][] = "خطأ في {$platform}: " . $e->getMessage();
        }
    }

    // ── Google Reviews (Tier 3 فقط) ──
    if ($tier >= 3
        && ($cfg['analysis']['competitor_include_reviews'] ?? true)
        && !empty($competitor['place_id'])
    ) {
        $reviewsCacheKey = 'comp_reviews_' . md5($competitor['place_id']);
        $cachedReviews = $cache->get($reviewsCacheKey);

        if ($cachedReviews !== null) {
            $competitor['reviews_summary'] = $cachedReviews['summary'] ?? null;
            $competitor['reviews_sample']  = array_slice($cachedReviews['reviews'] ?? [], 0, 5);
            $competitor['_meta']['reviews_source'] = 'cache';
        } else {
            $maxReviews = (int)($cfg['analysis']['competitor_max_reviews_per_comp'] ?? 20);
            $reviewsResult = fetchCompetitorGoogleReviews(
                $competitor['place_id'],
                '', // no URL needed
                $cfg,
                $maxReviews
            );

            if ($reviewsResult['success'] ?? false) {
                $competitor['reviews_summary'] = $reviewsResult['summary'];
                $competitor['reviews_sample']  = array_slice($reviewsResult['reviews'], 0, 5);
                $competitor['_meta']['reviews_source'] = 'google_maps';

                $cache->set($reviewsCacheKey, $reviewsResult);
            }
        }
    }

    // ── حساب ads info (مجاناً من بيانات Facebook) ──
    if (!empty($competitor['platforms']['facebook'])) {
        $fb = $competitor['platforms']['facebook'];
        $competitor['ads_info'] = [
            'is_running_ads' => (bool)($fb['ads_running'] ?? false),
            'ads_count'      => (int)($fb['ads_count']    ?? 0),
            '_source'        => 'facebook_pageAdLibrary',
        ];
    }

    // ── حساب data completeness ──
    $competitor['_meta']['tier_used'] = $tier;
    $competitor['_meta']['data_completeness'] = calculateDataCompleteness($competitor);

    if ($competitor['_meta']['data_completeness'] < 30) {
        $competitor['_warning'] = 'بيانات شحيحة - التحليل قد يكون محدود';
    }

    return $competitor;
}

/**
 * تحديد المنصات للسحب بناءً على tier + ما لدى العميل
 */
function _determineTargetPlatforms(array $competitor, array $clientProfile, int $tier): array {
    $available = [];
    foreach (['facebook','instagram','tiktok','twitter'] as $p) {
        if (!empty($competitor['social'][$p])) {
            $available[] = $p;
        }
    }

    if ($tier === 2) {
        // فقط منصات العميل
        $clientPlatforms = $clientProfile['social_platforms'] ?? [];
        return array_intersect($available, $clientPlatforms);
    }

    // Tier 3: كل المنصات المتوفرة
    return $available;
}

/**
 * router لاستدعاء scrape المناسب
 */
function _scrapePlatformForCompetitor(
    string $platform,
    string $url,
    string $token,
    array  $cfg
): array {
    if (empty($token)) {
        return ['success' => false, 'error' => 'No Apify token'];
    }

    switch ($platform) {
        case 'facebook':
            if (function_exists('scrapeFacebook')) {
                return scrapeFacebook($url, $token, $cfg);
            }
            break;
        case 'instagram':
            if (function_exists('scrapeInstagram')) {
                return scrapeInstagram($url, $token, $cfg);
            }
            break;
        case 'tiktok':
            if (function_exists('scrapeTikTok')) {
                return scrapeTikTok($url, $token, $cfg);
            }
            break;
        case 'twitter':
            if (function_exists('scrapeTwitter')) {
                return scrapeTwitter($url, $token, $cfg);
            }
            break;
    }

    return ['success' => false, 'error' => "Platform {$platform} not supported"];
}

/**
 * تبسيط بيانات المنصة (نريد فقط الأرقام المهمة، ليس كل شيء)
 *
 * هذا يقلل حجم البيانات المُرسلة للـ AI ويحمي من تسريب بيانات غير مهمة
 */
function _simplifyPlatformData(string $platform, array $raw): array {
    switch ($platform) {
        case 'facebook':
            return [
                'platform'        => 'facebook',
                'url'             => $raw['url']             ?? null,
                'page_name'       => $raw['page_name']       ?? null,
                'followers'       => $raw['followers']       ?? null,
                'likes'           => $raw['likes']           ?? null,
                'category'        => $raw['category']        ?? null,
                'is_verified'     => $raw['is_verified']     ?? false,
                'rating'          => $raw['rating']          ?? null,
                'ratings_count'   => $raw['ratings_count']   ?? null,
                'engagement_rate' => $raw['engagement_rate'] ?? null,
                'avg_likes'       => $raw['avg_likes']       ?? null,
                'avg_comments'    => $raw['avg_comments']    ?? null,
                'avg_shares'      => $raw['avg_shares']      ?? null,
                'posts_per_week'  => $raw['posts_per_week']  ?? null,
                'last_post_days'  => $raw['last_post_days']  ?? null,
                'posts_count'     => $raw['posts_count']     ?? null,
                'ads_running'     => $raw['ads_running']     ?? false,
                'ads_count'       => $raw['ads_count']       ?? 0,
                // top_5_posts للـ AI لتحليل أسلوب المنافس
                'top_5_posts'     => array_slice($raw['top_5_posts'] ?? [], 0, 5),
                'page_health'     => $raw['page_health']    ?? null,
            ];

        case 'instagram':
            return [
                'platform'             => 'instagram',
                'url'                  => $raw['url']                  ?? null,
                'username'             => $raw['username']             ?? null,
                'full_name'            => $raw['full_name']            ?? null,
                'followers'            => $raw['followers']            ?? null,
                'following'            => $raw['following']            ?? null,
                'is_verified'          => $raw['is_verified']          ?? false,
                'is_business'          => $raw['is_business_account'] ?? false,
                'category'             => $raw['business_category']    ?? null,
                'engagement_rate'      => $raw['engagement_rate']      ?? null,
                'posts_count'          => $raw['posts_count']          ?? null,
                'posts_per_week'       => $raw['posts_per_week']       ?? null,
                'last_post_days'       => $raw['last_post_days']       ?? null,
                'avg_likes'            => $raw['avg_likes']            ?? null,
                'avg_comments'         => $raw['avg_comments']         ?? null,
                'reels_count'          => $raw['reels_count']          ?? null,
                'top_5_posts'          => array_slice($raw['top_5_posts'] ?? [], 0, 5),
                'account_health'       => $raw['account_health']       ?? null,
                'bio_optimization'     => $raw['bio_optimization']     ?? null,
            ];

        case 'tiktok':
            return [
                'platform'        => 'tiktok',
                'url'             => $raw['url']             ?? null,
                'username'        => $raw['username']        ?? null,
                'followers'       => $raw['followers']       ?? null,
                'following'       => $raw['following']       ?? null,
                'is_verified'     => $raw['is_verified']     ?? false,
                'videos_count'    => $raw['videos_count']    ?? null,
                'total_likes'     => $raw['total_likes']     ?? null,
                'avg_views'       => $raw['avg_views']       ?? null,
                'avg_likes'       => $raw['avg_likes']       ?? null,
                'avg_comments'    => $raw['avg_comments']    ?? null,
                'engagement_rate' => $raw['engagement_rate'] ?? null,
                'posts_per_week'  => $raw['posts_per_week']  ?? null,
                'top_5_videos'    => array_slice($raw['top_5_videos'] ?? [], 0, 5),
            ];

        case 'twitter':
            return [
                'platform'        => 'twitter',
                'url'             => $raw['url']             ?? null,
                'username'        => $raw['username']        ?? null,
                'followers'       => $raw['followers']       ?? null,
                'following'       => $raw['following']       ?? null,
                'is_verified'     => $raw['is_verified']     ?? false,
                'tweets_count'    => $raw['tweets_count']    ?? null,
                'engagement_rate' => $raw['engagement_rate'] ?? null,
                'avg_likes'       => $raw['avg_likes']       ?? null,
                'avg_retweets'    => $raw['avg_retweets']    ?? null,
                'avg_replies'     => $raw['avg_replies']     ?? null,
                'posts_per_week'  => $raw['posts_per_week']  ?? null,
                'top_5_tweets'    => array_slice($raw['top_5_tweets'] ?? [], 0, 5),
            ];
    }

    return $raw;
}

/**
 * Wrapper: enrich كل المنافسين الـ 5 بالتوازي/التتابع
 */
function enrichAllCompetitors(
    array $competitors,
    array $clientProfile,
    array $cfg
): array {
    $results = [];
    foreach ($competitors as $idx => $comp) {
        try {
            $enriched = enrichCompetitor($comp, $clientProfile, $cfg);
            $enriched['_position'] = $idx + 1;
            $results[] = $enriched;
        } catch (\Throwable $e) {
            logError('Failed to enrich competitor', [
                'name'  => $comp['name'] ?? '?',
                'error' => $e->getMessage(),
            ]);
            // أبقِ المنافس مع warning
            $comp['_error'] = $e->getMessage();
            $comp['_position'] = $idx + 1;
            $results[] = $comp;
        }
    }
    return $results;
}
```

---

## 6. تعديل `orchestrator.php`

أضف في نهاية `runCompetitorDiscovery` بعد STEP 2:

```php
// ── STEP 3 + 4: Cross-platform discovery + Enrichment ──
require_once __DIR__ . '/cross-platform-discovery.php';
require_once __DIR__ . '/enrichment.php';

$enrichTier = (int)($cfg['analysis']['competitor_enrich_tier'] ?? 3);

if ($enrichTier > 0) {
    $enrichedCompetitors = [];
    foreach ($merged['top_competitors'] as $comp) {
        // STEP 3: استخراج روابط المنصات من موقع المنافس
        $comp = discoverCompetitorSocialLinks($comp, $cfg);

        // STEP 4: enrichment الكامل
        $comp = enrichCompetitor($comp, $profile, $cfg);

        $enrichedCompetitors[] = $comp;
    }
    $merged['top_competitors'] = $enrichedCompetitors;
}
```

---

# 🧪 خطة الاختبار

## اختبار 1: Tier 1 (مجاني)
```bash
COMPETITOR_ENRICH_TIER=1
# توقع: كل منافس عنده quick_analysis (has_ssl, tech_stack, has_pixel)
# لا استدعاءات Apify إضافية
```

## اختبار 2: Tier 2 (منصة العميل فقط)
```bash
COMPETITOR_ENRICH_TIER=2
# عميل على Instagram فقط
# توقع: 5 منافسين × Instagram = 5 Apify runs
```

## اختبار 3: Tier 3 (كل شيء)
```bash
COMPETITOR_ENRICH_TIER=3
# توقع: كل منافس عنده platforms.{facebook,instagram,...}
# + reviews_summary لو له place_id
# + ads_info من pageAdLibrary
# تكلفة: ~25 Apify runs
```

## اختبار 4: Caching
```bash
# شغّل الفحص مرتين متتاليتين
# المحاولة الأولى: 25 Apify runs
# المحاولة الثانية (خلال 6 ساعات): 0 Apify runs (كله من cache)
```

## اختبار 5: Data Completeness
```bash
# تحقق من _meta.data_completeness لكل منافس
# يجب أن يكون ≥ 30% لمعظم المنافسين
# المنافسون < 30% يحملون _warning
```

---

# ✅ Checklist للـ Coder Agent

- [ ] إنشاء `competitors/cache.php`
- [ ] إنشاء `competitors/cross-platform-discovery.php`
- [ ] إنشاء `competitors/google-reviews.php`
- [ ] إنشاء `competitors/helpers.php`
- [ ] إنشاء `competitors/enrichment.php`
- [ ] تعديل `competitors/orchestrator.php` لاستدعاء enrichment
- [ ] `php -l` لكل ملف
- [ ] commit: `feat(competitors): add Sprint 3 — multi-platform enrichment`
- [ ] PR إلى main: "Sprint 3: Competitor Data Enrichment"

---

# ⏭️ بعد Sprint 3

اقرأ `SPRINT-4-AI-AND-UI.md` لإكمال نظام التحليل والواجهة.
