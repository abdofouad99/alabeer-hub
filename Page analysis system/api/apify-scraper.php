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
        'is_active'           => ($ad['isActive'] ?? $ad['is_active'] ?? false) || $status === 'active',
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
function scrapeCompetitorsViaGoogle(string $companyName, string $targetAudience, string $token): array {
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
    foreach ($result as $item) {
        $title = $item['title'] ?? $item['metadataTitle'] ?? '';
        $url = $item['url'] ?? '';
        $desc = $item['metadataDescription'] ?? $item['description'] ?? '';
        if (!empty($title) && !empty($url)) {
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

function _apifyWaitAndFetch(string $runId, string $token, int $maxWait): ?array {
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
                $ch = curl_init("https://api.apify.com/v2/datasets/{$dsId}/items?token={$token}&limit=100");
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_SSL_VERIFYPEER => false]);
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
        if (empty($followers) && empty($email) && empty($phone) && empty($website)) {
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
                $reviews[] = [
                    'rating' => is_numeric($rating) ? (float)$rating : (in_array($rating, ['positive','recommends','POSITIVE'], true) ? 5 : (in_array($rating, ['negative','doesnt-recommend','NEGATIVE'], true) ? 1 : null)),
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
// Instagram Scraper (apify~instagram-profile-scraper)
// ============================================================
function scrapeInstagram(string $url, string $token, array $cfg): array {
    // ── Actor القوي الجديد: يسحب 100 منشور + تعليقات + Reels ──
    $actorId = $cfg['apis']['apify_actor_ig'] ?? 'shu8hvrXbJbY3Eb9W';

    // بناء رابط البروفايل
    preg_match('/instagram\.com\/([^\/\?#]+)/i', $url, $m);
    $username = trim($m[1] ?? '', '/@');
    $username = preg_replace('/[^a-zA-Z0-9_\.]/', '', $username);
    if (!$username) return ['success' => false, 'error' => 'لم يتم استخراج username'];

    $profileUrl = 'https://www.instagram.com/' . $username . '/';

    if (strpos($actorId, 'apify~instagram-profile-scraper') !== false) {
        // Schema for apify~instagram-profile-scraper
        $inputData = [
            'usernames'    => [$username],
            'resultsLimit' => 100
        ];
    } else {
        // Original schema for shu8hvrXbJbY3Eb9W
        $inputData = [
            'resultsType'  => 'posts',
            'directUrls'   => [$profileUrl],
            'resultsLimit' => 100,
            'searchType'   => 'hashtag',
            'searchLimit'  => 10,
            'addParentData'=> true,
        ];
    }
    $input = json_encode($inputData, JSON_PRESERVE_ZERO_FRACTION | JSON_NUMERIC_CHECK);

    $runId = _apifyStartRun($actorId, $input, $token);
    if (!$runId) return ['success' => false, 'error' => 'فشل تشغيل Instagram Scraper'];

    $result = _apifyWaitAndFetch($runId, $token, 150);
    if ($result === null) return ['success' => false, 'error' => 'انتهت مهلة Instagram Scraper'];
    if (empty($result)) return ['success' => false, 'error' => 'لا بيانات Instagram'];

    // أول عنصر يحتوي على بيانات المنشور مع ownerFullName/ownerUsername
    $firstPost = $result[0] ?? [];
    $posts     = $result;   // كل عنصر = منشور

    // استخراج بيانات الحساب من ownerFullName / ownerId / meta
    $igUser    = $firstPost['ownerUsername']  ?? $firstPost['owner']['username']   ?? $username;
    $fullName  = $firstPost['ownerFullName']  ?? $firstPost['owner']['full_name']  ?? '';
    $followers = $firstPost['ownerFollowersCount'] ?? $firstPost['owner']['edge_followed_by']['count'] ?? null;
    $following = $firstPost['ownerFollowingCount'] ?? $firstPost['owner']['edge_follow']['count'] ?? 0;
    $postsTotal= $firstPost['ownerPostsCount']     ?? count($posts);
    $bio       = $firstPost['ownerBiography']      ?? '';
    $website   = $firstPost['ownerExternalUrl']    ?? '';
    $verified  = $firstPost['ownerIsVerified']     ?? false;
    $picUrl    = $firstPost['ownerProfilePicUrl']  ?? '';

    // حساب متوسطات أدق بما يشمل Saves
    $totalLikes   = 0; $totalComments = 0; $totalViews = 0;
    $reelsCount   = 0; $savesTotal = 0;
    foreach ($posts as $p) {
        $totalLikes    += (int)($p['likesCount']    ?? $p['likes']    ?? 0);
        $totalComments += (int)($p['commentsCount'] ?? $p['comments'] ?? 0);
        $totalViews    += (int)($p['videoViewCount'] ?? 0);
        $savesTotal    += (int)($p['savesCount']    ?? $p['saves']    ?? 0);
        $t = strtolower($p['type'] ?? $p['mediaType'] ?? '');
        if (str_contains($t, 'video') || str_contains($t, 'reel')) $reelsCount++;
    }
    $cnt = max(count($posts), 1);
    $avgLikes    = round($totalLikes    / $cnt, 1);
    $avgComments = round($totalComments / $cnt, 1);
    $avgSaves    = round($savesTotal    / $cnt, 1);
    $avgViews    = round($totalViews    / $cnt, 1);
    $engRate     = $followers ? round((($avgLikes + $avgComments) / $followers) * 100, 2) : 0;

    return [
        'success'          => true,
        'source'           => 'apify_ig_v2',
        'platform'         => 'instagram',
        'username'         => $igUser,
        'full_name'        => $fullName,
        'followers'        => $followers,
        'following'        => $following,
        'posts_count'      => $postsTotal,
        'bio'              => $bio,
        'bio_length'       => mb_strlen($bio),
        'website'          => $website,
        'is_verified'      => $verified,
        'is_business'      => (bool)($firstPost['ownerIsBusinessAccount'] ?? $firstPost['owner']['is_business_account'] ?? false),
        'has_reels'        => $reelsCount > 0,
        'profile_pic'      => $picUrl,
        'avg_likes'        => $avgLikes,
        'avg_comments'     => $avgComments,
        'avg_saves'        => $avgSaves,          // ✅ جديد
        'avg_video_views'  => $avgViews,          // ✅ جديد
        'reels_count'      => $reelsCount,        // ✅ جديد
        'engagement_rate'  => $engRate,
        'top_post'         => getTopIGPost($posts),
        'deep_analysis'    => analyzeDeepContent($posts),
        'posts_per_week'   => calcPostsPerWeek($posts),
        'last_post_days'   => calcLastPostDays($posts),
        'latest_posts'     => array_slice($posts, 0, 30),
    ];
}

// ============================================================
// TikTok Scraper
// ============================================================
function scrapeTikTok(string $url, string $token, array $cfg): array {
    // ── Actor القوي الجديد: Shares + Saves + Trending Sounds ──
    $actorId = $cfg['apis']['apify_actor_tiktok'] ?? '0FXVyOXXEmdGcV88a';

    if (!str_contains($url, 'tiktok.com')) {
        $username = ltrim($url, '@');
    } else {
        preg_match('/tiktok\.com\/@?([^\/\?#]+)/i', $url, $m);
        $username = $m[1] ?? '';
    }
    if (!$username) return ['success' => false, 'error' => 'لم يتم استخراج TikTok username'];

    logInfo('Starting TikTok scrape via Apify v2', ['username' => $username, 'actor' => $actorId]);

    $input = json_encode([
        'profiles'             => ['https://www.tiktok.com/@' . $username],
        'profileScrapeSections'=> ['videos'],
        'profileSorting'       => 'latest',
        'resultsPerPage'       => 100,
        'shouldDownloadVideos' => false,
        'shouldDownloadCovers' => false,
        'shouldDownloadAvatars'=> false,
        'downloadSubtitlesOptions' => 'NEVER_DOWNLOAD_SUBTITLES',
    ], JSON_PRESERVE_ZERO_FRACTION | JSON_NUMERIC_CHECK);

    $runId = _apifyStartRun($actorId, $input, $token);
    if (!$runId) { logError('Failed to start TikTok run', ['actor' => $actorId]); return ['success' => false, 'error' => 'فشل تشغيل TikTok Scraper']; }

    $result = _apifyWaitAndFetch($runId, $token, 120);
    if (!$result) { logError('TikTok timeout', ['runId' => $runId]); return ['success' => false, 'error' => 'انتهت مهلة TikTok Scraper']; }

    // Actor الجديد يُرجع كل فيديو كعنصر منفصل + بيانات الحساب في authorMeta
    $firstItem = $result[0] ?? [];
    if (empty($firstItem)) return ['success' => false, 'error' => 'لا بيانات TikTok'];

    $author   = $firstItem['authorMeta'] ?? [];
    $videos   = $result;

    // حساب Likes + Shares + Saves + Views + Trending Sounds
    // ⚠️ ملاحظة هامة: diggCount في TikTok API = عدد الإعجابات (❤️)
    // shareCount = عدد المشاركات الفعلية
    $totalLikes = 0; $totalShares = 0; $totalSaves = 0; $totalViews = 0; $totalComments = 0;
    $sounds = [];
    foreach ($videos as $v) {
        $totalLikes    += (int)($v['diggCount']    ?? $v['likesCount']   ?? $v['likes']    ?? 0);
        $totalShares   += (int)($v['shareCount']   ?? $v['shares']       ?? 0);
        $totalComments += (int)($v['commentCount'] ?? $v['commentsCount'] ?? $v['comments'] ?? 0);
        $totalSaves    += (int)($v['collectCount'] ?? $v['savesCount']   ?? 0);
        $totalViews    += (int)($v['playCount']    ?? $v['viewCount']    ?? 0);
        $sound = $v['musicMeta']['musicName'] ?? $v['music']['title'] ?? '';
        if ($sound) $sounds[$sound] = ($sounds[$sound] ?? 0) + 1;
    }
    $cnt        = max(count($videos), 1);
    $followers  = (int)($author['fans'] ?? $author['followerCount'] ?? 0);
    $avgLikes   = round($totalLikes    / $cnt, 1);
    $avgComments= round($totalComments / $cnt, 1);
    $avgViews   = round($totalViews    / $cnt);
    $avgShares  = round($totalShares   / $cnt, 1);
    $avgSaves   = round($totalSaves    / $cnt, 1);
    arsort($sounds);
    $trendingSounds = array_slice(array_keys($sounds), 0, 5);

    logInfo('TikTok scrape successful v2', ['username' => $username, 'videos' => $cnt]);

    return [
        'success'          => true,
        'source'           => 'apify_tt_v2',
        'platform'         => 'tiktok',
        'username'         => $author['name']      ?? $username,
        'full_name'        => $author['nickName']   ?? '',
        'followers'        => $followers,
        'likes'            => (int)($author['heart'] ?? 0),
        'video_count'      => (int)($author['video'] ?? count($videos)),
        'bio'              => $author['signature']  ?? '',
        'is_verified'      => (bool)($author['verified'] ?? false),
        'website'          => $author['externalUrl'] ?? '',
        'avatar'           => $author['avatar']     ?? '',
        'avg_likes'        => $avgLikes,
        'avg_comments'     => $avgComments,
        'avg_shares'       => $avgShares,
        'avg_saves'        => $avgSaves,
        'avg_views'        => $avgViews,
        'trending_sounds'  => $trendingSounds,
        'engagement_rate'  => $followers > 0 ? round((($avgLikes + $avgComments + $avgShares) / $followers) * 100, 2) : 0,
        'posts_per_week'   => calcPostsPerWeek($videos),
        'deep_analysis'    => analyzeDeepContent($videos),
        'latest_posts'     => array_slice($videos, 0, 30),
    ];
}


// ============================================================
function scrapeTwitter(string $url, string $token, array $cfg): array {
    // ملاحظة: قائمة الـ Apify actors لتويتر تتغير بسرعة (التحول من twitter.com → x.com
    // أوقف عدة actors). نحاول أكثر من actor تلقائياً قبل الإعلان عن الفشل، وندعم
    // عدة أشكال output schemas (camelCase, snake_case, ومختلطة).
    $primaryActor = $cfg['apis']['apify_actor_twitter'] ?? '';
    $candidates = array_values(array_unique(array_filter([
        $primaryActor,
        'apidojo~twitter-scraper-lite',
        'kaitoeasyapi~twitter-x-profile-scraper',
        'shanes~twitter-profile-scraper',
    ])));

    // ── استخراج username (يدعم: user, @user, twitter.com/user, x.com/user) ──
    if (!str_contains($url, 'twitter.com') && !str_contains($url, 'x.com')) {
        $username = ltrim($url, '@');
    } else {
        preg_match('/(?:twitter|x)\.com\/([^\/\?#]+)/i', $url, $m);
        $username = $m[1] ?? '';
    }
    $username = trim($username);

    if (!$username) {
        return [
            'success'  => false,
            'platform' => 'twitter',
            'error'    => 'لم يتم استخراج Twitter username من الرابط',
            'url'      => $url,
        ];
    }

    $normalizedUrl = 'https://twitter.com/' . $username;

    foreach ($candidates as $actorId) {
        logInfo("Starting Twitter scrape attempt", ["username" => $username, "actor" => $actorId]);
        $input = json_encode([
            'startUrls'      => [$normalizedUrl, $url],
            'twitterHandles' => [$username],
            'handles'        => [$username],     // apidojo schema
            'maxItems'       => 1,
            'maxTweets'      => 0,                // نريد البروفايل فقط
            'getFollowers'   => true,
            'getFollowing'   => true,
            'getAbout'       => true,
        ], JSON_PRESERVE_ZERO_FRACTION | JSON_NUMERIC_CHECK);

        $runId = _apifyStartRun($actorId, $input, $token);
        if (!$runId) {
            logError("Failed to start Twitter run; trying next actor", ["actor" => $actorId]);
            continue;
        }

        $result = _apifyWaitAndFetch($runId, $token, 90);
        if (!$result) {
            logError("Twitter scrape timeout; trying next actor", ["run_id" => $runId, "actor" => $actorId]);
            continue;
        }

        $profile = $result[0] ?? [];
        if (empty($profile)) {
            logError("Twitter scrape returned empty; trying next actor", [
                'run_id' => $runId, 'actor' => $actorId,
            ]);
            continue;
        }

        // Validate against the RAW profile, not the parsed result. The parser
        // falls back to $username for the username field, so checking
        // !empty($parsed['username']) would always pass and short-circuit the
        // multi-actor retry. Instead, require that the raw response contains
        // at least one recognizable Twitter profile field.
        $knownTwitterKeys = [
            'userName', 'screenName', 'screen_name', 'handle', 'username',
            'followersCount', 'followers_count', 'followerCount', 'followers',
            'statusesCount', 'statuses_count', 'tweetCount', 'tweet_count', 'tweetsCount',
            'name', 'displayName', 'display_name', 'fullName',
        ];
        $hasRecognizedField = !empty(array_intersect_key($profile, array_flip($knownTwitterKeys)));
        if (!$hasRecognizedField) {
            logError("Twitter actor returned data without identifiable fields; trying next actor", [
                'actor' => $actorId, 'sample_keys' => array_slice(array_keys($profile), 0, 10),
            ]);
            continue;
        }

        $parsed = _parseTwitterProfile($profile, $username);
        logInfo("Twitter scrape successful", ['username' => $parsed['username'], 'actor' => $actorId]);
        return $parsed;
    }

    logError("All Twitter actors failed", ['username' => $username, 'tried' => $candidates]);
    return [
        'success'  => false,
        'platform' => 'twitter',
        'username' => $username,
        'url'      => $normalizedUrl,
        'error'    => 'تعذّر جلب بيانات تويتر — حاول المنصة لاحقاً.',
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
        'bio'        => (string)$first($p, ['description','bio','about'], ''),
        'location'   => (string)$first($p, ['location','place'], ''),
        'is_verified'=> (bool)$first($p, ['verified','isVerified','is_verified','isBlueVerified'], false),
        'website'    => (string)$first($p, ['url','external_url','externalUrl','website'], ''),
        'avatar'     => (string)$first($p, ['profileImageUrlHttps','profile_image_url_https','profileImageUrl','profile_image_url','avatar'], ''),
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
    $actorId = $cfg['apis']['apify_actor_website'] ?? 'apify/puppeteer-scraper';

    // نستخدم puppeteer-scraper لاستخراج الكود بعد الجافاسكربت
    // الانتظار 3.5 ثوانٍ يضمن لـ Google Tag manager والبيكسلات أن تحقن نفسها
    $pageFunction = "async function pageFunction(context) {
        await new Promise(r => setTimeout(r, 3500));
        return {
            html: await context.page.content()
        };
    }";

    $input = json_encode([
        'startUrls' => [['url' => $url]],
        'pageFunction' => $pageFunction,
        'proxyConfiguration' => ['useApifyProxy' => true]
    ]);

    // يبدأ السكربنج
    $runId = _apifyStartRun($actorId, $input, $token);
    if (!$runId) return null;

    // أقصى انتظار 45 ثانية لأن المواقع قد تختلف في الاستجابة
    $result = _apifyWaitAndFetch($runId, $token, 45);

    return $result[0]['html'] ?? null;
}
