<?php
// ============================================================
// api/apify-scraper.php — مكتبة دوال Apify (لا تُستدعى مباشرة)
// ============================================================
require_once __DIR__ . '/db.php';

// ← لا يوجد كود تنفيذي هنا — فقط دوال

// ============================================================
function runApifyScraper(string $url, string $type, array $cfg): array {
    // اختيار Token تلقائياً بالتناوب
    $tokens = $cfg['apis']['apify_tokens'] ?? [$cfg['apis']['apify_token'] ?? ''];
    $token  = $tokens[time() % count($tokens)];

    if (!$token) return ['success' => false, 'error' => 'Apify token غير مضبوط'];

    return $type === 'instagram'
        ? scrapeInstagram($url, $token, $cfg)
        : scrapeFacebook($url, $token, $cfg);
}

// ============================================================
// اختيار أفضل Token من القائمة (يتخطى المنتهية تلقائياً)
// ============================================================
function getValidApifyToken(array $cfg): string {
    $tokens = $cfg['apis']['apify_tokens'] ?? [];
    if (empty($tokens)) return '';

    // حاول كل توكن — يتحقق من HTTP 200 + isStatusActive
    foreach ($tokens as $token) {
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
        // تحقق إضافي من حالة الحساب
        $isActive = $userData['data']['isStatusActive'] ?? true;
        if ($isActive) return $token;
    }
    return $tokens[0] ?? ''; // fallback
}

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

    // ── المسار الأول: استخدام pageAdLibrary من Facebook (الأسرع) ───
    if (!empty($fbData['success'])) {
        $adLib    = $fbData['pageAdLibrary'] ?? [];
        $adStatus = strtoupper($fbData['ad_status'] ?? '');

        $adsActive = false;
        $adsCount  = 0;

        if (!empty($adLib)) {
            $adsActive = !empty($adLib['is_business_page_active'])
                      || (($adLib['ad_count'] ?? 0) > 0);
            $adsCount  = (int)($adLib['ad_count'] ?? 0);
        }
        if (!$adsActive && $adStatus === 'ACTIVE') {
            $adsActive = true;
        }

        return [
            'success'        => true,
            'source'         => 'facebook_actor',
            'is_running_ads' => $adsActive,
            'total_ads'      => $adsCount,
            'active_ads'     => $adsActive ? $adsCount : 0,
            'ads_status'     => $adStatus,
            'ads'            => [],   // لا تفاصيل إعلانات — فقط الحالة
        ];
    }

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

    $input = [
        'searchPageOrURL' => $cleanQuery,
        'country'         => $country,
        'maxResults'      => 20,
    ];

    $runId = _apifyStartRun($actorId, json_encode($input), $token);
    if (!$runId) return ['success' => false, 'error' => 'فشل تشغيل Ads Actor', 'ads' => []];

    $items = _apifyWaitAndFetch($runId, $token, 90);
    if ($items === null) return ['success' => false, 'error' => 'انتهت مهلة Ads Scraper', 'ads' => []];

    $ads = [];
    foreach (($items ?: []) as $item) {
        $adsList = $item['ads'] ?? null;
        if (is_array($adsList)) {
            foreach ($adsList as $ad) $ads[] = _parseAd($ad);
        } else {
            $ads[] = _parseAd($item);
        }
    }

    return [
        'success'        => true,
        'source'         => 'apify_actor',
        'total_ads'      => count($ads),
        'active_ads'     => count(array_filter($ads, fn($a) => $a['is_active'])),
        'ads'            => array_slice($ads, 0, 20),
        'is_running_ads' => count($ads) > 0,
    ];
}


function _parseAd(array $ad): array {
    $rawStatus = $ad['ad_delivery_status'] ?? $ad['status'] ?? '';
    $status = is_string($rawStatus) ? strtolower($rawStatus) : '';
    $images = $ad['snapshot']['images'] ?? [];
    $imgUrl = is_array($images) && isset($images[0]['original_image_url']) ? $images[0]['original_image_url'] : null;

    return [
        'id'          => $ad['adArchiveID']       ?? $ad['ad_archive_id'] ?? $ad['id']          ?? null,
        'title'       => $ad['adCreativeBody']    ?? $ad['ad_creative_body'] ?? $ad['body'] ?? $ad['title'] ?? '',
        'page_name'   => $ad['pageName']          ?? $ad['page_name'] ?? '',
        'is_active'   => ($ad['isActive'] ?? $ad['is_active'] ?? false) || $status === 'active',
        'start_date'  => $ad['startDate']         ?? $ad['start_date'] ?? $ad['ad_creation_time'] ?? null,
        'platforms'   => $ad['publisherPlatform'] ?? $ad['publisher_platforms'] ?? $ad['platforms'] ?? [],
        'spend'       => $ad['spend']             ?? null,
        'impressions' => $ad['impressions']       ?? null,
        'image_url'   => $imgUrl ?? $ad['image_url'] ?? $ad['thumbnail'] ?? null,
    ];
}


// ── Apify Internal Helpers ────────────────────────────────────
function _apifyStartRun(string $actorId, string $inputJson, string $token): ?string {
    $ch = curl_init("https://api.apify.com/v2/acts/{$actorId}/runs?token={$token}");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => $inputJson,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
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

    $input = json_encode([
        'startUrls'  => [['url' => $url]],
        'maxPosts'   => 12,
        'scrapeAbout'=> true,
    ]);

    $runId = _apifyStartRun($actorId, $input, $token);
    if (!$runId) return ['success' => false, 'error' => 'فشل تشغيل Facebook Actor'];

    $result = _apifyWaitAndFetch($runId, $token, 90);
    if (!$result) return ['success' => false, 'error' => 'انتهت مهلة Facebook Apify'];

    $page = $result[0] ?? [];
    if (empty($page)) return ['success' => false, 'error' => 'لا بيانات من Facebook Actor'];

    $posts = $page['posts'] ?? $page['latestPosts'] ?? [];

    // ── استخراج الحقول (يدعم الأسماء الجديدة والقديمة) ─────────
    $followers = $page['followers']    ?? $page['followersCount'] ?? $page['fans'] ?? $page['likesCount'] ?? null;
    $likes     = $page['likes']        ?? $followers;
    $phone     = $page['phone']        ?? $page['phoneNumber']    ?? '';
    $whatsapp  = $page['wa_number']    ?? $page['whatsapp_number'] ?? $page['whatsapp'] ?? '';
    $email     = $page['email']        ?? $page['emails'][0]      ?? '';

    // موقع الويب — Actor الجديد يضع URLs في مصفوفة 'websites'
    $website = $page['website'] ?? '';
    if (empty($website) && !empty($page['websites'])) {
        $ws = $page['websites'];
        $website = is_array($ws) ? ($ws[0] ?? '') : $ws;
    }
    $website = $website ?: ($page['externalUrl'] ?? $page['websiteUrl'] ?? '');

    // رابط إنستقرام المرتبط
    $igUrl = $page['instagram'] ?? $page['instagramLink'] ?? '';

    // حالة الإعلانات من pageAdLibrary (موجودة في Actor الجديد)
    $adLib    = $page['pageAdLibrary'] ?? [];
    $adsActive = false;
    $adsCount  = 0;
    if (!empty($adLib)) {
        $adsActive = !empty($adLib['is_business_page_active']) ||
                     (($adLib['ad_count'] ?? 0) > 0);
        $adsCount  = (int)($adLib['ad_count'] ?? 0);
    }
    // ad_status قد يكون "ACTIVE" أو "INACTIVE"
    if (!$adsActive && !empty($page['ad_status'])) {
        $adsActive = strtoupper($page['ad_status']) === 'ACTIVE';
    }

    return [
        'success'        => true,
        'source'         => 'apify',
        'platform'       => 'facebook',
        'page_name'      => $page['title']       ?? $page['pageName']    ?? $page['name'] ?? '',
        'page_id'        => $page['pageId']       ?? $page['facebookId']  ?? $page['id']  ?? '',
        'url'            => $url,
        'followers'      => $followers,
        'likes'          => $likes,
        'category'       => $page['category']    ?? $page['categories'][0] ?? $page['categoryName'] ?? '',
        'is_verified'    => !empty($page['verified']) || !empty($page['isVerified'])
                         || ($page['verifiedStatus'] ?? '') === 'BLUE_VERIFIED',
        'website'        => $website,
        'has_website'    => !empty($website),
        'phone'          => $phone,
        'whatsapp'       => $whatsapp,
        'email'          => $email,
        'address'        => $page['address']     ?? $page['location']     ?? '',
        'description'    => $page['intro']       ?? $page['description']  ?? $page['about'] ?? '',
        'rating'         => $page['rating']      ?? $page['overallStarRating'] ?? null,
        'ratings_count'  => $page['ratingsCount']?? $page['ratingCount']  ?? null,
        'posts_count'    => count($posts),
        'avg_engagement' => calcAvgEngagement($posts),
        'top_post'       => getTopPost($posts),
        'deep_analysis'  => analyzeDeepContent($posts),
        'has_contact'    => !empty($phone) || !empty($email) || !empty($whatsapp),
        'has_phone'      => !empty($phone),
        'has_whatsapp'   => !empty($whatsapp),
        'has_email'      => !empty($email),
        'instagram_url'  => $igUrl,
        'ads_running'    => $adsActive,
        'ads_count'      => $adsCount,
        'creation_date'  => $page['creation_date'] ?? '',
    ];
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
        'usernames' => [$username]
    ]);
    $runId = _apifyStartRun($actorId, $input, $token);

    if (!$runId) return ['success' => false, 'error' => 'فشل تشغيل Instagram Scraper'];

    $result = _apifyWaitAndFetch($runId, $token, 120);
    // إذا كانت النتيجة null يعني فشل أو مهلة، أما مصفوفة فارغة فتعني لا بيانات ولكن لا تعتبر فشلاً صراحةً
    if ($result === null) return ['success' => false, 'error' => 'انتهت مهلة Instagram Scraper'];

    $profile = $result[0] ?? [];
    if (empty($profile)) return ['success' => false, 'error' => 'لا بيانات Instagram'];

    $posts = $profile['latestPosts'] ?? $profile['posts'] ?? [];

    return [
        'success'         => true,
        'source'          => 'apify',
        'platform'        => 'instagram',
        'username'        => $profile['username']         ?? $username,
        'full_name'       => $profile['fullName']         ?? '',
        'followers'       => $profile['followersCount']   ?? null,
        'following'       => $profile['followsCount']     ?? null,
        'posts_count'     => $profile['postsCount']       ?? count($posts),
        'bio'             => $profile['biography']        ?? '',
        'bio_length'      => mb_strlen($profile['biography'] ?? ''),
        'website'         => $profile['externalUrl']      ?? '',
        'is_verified'     => $profile['verified']         ?? false,
        'is_business'     => $profile['isBusinessAccount']?? false,
        'business_category'=> $profile['businessCategoryName'] ?? '',
        'profile_pic'     => $profile['profilePicUrl']   ?? '',
        'highlights_count'=> $profile['highlightReelCount'] ?? 0,
        'has_reels'       => !empty($profile['reelsCount']) || !empty($profile['isEligibleToViewUsernameReels']),
        'avg_likes'       => calcAvgLikes($posts),
        'avg_comments'    => calcAvgComments($posts),
        'engagement_rate' => calcIGEngagement($posts, $profile['followersCount'] ?? 1),
        'top_post'        => getTopIGPost($posts),
        'deep_analysis'   => analyzeDeepContent($posts),
        'posts_per_week'  => calcPostsPerWeek($posts),
        'last_post_days'  => calcLastPostDays($posts),
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
    $total = array_sum(array_map(fn($p) => (int)($p['likes'] ?? 0) + (int)($p['comments'] ?? 0), $posts));
    return round($total / count($posts), 1);
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
        $img = $p['displayUrl'] ?? $p['thumbnailUrl'] ?? $p['imageUrl'] ?? $p['image'] ?? '';
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
