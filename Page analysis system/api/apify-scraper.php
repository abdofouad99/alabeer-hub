<?php
if (defined('APIFY_SCRAPER_LOADED')) return;
define('APIFY_SCRAPER_LOADED', true);

// ============================================================
// api/apify-scraper.php — مكتبة دوال Apify (لا تُستدعى مباشرة)
// ============================================================
require_once __DIR__ . '/db.php';

// ← لا يوجد كود تنفيذي هنا — فقط دوال

// ============================================================
function runApifyScraper(string $url, string $type, array $cfg): array {
    // اختيار Token ذكياً (عشوائي + validation)
    $token = getValidApifyToken($cfg);
    if (!$token) return ['success' => false, 'error' => 'Apify token غير مضبوط'];

    switch ($type) {
        case 'instagram': return scrapeInstagram($url, $token, $cfg);
        case 'tiktok':    return scrapeTikTok($url, $token, $cfg);
        case 'twitter':   return scrapeTwitter($url, $token, $cfg);
        default:          return scrapeFacebook($url, $token, $cfg);
    }
}

// ============================================================
// P1-3: اختيار أفضل Token من القائمة (عشوائي + تحقق + cache)
// ============================================================
if (!function_exists('getValidApifyToken')) {
function getValidApifyToken(array $cfg): string {
    $tokens = $cfg['apis']['apify_tokens'] ?? [];
    if (empty($tokens)) return '';

    // ── Cache داخل نفس الـ PHP process (تجنب إعادة الـ validation في كل استدعاء) ──
    static $cachedToken = null;
    if ($cachedToken !== null) return $cachedToken;

    // ── GLB-2 FIX: File-based cache لتجنب validation في كل HTTP request (5s ضائعة) ──
    $cacheFile = sys_get_temp_dir() . '/apify_valid_token_' . md5(implode(',', $tokens));
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 900) { // 15 دقيقة
        $saved = trim(file_get_contents($cacheFile));
        if ($saved !== '' && in_array($saved, $tokens, true)) {
            $cachedToken = $saved;
            return $cachedToken;
        }
    }

    // ── الخطوة 1: اختيار عشوائي (load balancing حقيقي بدلاً من time() % count) ──
    // نسخة مُرخَّلة للقائمة لمنع نمط ثابت
    $shuffled = $tokens;
    shuffle($shuffled);

    foreach ($shuffled as $token) {
        if (empty(trim($token))) continue;

        // ── الخطوة 2: تحقق سريع (5 ثوانٍ timeout) ──
        $ch = curl_init("https://api.apify.com/v2/users/me?token={$token}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) continue;

        $userData = json_decode($body, true);
        $isActive  = $userData['data']['isStatusActive'] ?? true;
        if (!$isActive) continue;

        // وجدنا token صالح — نُخزّنه ونُعيده
        $cachedToken = $token;
        // ✅ حفظ في file cache لتجنب validation في الطلبات القادمة
        file_put_contents($cacheFile, $cachedToken);
        return $token;
    }

    // Fallback: إذا فشل الـ validation لجميع التوكنات (مشكلة شبكة مؤقتة)
    // استخدم عشوائياً لتجنب التوقف الكامل
    $cachedToken = $shuffled[0];
    return $cachedToken;
}
} // end if(!function_exists('getValidApifyToken'))


// ============================================================
/**
 * scrapeAdsLibrary
 * ─────────────────────────────────────────────────────────────
 * المصدر الأساسي: بيانات pageAdLibrary التي يُرجعها Facebook Actor تلقائياً.
 * المصدر الاحتياطي: Apify actor مستقل (يُفعَّل إذا لم تتوفر بيانات Facebook).
 *
 * @param string $pageIdentifier  رابط أو اسم مستخدم أو 'ID:12345'
 * @param string $token           Apify token
 * @param array  $cfg             الإعدادات
 * @param array  $fbData          بيانات Facebook إذا سُحبت مسبقاً (اختياري)
 */
function scrapeAdsLibrary(
    string $pageIdentifier,
    string $token,
    array  $cfg      = [],
    string $country  = 'ALL',
    array  $fbData   = []
): array {

    // ── المسار الأول: استخدام بيانات Facebook (الأسرع) ───
    $fbAdsActive = null;
    $fbAdsCount  = null;
    $adStatus    = '';
    if (!empty($fbData['success'])) {
        $fbAdsActive = (bool)($fbData['ads_running'] ?? false);
        $fbAdsCount  = (int)($fbData['ads_count'] ?? 0);
    }
    // لم نعد نُرجع النتيجة المبدئية هنا، بل نستخدمها كمعلومة إضافية
    // لضمان تشغيل ساحب الإعلانات المخصص (Ads Actor) وجلب صور ونصوص الإعلانات فعلياً.

    // ── Actor الجديد: JJghSZmShuco4j9gJ — يقبل URL الصفحة + Ads Library URL مباشرة
    $actorId = $cfg['apis']['apify_actor_ads_fb'] ?? 'JJghSZmShuco4j9gJ';
    if (empty($actorId)) {
        return ['success' => false, 'error' => 'لا يوجد Ads Actor مُعرَّف', 'ads' => []];
    }

    $cleanUrl = str_starts_with($pageIdentifier, 'http')
        ? $pageIdentifier
        : 'https://www.facebook.com/' . ltrim($pageIdentifier, '/');

    preg_match('/facebook\.com\/([^\/\?#]+)/i', $cleanUrl, $m);
    $pageSlug  = $m[1] ?? '';
    $adsLibUrl = $pageSlug
        ? "https://www.facebook.com/ads/library/?active_status=all&ad_type=all&country={$country}&search_type=page&q=" . urlencode($pageSlug)
        : '';

    $startUrls = [['url' => $cleanUrl]];
    if ($adsLibUrl) $startUrls[] = ['url' => $adsLibUrl];

    $input = [
        'startUrls'        => $startUrls,
        'resultsLimit'     => 50,
        'onlyTotal'        => false,
        'includeAboutPage' => false,
        'isDetailsPerAd'   => false,
        'activeStatus'     => '',
    ];

    $fallbackReturn = [
        'success'        => true,
        'source'         => 'facebook_actor',
        'is_running_ads' => $fbAdsActive ?? false,
        'total_ads'      => $fbAdsCount  ?? 0,
        'active_ads'     => ($fbAdsActive ?? false) ? ($fbAdsCount ?? 0) : 0,
        'ads_status'     => $adStatus    ?? '',
        'ads'            => [],
    ];

    $runId = _apifyStartRun($actorId, json_encode($input), $token);
    if (!$runId) return $fallbackReturn;

    $items = _apifyWaitAndFetch($runId, $token, 120);  // 120s > Actor timeout (110s)
    if ($items === null) return $fallbackReturn;

    $ads = [];
    foreach (($items ?: []) as $item) {
        $adsList = $item['ads'] ?? null;
        if (is_array($adsList)) {
            foreach ($adsList as $ad) $ads[] = _parseAd($ad);
        } else {
            $ads[] = _parseAd($item);
        }
    }

    if (count($ads) === 0 && ($fbAdsActive ?? false)) {
        return $fallbackReturn;
    }

    // is_running_ads = "هل توجد إعلانات نشطة الآن؟" يجب أن يُشتق من active_ads
    // وليس من إجمالي الإعلانات (وإلا تظهر "نشط" مع "إعلانات نشطة: 0").
    $activeCount = count(array_filter($ads, fn($a) => !empty($a['is_active'])));

    return [
        'success'        => true,
        'source'         => 'apify_actor',
        'total_ads'      => count($ads),
        'active_ads'     => $activeCount,
        'ads'            => array_slice($ads, 0, 30),
        'is_running_ads' => $activeCount > 0,
    ];
}


function _parseAd(array $ad): array {
    $rawStatus = $ad['ad_delivery_status'] ?? $ad['status'] ?? '';
    $status = is_string($rawStatus) ? strtolower($rawStatus) : '';
    $images = $ad['snapshot']['images'] ?? [];
    $imgUrl = is_array($images) && isset($images[0]['original_image_url']) ? $images[0]['original_image_url'] : null;

    return [
        'id'                  => $ad['adArchiveID']              ?? $ad['ad_archive_id'] ?? $ad['id']          ?? null,
        'title'               => $ad['adCreativeBody']           ?? $ad['ad_creative_body'] ?? $ad['body'] ?? $ad['title'] ?? '',
        'page_name'           => $ad['pageName']                 ?? $ad['page_name'] ?? '',
        'is_active'           => ($ad['isActive'] ?? $ad['is_active'] ?? false) || $status === 'active'
                              || ($status === '' && !empty($ad['startDate'] ?? $ad['start_date'] ?? $ad['ad_creation_time'])), // ADS-1 FIX: إعلان بدون status لكن له تاريخ بدء = نشط
        'start_date'          => $ad['startDate']                ?? $ad['start_date'] ?? $ad['ad_creation_time'] ?? null,
        'platforms'           => $ad['publisherPlatform']        ?? $ad['publisher_platforms'] ?? $ad['platforms'] ?? [],
        'spend'               => $ad['spend']                    ?? null,
        'impressions'         => $ad['impressions']              ?? null,
        'image_url'           => $imgUrl ?? $ad['image_url'] ?? $ad['thumbnail'] ?? null,
        // ✅ بيانات هدف الإعلان
        'objective'           => $ad['ad_creative_link_caption'] ?? $ad['objective'] ?? $ad['campaign_type'] ?? '',
        'cta_type'            => $ad['snapshot']['cta_type']     ?? $ad['cta_type'] ?? '',
        'call_to_action_type' => $ad['snapshot']['link_url']     ?? $ad['destination_url'] ?? '',
    ];
}


// ============================================================
// Google Search Scraper (nWGjfqxH9vqmJN76s) for Competitors
// ============================================================
function scrapeCompetitorsViaGoogle(string $companyName, string $targetAudience, string $token, string $originalUrl = ''): array {
    $actorId = 'nWGjfqxH9vqmJN76s'; // Google Search Scraper

    // إذا لم يكن هناك اسم نشاط، لا داعي للبحث
    if (empty(trim($companyName))) return ['success' => false, 'error' => 'لا يوجد اسم نشاط للبحث عنه'];

    $location = trim($targetAudience) !== '' ? trim($targetAudience) : 'السعودية';
    $query = "أهم منافسين $companyName في $location";
    // أو صيغة أخرى
    // $query = "شركات مثل $companyName في $location";

    $input = [
        "queries" => [$query],
        "maxPagesPerQuery" => 1,
        "maxResults" => "10", // Apify requires this to be a string
        "countryCode" => "SA" // Default fallback
    ];

    $runId = _apifyStartRun($actorId, json_encode($input), $token);
    if (!$runId) return ['success' => false, 'error' => 'فشل تشغيل Google Actor'];

    $result = _apifyWaitAndFetch($runId, $token, 45); // أسرع من باقي السواحب
    if (!$result) return ['success' => false, 'error' => 'انتهت مهلة Google Apify'];

    // استخراج النتائج
    $competitors = [];
    // COMP-1 FIX: استبعاد الموقع الأصلي + مواقع عامة
    $excludeDomains = ['youtube.com','wikipedia.org','linkedin.com','twitter.com','x.com','instagram.com','facebook.com','tiktok.com'];
    $originalDomain = !empty($originalUrl) ? (parse_url($originalUrl, PHP_URL_HOST) ?? '') : '';

    foreach ($result as $item) {
        $title = $item['title'] ?? $item['metadataTitle'] ?? '';
        $url = $item['url'] ?? '';
        $desc = $item['metadataDescription'] ?? $item['description'] ?? '';
        if (!empty($title) && !empty($url)) {
            $domain = parse_url($url, PHP_URL_HOST) ?? '';

            // استبعاد الموقع الأصلي
            if ($originalDomain && str_contains($domain, str_replace('www.', '', $originalDomain))) continue;
            // استبعاد مواقع عامة
            $skip = false;
            foreach ($excludeDomains as $ed) { if (str_contains($domain, $ed)) { $skip = true; break; } }
            if ($skip) continue;

            $competitors[] = [
                'name' => $title,
                'url' => $url,
                'description' => $desc
            ];
        }
    }

    return [
        'success' => true,
        'competitors' => array_slice($competitors, 0, 5) // نأخذ أول 5
    ];
}

// ============================================================
// enrichCompetitorsData — مسح حسابات المنافسين كمّياً
// ============================================================
function enrichCompetitorsData(array $competitors, array $cfg): array {
    $enriched = [];
    foreach ($competitors as $comp) {
        $url = $comp['url'] ?? '';
        if (!empty($url)) {
            try {
                require_once __DIR__ . '/page-scan.php';
                if (function_exists('runPageScan')) {
                    $scan   = runPageScan($url, $cfg);
                    $social = $scan['social'] ?? [];

                    $comp['followers']      = $social['followers']      ?? null;
                    $comp['avg_engagement'] = $social['avg_engagement'] ?? null;
                    $comp['posts_per_week'] = $social['posts_per_week'] ?? null;
                    $comp['has_ads']        = $scan['ads_library']['is_running_ads'] ?? false;
                    $comp['website_score']  = $scan['scan_score']       ?? null;
                    $comp['platform']       = $social['platform']       ?? '';
                }
            } catch (\Throwable $e) {
                // أبقِ البيانات الأساسية إذا فشل المسح
            }
        }
        $enriched[] = $comp;
    }
    return $enriched;
}

// COMP-2 FIX: فحص خفيف للمنافسين (OG + HTML فقط — بدون Apify) — سريع ومجاني
function lightScanCompetitor(string $url, array $cfg): array {
    require_once __DIR__ . '/page-scan.php';
    $og = function_exists('scanOGTags') ? scanOGTags($url) : [];
    $ws = function_exists('scanWebsiteHTML') ? scanWebsiteHTML($url, $cfg) : [];
    return [
        'title'     => $og['title'] ?? '',
        'has_ssl'   => $ws['has_ssl'] ?? false,
        'has_pixel' => $ws['has_fb_pixel'] ?? false,
        'has_ga'    => $ws['has_ga'] ?? false,
        'has_cta'   => $ws['has_cta'] ?? false,
        'tech_stack'=> $ws['tech_stack'] ?? [],
    ];
}



// ============================================================
// scrapePostComments — سحب تعليقات المنشورات (فيسبوك + إنستغرام)
// FB: us5srxAYnsrkgUv2v | IG: SbK00X0JYCPblD2wp
// الهدف: تغذية تحليل المشاعر والاعتراضات للذكاء الاصطناعي
// ============================================================
function scrapePostComments(string $postUrl, string $platform, string $token, int $limit = 50): array {
    if ($platform === 'facebook') {
        $actorId = 'us5srxAYnsrkgUv2v';
        $input = json_encode([
            'startUrls'             => [['url' => $postUrl]],
            'resultsLimit'          => $limit,
            'includeNestedComments' => false,
            'viewOption'            => 'RANKED_UNFILTERED',
        ]);
    } elseif ($platform === 'instagram') {
        $actorId = 'SbK00X0JYCPblD2wp';
        $input = json_encode(['directUrls' => [$postUrl], 'resultsLimit' => $limit]);
    } elseif ($platform === 'tiktok') {
        // clockworks/tiktok-comments-scraper
        $actorId = 'clockworks/tiktok-comments-scraper';
        $input = json_encode([
            'postURLs'     => [$postUrl],
            'commentsPerPost' => $limit,
            'maxRepliesPerComment' => 0,
        ]);
    } elseif ($platform === 'twitter') {
        // apidojo/tweet-replies-scraper
        $actorId = 'apidojo/tweet-replies-scraper';
        $input = json_encode([
            'startUrls' => [$postUrl],
            'maxItems'  => $limit,
        ]);
    } else {
        return ['success' => false, 'error' => 'منصة غير مدعومة'];
    }

    $runId = _apifyStartRun($actorId, $input, $token);
    if (!$runId) return ['success' => false, 'error' => 'فشل تشغيل Comments Actor'];
    $items = _apifyWaitAndFetch($runId, $token, 90);
    if (!$items) return ['success' => false, 'error' => 'انتهت مهلة Comments Actor'];

    $positive = 0; $negative = 0; $questions = 0;
    $objections = []; $phrases = [];
    $negWords = ['غالي','لا','سيء','وحش','ضعيف','bad','worst','expensive'];
    $posWords = ['رائع','ممتاز','جيد','حلو','عظيم','great','love','excellent'];
    $qWords   = ['?','؟','كيف','متى','كم','هل','how','price','when'];

    foreach ($items as $item) {
        $text = $item['text'] ?? $item['comment'] ?? $item['message'] ?? '';
        if (!trim($text)) continue;
        $phrases[] = mb_substr($text, 0, 80);
        $isNeg = false; $isPos = false;
        foreach ($negWords as $w) { if (mb_stripos($text,$w) !== false) { $isNeg=true; $objections[]=mb_substr($text,0,60); break; } }
        foreach ($posWords as $w) { if (mb_stripos($text,$w) !== false) { $isPos=true; break; } }
        foreach ($qWords  as $w) { if (mb_stripos($text,$w) !== false) { $questions++; break; } }
        if ($isNeg) $negative++; elseif ($isPos) $positive++;
    }
    $total = max(count($items), 1);
    return [
        'success'        => true,
        'platform'       => $platform,
        'total_comments' => count($items),
        'positive_pct'   => round(($positive / $total) * 100),
        'negative_pct'   => round(($negative / $total) * 100),
        'questions_pct'  => round(($questions / $total) * 100),
        'top_objections' => array_slice(array_unique($objections), 0, 5),
        'sample_phrases' => array_slice($phrases, 0, 10),
    ];
}

// ============================================================
// scrapeGoogleMapsReviews — صوت العميل الحقيقي (Xb8osYTtOjlsgI6k9)
// ============================================================
function scrapeGoogleMapsReviews(string $mapsUrl, string $token, int $maxReviews = 50): array {
    $actorId = 'Xb8osYTtOjlsgI6k9';
    $input = json_encode([
        'startUrls'     => [['url' => $mapsUrl]],
        'maxReviews'    => $maxReviews,
        'reviewsSort'   => 'newest',
        'language'      => 'ar',
        'reviewsOrigin' => 'all',
        'personalData'  => false,
    ]);
    $runId = _apifyStartRun($actorId, $input, $token);
    if (!$runId) return ['success' => false, 'error' => 'فشل Maps Actor'];
    $items = _apifyWaitAndFetch($runId, $token, 90);
    if (!$items) return ['success' => false, 'error' => 'انتهت مهلة Maps Actor'];

    $ratings=[]; $neg=[]; $pos=[]; $texts=[];
    foreach ($items as $r) {
        $stars = (float)($r['stars'] ?? $r['rating'] ?? 0);
        if ($stars) $ratings[] = $stars;
        $text = $r['text'] ?? $r['reviewText'] ?? '';
        if ($text) {
            $texts[] = mb_substr($text, 0, 80);
            if ($stars <= 2) $neg[] = mb_substr($text, 0, 80);
            if ($stars >= 4) $pos[] = mb_substr($text, 0, 80);
        }
    }
    return [
        'success'       => true,
        'total_reviews' => count($items),
        'avg_rating'    => $ratings ? round(array_sum($ratings)/count($ratings),1) : null,
        'positive'      => array_slice($pos, 0, 5),
        'negative'      => array_slice($neg, 0, 5),
        'samples'       => array_slice($texts, 0, 10),
    ];
}

// ── Apify Internal Helpers ────────────────────────────────────
function _apifyStartRun(string $actorId, string $inputJson, string $token): ?string {
    $url = "https://api.apify.com/v2/acts/{$actorId}/runs?token={$token}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,   // start endpoint قد يستغرق وقتاً تحت ضغط Apify
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => $inputJson,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $res = json_decode($body, true);
    curl_close($ch);

    if ($code !== 201 && $code !== 200) {
        logError("Apify Start Run Failed", [
            "actor" => $actorId,
            "http_code" => $code,
            "response" => $res ?: $body,
            "url_mask" => "https://api.apify.com/v2/acts/{$actorId}/runs?token=***"
        ]);
        return null;
    }

    return $res['data']['id'] ?? null;
}

function _apifyWaitAndFetch(string $runId, string $token, int $maxWait, int $datasetLimit = 100): ?array {
    $start = time();
    while (time() - $start < $maxWait) {
        sleep(3);
        $ch = curl_init("https://api.apify.com/v2/actor-runs/{$runId}?token={$token}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => false]);
        $s = json_decode(curl_exec($ch), true);
        curl_close($ch);
        $status = $s['data']['status'] ?? '';
        // Treat BOTH SUCCEEDED and FINISHED as successful completion
        if (in_array($status, ['SUCCEEDED', 'FINISHED'])) {
            $dsId = $s['data']['defaultDatasetId'] ?? '';
            if ($dsId) {
                $datasetLimit = max(1, min($datasetLimit, 1000)); // safety cap
                $ch = curl_init("https://api.apify.com/v2/datasets/{$dsId}/items?token={$token}&limit={$datasetLimit}");
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => false]);
                $items = json_decode(curl_exec($ch), true);
                curl_close($ch);
                return $items ?: [];
            }
            return [];
        }
        if (in_array($status, ['FAILED','ABORTED','TIMED-OUT'])) return null;
    }
    return null;
}

// ============================================================
// Facebook Page Scraper (apify~facebook-pages-scraper)
// نفس المنهجية التي تعمل للإنستقرام
// ============================================================
function scrapeFacebook(string $url, string $token, array $cfg): array {
    require_once __DIR__ . '/facebook-deep.php';

    $actorId = $cfg['apis']['apify_actor_fb'] ?? 'apify~facebook-pages-scraper';

    $isPostsScraper = ($actorId === 'KoJrdxJCTtpon81KY');

    // عدد المنشورات: قابل للتعديل من الإعدادات (افتراضياً 50 لتحليل أعمق)
    $maxPosts = (int)($cfg['analysis']['fb_max_posts'] ?? 50);
    if ($maxPosts < 10) $maxPosts = 10;

    if ($isPostsScraper) {
        $input = json_encode([
            'startUrls'    => [['url' => $url]],
            'resultsLimit' => $maxPosts,
            'captionText'  => false,
        ], JSON_PRESERVE_ZERO_FRACTION | JSON_NUMERIC_CHECK);
    } else {
        // ✅ تفعيل scrapeReviews + scrapeServices لجلب التقييمات والخدمات
        $input = json_encode([
            'startUrls'      => [['url' => $url]],
            'maxPosts'       => $maxPosts,
            'scrapeAbout'    => true,
            'scrapeReviews'  => true,
            'scrapeServices' => true,
            'scrapePosts'    => true,
        ]);
    }

    $runId = _apifyStartRun($actorId, $input, $token);
    if (!$runId) {
        logError('Facebook scrape failed to start', ['url' => $url, 'actor' => $actorId]);
        return ['success' => false, 'error' => 'فشل تشغيل Facebook Actor'];
    }

    logInfo('Starting Facebook scrape', ['url' => $url, 'actor' => $actorId, 'runId' => $runId]);
    $result = _apifyWaitAndFetch($runId, $token, 150); // 150s for richer scrape with reviews+services
    if ($result === null) {
        logError('Facebook scrape timed out or failed', ['url' => $url, 'runId' => $runId]);
        return ['success' => false, 'error' => 'انتهت مهلة Facebook Apify'];
    }
    logInfo('Facebook scrape successful', ['url' => $url, 'items_count' => count($result)]);

    // ── المعالجة بناءً على نوع الـ Actor ─────────
    $reviews  = [];   // قائمة التقييمات (مع نصوصها)
    $services = [];   // قائمة الخدمات / المنتجات
    $hours    = [];   // ساعات العمل
    $aboutMe  = '';   // نص "عن المعلن"

    if ($isPostsScraper) {
        // Actor الجديد يرجع مصفوفة من المنشورات، أول عنصر يحتوي على معلومات الصفحة
        if (empty($result)) return ['success' => false, 'error' => 'لا يوجد منشورات للتحليل (أو الصفحة مغلقة)'];

        $firstItem = $result[0];
        $posts = $result; // كل عنصر هو منشور

        $followers = $firstItem['followers'] ?? $firstItem['followersCount'] ?? $firstItem['fans'] ?? null;
        $likes     = $firstItem['likes']     ?? $firstItem['likesCount']     ?? $followers;
        $pageName  = $firstItem['title']     ?? $firstItem['pageName']       ?? $firstItem['name'] ?? '';
        $pageId    = $firstItem['facebookId'] ?? $firstItem['pageId']         ?? $firstItem['id']   ?? '';

        $phone = '';
        $email = '';
        $whatsapp = '';
        $website = '';
        $igUrl = '';
        $category = '';
        $is_verified = false;

        // جلب الإعلانات من المنشور الأول
        $adLib = $firstItem['pageAdLibrary'] ?? [];
        $adsActive = !empty($adLib['is_business_page_active']);
        $adsCount = 0;

        // تعيين $page ليكون $firstItem لاستخدامه في الحقول المشتركة بالأسفل
        $page = $firstItem;

        // محاولة استخراج المزيد من البيانات من الحقول المتاحة في الـ Actor الجديد
        $phone     = $page['phone']        ?? $page['phoneNumber']    ?? '';
        $whatsapp  = $page['whatsappNumber'] ?? '';
        $email     = $page['email']        ?? '';
        $website   = $page['website']      ?? $page['externalUrl']    ?? '';
        $category  = $page['category']     ?? '';
        $is_verified = !empty($page['isVerified']) || !empty($page['verified']);

        // ── جلب النواقص (معلومات الصفحة + التقييمات + الخدمات) عبر Actor الصفحات السريع ──
        // FB-1 FIX: استدعاء Actor الثاني أيضاً عند غياب reviews/services/hours (كان يشترط أن كل الأربعة فارغة فقط)
        if ((empty($followers) && empty($email) && empty($phone) && empty($website))
            || (empty($reviews) && empty($services) && empty($hours))) {
            $aboutInput = json_encode([
                'startUrls'      => [['url' => $url]],
                'maxPosts'       => 0,
                'scrapeAbout'    => true,
                'scrapeServices' => true,   // ✅ تفعيل
                'scrapeReviews'  => true,   // ✅ تفعيل
            ]);
            $aboutRunId = _apifyStartRun('apify~facebook-pages-scraper', $aboutInput, $token);
            if ($aboutRunId) {
                $aboutResult = _apifyWaitAndFetch($aboutRunId, $token, 90);
                if (!empty($aboutResult[0])) {
                    $ap = $aboutResult[0];
                    $followers = $ap['followers'] ?? $ap['followersCount'] ?? $ap['fans'] ?? $ap['likesCount'] ?? $followers;
                    $likes     = $ap['likes'] ?? $ap['likesCount'] ?? $followers;
                    $phone     = $ap['phone'] ?? $ap['phoneNumber'] ?? $phone;
                    $whatsapp  = $ap['wa_number'] ?? $ap['whatsapp_number'] ?? $ap['whatsapp'] ?? $whatsapp;
                    $email     = $ap['email'] ?? ($ap['emails'][0] ?? $email);

                    $ws = $ap['website'] ?? '';
                    if (empty($ws) && !empty($ap['websites'])) {
                        $wsArray = $ap['websites'];
                        $ws = is_array($wsArray) ? ($wsArray[0] ?? '') : $wsArray;
                    }
                    $website = $ws ?: ($ap['externalUrl'] ?? $ap['websiteUrl'] ?? $website);

                    $category  = $ap['category'] ?? ($ap['categories'][0] ?? ($ap['categoryName'] ?? $category));
                    $is_verified = !empty($ap['verified']) || !empty($ap['isVerified']) || ($ap['verifiedStatus'] ?? '') === 'BLUE_VERIFIED' || $is_verified;

                    // استخدام صورة الغلاف والشخصية إذا توفرت
                    $page['cover']      = $ap['cover'] ?? $page['cover'] ?? null;
                    $page['profilePic'] = $ap['profilePic'] ?? $page['profilePic'] ?? null;

                    // استخراج التقييمات والخدمات وساعات العمل
                    $reviews  = _extractReviews($ap);
                    $services = _extractServices($ap);
                    $hours    = _extractHours($ap);
                    $aboutMe  = _extractAboutText($ap);
                }
            }
        }

    } else {
        // Actor القديم يرجع كائن واحد للصفحة وداخله المنشورات
        $page = $result[0] ?? [];
        if (empty($page)) return ['success' => false, 'error' => 'لا بيانات من Facebook Actor'];

        $posts = $page['posts'] ?? $page['latestPosts'] ?? [];
        $followers = $page['followers']    ?? $page['followersCount'] ?? $page['fans'] ?? $page['likesCount'] ?? null;
        $likes     = $page['likes']        ?? $followers;
        $phone     = $page['phone']        ?? $page['phoneNumber']    ?? '';
        $whatsapp  = $page['wa_number']    ?? $page['whatsapp_number'] ?? $page['whatsapp'] ?? '';
        $email     = $page['email']        ?? $page['emails'][0]      ?? '';

        $website = $page['website'] ?? '';
        if (empty($website) && !empty($page['websites'])) {
            $ws = $page['websites'];
            $website = is_array($ws) ? ($ws[0] ?? '') : $ws;
        }
        $website = $website ?: ($page['externalUrl'] ?? $page['websiteUrl'] ?? '');
        $igUrl = $page['instagram'] ?? $page['instagramLink'] ?? '';
        $category = $page['category'] ?? $page['categories'][0] ?? $page['categoryName'] ?? '';
        $is_verified = !empty($page['verified']) || !empty($page['isVerified']) || ($page['verifiedStatus'] ?? '') === 'BLUE_VERIFIED';
        $pageName = $page['title'] ?? $page['pageName'] ?? $page['name'] ?? '';
        $pageId = $page['pageId'] ?? $page['facebookId'] ?? $page['id'] ?? '';

        $adLib = $page['pageAdLibrary'] ?? [];
        $adsActive = !empty($adLib['is_business_page_active']) || (($adLib['ad_count'] ?? 0) > 0);
        $adsCount = (int)($adLib['ad_count'] ?? 0);
        if (!$adsActive && !empty($page['ad_status'])) {
            $adsActive = strtoupper($page['ad_status']) === 'ACTIVE';
        }

        // استخراج التقييمات والخدمات وساعات العمل والـ About
        $reviews  = _extractReviews($page);
        $services = _extractServices($page);
        $hours    = _extractHours($page);
        $aboutMe  = _extractAboutText($page);
    }

    // ── حساب المتوسطات المنفصلة (likes/comments/shares) + reactions ──
    $stats = _calcFacebookPostStats($posts);

    // ── معدّل التفاعل الحقيقي (Engagement Rate) ──
    $followersInt    = (int)($followers ?? 0);
    $engagementRate  = $followersInt > 0
        ? round((($stats['avg_likes'] + $stats['avg_comments'] + $stats['avg_shares']) / $followersInt) * 100, 2)
        : 0.0;

    // ── تنظيف العنوان: قد يكون object أو string ──
    $address = _normalizeAddress($page['address'] ?? $page['location'] ?? $page['city'] ?? null);

    // ── Reviews Summary (متوسط + توزيع نجوم) ──
    $reviewsSummary = _summarizeReviews($reviews);

    // ── 🎯 طبقات التحليل العميق (Facebook V3) ─────────────────
    // مكافئ لطبقات Instagram V3 الـ 11
    $hashtags         = extractFBHashtagsFromPosts($posts);
    $mentionsAnalysis = extractFBMentionsAnalysis($posts);
    $contentDist      = calcFBContentDistribution($posts);
    $heatmap          = calcFBPostingHeatmap($posts);
    $langMix          = detectFBPostsLanguageMix($posts);
    $locations        = extractFBLocations($posts);
    $sponsored        = calcFBSponsoredRatio($posts);

    // Page Optimization Score (مكافئ لـ analyzeBioOptimization)
    $pageOpt = analyzeFBPageOptimization([
        'about'         => $aboutMe,
        'description'   => $page['intro'] ?? $page['description'] ?? '',
        'category'      => $category,
        'website'       => $website,
        'phone'         => $phone,
        'whatsapp'      => $whatsapp,
        'email'         => $email,
        'address'       => $address,
        'cover_photo'   => $page['cover'] ?? $page['cover_photo'] ?? '',
        'profile_pic'   => $page['profilePic'] ?? $page['profile_pic'] ?? '',
        'is_verified'   => $is_verified,
        'rating'        => _cleanRating($page['rating'] ?? $page['overallStarRating'] ?? null) ?? $reviewsSummary['avg_rating'],
        'opening_hours' => $hours,
        'services'      => $services,
    ]);

    // Page Health Score (مكافئ لـ calcAccountHealthScore)
    $pageHealth = calcFBPageHealthScore([
        'followers'            => (int)($followers ?? 0),
        'likes'                => (int)($likes ?? 0),
        'posts_count'          => count($posts),
        'engagement_rate'      => $engagementRate,
        'is_verified'          => $is_verified,
        'has_reviews'          => $reviewsSummary['count'] > 0,
        'rating'               => $reviewsSummary['avg_rating'],
        'reviews_count'        => $reviewsSummary['count'],
        'has_shop'             => !empty($page['hasShop']) || !empty($page['shop_enabled']),
        'website'              => $website,
        'about_length'         => mb_strlen((string)$aboutMe),
        'posts_per_week'       => calcPostsPerWeek($posts),
        'last_post_days'       => calcLastPostDays($posts),
        'ads_running'          => $adsActive,
        'content_distribution' => $contentDist,
    ]);

    // أفضل 5 منشورات تفاعلاً (تُستخدم في Sentiment + Vision)
    $sortedByEng = $posts;
    usort($sortedByEng, function($a, $b) {
        $ea = (int)($a['likesCount']    ?? $a['likes']    ?? $a['reactionsCount'] ?? 0)
            + (int)($a['commentsCount'] ?? $a['comments'] ?? $a['commentCount']   ?? 0)
            + (int)($a['sharesCount']   ?? $a['shareCount'] ?? $a['shares']       ?? 0);
        $eb = (int)($b['likesCount']    ?? $b['likes']    ?? $b['reactionsCount'] ?? 0)
            + (int)($b['commentsCount'] ?? $b['comments'] ?? $b['commentCount']   ?? 0)
            + (int)($b['sharesCount']   ?? $b['shareCount'] ?? $b['shares']       ?? 0);
        return $eb - $ea;
    });
    $top5 = array_slice($sortedByEng, 0, 5);

    // Comments Sentiment (اختياري — مفعّل افتراضياً)
    $sentiment = null;
    if (!empty($cfg['analysis']['enable_fb_comments'])) {
        try { $sentiment = analyzeFBCommentsSentiment($top5, $token, $cfg); }
        catch (\Throwable $e) { logError('FB sentiment failed', ['err' => $e->getMessage()]); $sentiment = ['success' => false, 'reason' => $e->getMessage()]; }
    }

    // Vision AI (اختياري — معطّل افتراضياً)
    $vision = null;
    if (!empty($cfg['analysis']['enable_fb_vision'])) {
        try { $vision = analyzeFBImagesVision($top5, $cfg); }
        catch (\Throwable $e) { logError('FB vision failed', ['err' => $e->getMessage()]); $vision = ['success' => false, 'reason' => $e->getMessage()]; }
    }

    $res = [
        'success'         => true,
        'source'          => 'apify',
        'platform'        => 'facebook',
        'page_name'       => $pageName,
        'page_id'         => $pageId,
        'url'             => $url,
        'followers'       => $followers,
        'likes'           => $likes,
        'category'        => $category,
        'is_verified'     => $is_verified,
        'website'         => $website,
        'phone'           => $phone,
        'whatsapp'        => $whatsapp,
        'email'           => $email,
        'address'         => $address,
        'description'     => $page['intro'] ?? $page['description'] ?? $page['about'] ?? $page['text'] ?? $aboutMe,
        'about'           => $aboutMe,
        'rating'          => _cleanRating($page['rating'] ?? $page['overallStarRating'] ?? null) ?? $reviewsSummary['avg_rating'],
        'ratings_count'   => $page['ratingsCount'] ?? $page['ratingCount'] ?? $reviewsSummary['count'],
        'response_time'   => $page['responseTime'] ?? $page['response_time'] ?? $page['messagingResponseTime'] ?? null,
        'posts_count'     => count($posts),
        // ✅ متوسطات منفصلة (تستهلكها AI مباشرة)
        'avg_likes'       => $stats['avg_likes'],
        'avg_comments'    => $stats['avg_comments'],
        'avg_shares'      => $stats['avg_shares'],
        'avg_video_views' => $stats['avg_video_views'],
        'avg_engagement'  => $stats['avg_engagement'],
        'engagement_rate' => $engagementRate,
        // ✅ تفصيل تفاعل المنشورات (Love/Haha/Wow/Sad/Angry)
        'reactions_breakdown' => $stats['reactions_breakdown'],
        'top_post'        => getTopPost($posts),
        'top_5_posts'     => $top5,
        'deep_analysis'   => analyzeDeepContent($posts),
        'instagram_url'   => $igUrl,
        'ads_running'     => $adsActive,
        'ads_count'       => $adsCount,
        'creation_date'   => $page['creation_date'] ?? $page['createdTime'] ?? $page['pageCreatedDate'] ?? $page['foundedDate'] ?? '',
        'profile_pic'     => $page['profilePic'] ?? $page['profile_pic'] ?? '',
        'cover_photo'     => $page['cover'] ?? $page['cover_photo'] ?? '',
        'posts_per_week'  => calcPostsPerWeek($posts),
        'last_post_days'  => calcLastPostDays($posts),
        'posts'           => array_slice($posts, 0, 10),  // للعرض في التقرير
        // ✅ ميزات جديدة لتحليل أعمق
        'reviews'         => array_slice($reviews, 0, 20),
        'reviews_summary' => $reviewsSummary,
        'services'        => $services,
        'opening_hours'   => $hours,
        // 🎯 طبقات التحليل العميق (Facebook V3)
        'hashtags_analysis'    => $hashtags,
        'mentions_analysis'    => $mentionsAnalysis,
        'content_distribution' => $contentDist,
        'posting_heatmap'      => $heatmap,
        'language_mix'         => $langMix,
        'locations'            => $locations,
        'sponsored_ratio'      => $sponsored,
        'page_optimization'    => $pageOpt,
        'page_health'          => $pageHealth,
        'comments_sentiment'   => $sentiment,
        'vision_analysis'      => $vision,
        // علامة الإصدار
        'fb_version'           => 'v3',
    ];

    // فقط أضف الـ signals إذا كانت حقيقية (حتى لا نمسح ما يجده الـ Scraper العام)
    if (!empty($phone))    $res['has_phone']    = true;
    if (!empty($whatsapp)) $res['has_whatsapp'] = true;
    if (!empty($email))    $res['has_email']    = true;
    if (!empty($website))  $res['has_website']  = true;
    if ($is_verified)      $res['is_verified']  = true;
    if (!empty($services)) $res['has_services'] = true;
    if (!empty($hours))    $res['has_hours']    = true;

    return $res;
}


// ============================================================
// Helpers لمعالجة بيانات الفيسبوك (post stats, reactions, reviews, services, hours, address)
// ============================================================

/**
 * حساب متوسطات التفاعل المنفصلة + Reactions Breakdown من المنشورات
 * يقرأ من جميع الحقول الممكنة التي يُرجعها Apify Facebook Actor.
 */
function _calcFacebookPostStats(array $posts): array {
    $totalLikes = 0; $totalComments = 0; $totalShares = 0; $totalVideoViews = 0;
    $reactions  = ['like' => 0, 'love' => 0, 'haha' => 0, 'wow' => 0, 'sad' => 0, 'angry' => 0, 'care' => 0];
    $cnt = max(count($posts), 1);

    foreach ($posts as $p) {
        // Likes / Reactions Total
        $likes = (int)($p['likesCount']
                    ?? $p['likes']
                    ?? $p['reactionsCount']
                    ?? $p['reactions']['total']
                    ?? 0);
        // Comments
        $comments = (int)($p['commentsCount']
                       ?? $p['comments']
                       ?? $p['commentCount']
                       ?? 0);
        // Shares
        $shares = (int)($p['sharesCount']
                     ?? $p['shareCount']
                     ?? $p['shares']
                     ?? 0);
        // Video Views
        $views = (int)($p['videoViewCount']
                    ?? $p['viewsCount']
                    ?? $p['videoViews']
                    ?? $p['playCount']
                    ?? 0);

        $totalLikes      += $likes;
        $totalComments   += $comments;
        $totalShares     += $shares;
        $totalVideoViews += $views;

        // Reactions detail — Apify يُرجع الحقول إما تحت reactions{} أو reactionsByType{} أو reactionCount
        $rb = $p['reactions'] ?? $p['reactionsByType'] ?? $p['reactionCount'] ?? null;
        if (is_array($rb)) {
            foreach (['like','love','haha','wow','sad','angry','care'] as $k) {
                // المفاتيح المحتملة: love, LOVE, REACTION_LOVE, reactions_love...
                $val = 0;
                foreach ([$k, strtoupper($k), 'REACTION_' . strtoupper($k), 'reactions_' . $k] as $key) {
                    if (isset($rb[$key]) && is_numeric($rb[$key])) {
                        $val = (int)$rb[$key];
                        break;
                    }
                }
                $reactions[$k] += $val;
            }
        }
    }

    return [
        'avg_likes'           => round($totalLikes / $cnt, 1),
        'avg_comments'        => round($totalComments / $cnt, 1),
        'avg_shares'          => round($totalShares / $cnt, 1),
        'avg_video_views'     => round($totalVideoViews / $cnt, 1),
        'avg_engagement'      => round(($totalLikes + $totalComments + $totalShares) / $cnt, 1),
        'reactions_breakdown' => $reactions,
    ];
}

/**
 * استخراج التقييمات (reviews) من بيانات صفحة Apify
 * يدعم عدة هياكل ممكنة من Actors مختلفة
 */
function _extractReviews(array $page): array {
    $sources = [
        $page['reviews']         ?? null,
        $page['pageReviews']     ?? null,
        $page['userReviews']     ?? null,
        $page['recommendations'] ?? null,
    ];
    $reviews = [];
    foreach ($sources as $src) {
        if (is_array($src)) {
            foreach ($src as $r) {
                if (!is_array($r)) continue;
                $rating = $r['rating'] ?? $r['stars'] ?? $r['recommendation'] ?? null;
                $text   = $r['text'] ?? $r['reviewText'] ?? $r['content'] ?? $r['comment'] ?? '';
                $author = $r['author'] ?? $r['userName'] ?? $r['user']['name'] ?? '';
                $date   = $r['date'] ?? $r['createdTime'] ?? $r['timestamp'] ?? '';
                if (!$text && $rating === null) continue;
                // Normalize rating: numeric → float, boolean → 5.0/1.0, semantic strings → 5.0/1.0 (consistent type)
                $normalizedRating = null;
                if (is_numeric($rating)) {
                    $normalizedRating = (float)$rating;
                } elseif (is_bool($rating)) {
                    // FB-2 FIX: معالجة recommendation من نوع boolean (true = إيجابي, false = سلبي)
                    $normalizedRating = $rating ? 5.0 : 1.0;
                } elseif (is_string($rating)) {
                    $low = strtolower($rating);
                    if (in_array($low, ['positive','recommends','recommend'], true))           $normalizedRating = 5.0;
                    elseif (in_array($low, ['negative','doesnt-recommend','dont-recommend'], true)) $normalizedRating = 1.0;
                }
                $reviews[] = [
                    'rating' => $normalizedRating,
                    'text'   => is_string($text) ? mb_substr(trim($text), 0, 500) : '',
                    'author' => is_string($author) ? $author : '',
                    'date'   => is_string($date) ? $date : '',
                ];
            }
        }
    }
    return $reviews;
}

/**
 * تلخيص التقييمات: متوسط النجوم + توزيع + استخراج إيجابيات/سلبيات
 */
function _summarizeReviews(array $reviews): array {
    if (empty($reviews)) {
        return ['count' => 0, 'avg_rating' => null, 'distribution' => [], 'positive' => [], 'negative' => []];
    }
    $ratings = [];
    $dist = [1=>0, 2=>0, 3=>0, 4=>0, 5=>0];
    $pos = []; $neg = [];
    foreach ($reviews as $r) {
        $rt = $r['rating'] ?? null;
        if (is_numeric($rt)) {
            $ratings[] = (float)$rt;
            $star = max(1, min(5, (int)round($rt)));
            $dist[$star] = ($dist[$star] ?? 0) + 1;
        }
        if (!empty($r['text'])) {
            if (is_numeric($rt) && $rt >= 4) $pos[] = $r['text'];
            elseif (is_numeric($rt) && $rt <= 2) $neg[] = $r['text'];
        }
    }
    return [
        'count'        => count($reviews),
        'avg_rating'   => $ratings ? round(array_sum($ratings) / count($ratings), 1) : null,
        'distribution' => $dist,
        'positive'     => array_slice($pos, 0, 5),
        'negative'     => array_slice($neg, 0, 5),
    ];
}

/**
 * استخراج الخدمات/المنتجات
 */
function _extractServices(array $page): array {
    $sources = [
        $page['services']       ?? null,
        $page['pageServices']   ?? null,
        $page['products']       ?? null,
        $page['servicesOffered']?? null,
    ];
    $out = [];
    foreach ($sources as $src) {
        if (is_array($src)) {
            foreach ($src as $s) {
                if (is_string($s)) {
                    $name = trim($s);
                    if ($name !== '') $out[] = ['name' => $name, 'description' => '', 'price' => ''];
                } elseif (is_array($s)) {
                    $name = $s['name'] ?? $s['title'] ?? '';
                    $desc = $s['description'] ?? $s['text'] ?? '';
                    $price = $s['price'] ?? $s['priceRange'] ?? '';
                    if ($name) $out[] = ['name' => trim($name), 'description' => trim((string)$desc), 'price' => is_string($price) ? $price : ''];
                }
            }
        }
    }
    return array_slice($out, 0, 30);
}

/**
 * استخراج ساعات العمل (يُرجع array مفهوم: اليوم => "HH:MM-HH:MM")
 */
function _extractHours(array $page): array {
    $h = $page['openingHours'] ?? $page['hours'] ?? $page['workingHours'] ?? null;
    if (!is_array($h)) return [];
    $out = [];
    $days = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
    foreach ($days as $d) {
        // قد يكون "monday" => "09:00-22:00" أو "monday" => ["09:00","22:00"]
        $val = $h[$d] ?? $h[ucfirst($d)] ?? null;
        if ($val === null) continue;
        if (is_string($val))      $out[$d] = $val;
        elseif (is_array($val))   $out[$d] = implode('-', array_map('strval', $val));
    }
    // إذا لم نجد بهذه المفاتيح، نُرجع $h كما هو إذا كانت associative
    if (empty($out) && is_array($h)) {
        foreach ($h as $k => $v) {
            if (is_string($v))    $out[(string)$k] = $v;
            elseif (is_array($v)) $out[(string)$k] = implode('-', array_map('strval', $v));
        }
    }
    return $out;
}

/**
 * استخراج نص "About" من حقول مختلفة
 */
function _extractAboutText(array $page): string {
    $candidates = [
        $page['about']       ?? null,
        $page['aboutText']   ?? null,
        $page['intro']       ?? null,
        $page['description'] ?? null,
        $page['bio']         ?? null,
        $page['mission']     ?? null,
    ];
    foreach ($candidates as $c) {
        if (is_string($c) && trim($c) !== '') return trim($c);
        if (is_array($c)) {
            $joined = implode(' ', array_filter(array_map('strval', $c)));
            if (trim($joined) !== '') return trim($joined);
        }
    }
    return '';
}

/**
 * تطبيع العنوان (قد يأتي string أو object {street, city, country})
 */
function _normalizeAddress($addr): string {
    if ($addr === null) return '';
    if (is_string($addr)) return trim($addr);
    if (is_array($addr)) {
        $parts = [];
        foreach (['street','street1','address','city','region','state','country','postalCode','zip'] as $k) {
            if (!empty($addr[$k]) && is_string($addr[$k])) $parts[] = trim($addr[$k]);
        }
        if (empty($parts)) {
            // جرب أي قيم نصية في الـ array
            foreach ($addr as $v) {
                if (is_string($v) && trim($v) !== '') $parts[] = trim($v);
            }
        }
        return implode('، ', array_unique($parts));
    }
    return '';
}


// ============================================================
// Instagram Scraper V3 — Deep Profile + Posts + Media + Comments + Vision
// ------------------------------------------------------------
// Default actor: apify/instagram-scraper (returns rich fields:
//   id, businessCategoryName, joinedRecently, relatedProfiles,
//   latestPosts[] with hashtags, mentions, taggedUsers, images,
//   videoUrl, displayUrl, videoPlayCount, videoViewCount,
//   locationName, isSponsored, alt, latestComments[], highlightReelCount).
// Pipeline:
//   1. fetch profile + 100 posts via Apify
//   2. normalize each post to a unified shape
//   3. run deep analytics layer (instagram-deep.php)
//   4. (optional) sentiment via Comments Actor + OpenAI
//   5. (optional) Vision AI on top images
//   6. (optional) Stories + Highlights
// ============================================================
function scrapeInstagram(string $url, string $token, array $cfg): array {
    require_once __DIR__ . '/instagram-deep.php';

    $actorId = $cfg['apis']['apify_actor_ig'] ?? 'apify/instagram-scraper';

    // 1) extract username
    if (!str_contains($url, 'instagram.com')) {
        $username = ltrim(trim($url), '@');
    } else {
        preg_match('/instagram\.com\/([^\/\?#]+)/i', $url, $m);
        $username = trim($m[1] ?? '', '/@');
    }
    $username = preg_replace('/[^a-zA-Z0-9_\.]/', '', $username);
    if (!$username) return ['success' => false, 'error' => 'failed to extract username'];

    $profileUrl = 'https://www.instagram.com/' . $username . '/';
    logInfo('IG scrape v3 start', ['username' => $username, 'actor' => $actorId]);

    // 2) build flexible input
    $isOfficial = (str_contains($actorId, 'apify/instagram-scraper') || $actorId === 'shu8hvrXbJbY3Eb9W');
    if (str_contains($actorId, 'instagram-profile-scraper')) {
        $inputData = ['usernames' => [$username], 'resultsLimit' => 100];
    } elseif ($isOfficial) {
        $inputData = [
            'directUrls'    => [$profileUrl],
            'resultsType'   => 'posts',      // IG-1 FIX: كان 'details' يُرجع 12-24 منشور فقط
            'resultsLimit'  => 100,
            'addParentData' => true,          // يُضمّن بيانات البروفايل مع كل منشور
            'searchLimit'   => 1,
        ];
    } else {
        $inputData = [
            'directUrls'    => [$profileUrl],
            'resultsType'   => 'posts',
            'resultsLimit'  => 100,
            'searchType'    => 'hashtag',
            'searchLimit'   => 10,
            'addParentData' => true,
        ];
    }
    $input = json_encode($inputData, JSON_PRESERVE_ZERO_FRACTION | JSON_NUMERIC_CHECK);

    $runId = _apifyStartRun($actorId, $input, $token);
    if (!$runId) return ['success' => false, 'error' => 'failed to start IG actor'];

    $result = _apifyWaitAndFetch($runId, $token, 180);
    if ($result === null) return ['success' => false, 'error' => 'IG actor timeout'];
    if (empty($result))   return ['success' => false, 'error' => 'no IG data'];

    // 3) split profile vs posts (multi-schema tolerant)
    $profile = null;
    $posts   = [];
    foreach ($result as $item) {
        if (!is_array($item)) continue;
        if ((isset($item['latestPosts']) && is_array($item['latestPosts'])) ||
            (isset($item['username']) && (isset($item['followersCount']) || isset($item['followers_count']) || isset($item['followsCount'])))) {
            $profile = $item;
            if (!empty($item['latestPosts']) && is_array($item['latestPosts'])) {
                foreach ($item['latestPosts'] as $lp) if (is_array($lp)) $posts[] = $lp;
            }
            continue;
        }
        if (isset($item['caption']) || isset($item['shortCode']) || isset($item['type']) || isset($item['mediaType'])) {
            $posts[] = $item;
        }
    }
    if ($profile === null && !empty($posts)) {
        $first = $posts[0];
        $profile = [
            'id'                  => $first['ownerId']             ?? null,
            'username'            => $first['ownerUsername']       ?? $username,
            'fullName'            => $first['ownerFullName']       ?? '',
            'biography'           => $first['ownerBiography']      ?? '',
            'externalUrl'         => $first['ownerExternalUrl']    ?? '',
            'followersCount'      => $first['ownerFollowersCount'] ?? null,
            'followsCount'        => $first['ownerFollowingCount'] ?? null,
            'postsCount'          => $first['ownerPostsCount']     ?? count($posts),
            'verified'            => $first['ownerIsVerified']     ?? false,
            'isBusinessAccount'   => $first['ownerIsBusinessAccount'] ?? false,
            'profilePicUrl'       => $first['ownerProfilePicUrl']  ?? '',
            'profilePicUrlHD'     => $first['ownerProfilePicUrlHD'] ?? '',
        ];
    }
    if ($profile === null) {
        return ['success' => false, 'error' => 'no profile in Apify response'];
    }

    // helper for tolerant key reads
    $get = function (array $a, array $keys, $default = null) {
        foreach ($keys as $k) if (array_key_exists($k, $a) && $a[$k] !== null && $a[$k] !== '') return $a[$k];
        return $default;
    };

    // 4) profile mapping
    $igUserId    = $get($profile, ['id','userId','user_id','pk']);
    $igUser      = $get($profile, ['username','userName','handle'], $username);
    $fullName    = $get($profile, ['fullName','full_name','name'], '');
    $bio         = (string)$get($profile, ['biography','bio'], '');
    $externalUrl = (string)$get($profile, ['externalUrl','external_url','website'], '');
    $followers   = (int)$get($profile, ['followersCount','followers_count','followerCount','followers'], 0);
    $following   = (int)$get($profile, ['followsCount','followingCount','following_count','following'], 0);
    $postsTotal  = (int)$get($profile, ['postsCount','posts_count','mediaCount','media_count'], count($posts));
    $highlights  = (int)$get($profile, ['highlightReelCount','highlight_reel_count','highlightsCount'], 0);
    $isBusiness  = (bool)$get($profile, ['isBusinessAccount','is_business_account','isBusiness'], false);
    $bizCategory = (string)$get($profile, ['businessCategoryName','business_category_name','category_name','category'], '');
    $verified    = (bool)$get($profile, ['verified','isVerified','is_verified'], false);
    $isPrivate   = (bool)$get($profile, ['private','isPrivate','is_private'], false);
    $picUrl      = (string)$get($profile, ['profilePicUrl','profile_pic_url'], '');
    $picUrlHD    = (string)$get($profile, ['profilePicUrlHD','profile_pic_url_hd'], $picUrl);
    $joinedNew   = (bool)$get($profile, ['joinedRecently','joined_recently'], false);
    $relatedRaw  = $get($profile, ['relatedProfiles','related_profiles'], []);
    $relatedProfiles = [];
    if (is_array($relatedRaw)) {
        foreach ($relatedRaw as $rp) {
            if (is_array($rp)) {
                $relatedProfiles[] = [
                    'username'    => $rp['username'] ?? $rp['user']['username'] ?? '',
                    'full_name'   => $rp['fullName'] ?? $rp['full_name'] ?? '',
                    'verified'    => (bool)($rp['isVerified'] ?? $rp['is_verified'] ?? false),
                    'profile_pic' => $rp['profilePicUrl'] ?? $rp['profile_pic_url'] ?? '',
                ];
            } elseif (is_string($rp)) {
                $relatedProfiles[] = ['username' => $rp];
            }
        }
    }

    // 5) normalize each post — unified rich shape
    $normalizedPosts = [];
    foreach ($posts as $p) {
        if (!is_array($p)) continue;
        $type = strtolower($p['type'] ?? $p['mediaType'] ?? $p['productType'] ?? '');
        $isReel = str_contains($type, 'reel') || !empty($p['isReel']) || (!empty($p['videoUrl']) && (int)($p['videoPlayCount'] ?? 0) > 0);

        $imgs = [];
        if (!empty($p['images']) && is_array($p['images'])) {
            foreach ($p['images'] as $im) $imgs[] = is_string($im) ? $im : ($im['url'] ?? $im['src'] ?? '');
        }
        if (!empty($p['childPosts']) && is_array($p['childPosts'])) {
            foreach ($p['childPosts'] as $cp) {
                if (is_array($cp)) {
                    $u = $cp['displayUrl'] ?? $cp['imageUrl'] ?? '';
                    if ($u) $imgs[] = $u;
                }
            }
        }

        $tagged = [];
        if (!empty($p['taggedUsers']) && is_array($p['taggedUsers'])) {
            foreach ($p['taggedUsers'] as $tu) {
                if (is_array($tu))      $tagged[] = ['username' => $tu['username'] ?? $tu['user']['username'] ?? '', 'full_name' => $tu['fullName'] ?? ''];
                elseif (is_string($tu)) $tagged[] = ['username' => ltrim($tu,'@'), 'full_name' => ''];
            }
        }

        $latestComments = [];
        if (!empty($p['latestComments']) && is_array($p['latestComments'])) {
            foreach ($p['latestComments'] as $c) {
                if (!is_array($c)) continue;
                $latestComments[] = [
                    'text'         => $c['text'] ?? $c['comment'] ?? '',
                    'ownerUsername'=> $c['ownerUsername'] ?? $c['owner']['username'] ?? '',
                    'likesCount'   => (int)($c['likesCount'] ?? 0),
                    'timestamp'    => $c['timestamp'] ?? null,
                ];
            }
        }

        $normalizedPosts[] = [
            'id'              => $p['id'] ?? $p['pk'] ?? null,
            'shortCode'       => $p['shortCode'] ?? $p['code'] ?? '',
            'url'             => $p['url'] ?? $p['postUrl'] ?? (!empty($p['shortCode']) ? 'https://www.instagram.com/p/' . $p['shortCode'] . '/' : ''),
            'type'            => $type ?: ($isReel ? 'reel' : 'image'),
            'isReel'          => $isReel,
            'caption'         => (string)($p['caption'] ?? $p['text'] ?? ''),
            'hashtags'        => $p['hashtags'] ?? [],
            'mentions'        => $p['mentions'] ?? [],
            'likesCount'      => (int)($p['likesCount']    ?? $p['likes']    ?? 0),
            'commentsCount'   => (int)($p['commentsCount'] ?? $p['comments'] ?? 0),
            'savesCount'      => (int)($p['savesCount']    ?? $p['saves']    ?? 0),
            'videoViewCount'  => (int)($p['videoViewCount'] ?? 0),
            'videoPlayCount'  => (int)($p['videoPlayCount'] ?? 0),
            'videoDuration'   => $p['videoDuration'] ?? null,
            'videoUrl'        => $p['videoUrl'] ?? '',
            'displayUrl'      => $p['displayUrl'] ?? $p['imageUrl'] ?? $p['thumbnailUrl'] ?? '',
            'images'          => $imgs,
            'alt'             => $p['alt'] ?? $p['accessibilityCaption'] ?? '',
            'locationName'    => $p['locationName'] ?? $p['location']['name'] ?? '',
            'locationId'      => $p['locationId']   ?? $p['location']['id']   ?? null,
            'isSponsored'     => (bool)($p['isSponsored'] ?? $p['is_paid_partnership'] ?? false),
            'taggedUsers'     => $tagged,
            'latestComments'  => $latestComments,
            'timestamp'       => $p['timestamp'] ?? $p['takenAt'] ?? null,
            'ownerUsername'   => $p['ownerUsername'] ?? $igUser,
        ];
    }

    // 6) aggregates
    $cnt = max(count($normalizedPosts), 1);
    $totalLikes = 0; $totalComments = 0; $totalSaves = 0; $totalViews = 0; $totalPlays = 0; $reelsCount = 0;
    foreach ($normalizedPosts as $p) {
        $totalLikes    += $p['likesCount'];
        $totalComments += $p['commentsCount'];
        $totalSaves    += $p['savesCount'];
        $totalViews    += $p['videoViewCount'];
        $totalPlays    += $p['videoPlayCount'];
        if ($p['isReel']) $reelsCount++;
    }
    $avgLikes    = round($totalLikes    / $cnt, 1);
    $avgComments = round($totalComments / $cnt, 1);
    $avgSaves    = round($totalSaves    / $cnt, 1);
    $avgViews    = round($totalViews    / $cnt, 1);
    $avgPlays    = round($totalPlays    / $cnt, 1);
    $engRate     = $followers > 0 ? round((($avgLikes + $avgComments) / $followers) * 100, 2) : 0;

    // 7) deep analytics
    $hashtags    = extractHashtagsFromPosts($normalizedPosts);
    $mentions    = extractMentionsFromPosts($normalizedPosts);
    $contentDist = calcContentTypeDistribution($normalizedPosts);
    $heatmap     = calcPostingHeatmap($normalizedPosts);
    $bioOpt      = analyzeBioOptimization($bio, $externalUrl, $isBusiness, $bizCategory);
    $langMix     = detectPostsLanguageMix($normalizedPosts);
    $locations   = extractTopLocations($normalizedPosts);
    $sponsored   = calcSponsoredRatio($normalizedPosts);
    $reelsPerf   = analyzeReelsPerformance($normalizedPosts);

    // 8) top 5 posts (used by sentiment + vision)
    $sortedByEng = $normalizedPosts;
    usort($sortedByEng, fn($a, $b) => ($b['likesCount'] + $b['commentsCount']) - ($a['likesCount'] + $a['commentsCount']));
    $top5 = array_slice($sortedByEng, 0, 5);

    // 9) comments + sentiment (optional)
    $sentiment = null;
    if (!empty($cfg['analysis']['enable_ig_comments'])) {
        try { $sentiment = analyzeIGCommentsSentiment($top5, $token, $cfg); }
        catch (\Throwable $e) { logError('IG sentiment failed', ['err' => $e->getMessage()]); $sentiment = ['success' => false, 'reason' => $e->getMessage()]; }
    }

    // 10) vision (optional)
    $vision = null;
    if (!empty($cfg['analysis']['enable_ig_vision'])) {
        try { $vision = analyzeIGImagesVision($top5, $cfg); }
        catch (\Throwable $e) { logError('IG vision failed', ['err' => $e->getMessage()]); $vision = ['success' => false, 'reason' => $e->getMessage()]; }
    }

    // 11) stories + highlights (optional)
    $stories = null;
    if (!empty($cfg['analysis']['enable_ig_stories']) && !$isPrivate) {
        try { $stories = scrapeIGStoriesAndHighlights($igUser, $token, $cfg); }
        catch (\Throwable $e) { logError('IG stories failed', ['err' => $e->getMessage()]); }
    }

    // 12) account health
    $health = calcAccountHealthScore([
        'followers'            => $followers,
        'following'            => $following,
        'posts_count'          => $postsTotal,
        'engagement_rate'      => $engRate,
        'is_verified'          => $verified,
        'is_business'          => $isBusiness,
        'private'              => $isPrivate,
        'has_reels'            => $reelsCount > 0,
        'website'              => $externalUrl,
        'bio_length'           => mb_strlen($bio),
        'posts_per_week'       => calcPostsPerWeek($normalizedPosts),
        'last_post_days'       => calcLastPostDays($normalizedPosts),
        'highlight_reel_count' => $highlights,
    ]);

    logInfo('IG scrape v3 done', [
        'username' => $igUser, 'posts' => $cnt, 'followers' => $followers,
        'sentiment' => $sentiment['success'] ?? false,
        'vision'    => $vision['success']    ?? false,
        'stories'   => $stories['success']   ?? false,
    ]);

    return [
        'success'              => true,
        'source'               => 'apify_ig_v3',
        'platform'             => 'instagram',
        // Identity
        'id'                   => $igUserId,
        'username'             => $igUser,
        'full_name'            => $fullName,
        'profile_url'          => $profileUrl,
        'profile_pic'          => $picUrl,
        'profile_pic_hd'       => $picUrlHD,
        // Profile data
        'bio'                  => $bio,
        'bio_length'           => mb_strlen($bio),
        'website'              => $externalUrl,
        'has_link'             => !empty($externalUrl),
        'followers'            => $followers,
        'following'            => $following,
        'posts_count'          => $postsTotal,
        'highlight_reel_count' => $highlights,
        'highlights'           => $highlights,
        'is_verified'          => $verified,
        'is_business'          => $isBusiness,
        'business_category'    => $bizCategory,
        'private'              => $isPrivate,
        'joined_recently'      => $joinedNew,
        'related_profiles'     => $relatedProfiles,
        // Engagement aggregates
        'avg_likes'            => $avgLikes,
        'avg_comments'         => $avgComments,
        'avg_saves'            => $avgSaves,
        'avg_video_views'      => $avgViews,
        'avg_video_plays'      => $avgPlays,
        'reels_count'          => $reelsCount,
        'has_reels'            => $reelsCount > 0,
        'engagement_rate'      => $engRate,
        'followers_following_ratio' => $following > 0 ? round($followers / $following, 2) : null,
        // Activity
        'posts_per_week'       => calcPostsPerWeek($normalizedPosts),
        'last_post_days'       => calcLastPostDays($normalizedPosts),
        // Top performers
        'top_post'             => $top5[0] ?? null,
        'top_5_posts'          => $top5,
        // Deep content analytics
        'hashtags_analysis'    => $hashtags,
        'mentions_analysis'    => $mentions,
        'content_distribution' => $contentDist,
        'posting_heatmap'      => $heatmap,
        'language_mix'         => $langMix,
        'locations'            => $locations,
        'sponsored_ratio'      => $sponsored,
        'reels_performance'    => $reelsPerf,
        'bio_optimization'     => $bioOpt,
        'account_health'       => $health,
        // Optional layers
        'comments_sentiment'   => $sentiment,
        'vision_analysis'      => $vision,
        'stories_data'         => $stories,
        // Legacy compat
        'deep_analysis'        => analyzeDeepContent($normalizedPosts),
        'latest_posts'         => array_slice($normalizedPosts, 0, 30),
        'accessible'           => true,
    ];
}


// ============================================================
// TikTok Scraper — Comprehensive (profile + 200 videos + full analytics)
// ============================================================
// ── TT-1 FIX: Blacklist مؤقت (file-based) للـ actors الفاشلة ──
if (!function_exists('_isActorBlacklisted')) {
function _isActorBlacklisted(string $actorId): bool {
    $file = sys_get_temp_dir() . '/apify_blacklist_' . md5($actorId);
    if (!file_exists($file)) return false;
    // blacklist لمدة ساعة واحدة
    return (time() - filemtime($file)) < 3600;
}
}

if (!function_exists('_blacklistActor')) {
function _blacklistActor(string $actorId): void {
    $file = sys_get_temp_dir() . '/apify_blacklist_' . md5($actorId);
    file_put_contents($file, (string)time());
}
}

function scrapeTikTok(string $url, string $token, array $cfg): array {
    // Actor الافتراضي: clockworks/tiktok-scraper (المدفوع — schema الحديث)
    // البديل المجاني: clockworks/free-tiktok-scraper بنفس الـ schema
    $primaryActor = $cfg['apis']['apify_actor_tiktok'] ?? 'clockworks/tiktok-scraper';
    $candidates   = array_values(array_unique(array_filter([
        $primaryActor,
        'clockworks/tiktok-scraper',
        'clockworks/free-tiktok-scraper',
        '0FXVyOXXEmdGcV88a',
    ])));

    // ── استخراج username (يدعم: user, @user, tiktok.com/@user, tiktok.com/user) ──
    if (!str_contains($url, 'tiktok.com')) {
        $username = ltrim($url, '@');
    } else {
        preg_match('/tiktok\.com\/@?([^\/\?#]+)/i', $url, $m);
        $username = $m[1] ?? '';
    }
    $username = trim($username, " \t\n\r\0\x0B/@");
    if (!$username) {
        return ['success' => false, 'platform' => 'tiktok', 'url' => $url, 'error' => 'لم يتم استخراج TikTok username'];
    }

    $profileUrl = 'https://www.tiktok.com/@' . $username;
    $resultsPerPage = (int)($cfg['apis']['tiktok_videos_limit'] ?? 200);
    if ($resultsPerPage < 30) $resultsPerPage = 30;
    if ($resultsPerPage > 500) $resultsPerPage = 500;

    foreach ($candidates as $actorId) {
        // TT-1 FIX: تخطي actors مُدرجة في blacklist
        if (_isActorBlacklisted($actorId)) {
            logInfo('Skipping blacklisted TikTok actor', ['actor' => $actorId]);
            continue;
        }

        logInfo('Starting TikTok scrape attempt', ['username' => $username, 'actor' => $actorId, 'limit' => $resultsPerPage]);

        $input = json_encode([
            'profiles'                 => [$profileUrl],
            'profileScrapeSections'    => ['videos'],
            'profileSorting'           => 'latest',
            'resultsPerPage'           => $resultsPerPage,
            'maxProfilesPerQuery'      => 1,
            'shouldDownloadVideos'     => false,
            'shouldDownloadCovers'     => false,
            'shouldDownloadAvatars'    => false,
            'shouldDownloadSlideshowImages' => false,
            'shouldDownloadSubtitles'  => false,
            'downloadSubtitlesOptions' => 'NEVER_DOWNLOAD_SUBTITLES',
            'proxyCountryCode'         => 'None',
        ], JSON_PRESERVE_ZERO_FRACTION | JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);

        $runId = _apifyStartRun($actorId, $input, $token);
        if (!$runId) {
            logError('Failed to start TikTok run; trying next actor', ['actor' => $actorId]);
            _blacklistActor($actorId); // TT-1 FIX: سجّل الفشل
            continue;
        }

        $result = _apifyWaitAndFetch($runId, $token, 180, $resultsPerPage);
        if (!$result) {
            logError('TikTok timeout; trying next actor', ['runId' => $runId, 'actor' => $actorId]);
            _blacklistActor($actorId); // TT-1 FIX: سجّل الفشل
            continue;
        }

        // فلترة العناصر الفارغة أو غير المتعلقة بالحساب
        $videos = array_values(array_filter($result, function ($v) use ($username) {
            if (!is_array($v) || empty($v)) return false;
            $author = $v['authorMeta']['name'] ?? $v['author']['uniqueId'] ?? $v['authorMeta']['uniqueId'] ?? '';
            // إذا الـ actor أرجع بيانات لمستخدم مختلف نتجاهلها (دعمًا لاستجابات غير دقيقة)
            return empty($author) || strcasecmp($author, $username) === 0;
        }));

        if (empty($videos)) {
            // ربما الـ actor أرجع بيانات بصيغة أخرى — جرّبه كما هو
            $videos = $result;
        }

        $firstItem = $videos[0] ?? [];
        if (empty($firstItem)) {
            logError('TikTok scrape returned empty; trying next actor', ['actor' => $actorId, 'runId' => $runId]);
            continue;
        }

        $author = $firstItem['authorMeta'] ?? $firstItem['author'] ?? [];

        // ── حساب الإحصائيات الكلية والمتوسطات ──
        $totalLikes = 0; $totalShares = 0; $totalSaves = 0; $totalViews = 0; $totalComments = 0;
        $totalDuration = 0; $videosWithDuration = 0;
        $sounds = []; $hashtagsAll = []; $mentionsAll = []; $captionLengths = [];
        $originalSoundsCount = 0;
        $verifiedHashtags = 0;

        foreach ($videos as $v) {
            $likes    = (int)($v['diggCount']    ?? $v['likesCount']    ?? $v['likes']    ?? 0);
            $shares   = (int)($v['shareCount']   ?? $v['sharesCount']   ?? $v['shares']   ?? 0);
            $comments = (int)($v['commentCount'] ?? $v['commentsCount'] ?? $v['comments'] ?? 0);
            $saves    = (int)($v['collectCount'] ?? $v['savesCount']    ?? $v['saves']    ?? 0);
            $views    = (int)($v['playCount']    ?? $v['viewCount']     ?? $v['views']    ?? 0);
            $duration = (int)($v['videoMeta']['duration'] ?? $v['duration'] ?? 0);

            $totalLikes    += $likes;
            $totalShares   += $shares;
            $totalComments += $comments;
            $totalSaves    += $saves;
            $totalViews    += $views;
            if ($duration > 0) { $totalDuration += $duration; $videosWithDuration++; }

            // الموسيقى
            $soundName  = $v['musicMeta']['musicName']  ?? $v['music']['title']    ?? '';
            $soundAuth  = $v['musicMeta']['musicAuthor']?? $v['music']['authorName']?? '';
            $isOriginal = (bool)($v['musicMeta']['musicOriginal'] ?? $v['music']['original'] ?? false);
            if ($isOriginal) $originalSoundsCount++;
            if ($soundName) {
                $key = $soundAuth ? "{$soundName} — {$soundAuth}" : $soundName;
                $sounds[$key] = ($sounds[$key] ?? 0) + 1;
            }

            // الهاشتاجات (من حقل hashtags المنظم لو موجود)
            if (!empty($v['hashtags']) && is_array($v['hashtags'])) {
                foreach ($v['hashtags'] as $h) {
                    $name = is_array($h) ? ($h['name'] ?? $h['title'] ?? '') : (string)$h;
                    $name = mb_strtolower(ltrim(trim($name), '#'));
                    if ($name) $hashtagsAll[$name] = ($hashtagsAll[$name] ?? 0) + 1;
                }
            }

            // الكابشن: استخراج هاشتاجات وإشارات
            $caption = (string)($v['text'] ?? $v['desc'] ?? $v['caption'] ?? '');
            if ($caption !== '') $captionLengths[] = mb_strlen($caption);
            if ($caption) {
                if (preg_match_all('/#([\p{L}\p{N}_]+)/u', $caption, $mh)) {
                    foreach ($mh[1] as $h) {
                        $h = mb_strtolower($h);
                        if ($h) $hashtagsAll[$h] = ($hashtagsAll[$h] ?? 0) + 1;
                    }
                }
                if (preg_match_all('/@([A-Za-z0-9_\.]+)/u', $caption, $mm)) {
                    foreach ($mm[1] as $u) {
                        $u = mb_strtolower($u);
                        if ($u) $mentionsAll[$u] = ($mentionsAll[$u] ?? 0) + 1;
                    }
                }
            }
        }

        $cnt        = max(count($videos), 1);
        $followers  = (int)($author['fans'] ?? $author['followerCount'] ?? $author['followers'] ?? 0);
        $following  = (int)($author['following'] ?? $author['followingCount'] ?? 0);
        $heartTotal = (int)($author['heart'] ?? $author['heartCount'] ?? 0);
        $videoCountTotal = (int)($author['video'] ?? $author['videoCount'] ?? count($videos));

        $avgLikes    = round($totalLikes    / $cnt, 1);
        $avgComments = round($totalComments / $cnt, 1);
        $avgShares   = round($totalShares   / $cnt, 1);
        $avgSaves    = round($totalSaves    / $cnt, 1);
        $avgViews    = (int)round($totalViews / $cnt);
        $avgDuration = $videosWithDuration > 0 ? round($totalDuration / $videosWithDuration, 1) : 0;
        $avgCaptionLen = !empty($captionLengths) ? (int)round(array_sum($captionLengths) / count($captionLengths)) : 0;

        // معدل التفاعل: المعيار الصناعي لتيك توك = (likes+comments+shares) / views * 100
        // مع fallback على followers لو المشاهدات صفر
        $engagementByViews = $totalViews > 0
            ? round((($totalLikes + $totalComments + $totalShares) / $totalViews) * 100, 2)
            : 0;
        $engagementByFollowers = $followers > 0
            ? round((($avgLikes + $avgComments + $avgShares) / $followers) * 100, 2)
            : 0;

        // ترتيب الأصوات والهاشتاجات
        arsort($sounds); arsort($hashtagsAll); arsort($mentionsAll);
        $trendingSounds = array_slice(array_keys($sounds), 0, 10);
        $topHashtags    = array_slice(array_keys($hashtagsAll), 0, 15);
        $topMentions    = array_slice(array_keys($mentionsAll), 0, 10);

        // أفضل 10 فيديوهات حسب التفاعل
        $rankedVideos = $videos;
        usort($rankedVideos, function ($a, $b) {
            $ea = (int)($a['diggCount'] ?? 0) + (int)($a['shareCount'] ?? 0) + (int)($a['commentCount'] ?? 0);
            $eb = (int)($b['diggCount'] ?? 0) + (int)($b['shareCount'] ?? 0) + (int)($b['commentCount'] ?? 0);
            return $eb - $ea;
        });
        $topVideos = array_map('_parseTikTokVideo', array_slice($rankedVideos, 0, 10));

        // كل المنشورات بصيغة موحّدة (تُستخدم في تحليل الفيديو وعرض الواجهة)
        $latestPosts = array_map('_parseTikTokVideo', $videos);

        logInfo('TikTok scrape successful', [
            'username' => $username,
            'actor'    => $actorId,
            'videos'   => $cnt,
            'followers'=> $followers,
        ]);

        // أنواع المحتوى (تيك توك = video دائمًا، لكن نتعقّب slideshow/photo)
        $typesCount = ['video' => 0, 'slideshow' => 0, 'photo' => 0];
        foreach ($videos as $v) {
            $hasImages = !empty($v['imageUrls']) || !empty($v['images']) || !empty($v['slideshow']);
            if ($hasImages) $typesCount['slideshow']++;
            else $typesCount['video']++;
        }

        // TT-2 FIX: Comments Sentiment (مكافئ لـ FB/IG)
        $ttSentiment = null;
        if (!empty($cfg['analysis']['enable_tt_comments'] ?? true)) {
            try {
                // سحب تعليقات أفضل 3 فيديوهات
                $topForComments = array_slice($topVideos, 0, 3);
                $allComments = [];
                foreach ($topForComments as $tv) {
                    $tvUrl = $tv['url'] ?? '';
                    if (!$tvUrl) continue;
                    if (function_exists('scrapePostComments')) {
                        $cResult = scrapePostComments($tvUrl, 'tiktok', $token, 30);
                        if ($cResult['success'] ?? false) {
                            $allComments = array_merge($allComments, $cResult['sample_phrases'] ?? []);
                        }
                    }
                }
                if (count($allComments) >= 5) {
                    $ttSentiment = [
                        'success' => true,
                        'total_comments' => count($allComments),
                        'samples' => array_slice($allComments, 0, 15),
                    ];
                }
            } catch (\Throwable $e) {
                if (function_exists('logError')) logError('TikTok comments failed', ['err' => $e->getMessage()]);
            }
        }

        return [
            'success'              => true,
            'source'               => 'apify_tt_v3',
            'platform'             => 'tiktok',
            'actor_used'           => $actorId,
            'url'                  => $profileUrl,

            // ── بيانات الحساب ──
            'username'             => (string)($author['name']     ?? $author['uniqueId']  ?? $username),
            'full_name'            => (string)($author['nickName'] ?? $author['nickname']  ?? ''),
            'bio'                  => (string)($author['signature']?? $author['bio']       ?? ''),
            'is_verified'          => (bool)($author['verified']   ?? $author['isVerified']?? false),
            'avatar'               => (string)($author['avatar']   ?? $author['avatarLarger'] ?? $author['avatarMedium'] ?? ''),
            'website'              => (string)($author['externalUrl'] ?? $author['website'] ?? ''),
            'region'               => (string)($author['region']      ?? ''),
            'language'             => (string)($author['language']    ?? ''),

            // ── أرقام إجمالية ──
            'followers'            => $followers,
            'following'            => $following,
            'likes'                => $heartTotal,           // إجمالي القلوب على كل المحتوى
            'video_count'          => $videoCountTotal,
            'videos_analyzed'      => $cnt,
            'total_views'          => $totalViews,
            'total_likes'          => $totalLikes,
            'total_comments'       => $totalComments,
            'total_shares'         => $totalShares,
            'total_saves'          => $totalSaves,

            // ── متوسطات ──
            'avg_likes'            => $avgLikes,
            'avg_comments'         => $avgComments,
            'avg_shares'           => $avgShares,
            'avg_saves'            => $avgSaves,
            'avg_views'            => $avgViews,
            'avg_video_duration'   => $avgDuration,        // بالثواني
            'avg_caption_length'   => $avgCaptionLen,

            // ── معدل التفاعل (المعيار الصناعي + fallback) ──
            'engagement_rate'              => $engagementByViews > 0 ? $engagementByViews : $engagementByFollowers,
            'engagement_rate_by_views'     => $engagementByViews,
            'engagement_rate_by_followers' => $engagementByFollowers,

            // ── معدل النشر والنشاط ──
            'posts_per_week'       => calcPostsPerWeek($videos),
            'last_post_days'       => calcLastPostDays($videos),

            // ── محتوى ──
            'trending_sounds'      => $trendingSounds,
            'original_sounds'      => $originalSoundsCount,
            'top_hashtags'         => $topHashtags,
            'top_mentions'         => $topMentions,
            'content_types'        => $typesCount,

            // ── تحليل عميق + أفضل المنشورات + كل المنشورات ──
            'top_videos'           => $topVideos,
            'top_post'             => $topVideos[0] ?? null,
            'deep_analysis'        => analyzeDeepContent($videos),
            'latest_posts'         => $latestPosts,         // كل الفيديوهات (200) — لا قطع
            'comments_sentiment'   => $ttSentiment,         // TT-2 FIX: تحليل مشاعر التعليقات
        ];
    }

    logError('All TikTok actors failed', ['username' => $username, 'tried' => $candidates]);
    return [
        'success'  => false,
        'platform' => 'tiktok',
        'url'      => $profileUrl,
        'username' => $username,
        'error'    => 'تعذّر جلب بيانات TikTok — حاول لاحقًا.',
    ];
}

/**
 * Normalize a single TikTok video into a flat structure used everywhere.
 */
function _parseTikTokVideo(array $v): array {
    $likes    = (int)($v['diggCount']    ?? $v['likesCount']    ?? $v['likes']    ?? 0);
    $shares   = (int)($v['shareCount']   ?? $v['sharesCount']   ?? $v['shares']   ?? 0);
    $comments = (int)($v['commentCount'] ?? $v['commentsCount'] ?? $v['comments'] ?? 0);
    $saves    = (int)($v['collectCount'] ?? $v['savesCount']    ?? $v['saves']    ?? 0);
    $views    = (int)($v['playCount']    ?? $v['viewCount']     ?? $v['views']    ?? 0);
    $caption  = (string)($v['text'] ?? $v['desc'] ?? $v['caption'] ?? '');

    // الهاشتاجات للفيديو الواحد
    $tags = [];
    if (!empty($v['hashtags']) && is_array($v['hashtags'])) {
        foreach ($v['hashtags'] as $h) {
            $name = is_array($h) ? ($h['name'] ?? $h['title'] ?? '') : (string)$h;
            $name = trim((string)$name);
            if ($name !== '') $tags[] = ltrim($name, '#');
        }
    } elseif (preg_match_all('/#([\p{L}\p{N}_]+)/u', $caption, $mh)) {
        $tags = $mh[1];
    }

    $createdTs = $v['createTimeISO'] ?? $v['createTime'] ?? $v['timestamp'] ?? null;
    if (is_numeric($createdTs)) {
        $createdIso = date('c', (int)$createdTs);
    } else {
        $createdIso = is_string($createdTs) ? $createdTs : '';
    }

    return [
        'id'            => (string)($v['id'] ?? $v['videoId'] ?? ''),
        'url'           => (string)($v['webVideoUrl'] ?? $v['videoUrl'] ?? $v['url'] ?? ''),
        'video_url'     => (string)($v['videoMeta']['downloadAddr'] ?? $v['videoMeta']['playAddr'] ?? $v['playAddr'] ?? ''),
        'cover'         => (string)($v['videoMeta']['coverUrl'] ?? $v['covers']['default'] ?? $v['cover'] ?? ''),
        'caption'       => $caption,
        'caption_length'=> mb_strlen($caption),
        'duration'      => (int)($v['videoMeta']['duration'] ?? $v['duration'] ?? 0),
        'created_at'    => $createdIso,
        // مقاييس
        'likes'         => $likes,
        'comments'      => $comments,
        'shares'        => $shares,
        'saves'         => $saves,
        'views'         => $views,
        'engagement'    => $likes + $comments + $shares,
        'engagement_rate'=> $views > 0 ? round((($likes + $comments + $shares) / $views) * 100, 2) : 0,
        // محتوى
        'hashtags'      => array_values(array_unique(array_filter(array_map('mb_strtolower', $tags)))),
        'is_pinned'     => (bool)($v['isPinned'] ?? $v['isPinnedItem'] ?? false),
        'is_ad'         => (bool)($v['isAd'] ?? false),
        'sound'         => [
            'name'     => (string)($v['musicMeta']['musicName']   ?? $v['music']['title']    ?? ''),
            'author'   => (string)($v['musicMeta']['musicAuthor'] ?? $v['music']['authorName']?? ''),
            'original' => (bool)($v['musicMeta']['musicOriginal'] ?? $v['music']['original']  ?? false),
        ],
    ];
}


// ============================================================
// Twitter / X Scraper — Comprehensive (profile + 100 tweets + analytics)
// ============================================================
// ── TW-4 FIX: Twitter Health Score مخصص ──
function _calcTwitterHealthScore(array $data): array {
    $score = 0; $issues = []; $strengths = [];
    $followers = (int)($data['followers'] ?? 0);
    $eng = (float)($data['engagement_rate'] ?? 0);
    $postsPerWeek = (float)($data['posts_per_week'] ?? 0);
    $verified = (bool)($data['is_verified'] ?? false);

    if ($postsPerWeek >= 5) { $score += 25; $strengths[] = 'نشاط ممتاز'; }
    elseif ($postsPerWeek >= 2) { $score += 15; }
    elseif ($postsPerWeek > 0) { $score += 5; $issues[] = 'نشر متباعد'; }
    else $issues[] = 'لا نشاط';

    if ($eng >= 3) { $score += 25; $strengths[] = 'تفاعل ممتاز'; }
    elseif ($eng >= 1) { $score += 15; }
    elseif ($eng > 0) { $score += 5; }
    else $issues[] = 'تفاعل ضعيف';

    if ($followers >= 100000) $score += 20;
    elseif ($followers >= 10000) $score += 15;
    elseif ($followers >= 1000) $score += 10;
    else $issues[] = 'جمهور صغير';

    if ($verified) { $score += 15; $strengths[] = 'حساب موثّق'; }
    if (!empty($data['website'])) { $score += 5; }
    if (!empty($data['bio']) && mb_strlen($data['bio']) >= 50) { $score += 10; $strengths[] = 'Bio محسّن'; }
    else $issues[] = 'Bio قصير أو فارغ';

    $score = min(100, $score);
    $grade = $score >= 80 ? 'A' : ($score >= 65 ? 'B' : ($score >= 50 ? 'C' : ($score >= 35 ? 'D' : 'F')));
    return ['score' => $score, 'grade' => $grade, 'strengths' => $strengths, 'issues' => $issues];
}

function scrapeTwitter(string $url, string $token, array $cfg): array {
    // ملاحظة: قائمة الـ Apify actors لتويتر تتغيّر بسرعة (التحول من twitter.com → x.com).
    // نحاول أكثر من actor تلقائيًا. الأولوية للـ actors التي تجلب التغريدات بشكل افتراضي.
    $primaryActor = $cfg['apis']['apify_actor_twitter'] ?? '';
    $candidates   = array_values(array_unique(array_filter([
        $primaryActor,
        'apidojo/tweet-scraper',          // يدعم startUrls لبروفايل + تغريدات
        'kaitoeasyapi/twitter-x-data-tweet-scraper-pay-per-result-cheapest',
    ])));
    // TW-1 FIX: حد أقصى 3 actors لتجنب timeout مفرط (كان 7 actors = حتى 17.5 دقيقة)
    $candidates = array_slice($candidates, 0, 3);

    // ── استخراج username ──
    if (!str_contains($url, 'twitter.com') && !str_contains($url, 'x.com')) {
        $username = ltrim($url, '@');
    } else {
        preg_match('/(?:twitter|x)\.com\/([^\/\?#]+)/i', $url, $m);
        $username = $m[1] ?? '';
    }
    $username = trim($username, " \t\n\r\0\x0B/@");
    // إزالة أي مسارات فرعية محتملة (مثل /status/...)
    if (str_contains($username, '/')) {
        $username = explode('/', $username)[0];
    }

    if (!$username) {
        return [
            'success'  => false, 'platform' => 'twitter',
            'error'    => 'لم يتم استخراج Twitter username من الرابط', 'url' => $url,
        ];
    }

    $maxTweets = (int)($cfg['apis']['twitter_tweets_limit'] ?? 100);
    if ($maxTweets < 20)  $maxTweets = 20;
    if ($maxTweets > 300) $maxTweets = 300;

    $profileUrlTwitter = 'https://twitter.com/' . $username;
    $profileUrlX       = 'https://x.com/' . $username;

    foreach ($candidates as $actorId) {
        logInfo('Starting Twitter scrape attempt', ['username' => $username, 'actor' => $actorId, 'tweets' => $maxTweets]);

        // TW-1 FIX: تخطي actors مُدرجة في blacklist
        if (function_exists('_isActorBlacklisted') && _isActorBlacklisted($actorId)) {
            logInfo('Skipping blacklisted Twitter actor', ['actor' => $actorId]);
            continue;
        }

        // TW-2 FIX: بناء input مخصص لكل actor بدلاً من schema موحّد (بعض actors تفشل 400 مع حقول غير معروفة)
        // ── إصلاح Twitter: دعم actor nfp1fpt5gUlBwPcor (searchTerms-based) كـ actor أساسي
        if (str_contains($actorId, 'nfp1fpt5')) {
            $input = json_encode([
                'searchTerms' => ['from:' . $username],
                'sort'        => 'Latest',
                'maxItems'    => $maxTweets,
            ], JSON_PRESERVE_ZERO_FRACTION | JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
        } elseif (str_contains($actorId, 'tweet-scraper') || str_contains($actorId, 'apidojo')) {
            $input = json_encode([
                'startUrls'        => [$profileUrlTwitter, $profileUrlX],
                'maxItems'         => $maxTweets,
                'addUserInfo'      => true,
                'sort'             => 'Latest',
            ], JSON_PRESERVE_ZERO_FRACTION | JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
        } elseif (str_contains($actorId, 'profile-scraper') || str_contains($actorId, 'kaitoeasyapi')) {
            $input = json_encode([
                'handles'          => [$username],
                'tweetsDesired'    => $maxTweets,
                'getAbout'         => true,
            ], JSON_PRESERVE_ZERO_FRACTION | JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
        } else {
            // fallback عام
            $input = json_encode([
                'startUrls'        => [$profileUrlTwitter],
                'twitterHandles'   => [$username],
                'handles'          => [$username],
                'maxItems'         => $maxTweets,
                'addUserInfo'      => true,
                'sort'             => 'Latest',
            ], JSON_PRESERVE_ZERO_FRACTION | JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
        }

        $runId = _apifyStartRun($actorId, $input, $token);
        if (!$runId) {
            logError('Failed to start Twitter run; trying next actor', ['actor' => $actorId]);
            continue;
        }

        $result = _apifyWaitAndFetch($runId, $token, 90, $maxTweets + 5); // TW-1 FIX: كان 150 — قلّلناه لتجنب timeout مفرط
        if (!$result) {
            logError('Twitter scrape timeout; trying next actor', ['run_id' => $runId, 'actor' => $actorId]);
            continue;
        }

        // تصنيف النتائج: تغريدات vs بروفايل
        $profile = null;
        $tweets  = [];
        foreach ($result as $item) {
            if (!is_array($item) || empty($item)) continue;
            $type = strtolower((string)($item['type'] ?? ''));

            // Profile-only schemas: يحتوي على followers بدون نص تغريدة
            $hasTweetText = !empty($item['text']) || !empty($item['fullText']) || !empty($item['full_text']);
            $hasUserBlock = !empty($item['user']) || !empty($item['author']);
            $hasFollowers = isset($item['followers']) || isset($item['followersCount']) || isset($item['followers_count']);

            if ($type === 'tweet' || $hasTweetText) {
                $tweets[] = $item;
                // استخراج البروفايل من user داخل التغريدة لو لم يكن لدينا بعد
                if (!$profile && $hasUserBlock) {
                    $profile = $item['user'] ?? $item['author'] ?? null;
                }
            } elseif ($hasFollowers && !$hasTweetText) {
                // العنصر بروفايل خالص
                if (!$profile) $profile = $item;
            } else {
                // غير معروف — لو فيه user block استخدمه، وإلا تجاهله
                if (!$profile && $hasUserBlock) $profile = $item['user'] ?? $item['author'] ?? null;
            }
        }

        // لو لم نجد profile منفصل، حاول الاستخراج من أول تغريدة
        if (!$profile && !empty($tweets)) {
            $profile = $tweets[0]['user'] ?? $tweets[0]['author'] ?? $tweets[0];
        }
        // TW-3 FIX: تأكد أنه بروفايل فعلاً (يحتوي followers أو screen_name) وليس تغريدة
        if (!$profile && !empty($result[0])) {
            $candidate = $result[0];
            if (is_array($candidate) && (
                isset($candidate['followers']) || isset($candidate['followersCount'])
                || isset($candidate['followers_count']) || isset($candidate['screenName'])
                || isset($candidate['screen_name'])
            )) {
                $profile = $candidate;
            }
        }

        // التحقق من وجود حقول تويتر معروفة
        $knownTwitterKeys = [
            'userName','screenName','screen_name','handle','username',
            'followersCount','followers_count','followerCount','followers',
            'statusesCount','statuses_count','tweetCount','tweet_count','tweetsCount',
            'name','displayName','display_name','fullName',
        ];
        $hasRecognizedField = is_array($profile) && !empty(array_intersect_key($profile, array_flip($knownTwitterKeys)));
        if (!$hasRecognizedField && empty($tweets)) {
            logError('Twitter actor returned data without identifiable fields; trying next actor', [
                'actor' => $actorId, 'sample_keys' => is_array($profile) ? array_slice(array_keys($profile), 0, 10) : [],
            ]);
            continue;
        }

        // ─── تجميع الإحصائيات من التغريدات ───
        $totalLikes = 0; $totalRetweets = 0; $totalReplies = 0; $totalViews = 0; $totalQuotes = 0; $totalBookmarks = 0;
        $hashtags = []; $mentions = []; $urls = []; $captionLengths = [];
        $typesCount = ['text' => 0, 'photo' => 0, 'video' => 0, 'reply' => 0, 'retweet' => 0, 'quote' => 0];

        $parsedTweets = [];
        foreach ($tweets as $t) {
            $parsed = _parseTweet($t);
            $parsedTweets[] = $parsed;

            $totalLikes     += $parsed['likes'];
            $totalRetweets  += $parsed['retweets'];
            $totalReplies   += $parsed['replies'];
            $totalViews     += $parsed['views'];
            $totalQuotes    += $parsed['quotes'];
            $totalBookmarks += $parsed['bookmarks'];

            if ($parsed['caption_length'] > 0) $captionLengths[] = $parsed['caption_length'];

            foreach ($parsed['hashtags'] as $h) { $h = mb_strtolower($h); if ($h) $hashtags[$h] = ($hashtags[$h] ?? 0) + 1; }
            foreach ($parsed['mentions'] as $m) { $m = mb_strtolower($m); if ($m) $mentions[$m] = ($mentions[$m] ?? 0) + 1; }
            foreach ($parsed['urls'] as $u) { if ($u) $urls[$u] = ($urls[$u] ?? 0) + 1; }

            if ($parsed['is_retweet'])    $typesCount['retweet']++;
            elseif ($parsed['is_reply'])  $typesCount['reply']++;
            elseif ($parsed['is_quote'])  $typesCount['quote']++;
            elseif ($parsed['has_video']) $typesCount['video']++;
            elseif ($parsed['has_photo']) $typesCount['photo']++;
            else                          $typesCount['text']++;
        }

        // ─── تطبيع البروفايل ───
        $parsed = _parseTwitterProfile(is_array($profile) ? $profile : [], $username);

        // إن لم نحصل على followers من profile، حاول من user داخل أول تغريدة
        if (($parsed['followers'] ?? 0) === 0 && !empty($tweets[0]['user'])) {
            $fb = _parseTwitterProfile($tweets[0]['user'], $username);
            $parsed['followers']  = $parsed['followers']  ?: $fb['followers'];
            $parsed['following']  = $parsed['following']  ?: $fb['following'];
            $parsed['posts_count']= $parsed['posts_count']?: $fb['posts_count'];
            $parsed['bio']        = $parsed['bio']        ?: $fb['bio'];
            $parsed['avatar']     = $parsed['avatar']     ?: $fb['avatar'];
            $parsed['full_name']  = $parsed['full_name']  ?: $fb['full_name'];
            $parsed['is_verified']= $parsed['is_verified']|| $fb['is_verified'];
            $parsed['location']   = $parsed['location']   ?: $fb['location'];
            $parsed['website']    = $parsed['website']    ?: $fb['website'];
        }

        $cnt = max(count($parsedTweets), 1);
        $followers = (int)$parsed['followers'];

        // المتوسطات تُحسب فقط على التغريدات الأصلية (ليس RT) لأن RT تُكرّر مقاييس الأصل
        $originalTweets = array_values(array_filter($parsedTweets, fn($t) => !$t['is_retweet']));
        $cntOrig = max(count($originalTweets), 1);

        $sumLikesO    = array_sum(array_column($originalTweets, 'likes'));
        $sumRtO       = array_sum(array_column($originalTweets, 'retweets'));
        $sumRepliesO  = array_sum(array_column($originalTweets, 'replies'));
        $sumViewsO    = array_sum(array_column($originalTweets, 'views'));
        $sumQuotesO   = array_sum(array_column($originalTweets, 'quotes'));

        $avgLikes    = round($sumLikesO   / $cntOrig, 1);
        $avgRetweets = round($sumRtO      / $cntOrig, 1);
        $avgReplies  = round($sumRepliesO / $cntOrig, 1);
        $avgQuotes   = round($sumQuotesO  / $cntOrig, 1);
        $avgViews    = (int)round($sumViewsO / $cntOrig);
        $avgCaption  = !empty($captionLengths) ? (int)round(array_sum($captionLengths) / count($captionLengths)) : 0;

        $engByViews = $sumViewsO > 0
            ? round((($sumLikesO + $sumRtO + $sumRepliesO + $sumQuotesO) / $sumViewsO) * 100, 2)
            : 0;
        $engByFollowers = $followers > 0
            ? round((($avgLikes + $avgRetweets + $avgReplies + $avgQuotes) / $followers) * 100, 2)
            : 0;

        // ترتيب
        arsort($hashtags); arsort($mentions); arsort($urls);
        $topHashtags = array_slice(array_keys($hashtags), 0, 15);
        $topMentions = array_slice(array_keys($mentions), 0, 10);
        $topUrls     = array_slice(array_keys($urls), 0, 10);

        // أفضل 10 تغريدات
        $rankedTweets = $parsedTweets;
        usort($rankedTweets, fn($a, $b) => ($b['likes'] + $b['retweets'] + $b['replies']) - ($a['likes'] + $a['retweets'] + $a['replies']));
        $topTweets = array_slice($rankedTweets, 0, 10);

        // معدل النشر / آخر تغريدة (نُعيد timestamps إلى الصيغة المتوقعة من calc helpers)
        $tweetsForCalc = array_map(fn($t) => ['timestamp' => $t['created_at'] ?? '', 'caption' => $t['text']], $parsedTweets);
        $postsPerWeek  = calcPostsPerWeek($tweetsForCalc);
        $lastPostDays  = calcLastPostDays($tweetsForCalc);

        logInfo('Twitter scrape successful', [
            'username' => $parsed['username'],
            'actor'    => $actorId,
            'tweets'   => $cnt,
            'original' => $cntOrig,
            'followers'=> $followers,
        ]);

        // TW-4 FIX: حساب Twitter Health Score مخصص
        $twHealthScore = _calcTwitterHealthScore([
            'followers' => $followers,
            'engagement_rate' => $engByViews > 0 ? $engByViews : $engByFollowers,
            'posts_per_week' => $postsPerWeek,
            'is_verified' => $parsed['is_verified'] ?? false,
            'website' => $parsed['website'] ?? '',
            'bio' => $parsed['bio'] ?? '',
        ]);

        return array_merge($parsed, [
            'success'      => true,
            'source'       => 'apify_tw_v3',
            'actor_used'   => $actorId,
            'url'          => $profileUrlTwitter,

            // ── أرقام إجمالية على عينة التغريدات ──
            'tweets_analyzed'      => $cnt,
            'original_tweets'      => $cntOrig,
            'retweets_in_sample'   => $cnt - $cntOrig,
            'total_likes'          => $totalLikes,
            'total_retweets'       => $totalRetweets,
            'total_replies'        => $totalReplies,
            'total_quotes'         => $totalQuotes,
            'total_views'          => $totalViews,
            'total_bookmarks'      => $totalBookmarks,

            // ── متوسطات ──
            'avg_likes'        => $avgLikes,
            'avg_retweets'     => $avgRetweets,
            'avg_replies'      => $avgReplies,
            'avg_quotes'       => $avgQuotes,
            'avg_views'        => $avgViews,
            'avg_caption_length' => $avgCaption,

            // ── معدل التفاعل ──
            'engagement_rate'              => $engByViews > 0 ? $engByViews : $engByFollowers,
            'engagement_rate_by_views'     => $engByViews,
            'engagement_rate_by_followers' => $engByFollowers,

            // ── معدل النشر ──
            'posts_per_week'   => $postsPerWeek,
            'last_post_days'   => $lastPostDays,

            // ── محتوى ──
            'top_hashtags'     => $topHashtags,
            'top_mentions'     => $topMentions,
            'top_urls'         => $topUrls,
            'content_types'    => $typesCount,

            // ── Top + كل التغريدات ──
            'top_tweets'       => $topTweets,
            'top_post'         => $topTweets[0] ?? null,
            'deep_analysis'    => analyzeDeepContent(array_map(fn($t) => [
                'caption'      => $t['text'],
                'type'         => $t['has_video'] ? 'video' : ($t['has_photo'] ? 'image' : 'text'),
                'url'          => $t['url'],
                'image'        => $t['media'][0] ?? '',
                'likesCount'   => $t['likes'],
                'commentsCount'=> $t['replies'],
                'hashtags'     => $t['hashtags'],
            ], $parsedTweets)),
            'latest_posts'     => $parsedTweets,    // كل التغريدات
            'health_score'     => $twHealthScore,    // TW-4 FIX: Twitter Health Score
        ]);
    }

    logError('All Twitter actors failed', ['username' => $username, 'tried' => $candidates]);
    return [
        'success'  => false,
        'platform' => 'twitter',
        'username' => $username,
        'url'      => $profileUrlTwitter,
        'error'    => 'تعذّر جلب بيانات تويتر — حاول لاحقًا.',
    ];
}

/**
 * Normalize a single tweet into a flat structure.
 */
function _parseTweet(array $t): array {
    $text = (string)($t['text'] ?? $t['fullText'] ?? $t['full_text'] ?? $t['content'] ?? '');

    // الهاشتاجات والإشارات (من entities أو من النص)
    $hashtags = []; $mentions = []; $urls = [];
    if (!empty($t['entities']['hashtags']) && is_array($t['entities']['hashtags'])) {
        foreach ($t['entities']['hashtags'] as $h) $hashtags[] = is_array($h) ? ($h['text'] ?? $h['tag'] ?? '') : (string)$h;
    }
    if (!empty($t['hashtags']) && is_array($t['hashtags'])) {
        foreach ($t['hashtags'] as $h) $hashtags[] = is_array($h) ? ($h['text'] ?? $h['tag'] ?? '') : (string)$h;
    }
    if (empty($hashtags) && preg_match_all('/#([\p{L}\p{N}_]+)/u', $text, $mh)) {
        $hashtags = $mh[1];
    }
    $hashtags = array_values(array_unique(array_filter(array_map(fn($h) => ltrim(trim((string)$h), '#'), $hashtags))));

    if (!empty($t['entities']['user_mentions']) && is_array($t['entities']['user_mentions'])) {
        foreach ($t['entities']['user_mentions'] as $m) $mentions[] = is_array($m) ? ($m['screen_name'] ?? $m['username'] ?? '') : (string)$m;
    }
    if (empty($mentions) && preg_match_all('/@([A-Za-z0-9_]+)/u', $text, $mm)) {
        $mentions = $mm[1];
    }
    $mentions = array_values(array_unique(array_filter(array_map('trim', $mentions))));

    if (!empty($t['entities']['urls']) && is_array($t['entities']['urls'])) {
        foreach ($t['entities']['urls'] as $u) $urls[] = is_array($u) ? ($u['expanded_url'] ?? $u['url'] ?? '') : (string)$u;
    }
    if (!empty($t['urls']) && is_array($t['urls'])) {
        foreach ($t['urls'] as $u) $urls[] = is_array($u) ? ($u['expanded_url'] ?? $u['url'] ?? '') : (string)$u;
    }
    $urls = array_values(array_unique(array_filter($urls)));

    // الميديا
    $media = [];
    $hasVideo = false; $hasPhoto = false;
    $mediaSources = $t['media'] ?? $t['extendedEntities']['media'] ?? $t['extended_entities']['media'] ?? $t['entities']['media'] ?? [];
    if (is_array($mediaSources)) {
        foreach ($mediaSources as $m) {
            if (!is_array($m)) continue;
            $type = strtolower((string)($m['type'] ?? ''));
            $mUrl = (string)($m['media_url_https'] ?? $m['mediaUrlHttps'] ?? $m['mediaUrl'] ?? $m['media_url'] ?? $m['url'] ?? '');
            if ($mUrl) $media[] = $mUrl;
            if (str_contains($type, 'video') || str_contains($type, 'gif')) $hasVideo = true;
            elseif (str_contains($type, 'photo') || str_contains($type, 'image')) $hasPhoto = true;
        }
    }
    if (empty($media) && !empty($t['imageUrl'])) { $media[] = $t['imageUrl']; $hasPhoto = true; }
    if (empty($media) && !empty($t['videoUrl'])) { $media[] = $t['videoUrl']; $hasVideo = true; }

    $isRetweet = (bool)($t['isRetweet'] ?? $t['retweeted'] ?? false) || str_starts_with(trim($text), 'RT @');
    $isReply   = !empty($t['inReplyToStatusId']) || !empty($t['in_reply_to_status_id']) || !empty($t['replyTo']) || !empty($t['inReplyToId']);
    $isQuote   = (bool)($t['isQuote'] ?? $t['is_quote_status'] ?? !empty($t['quotedTweet']));

    $createdAt = $t['createdAt'] ?? $t['created_at'] ?? $t['date'] ?? $t['timestamp'] ?? '';

    return [
        'id'           => (string)($t['id'] ?? $t['id_str'] ?? $t['tweetId'] ?? ''),
        'url'          => (string)($t['url'] ?? $t['twitterUrl'] ?? $t['link'] ?? ''),
        'text'         => $text,
        'caption_length'=> mb_strlen($text),
        'created_at'   => is_string($createdAt) ? $createdAt : '',
        'lang'         => (string)($t['lang'] ?? $t['language'] ?? ''),
        // مقاييس
        'likes'        => (int)($t['favoriteCount'] ?? $t['likeCount'] ?? $t['favouriteCount'] ?? $t['favorites'] ?? $t['likes'] ?? 0),
        'retweets'     => (int)($t['retweetCount'] ?? $t['retweets'] ?? 0),
        'replies'      => (int)($t['replyCount']   ?? $t['replies']   ?? 0),
        'quotes'       => (int)($t['quoteCount']   ?? $t['quotes']    ?? 0),
        'views'        => (int)($t['viewCount']    ?? $t['views']     ?? $t['impressionCount'] ?? 0),
        'bookmarks'    => (int)($t['bookmarkCount']?? $t['bookmarks'] ?? 0),
        // محتوى
        'hashtags'     => $hashtags,
        'mentions'     => $mentions,
        'urls'         => $urls,
        'media'        => $media,
        'has_photo'    => $hasPhoto,
        'has_video'    => $hasVideo,
        // أنواع
        'is_retweet'   => $isRetweet,
        'is_reply'     => $isReply,
        'is_quote'     => $isQuote,
        'source'       => (string)($t['source'] ?? ''),
    ];
}

/**
 * Normalize Twitter profile from various Apify actors (camelCase, snake_case, mixed).
 * Different actors return different field names — we try them all.
 */
function _parseTwitterProfile(array $p, string $usernameFallback): array {
    $first = static function (array $haystack, array $keys, $default = null) {
        foreach ($keys as $k) {
            if (array_key_exists($k, $haystack) && $haystack[$k] !== null && $haystack[$k] !== '') {
                return $haystack[$k];
            }
        }
        return $default;
    };
    $intOr0 = static function ($v): int {
        if (is_numeric($v)) return (int)$v;
        return 0;
    };

    return [
        'success'    => true,
        'platform'   => 'twitter',
        'username'   => (string)$first($p, ['userName','screenName','screen_name','handle','username'], $usernameFallback),
        'full_name'  => (string)$first($p, ['name','displayName','display_name','fullName','full_name'], ''),
        'followers'  => $intOr0($first($p, ['followers','followersCount','followers_count','followerCount'], 0)),
        'following'  => $intOr0($first($p, ['following','followingCount','following_count','friendsCount','friends_count'], 0)),
        'posts_count'=> $intOr0($first($p, ['statusesCount','statuses_count','tweetCount','tweet_count','tweetsCount','postsCount','posts_count'], 0)),
        'likes_given'=> $intOr0($first($p, ['favouritesCount','favoritesCount','likesCount','likes_count'], 0)),
        'media_count'=> $intOr0($first($p, ['mediaCount','media_count','statuses_with_media'], 0)),
        'listed_count'=> $intOr0($first($p, ['listedCount','listed_count'], 0)),
        'bio'        => (string)$first($p, ['description','bio','about'], ''),
        'location'   => (string)$first($p, ['location','place'], ''),
        'is_verified'=> (bool)$first($p, ['verified','isVerified','is_verified','isBlueVerified'], false),
        'is_blue_verified' => (bool)$first($p, ['isBlueVerified','blueVerified','is_blue_verified'], false),
        'website'    => (string)$first($p, ['url','external_url','externalUrl','website'], ''),
        'avatar'     => (string)$first($p, ['profileImageUrlHttps','profile_image_url_https','profileImageUrl','profile_image_url','avatar'], ''),
        'header_image'=> (string)$first($p, ['profileBannerUrl','profile_banner_url','bannerUrl','banner'], ''),
        'created_at' => (string)$first($p, ['createdAt','created_at','joinDate','join_date'], ''),
        'protected'  => (bool)$first($p, ['protected','isProtected','is_protected'], false),
    ];
}

// ============================================================
// Apify API Public Aliases (تعيد استخدام الدوال الداخلية _apify*)
// ============================================================
function startApifyRun(string $actorId, array $input, string $token): ?string {
    return _apifyStartRun($actorId, json_encode($input), $token);
}

function waitForApifyResult(string $runId, string $token, int $maxWait): ?array {
    return _apifyWaitAndFetch($runId, $token, $maxWait);
}

function fetchApifyDataset(string $datasetId, string $token): ?array {
    if (!$datasetId) return null;
    $url = "https://api.apify.com/v2/datasets/{$datasetId}/items?token={$token}&limit=100";
    $ch  = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true) ?: null;
}

// ============================================================
// Calculation Helpers
// ============================================================
function calcAvgEngagement(array $posts): float {
    if (!$posts) return 0;
    $total = array_sum(array_map(fn($p) =>
        (int)($p['likes']        ?? $p['likesCount']    ?? $p['reactionsCount'] ?? 0)
      + (int)($p['comments']     ?? $p['commentsCount'] ?? $p['commentCount']   ?? 0)
      + (int)($p['shares']       ?? $p['sharesCount']   ?? $p['shareCount']     ?? 0)
    , $posts));
    return round($total / count($posts), 1);
}

function _cleanRating($val): ?float {
    if ($val === null) return null;
    if (is_numeric($val)) return round((float)$val, 1);
    // Extract first number, allowing decimals
    if (preg_match('/(\d+[\.,]\d+)/', $val, $m)) {
        return round((float)str_replace(',', '.', $m[1]), 1);
    }
    if (preg_match('/(\d+)/', $val, $m)) {
        return round((float)$m[1], 1);
    }
    return null;
}

function calcAvgLikes(array $posts): float {
    if (!$posts) return 0;
    return round(array_sum(array_column($posts, 'likesCount')) / count($posts), 1);
}

function calcAvgComments(array $posts): float {
    if (!$posts) return 0;
    return round(array_sum(array_column($posts, 'commentsCount')) / count($posts), 1);
}

function calcIGEngagement(array $posts, int $followers): float {
    if (!$posts || !$followers) return 0;
    $avg = calcAvgLikes($posts) + calcAvgComments($posts);
    return round(($avg / $followers) * 100, 2);
}

function getTopPost(array $posts): ?array {
    if (!$posts) return null;
    usort($posts, fn($a, $b) =>
        ((int)($b['likes'] ?? $b['likesCount'] ?? 0) + (int)($b['comments'] ?? $b['commentsCount'] ?? 0) + (int)($b['shares'] ?? $b['sharesCount'] ?? 0))
      - ((int)($a['likes'] ?? $a['likesCount'] ?? 0) + (int)($a['comments'] ?? $a['commentsCount'] ?? 0) + (int)($a['shares'] ?? $a['sharesCount'] ?? 0))
    );
    // ✅ تطبيع: نضمن وجود حقل url لاستخدامه في scrapePostComments لاحقاً
    $top = $posts[0] ?? null;
    if (is_array($top) && empty($top['url'])) {
        $top['url'] = $top['postUrl']
                   ?? $top['link']
                   ?? $top['permalink']
                   ?? $top['permalink_url']
                   ?? '';
    }
    return $top;
}

function getTopIGPost(array $posts): ?array {
    if (!$posts) return null;
    usort($posts, fn($a, $b) => (($b['likesCount'] ?? 0) + ($b['commentsCount'] ?? 0)) - (($a['likesCount'] ?? 0) + ($a['commentsCount'] ?? 0)));
    return $posts[0] ?? null;
}

function calcPostsPerWeek(array $posts): float {
    if (count($posts) < 2) return 0;
    $timestamps = array_filter(array_map(fn($p) => strtotime($p['timestamp'] ?? $p['takenAt'] ?? ''), $posts));
    if (count($timestamps) < 2) return 0;

    // IG-2 FIX: استخدم آخر 30 يوم فقط لحساب المعدل الحالي (أدق من range كامل للحسابات القديمة)
    $now = time();
    $thirtyDaysAgo = $now - (30 * 86400);
    $recentPosts = array_filter($timestamps, fn($ts) => $ts >= $thirtyDaysAgo);

    if (count($recentPosts) >= 2) {
        // معدل بناءً على آخر 30 يوم
        return round(count($recentPosts) / (30 / 7), 1);
    }

    // fallback: لو أقل من 2 منشور في 30 يوم، استخدم range كامل
    $range = (max($timestamps) - min($timestamps));
    if ($range <= 0) return 0;
    return round(count($timestamps) / ($range / 604800), 1); // 604800 = ثواني الأسبوع
}

function calcLastPostDays(array $posts): ?int {
    if (!$posts) return null;
    $timestamps = array_filter(array_map(fn($p) => strtotime($p['timestamp'] ?? $p['takenAt'] ?? ''), $posts));
    if (!$timestamps) return null;
    return (int)((time() - max($timestamps)) / 86400);
}

// ── المحلل العميق (Deep Content Analyzer) ───────────────
function analyzeDeepContent(array $posts): array {
    if (empty($posts)) return [];

    $total = count($posts);
    $types = ['video' => 0, 'image' => 0, 'carousel' => 0];
    $hashtags = [];
    $totalWords = 0;
    $ctaCount = 0;
    $totalShares = 0;
    $totalVideoViews = 0;
    $reactionsTotal = ['like'=>0,'love'=>0,'haha'=>0,'wow'=>0,'sad'=>0,'angry'=>0,'care'=>0];
    $postingHours = []; // hour-of-day distribution
    $postingDays  = []; // day-of-week distribution

    // الكلمات التي لو وجدت بالنص تدل على دعوة لإجراء
    $ctaKeywords = ['رابط', 'بايو', 'bio', 'link', 'تواصل', 'واتساب', 'رسالة', 'اشتري', 'احجز', 'سجل', 'خصم', 'الآن', 'تخفيض', 'اتصل', 'موقعنا'];

    $parsed_posts = [];

    foreach ($posts as $p) {
        $text = $p['caption'] ?? $p['text'] ?? $p['message'] ?? '';
        $type = strtolower($p['type'] ?? $p['mediaType'] ?? '');
        $url = $p['url'] ?? $p['postUrl'] ?? $p['permalink'] ?? $p['permalink_url'] ?? '';
        $img = $p['displayUrl'] ?? $p['thumbnailUrl'] ?? $p['imageUrl'] ?? '';
        if (empty($img) && is_string($p['image'] ?? null)) $img = $p['image'];
        if (empty($img) && isset($p['image']['uri'])) $img = $p['image']['uri'];
        if (empty($img) && !empty($p['media'][0]['thumbnail'])) $img = $p['media'][0]['thumbnail'];
        if (empty($img) && !empty($p['media'][0]['image'])) $img = is_string($p['media'][0]['image']) ? $p['media'][0]['image'] : ($p['media'][0]['image']['uri'] ?? '');
        if (empty($img) && !empty($p['attachments'][0]['image'])) $img = is_string($p['attachments'][0]['image']) ? $p['attachments'][0]['image'] : ($p['attachments'][0]['image']['uri'] ?? '');
        if (empty($img) && !empty($p['attachments'][0]['media']['image']['src'])) $img = $p['attachments'][0]['media']['image']['src'];
        if (empty($img) && !empty($p['thumbnail'])) $img = $p['thumbnail'];
        $likes    = (int)($p['likesCount']    ?? $p['likes']        ?? $p['reactionsCount'] ?? 0);
        $comments = (int)($p['commentsCount'] ?? $p['comments']     ?? $p['commentCount']   ?? 0);
        $shares   = (int)($p['sharesCount']   ?? $p['shareCount']   ?? $p['shares']         ?? 0);
        $views    = (int)($p['videoViewCount']?? $p['viewsCount']   ?? $p['videoViews']     ?? $p['playCount'] ?? 0);
        $totalShares     += $shares;
        $totalVideoViews += $views;

        // Reactions detail
        $rb = $p['reactions'] ?? $p['reactionsByType'] ?? $p['reactionCount'] ?? null;
        if (is_array($rb)) {
            foreach (['like','love','haha','wow','sad','angry','care'] as $k) {
                $val = 0;
                foreach ([$k, strtoupper($k), 'REACTION_' . strtoupper($k), 'reactions_' . $k] as $key) {
                    if (isset($rb[$key]) && is_numeric($rb[$key])) { $val = (int)$rb[$key]; break; }
                }
                $reactionsTotal[$k] += $val;
            }
        }

        // 1. فرز الأنواع
        if (str_contains($type, 'video') || str_contains($type, 'reel')) {
            $types['video']++;
        } elseif (str_contains($type, 'sidecar') || str_contains($type, 'album') || str_contains($type, 'carousel')) {
            $types['carousel']++;
        } else {
            $types['image']++;
        }

        // 2. كيمياء النصوص
        $wordArray = preg_split('/\s+/', trim($text));
        $wordsCount = empty(trim($text)) ? 0 : count($wordArray);
        $totalWords += $wordsCount;

        // 3. بناء سحابة الهاشتاج
        preg_match_all('/#([\p{L}\p{N}_]+)/u', $text, $matches);
        foreach ($matches[1] as $ht) {
            $ht = mb_strtolower($ht);
            $hashtags[$ht] = ($hashtags[$ht] ?? 0) + 1;
        }
        if (isset($p['hashtags']) && is_array($p['hashtags'])) {
            foreach ($p['hashtags'] as $ht) {
                if (is_string($ht)) {
                    $ht = mb_strtolower(ltrim($ht, '#'));
                    $hashtags[$ht] = ($hashtags[$ht] ?? 0) + 1;
                }
            }
        }

        // 4. فحص الـ CTA
        $hasCta = false;
        foreach ($ctaKeywords as $cta) {
            if (mb_stripos($text, $cta) !== false) {
                $hasCta = true; break;
            }
        }
        if ($hasCta) $ctaCount++;

        // 4b. أوقات النشر (لاكتشاف أفضل وقت)
        $ts = strtotime((string)($p['timestamp'] ?? $p['takenAt'] ?? $p['time'] ?? $p['date'] ?? ''));
        if ($ts) {
            $h = (int)date('G', $ts);
            $d = (int)date('w', $ts); // 0=Sun .. 6=Sat
            $postingHours[$h] = ($postingHours[$h] ?? 0) + 1;
            $postingDays[$d]  = ($postingDays[$d]  ?? 0) + 1;
        }

        // 5. تجميع بيانات المنشورات للأفضل
        $parsed_posts[] = [
            'url' => $url,
            'image' => $img,
            'text' => mb_strlen($text) > 80 ? mb_substr($text, 0, 80) . '...' : (empty($text) ? 'بدون نص' : $text),
            'engagement' => $likes + $comments + $shares,
            'likes' => $likes,
            'comments' => $comments,
            'shares' => $shares,
            'video_views' => $views,
        ];
    }

    // استخراج أفضل 5 منشورات
    usort($parsed_posts, fn($a, $b) => $b['engagement'] - $a['engagement']);
    $top5 = array_slice($parsed_posts, 0, 5);

    // ترتيب الهاشتاجات بأكثرها تكراراً
    arsort($hashtags);
    $topHashtags = array_slice(array_keys($hashtags), 0, 10);

    // أفضل 3 ساعات نشر و3 أيام
    arsort($postingHours);
    arsort($postingDays);
    $bestHours = array_slice(array_keys($postingHours), 0, 3);
    $bestDays  = array_slice(array_keys($postingDays), 0, 3);

    return [
        'posts_analyzed' => $total,
        'types_percent' => [
            'video'    => round(($types['video']    / $total) * 100),
            'image'    => round(($types['image']    / $total) * 100),
            'carousel' => round(($types['carousel'] / $total) * 100),
        ],
        'avg_words'        => round($totalWords / $total),
        'top_hashtags'     => $topHashtags,
        'cta_percent'      => round(($ctaCount / $total) * 100),
        'top_5_posts'      => $top5,
        // ✅ مقاييس أعمق
        'avg_shares'       => round($totalShares / $total, 1),
        'avg_video_views'  => round($totalVideoViews / $total, 1),
        'reactions_total'  => $reactionsTotal,
        'best_hours'       => $bestHours,   // أرقام 0-23
        'best_days'        => $bestDays,    // أرقام 0=الأحد .. 6=السبت
    ];
}

// ============================================================
// Website Headless Scraper (Apify Puppeteer) - حل جذري لعمق الفحص
// ============================================================
function scrapeWebsiteApify(string $url, string $token, array $cfg): ?string {
    $actorId = $cfg['apis']['apify_actor_website'] ?? 'apify/website-content-crawler';

    if (str_contains($actorId, 'website-content-crawler')) {
        // Input schema for apify/website-content-crawler
        $input = json_encode([
            'startUrls' => [['url' => $url]],
            'maxCrawlPages' => 1,
            'saveHtml' => true,
            'saveMarkdown' => false,
        ]);
    } else {
        // Input schema for apify/puppeteer-scraper
        $pageFunction = "async function pageFunction(context) {
            const page = context.page;
            await page.waitForNetworkIdle({timeout: 8000}).catch(() => {});
            await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight / 2));
            await new Promise(r => setTimeout(r, 1500));
            await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
            await new Promise(r => setTimeout(r, 1500));
            const html = await page.content();
            return { html };
        }";

        $input = json_encode([
            'startUrls' => [['url' => $url]],
            'pageFunction' => $pageFunction,
            'proxyConfiguration' => ['useApifyProxy' => true]
        ]);
    }

    // يبدأ السكربنج
    $runId = _apifyStartRun($actorId, $input, $token);
    if (!$runId) return null;

    // أقصى انتظار 45 ثانية لأن المواقع قد تختلف في الاستجابة
    $result = _apifyWaitAndFetch($runId, $token, 45);

    return $result[0]['html'] ?? null;
}
