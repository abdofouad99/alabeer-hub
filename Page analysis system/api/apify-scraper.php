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

    if ($isPostsScraper) {
        $input = json_encode([
            'startUrls'    => [['url' => $url]],
            'resultsLimit' => 30,
            'captionText'  => false,
        ], JSON_PRESERVE_ZERO_FRACTION | JSON_NUMERIC_CHECK);
    } else {
        $input = json_encode([
            'startUrls'  => [['url' => $url]],
            'maxPosts'   => 30,
            'scrapeAbout'=> true,
        ]);
    }

    $runId = _apifyStartRun($actorId, $input, $token);
    if (!$runId) {
        logError('Facebook scrape failed to start', ['url' => $url, 'actor' => $actorId]);
        return ['success' => false, 'error' => 'فشل تشغيل Facebook Actor'];
    }

    logInfo('Starting Facebook scrape', ['url' => $url, 'actor' => $actorId, 'runId' => $runId]);
    $result = _apifyWaitAndFetch($runId, $token, 120); // 120s because posts scraper takes longer
    if ($result === null) {
        logError('Facebook scrape timed out or failed', ['url' => $url, 'runId' => $runId]);
        return ['success' => false, 'error' => 'انتهت مهلة Facebook Apify'];
    }
    logInfo('Facebook scrape successful', ['url' => $url, 'items_count' => count($result)]);

    // ── المعالجة بناءً على نوع الـ Actor ─────────
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

        // ── جلب النواقص (معلومات الصفحة) عبر Actor الصفحات السريع ──
        if (empty($followers) && empty($email) && empty($phone) && empty($website)) {
            $aboutInput = json_encode([
                'startUrls' => [['url' => $url]],
                'maxPosts' => 0,
                'scrapeAbout' => true,
                'scrapeServices' => false,
                'scrapeReviews' => false
            ]);
            $aboutRunId = _apifyStartRun('apify~facebook-pages-scraper', $aboutInput, $token);
            if ($aboutRunId) {
                $aboutResult = _apifyWaitAndFetch($aboutRunId, $token, 60);
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
                    $page['cover'] = $ap['cover'] ?? $page['cover'] ?? null;
                    $page['profilePic'] = $ap['profilePic'] ?? $page['profilePic'] ?? null;
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
    }

    $res = [
        'success'        => true,
        'source'         => 'apify',
        'platform'       => 'facebook',
        'page_name'      => $pageName,
        'page_id'        => $pageId,
        'url'            => $url,
        'followers'      => $followers,
        'likes'          => $likes,
        'category'       => $category,
        'is_verified'    => $is_verified,
        'website'        => $website,
        'phone'          => $phone,
        'whatsapp'       => $whatsapp,
        'email'          => $email,
        'address'        => $page['address'] ?? $page['location'] ?? $page['city'] ?? '',
        'description'    => $page['intro'] ?? $page['description'] ?? $page['about'] ?? $page['text'] ?? '',
        'rating'         => _cleanRating($page['rating'] ?? $page['overallStarRating'] ?? null),
        'ratings_count'  => $page['ratingsCount'] ?? $page['ratingCount'] ?? null,
        'response_time'  => $page['responseTime'] ?? $page['response_time'] ?? $page['messagingResponseTime'] ?? null,
        'posts_count'    => count($posts),
        'avg_engagement' => calcAvgEngagement($posts),
        'top_post'       => getTopPost($posts),
        'deep_analysis'  => analyzeDeepContent($posts),
        'instagram_url'  => $igUrl,
        'ads_running'    => $adsActive,
        'ads_count'      => $adsCount,
        'creation_date'  => $page['creation_date'] ?? $page['createdTime'] ?? $page['pageCreatedDate'] ?? $page['foundedDate'] ?? '',
        'profile_pic'    => $page['profilePic'] ?? $page['profile_pic'] ?? '',
        'cover_photo'    => $page['cover'] ?? $page['cover_photo'] ?? '',
        'posts_per_week' => calcPostsPerWeek($posts),
        'last_post_days' => calcLastPostDays($posts),
        'posts'          => array_slice($posts, 0, 10),  // للعرض في التقرير
    ];

    // فقط أضف الـ signals إذا كانت حقيقية (حتى لا نمسح ما يجده الـ Scraper العام)
    if (!empty($phone))    $res['has_phone']    = true;
    if (!empty($whatsapp)) $res['has_whatsapp'] = true;
    if (!empty($email))    $res['has_email']    = true;
    if (!empty($website))  $res['has_website']  = true;
    if ($is_verified)      $res['is_verified']  = true;

    return $res;
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
            'resultsType'   => 'details',
            'resultsLimit'  => 100,
            'addParentData' => true,
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
    $total = array_sum(array_map(fn($p) => (int)($p['likes'] ?? $p['likesCount'] ?? 0) + (int)($p['comments'] ?? $p['commentsCount'] ?? 0), $posts));
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
    usort($posts, fn($a, $b) => (($b['likes'] ?? 0) + ($b['comments'] ?? 0)) - (($a['likes'] ?? 0) + ($a['comments'] ?? 0)));
    return $posts[0] ?? null;
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

    // الكلمات التي لو وجدت بالنص تدل على دعوة لإجراء
    $ctaKeywords = ['رابط', 'بايو', 'bio', 'link', 'تواصل', 'واتساب', 'رسالة', 'اشتري', 'احجز', 'سجل', 'خصم', 'الآن', 'تخفيض', 'اتصل', 'موقعنا'];

    $parsed_posts = [];

    foreach ($posts as $p) {
        $text = $p['caption'] ?? $p['text'] ?? $p['message'] ?? '';
        $type = strtolower($p['type'] ?? $p['mediaType'] ?? '');
        $url = $p['url'] ?? $p['postUrl'] ?? '';
        $img = $p['displayUrl'] ?? $p['thumbnailUrl'] ?? $p['imageUrl'] ?? '';
        if (empty($img) && is_string($p['image'] ?? null)) $img = $p['image'];
        if (empty($img) && isset($p['image']['uri'])) $img = $p['image']['uri'];
        if (empty($img) && !empty($p['media'][0]['thumbnail'])) $img = $p['media'][0]['thumbnail'];
        if (empty($img) && !empty($p['media'][0]['image'])) $img = is_string($p['media'][0]['image']) ? $p['media'][0]['image'] : ($p['media'][0]['image']['uri'] ?? '');
        if (empty($img) && !empty($p['attachments'][0]['image'])) $img = is_string($p['attachments'][0]['image']) ? $p['attachments'][0]['image'] : ($p['attachments'][0]['image']['uri'] ?? '');
        if (empty($img) && !empty($p['attachments'][0]['media']['image']['src'])) $img = $p['attachments'][0]['media']['image']['src'];
        if (empty($img) && !empty($p['thumbnail'])) $img = $p['thumbnail'];
        $likes = (int)($p['likesCount'] ?? $p['likes'] ?? 0);
        $comments = (int)($p['commentsCount'] ?? $p['comments'] ?? 0);

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

        // 5. تجميع بيانات المنشورات للأفضل
        $parsed_posts[] = [
            'url' => $url,
            'image' => $img,
            'text' => mb_strlen($text) > 80 ? mb_substr($text, 0, 80) . '...' : (empty($text) ? 'بدون نص' : $text),
            'engagement' => $likes + $comments,
            'likes' => $likes,
            'comments' => $comments,
        ];
    }

    // استخراج أفضل 5 منشورات
    usort($parsed_posts, fn($a, $b) => $b['engagement'] - $a['engagement']);
    $top5 = array_slice($parsed_posts, 0, 5);

    // ترتيب الهاشتاجات بأكثرها تكراراً
    arsort($hashtags);
    $topHashtags = array_slice(array_keys($hashtags), 0, 10);

    return [
        'posts_analyzed' => $total,
        'types_percent' => [
            'video' => round(($types['video'] / $total) * 100),
            'image' => round(($types['image'] / $total) * 100),
            'carousel' => round(($types['carousel'] / $total) * 100),
        ],
        'avg_words' => round($totalWords / $total),
        'top_hashtags' => $topHashtags,
        'cta_percent' => round(($ctaCount / $total) * 100),
        'top_5_posts' => $top5
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
