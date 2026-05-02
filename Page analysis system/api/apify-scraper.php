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

    // ── المسار الاحتياطي: Apify actor منفصل ──────────────────────
    $actorId = $cfg['apis']['apify_actor_ads_fb'] ?? '';
    if (empty($actorId)) {
        return ['success' => false, 'error' => 'لا يوجد Ads Actor مُعرَّف', 'ads' => []];
    }

    // استخراج اسم الصفحة من الرابط
    $cleanQuery = $pageIdentifier;
    if (str_starts_with($pageIdentifier, 'http')) {
        preg_match('/(?:facebook|instagram)\.com\/([^\/\?#]+)/i', $pageIdentifier, $m);
        if (!empty($m[1])) $cleanQuery = trim($m[1]);
    }

    $urlToSearch = str_starts_with($pageIdentifier, 'http')
        ? $pageIdentifier
        : "https://www.facebook.com/ads/library/?active_status=all&ad_type=all&country={$country}&q=" . urlencode($cleanQuery) . "&search_type=keyword_unordered";

    $input = [
        'startUrls'       => [['url' => $urlToSearch]],
        'maxItems'        => 30,
        'resultsPerPage'  => 30
    ];

    $fallbackReturn = [
        'success'        => true,
        'source'         => 'facebook_actor',
        'is_running_ads' => $fbAdsActive ?? false,
        'total_ads'      => $fbAdsCount ?? 0,
        'active_ads'     => ($fbAdsActive ?? false) ? ($fbAdsCount ?? 0) : 0,
        'ads_status'     => $adStatus ?? '',
        'ads'            => [],
    ];

    // ── حماية الرصيد: لا تقم بتشغيل الساحب المكلف إذا كنا متأكدين من عدم وجود إعلانات ──
    // فقط إذا نجح سحب فيسبوك وأكد أنه لا توجد إعلانات، نتوقف.
    if ($fbAdsActive === false && $fbAdsCount === 0) {
        return $fallbackReturn;
    }

    $runId = _apifyStartRun($actorId, json_encode($input), $token);
    if (!$runId) return $fallbackReturn;

    $items = _apifyWaitAndFetch($runId, $token, 90);
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



// ── Apify Internal Helpers ────────────────────────────────────
function _apifyStartRun(string $actorId, string $inputJson, string $token): ?string {
    $url = "https://api.apify.com/v2/acts/{$actorId}/runs?token={$token}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
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
        'posts_count'    => count($posts),
        'avg_engagement' => calcAvgEngagement($posts),
        'top_post'       => getTopPost($posts),
        'deep_analysis'  => analyzeDeepContent($posts),
        'instagram_url'  => $igUrl,
        'ads_running'    => $adsActive,
        'ads_count'      => $adsCount,
        'creation_date'  => $page['creation_date'] ?? '',
        'profile_pic'    => $page['profilePic'] ?? $page['profile_pic'] ?? '',
        'cover_photo'    => $page['cover'] ?? $page['cover_photo'] ?? '',
        'posts_per_week' => calcPostsPerWeek($posts),
        'last_post_days' => calcLastPostDays($posts),
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
// Instagram Scraper (apify~instagram-profile-scraper)
// ============================================================
function scrapeInstagram(string $url, string $token, array $cfg): array {
    $actorId = $cfg['apis']['apify_actor_ig'] ?? 'apify~instagram-profile-scraper';

    // استخراج username
    preg_match('/instagram\.com\/([^\/\?#]+)/i', $url, $m);
    $username = $m[1] ?? '';
    $username = trim($username, '/@');
    $username = preg_replace('/[^a-zA-Z0-9_\.]/', '', $username);

    if (!$username) return ['success' => false, 'error' => 'لم يتم استخراج username'];

    $input = json_encode([
        'usernames'      => [$username],
        'resultsPerPage' => 30,
    ], JSON_PRESERVE_ZERO_FRACTION | JSON_NUMERIC_CHECK);
    $runId = _apifyStartRun($actorId, $input, $token);

    if (!$runId) return ['success' => false, 'error' => 'فشل تشغيل Instagram Scraper'];

    $result = _apifyWaitAndFetch($runId, $token, 120);
    // إذا كانت النتيجة null يعني فشل أو مهلة، أما مصفوفة فارغة فتعني لا بيانات ولكن لا تعتبر فشلاً صراحةً
    if ($result === null) return ['success' => false, 'error' => 'انتهت مهلة Instagram Scraper'];

    $profile = $result[0] ?? [];
    if (empty($profile)) return ['success' => false, 'error' => 'لا بيانات Instagram'];

    $posts = $profile['latestPosts'] ?? $profile['posts'] ?? [];

    return [
        'success'          => true,
        'source'           => 'apify',
        'platform'         => 'instagram',
        'username'         => $profile['username']          ?? $username,
        'full_name'        => $profile['fullName']          ?? '',
        'followers'        => $profile['followersCount']    ?? null,
        'following'        => $profile['followsCount']      ?? null,
        'posts_count'      => $profile['postsCount']        ?? count($posts),
        'bio'              => $profile['biography']         ?? '',
        'bio_length'       => mb_strlen($profile['biography'] ?? ''),
        'website'          => $profile['externalUrl']       ?? '',
        'is_verified'      => $profile['verified']          ?? false,
        'is_business'      => $profile['isBusinessAccount'] ?? false,
        'business_category'=> $profile['businessCategoryName'] ?? '',
        'profile_pic'      => $profile['profilePicUrl']    ?? '',
        'highlights_count' => $profile['highlightReelCount'] ?? 0,
        'has_reels'        => !empty($profile['reelsCount']) || !empty($profile['isEligibleToViewUsernameReels']),
        'avg_likes'        => calcAvgLikes($posts),
        'avg_comments'     => calcAvgComments($posts),
        'engagement_rate'  => calcIGEngagement($posts, $profile['followersCount'] ?? 1),
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
    $actorId = $cfg['apis']['apify_actor_tiktok'] ?? 'GdWCkxBtKWOsKjdch';

    // استخراج username (يدعم: @user, user, tiktok.com/@user, tiktok.com/user)
    if (!str_contains($url, 'tiktok.com')) {
        $username = ltrim($url, '@');
    } else {
        preg_match('/tiktok\.com\/@?([^\/\?#]+)/i', $url, $m);
        $username = $m[1] ?? '';
    }

    if (!$username) return ['success' => false, 'error' => 'لم يتم استخراج TikTok username'];

    logInfo("Starting TikTok scrape", ["username" => $username, "actor" => $actorId]);
    $input = json_encode([
        'profiles'       => ['https://www.tiktok.com/@' . $username],
        'resultsPerPage' => 30,
    ], JSON_PRESERVE_ZERO_FRACTION | JSON_NUMERIC_CHECK);
    $runId = _apifyStartRun($actorId, $input, $token);
    if (!$runId) {
        logError("Failed to start TikTok run", ["actor" => $actorId]);
        return ['success' => false, 'error' => 'فشل تشغيل TikTok Scraper'];
    }

    $result = _apifyWaitAndFetch($runId, $token, 90);
    if (!$result) {
        logError("TikTok scrape timeout or failed", ["run_id" => $runId]);
        return ['success' => false, 'error' => 'انتهت مهلة TikTok Scraper'];
    }

    $profile = $result[0] ?? [];
    if (empty($profile)) {
        logError("TikTok scrape returned no data", ["run_id" => $runId, "result" => $result]);
        return ['success' => false, 'error' => 'لا بيانات TikTok'];
    }

    logInfo("TikTok scrape successful", ["username" => $username]);

    return [
        'success'         => true,
        'platform'        => 'tiktok',
        'username'        => $profile['uniqueId']          ?? $profile['authorMeta']['name']      ?? $username,
        'full_name'       => $profile['nickname']          ?? $profile['authorMeta']['nickName']  ?? '',
        'followers'       => $profile['followerCount']     ?? $profile['authorMeta']['fans']      ?? 0,
        'likes'           => $profile['heartCount']        ?? $profile['authorMeta']['heart']     ?? 0,
        'video_count'     => $profile['videoCount']        ?? $profile['authorMeta']['video']     ?? 0,
        'bio'             => $profile['signature']         ?? $profile['authorMeta']['signature'] ?? '',
        'is_verified'     => $profile['verified']          ?? $profile['authorMeta']['verified']  ?? false,
        'website'         => $profile['externalWebUrl']    ?? $profile['authorMeta']['externalUrl'] ?? '',
        'avatar'          => $profile['avatarLarger']      ?? $profile['authorMeta']['avatar']    ?? '',
        // ✅ تحليل المنشورات
        'avg_likes'       => calcAvgLikes($profile['videos'] ?? []),
        'avg_comments'    => calcAvgComments($profile['videos'] ?? []),
        'engagement_rate' => calcIGEngagement($profile['videos'] ?? [], $profile['followerCount'] ?? 1),
        'posts_per_week'  => calcPostsPerWeek($profile['videos'] ?? []),
        'deep_analysis'   => analyzeDeepContent($profile['videos'] ?? []),
        'latest_posts'    => array_slice($profile['videos'] ?? [], 0, 30),
    ];
}

// ============================================================
// Twitter (X) Scraper
// ============================================================
function scrapeTwitter(string $url, string $token, array $cfg): array {
    // ملاحظة: قائمة الـ Apify actors لتويتر تتغير بسرعة (التحول من twitter.com → x.com
    // أوقف عدة actors). نحاول أكثر من actor تلقائياً قبل الإعلان عن الفشل، وندعم
    // عدة أشكال output schemas (camelCase, snake_case, ومختلطة).
    $primaryActor = $cfg['apis']['apify_actor_twitter'] ?? '';
    $candidates = array_values(array_unique(array_filter([
        $primaryActor,
        'apidojo~twitter-scraper-lite',
        'quacker~twitter-url-scraper',
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
