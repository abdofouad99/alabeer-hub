<?php
// ============================================================
// facebook-deep.php — الطبقة العميقة لفحص Facebook (مكافئ لـ instagram-deep.php)
// ------------------------------------------------------------
// يحتوي على 11 دالة تحليل متقدمة:
//   1)  extractFBHashtagsFromPosts          — Hashtags top 20 + counts
//   2)  extractFBMentionsAnalysis           — Mentions + tagged pages
//   3)  calcFBContentDistribution           — types_percent + carousel slides
//   4)  calcFBPostingHeatmap (7×24)         — best day/hour
//   5)  analyzeFBPageOptimization           — About/CTA/Contact/Verification (0-100 + grade)
//   6)  calcFBPageHealthScore               — health 0-100 + grade
//   7)  detectFBPostsLanguageMix            — arabic/english/mixed/empty
//   8)  extractFBLocations                  — location tags (check-ins)
//   9)  calcFBSponsoredRatio                — paid partnerships %
//  10)  analyzeFBCommentsSentiment          — heuristic + OpenAI overlay
//  11)  analyzeFBImagesVision               — OpenAI Vision على top posts
//
// كل الدوال محمية بـ function_exists() لتجنّب الـ redeclare لو أُدرج
// الملف مرتين عبر مسارين مختلفين.
// ============================================================

if (!function_exists('logInfo')) {
    require_once __DIR__ . '/logger.php';
}

// ============================================================
// 1) Hashtags Analysis (top 20 + uniques)
// ============================================================
if (!function_exists('extractFBHashtagsFromPosts')) {
function extractFBHashtagsFromPosts(array $posts): array {
    $bag = [];
    foreach ($posts as $p) {
        // (أ) من حقل hashtags المباشر إن وُجد
        if (!empty($p['hashtags']) && is_array($p['hashtags'])) {
            foreach ($p['hashtags'] as $ht) {
                if (!is_string($ht)) continue;
                $clean = mb_strtolower(ltrim(trim($ht), '#'));
                if ($clean !== '') $bag[$clean] = ($bag[$clean] ?? 0) + 1;
            }
        }
        // (ب) regex على نص المنشور (caption/text/message)
        $text = $p['caption'] ?? $p['text'] ?? $p['message'] ?? '';
        if (!is_string($text) || $text === '') continue;
        if (preg_match_all('/#([\p{L}\p{N}_]+)/u', $text, $m)) {
            foreach ($m[1] as $ht) {
                $clean = mb_strtolower($ht);
                if ($clean !== '') $bag[$clean] = ($bag[$clean] ?? 0) + 1;
            }
        }
    }
    arsort($bag);
    $top = [];
    foreach (array_slice($bag, 0, 20, true) as $tag => $count) {
        $top[] = ['tag' => $tag, 'count' => $count];
    }
    return [
        'unique_count' => count($bag),
        'total_uses'   => array_sum($bag),
        'top'          => $top,
    ];
}}

// ============================================================
// 2) Mentions Analysis (top mentions + tagged pages)
// ============================================================
if (!function_exists('extractFBMentionsAnalysis')) {
function extractFBMentionsAnalysis(array $posts): array {
    $mentionBag = [];
    $taggedBag  = [];

    foreach ($posts as $p) {
        // (أ) mentions المباشرة من Apify
        if (!empty($p['mentions']) && is_array($p['mentions'])) {
            foreach ($p['mentions'] as $u) {
                if (!is_string($u)) continue;
                $clean = mb_strtolower(ltrim(trim($u), '@'));
                if ($clean !== '') $mentionBag[$clean] = ($mentionBag[$clean] ?? 0) + 1;
            }
        }
        // (ب) من نص المنشور
        $text = $p['caption'] ?? $p['text'] ?? $p['message'] ?? '';
        if (is_string($text) && $text !== '') {
            if (preg_match_all('/@([A-Za-z0-9_\.]{2,50})/', $text, $m)) {
                foreach ($m[1] as $u) {
                    $clean = mb_strtolower($u);
                    $mentionBag[$clean] = ($mentionBag[$clean] ?? 0) + 1;
                }
            }
        }
        // (ج) Tagged pages داخل المنشور (with_tags / taggedPages)
        $taggedSources = [
            $p['taggedUsers']   ?? null,
            $p['taggedPages']   ?? null,
            $p['with_tags']     ?? null,
            $p['tagged']        ?? null,
        ];
        foreach ($taggedSources as $src) {
            if (!is_array($src)) continue;
            foreach ($src as $tu) {
                $u = is_array($tu) ? ($tu['username'] ?? $tu['name'] ?? $tu['title'] ?? '') : (is_string($tu) ? $tu : '');
                $clean = mb_strtolower(ltrim(trim($u), '@'));
                if ($clean !== '') $taggedBag[$clean] = ($taggedBag[$clean] ?? 0) + 1;
            }
        }
    }
    arsort($mentionBag);
    arsort($taggedBag);
    $topMentions = [];
    foreach (array_slice($mentionBag, 0, 15, true) as $u => $c) $topMentions[] = ['user' => $u, 'count' => $c];
    $topTagged = [];
    foreach (array_slice($taggedBag, 0, 15, true) as $u => $c) $topTagged[] = ['page' => $u, 'count' => $c];
    return [
        'unique_mentions' => count($mentionBag),
        'total_mentions'  => array_sum($mentionBag),
        'top_mentions'    => $topMentions,
        'unique_tagged'   => count($taggedBag),
        'total_tagged'    => array_sum($taggedBag),
        'top_tagged'      => $topTagged,
    ];
}}

// ============================================================
// 3) Content Type Distribution (image/video/link/event/photo album/...)
// ============================================================
if (!function_exists('calcFBContentDistribution')) {
function calcFBContentDistribution(array $posts): array {
    if (empty($posts)) {
        return ['counts' => [], 'percent' => [], 'avg_album_photos' => 0];
    }
    $counts = [
        'photo'    => 0,
        'video'    => 0,
        'reel'     => 0,
        'album'    => 0,
        'link'     => 0,
        'status'   => 0,
        'event'    => 0,
        'live'     => 0,
    ];
    $albumPhotosTotal = 0; $albumCount = 0;
    foreach ($posts as $p) {
        $type = strtolower((string)($p['type'] ?? $p['mediaType'] ?? $p['postType'] ?? ''));
        $isReel = !empty($p['isReel']) || str_contains($type, 'reel');
        $isLive = !empty($p['isLive']) || str_contains($type, 'live');

        if ($isReel) {
            $counts['reel']++;
        } elseif ($isLive) {
            $counts['live']++;
        } elseif (str_contains($type, 'video')) {
            $counts['video']++;
        } elseif (str_contains($type, 'album') || (!empty($p['photos']) && is_array($p['photos']) && count($p['photos']) > 1)) {
            $counts['album']++;
            $albumPhotosTotal += !empty($p['photos']) && is_array($p['photos']) ? count($p['photos']) : 0;
            $albumCount++;
        } elseif (str_contains($type, 'photo') || !empty($p['image']) || !empty($p['imageUrl']) || !empty($p['displayUrl'])) {
            $counts['photo']++;
        } elseif (str_contains($type, 'link') || !empty($p['linkUrl'])) {
            $counts['link']++;
        } elseif (str_contains($type, 'event')) {
            $counts['event']++;
        } else {
            $counts['status']++;
        }
    }
    $total = max(array_sum($counts), 1);
    $pct = [];
    foreach ($counts as $k => $v) $pct[$k] = round(($v / $total) * 100, 1);
    return [
        'counts'           => $counts,
        'percent'          => $pct,
        'avg_album_photos' => $albumCount > 0 ? round($albumPhotosTotal / $albumCount, 1) : 0,
    ];
}}

// ============================================================
// 4) Posting Heatmap (Day x Hour) — Asia/Riyadh by default
// ============================================================
if (!function_exists('calcFBPostingHeatmap')) {
function calcFBPostingHeatmap(array $posts, string $tz = 'Asia/Riyadh'): array {
    $days  = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    $grid  = []; $count = [];
    foreach ($days as $d) {
        $grid[$d]  = array_fill(0, 24, 0);
        $count[$d] = array_fill(0, 24, 0);
    }
    $hourTotal = array_fill(0, 24, ['posts'=>0,'eng'=>0]);
    $dayTotal  = []; foreach ($days as $d) $dayTotal[$d] = ['posts'=>0,'eng'=>0];
    try { $tzObj = new DateTimeZone($tz); } catch (\Throwable $e) { $tzObj = new DateTimeZone('UTC'); }

    foreach ($posts as $p) {
        $ts = $p['timestamp'] ?? $p['takenAt'] ?? $p['publishedAt'] ?? $p['time'] ?? $p['date'] ?? null;
        if (!$ts) continue;
        try {
            if (is_numeric($ts)) {
                $dt = (new DateTimeImmutable('@' . (int)$ts))->setTimezone($tzObj);
            } else {
                $dt = (new DateTimeImmutable($ts))->setTimezone($tzObj);
            }
        } catch (\Throwable $e) { continue; }

        $d = $days[(int)$dt->format('w')];
        $h = (int)$dt->format('G');
        $likes    = (int)($p['likesCount']    ?? $p['likes']    ?? $p['reactionsCount'] ?? 0);
        $comments = (int)($p['commentsCount'] ?? $p['comments'] ?? $p['commentCount']   ?? 0);
        $shares   = (int)($p['sharesCount']   ?? $p['shareCount'] ?? $p['shares']       ?? 0);
        $eng = $likes + $comments + $shares;

        $grid[$d][$h]    += $eng;
        $count[$d][$h]   += 1;
        $hourTotal[$h]['posts']++; $hourTotal[$h]['eng'] += $eng;
        $dayTotal[$d]['posts']++;  $dayTotal[$d]['eng']  += $eng;
    }

    // أفضل ساعة (متوسط تفاعل)
    $bestHour = null; $bestHourAvg = -1;
    for ($h = 0; $h < 24; $h++) {
        if ($hourTotal[$h]['posts'] === 0) continue;
        $avg = $hourTotal[$h]['eng'] / $hourTotal[$h]['posts'];
        if ($avg > $bestHourAvg) { $bestHourAvg = $avg; $bestHour = $h; }
    }
    // أفضل يوم
    $bestDay = null; $bestDayAvg = -1;
    foreach ($days as $d) {
        if ($dayTotal[$d]['posts'] === 0) continue;
        $avg = $dayTotal[$d]['eng'] / $dayTotal[$d]['posts'];
        if ($avg > $bestDayAvg) { $bestDayAvg = $avg; $bestDay = $d; }
    }
    return [
        'timezone'                 => $tz,
        'grid_engagement'          => $grid,
        'grid_count'               => $count,
        'best_hour'                => $bestHour,
        'best_hour_avg_engagement' => $bestHour !== null ? round($bestHourAvg, 1) : null,
        'best_day'                 => $bestDay,
        'best_day_avg_engagement'  => $bestDay  !== null ? round($bestDayAvg, 1)  : null,
        'hour_totals'              => $hourTotal,
        'day_totals'               => $dayTotal,
    ];
}}

// ============================================================
// 5) Page Optimization Analyzer (0-100 + grade A-F)
// مكافئ لـ analyzeBioOptimization لكن لصفحات الفيسبوك
// ============================================================
if (!function_exists('analyzeFBPageOptimization')) {
function analyzeFBPageOptimization(array $page): array {
    $score = 0; $issues = []; $strengths = [];

    $about      = (string)($page['about'] ?? $page['description'] ?? '');
    $category   = (string)($page['category'] ?? '');
    $website    = (string)($page['website'] ?? '');
    $phone      = (string)($page['phone'] ?? '');
    $whatsapp   = (string)($page['whatsapp'] ?? '');
    $email      = (string)($page['email'] ?? '');
    $address    = (string)($page['address'] ?? '');
    $coverPhoto = (string)($page['cover_photo'] ?? '');
    $profilePic = (string)($page['profile_pic'] ?? '');
    $verified   = (bool)($page['is_verified'] ?? false);
    $rating     = $page['rating'] ?? null;
    $hours      = $page['opening_hours'] ?? [];
    $services   = $page['services'] ?? [];

    // 1) About / Description (15 نقطة)
    $aboutLen = mb_strlen($about);
    if ($aboutLen === 0) { $issues[] = 'لا يوجد وصف للصفحة'; }
    elseif ($aboutLen < 50)   { $score += 5;  $issues[]    = 'الوصف قصير جداً (<50)'; }
    elseif ($aboutLen <= 250) { $score += 15; $strengths[] = 'وصف الصفحة بطول مثالي'; }
    else                       { $score += 10; $strengths[] = 'وصف موجود (طويل)'; }

    // 2) Category / Business Type (10)
    if (!empty($category)) { $score += 10; $strengths[] = "تصنيف: $category"; }
    else $issues[] = 'لا يوجد تصنيف للصفحة';

    // 3) Verification (10)
    if ($verified) { $score += 10; $strengths[] = 'صفحة موثّقة بعلامة زرقاء ✓'; }

    // 4) Contact Info (20: 5 نقاط لكل قناة)
    $contactPts = 0;
    if (!empty($phone))    { $contactPts += 5; $strengths[] = 'هاتف موجود'; }
    if (!empty($whatsapp)) { $contactPts += 5; $strengths[] = 'واتساب موجود'; }
    if (!empty($email))    { $contactPts += 5; $strengths[] = 'بريد إلكتروني موجود'; }
    if (!empty($website))  { $contactPts += 5; $strengths[] = 'موقع إلكتروني موجود'; }
    $score += $contactPts;
    if ($contactPts === 0) $issues[] = 'لا يوجد أي وسيلة تواصل';
    elseif ($contactPts < 15) $issues[] = 'وسائل تواصل محدودة';

    // 5) Address (5)
    if (!empty($address)) { $score += 5; $strengths[] = 'عنوان فيزيائي موجود'; }

    // 6) Visual Identity (10: 5 لكل من profile + cover)
    if (!empty($profilePic)) $score += 5;
    else $issues[] = 'لا توجد صورة شخصية للصفحة';
    if (!empty($coverPhoto)) $score += 5;
    else $issues[] = 'لا توجد صورة غلاف';

    // 7) Hours (5)
    if (!empty($hours) && is_array($hours) && count($hours) > 0) {
        $score += 5; $strengths[] = 'ساعات العمل محددة';
    } else $issues[] = 'ساعات العمل غير محددة';

    // 8) Rating (10)
    if ($rating !== null && is_numeric($rating)) {
        if ($rating >= 4.5)      { $score += 10; $strengths[] = "تقييم ممتاز ($rating⭐)"; }
        elseif ($rating >= 3.5)  { $score += 7;  $strengths[] = "تقييم جيد ($rating⭐)"; }
        elseif ($rating >= 2.5)  { $score += 3; }
        else                      $issues[] = "تقييم منخفض ($rating⭐)";
    }

    // 9) Services / Products (10)
    if (!empty($services) && is_array($services) && count($services) >= 3) {
        $score += 10; $strengths[] = 'خدمات/منتجات معروضة (' . count($services) . ')';
    } elseif (!empty($services) && is_array($services)) {
        $score += 5;
    } else $issues[] = 'لا توجد خدمات/منتجات معروضة';

    // 10) About has CTA keywords (5)
    $ctaWords = ['احجز','اطلب','اتصل','تواصل','رابط','واتساب','رسالة','order','book','call','contact','whatsapp'];
    $hasCta = false;
    foreach ($ctaWords as $w) { if (mb_stripos($about, $w) !== false) { $hasCta = true; break; } }
    if ($hasCta) { $score += 5; $strengths[] = 'الوصف يحتوي CTA'; }
    elseif (!empty($about)) $issues[] = 'الوصف بدون CTA واضح';

    $score = min(100, $score);
    $grade = $score >= 80 ? 'A' : ($score >= 65 ? 'B' : ($score >= 50 ? 'C' : ($score >= 35 ? 'D' : 'F')));

    return [
        'score'       => $score,
        'grade'       => $grade,
        'about_length' => $aboutLen,
        'has_category' => !empty($category),
        'is_verified'  => $verified,
        'has_phone'    => !empty($phone),
        'has_whatsapp' => !empty($whatsapp),
        'has_email'    => !empty($email),
        'has_website'  => !empty($website),
        'has_address'  => !empty($address),
        'has_profile_pic' => !empty($profilePic),
        'has_cover'    => !empty($coverPhoto),
        'has_hours'    => !empty($hours),
        'has_services' => !empty($services),
        'has_cta'      => $hasCta,
        'rating'       => $rating,
        'strengths'    => $strengths,
        'issues'       => $issues,
    ];
}}

// ============================================================
// 6) Page Health Score (0-100 + grade)
// مكافئ لـ calcAccountHealthScore لكن لصفحات الفيسبوك
// ============================================================
if (!function_exists('calcFBPageHealthScore')) {
function calcFBPageHealthScore(array $data): array {
    $score = 0; $issues = []; $strengths = [];
    $followers   = (int)($data['followers'] ?? 0);
    $likes       = (int)($data['likes'] ?? 0);
    $postsCount  = (int)($data['posts_count'] ?? 0);
    $eng         = (float)($data['engagement_rate'] ?? 0);
    $verified    = (bool)($data['is_verified'] ?? false);
    $hasReviews  = (bool)($data['has_reviews'] ?? false);
    $rating      = $data['rating'] ?? null;
    $reviewsCount= (int)($data['reviews_count'] ?? 0);
    $hasShop     = (bool)($data['has_shop'] ?? false);
    $hasWebsite  = !empty($data['website'] ?? '');
    $aboutLen    = (int)($data['about_length'] ?? 0);
    $postsPerWeek= (float)($data['posts_per_week'] ?? 0);
    $lastPostDays= $data['last_post_days'] ?? null;
    $adsRunning  = (bool)($data['ads_running'] ?? false);

    // 1) Activity (20): Regular posting
    if ($postsPerWeek >= 4)      { $score += 20; $strengths[] = 'نشاط ممتاز (>=4 منشورات/أسبوع)'; }
    elseif ($postsPerWeek >= 2)  { $score += 15; $strengths[] = 'نشاط جيد'; }
    elseif ($postsPerWeek >= 1)  { $score += 10; $issues[]    = 'نشر متباعد (~1/أسبوع)'; }
    elseif ($postsPerWeek > 0)   { $score += 5;  $issues[]    = 'نشاط ضعيف'; }
    else                          $issues[] = 'لا يوجد نشاط منتظم';

    // 2) Recency (10)
    if ($lastPostDays !== null) {
        if ($lastPostDays <= 3)       { $score += 10; $strengths[] = 'آخر منشور حديث جداً'; }
        elseif ($lastPostDays <= 7)   { $score += 7; }
        elseif ($lastPostDays <= 30)  { $score += 3; $issues[] = 'آخر منشور > أسبوع'; }
        else                          { $issues[] = "آخر منشور قبل $lastPostDays يوم"; }
    }

    // 3) Engagement Rate (20)
    if ($eng >= 5)      { $score += 20; $strengths[] = 'تفاعل ممتاز (>=5%)'; }
    elseif ($eng >= 2)  { $score += 15; $strengths[] = 'تفاعل جيد'; }
    elseif ($eng >= 0.5){ $score += 10; }
    elseif ($eng > 0)   { $score += 5;  $issues[] = 'تفاعل منخفض'; }
    else                  $issues[] = 'لا يوجد تفاعل ملموس';

    // 4) Audience Size (10)
    if ($followers >= 100000)      $score += 10;
    elseif ($followers >= 10000)   $score += 7;
    elseif ($followers >= 1000)    $score += 4;
    elseif ($followers >= 100)     $score += 2;
    else                           $issues[] = 'جمهور صغير جداً';

    // 5) Reviews & Ratings (10)
    if ($rating !== null && is_numeric($rating) && $reviewsCount > 0) {
        if ($rating >= 4.5 && $reviewsCount >= 50)      { $score += 10; $strengths[] = "تقييم ممتاز مع $reviewsCount مراجعة"; }
        elseif ($rating >= 4.0)                          { $score += 7;  $strengths[] = "تقييم جيد ($rating⭐)"; }
        elseif ($rating >= 3.0)                          { $score += 3; }
        else                                              $issues[] = "تقييم منخفض ($rating⭐)";
    } elseif ($hasReviews) {
        $score += 3;
    } else $issues[] = 'لا توجد تقييمات';

    // 6) Trust signals (10)
    if ($verified) { $score += 5; $strengths[] = 'صفحة موثقة'; }
    if ($hasWebsite) $score += 3; else $issues[] = 'لا يوجد موقع إلكتروني';
    if ($aboutLen >= 100) $score += 2;

    // 7) Commerce (10)
    if ($hasShop) { $score += 5; $strengths[] = 'متجر مفعّل'; }
    if ($adsRunning) { $score += 5; $strengths[] = 'إعلانات نشطة'; }
    elseif (!$hasShop) $issues[] = 'لا يوجد متجر ولا إعلانات نشطة';

    // 8) Content variety (10)
    if (!empty($data['content_distribution']) && is_array($data['content_distribution'])) {
        $pct = $data['content_distribution']['percent'] ?? [];
        $videoPlusReel = ($pct['video'] ?? 0) + ($pct['reel'] ?? 0);
        if ($videoPlusReel >= 30)      { $score += 10; $strengths[] = 'محتوى فيديو جيد'; }
        elseif ($videoPlusReel >= 15)  { $score += 5; }
        elseif ($videoPlusReel === 0)  { $issues[] = 'لا يوجد محتوى فيديو'; }
    }

    $score = min(100, $score);
    $grade = $score >= 80 ? 'A' : ($score >= 65 ? 'B' : ($score >= 50 ? 'C' : ($score >= 35 ? 'D' : 'F')));

    return [
        'score'     => $score,
        'grade'     => $grade,
        'strengths' => $strengths,
        'issues'    => $issues,
    ];
}}

// ============================================================
// 7) Languages Distribution
// ============================================================
if (!function_exists('detectFBPostsLanguageMix')) {
function detectFBPostsLanguageMix(array $posts): array {
    $ar = 0; $en = 0; $mix = 0; $other = 0; $empty = 0;
    foreach ($posts as $p) {
        $t = $p['caption'] ?? $p['text'] ?? $p['message'] ?? '';
        if (!is_string($t) || trim($t) === '') { $empty++; continue; }
        $hasAr = (bool)preg_match('/[\x{0600}-\x{06FF}]/u', $t);
        $hasEn = (bool)preg_match('/[A-Za-z]{3,}/', $t);
        if ($hasAr && $hasEn) $mix++;
        elseif ($hasAr)        $ar++;
        elseif ($hasEn)        $en++;
        else                   $other++;
    }
    $total = max($ar + $en + $mix + $other + $empty, 1);
    return [
        'arabic_pct'  => round(($ar / $total) * 100, 1),
        'english_pct' => round(($en / $total) * 100, 1),
        'mixed_pct'   => round(($mix / $total) * 100, 1),
        'other_pct'   => round(($other / $total) * 100, 1),
        'empty_pct'   => round(($empty / $total) * 100, 1),
        'dominant'    => ($ar >= $en && $ar >= $mix) ? 'arabic' : ($en >= $mix ? 'english' : 'mixed'),
    ];
}}

// ============================================================
// 8) Locations / Check-ins distribution
// ============================================================
if (!function_exists('extractFBLocations')) {
function extractFBLocations(array $posts): array {
    $bag = [];
    foreach ($posts as $p) {
        // FB Apify يعطي location داخل place أو checkin
        $locName = $p['locationName']
                ?? $p['location']['name']
                ?? $p['place']['name']
                ?? $p['checkin']['place']
                ?? null;
        if (!is_string($locName) || trim($locName) === '') continue;
        $bag[$locName] = ($bag[$locName] ?? 0) + 1;
    }
    arsort($bag);
    $top = [];
    foreach (array_slice($bag, 0, 10, true) as $loc => $c) $top[] = ['name' => $loc, 'count' => $c];
    return [
        'unique_locations' => count($bag),
        'tagged_posts'     => array_sum($bag),
        'top'              => $top,
    ];
}}

// ============================================================
// 9) Sponsored / Branded Content ratio
// ============================================================
if (!function_exists('calcFBSponsoredRatio')) {
function calcFBSponsoredRatio(array $posts): array {
    if (empty($posts)) return ['sponsored_count' => 0, 'percent' => 0];
    $count = 0;
    foreach ($posts as $p) {
        if (!empty($p['isSponsored'])
            || !empty($p['is_paid_partnership'])
            || !empty($p['paid_partnership'])
            || !empty($p['isBranded'])
            || !empty($p['branded_content'])
        ) $count++;
    }
    return [
        'sponsored_count' => $count,
        'percent'         => round(($count / count($posts)) * 100, 1),
    ];
}}

// ============================================================
// 10) Comments Sentiment Analysis (heuristic + optional OpenAI)
// ============================================================
if (!function_exists('analyzeFBCommentsSentiment')) {
/**
 * يجمع تعليقات أفضل N منشورات عبر Apify FB Comments Actor
 * (us5srxAYnsrkgUv2v) ثم يحلل المشاعر: heuristic + OpenAI overlay.
 *
 * @param array  $topPosts قائمة منشورات (يجب أن تحتوي url/postUrl)
 * @param string $token    Apify token صالح
 * @param array  $cfg      config كامل
 */
function analyzeFBCommentsSentiment(array $topPosts, string $token, array $cfg): array {
    $maxPosts = (int)($cfg['analysis']['fb_comments_top_posts'] ?? 5);
    $actorId  = $cfg['apis']['apify_actor_fb_comments'] ?? 'us5srxAYnsrkgUv2v';
    $useAi    = !empty($cfg['analysis']['enable_fb_sentiment_ai']);

    $allComments = [];
    $repliesByOwner = 0;
    $picked = 0;
    $ownerHandle = '';
    foreach ($topPosts as $p) {
        if ($picked >= $maxPosts) break;
        $url = $p['url'] ?? $p['postUrl'] ?? $p['permalink'] ?? $p['permalink_url'] ?? null;
        if (!$url) continue;

        $input = json_encode([
            'startUrls'             => [['url' => $url]],
            'resultsLimit'          => 30,
            'includeNestedComments' => false,
            'viewOption'            => 'RANKED_UNFILTERED',
        ]);
        $runId = _apifyStartRun($actorId, $input, $token);
        if (!$runId) continue;
        $items = _apifyWaitAndFetch($runId, $token, 90);
        if (!is_array($items) || empty($items)) continue;
        $picked++;

        // تحديد handle المالك (لمعرفة معدل ردوده)
        $pageOwner = $p['ownerUsername'] ?? $p['pageName'] ?? '';
        if ($ownerHandle === '' && $pageOwner) $ownerHandle = $pageOwner;

        foreach ($items as $c) {
            $text = trim((string)($c['text'] ?? $c['comment'] ?? $c['message'] ?? ''));
            if ($text === '') continue;
            $u = $c['ownerUsername'] ?? $c['authorName'] ?? $c['user']['name'] ?? '';
            $isOwner = $ownerHandle && strcasecmp($u, $ownerHandle) === 0;
            $allComments[] = [
                'text'      => $text,
                'owner'     => $u,
                'likes'     => (int)($c['likesCount'] ?? $c['reactionsCount'] ?? 0),
                'timestamp' => $c['timestamp'] ?? null,
                'post_url'  => $url,
                'is_owner'  => $isOwner,
            ];
            if ($isOwner) $repliesByOwner++;
        }
    }

    if (empty($allComments)) {
        return [
            'success'        => false,
            'reason'         => 'لم تُسحب أي تعليقات',
            'total_comments' => 0,
        ];
    }

    // (1) heuristic — تصنيف مبدئي سريع
    $heur = _fbHeuristicSentiment($allComments);

    // (2) OpenAI overlay (اختياري)
    $aiSummary = null;
    if ($useAi && !empty($cfg['apis']['openai_key']) && count($allComments) >= 5) {
        $aiSummary = _fbOpenaiSentimentSummary($allComments, $cfg);
    }

    $total = count($allComments);
    return [
        'success'         => true,
        'total_comments'  => $total,
        'posts_sampled'   => $picked,
        'positive_pct'    => $heur['positive_pct'],
        'negative_pct'    => $heur['negative_pct'],
        'neutral_pct'     => $heur['neutral_pct'],
        'questions_pct'   => $heur['questions_pct'],
        'top_objections'  => $heur['top_objections'],
        'top_praise'      => $heur['top_praise'],
        'top_questions'   => $heur['top_questions'],
        'samples'         => $heur['samples'],
        'response_rate'   => $total > 0 ? round(($repliesByOwner / $total) * 100, 1) : 0,
        'ai_summary'      => $aiSummary,
    ];
}}

if (!function_exists('_fbHeuristicSentiment')) {
function _fbHeuristicSentiment(array $comments): array {
    // SENT-1 FIX: أزلنا "لا" — كلمة عامة جداً تسبب false positives (مثل "لا يوجد أفضل منكم")
    $negWords = ['غالي','مكلف','سيء','وحش','رديء','ضعيف','فاشل','تأخر','تعطل','مزعج','خايس','خداع','نصب','احتيال','bad','worst','expensive','scam','poor','rude','slow','terrible'];
    $posWords = ['رائع','ممتاز','جيد','حلو','عظيم','جميل','شكر','الله يعطيكم','يعطيك','مبدع','أفضل','كفو','كويس','great','love','excellent','amazing','perfect','awesome'];
    $qMarkers = ['?','؟','كيف','متى','كم','هل','وين','أين','الأسعار','السعر','price','how','when','where'];
    // SENT-1 FIX: كلمات استثناء — لو "غالي" مع كلمة إيجابية = neutral وليس negative
    $negExceptions = ['يستاهل','يستحق','بس','لكن الجودة','worth','ولكن'];

    $pos=0; $neg=0; $q=0; $neu=0;
    $obj=[]; $praise=[]; $questions=[]; $samples=[];
    foreach ($comments as $c) {
        $t = $c['text'];
        $samples[] = mb_substr($t, 0, 100);
        $isQ=false; $isN=false; $isP=false;
        foreach ($qMarkers as $w) if (mb_stripos($t,$w)!==false) { $isQ=true; break; }
        foreach ($negWords as $w) {
            if (mb_stripos($t,$w)!==false) {
                // SENT-1 FIX: فحص سياقي — هل توجد كلمة استثناء بالقرب؟
                $hasException = false;
                foreach ($negExceptions as $ex) {
                    if (mb_stripos($t, $ex) !== false) { $hasException = true; break; }
                }
                if (!$hasException) { $isN = true; break; }
            }
        }
        foreach ($posWords as $w) if (mb_stripos($t,$w)!==false) { $isP=true; break; }
        if ($isQ) { $q++; $questions[] = mb_substr($t, 0, 120); }
        if ($isN) { $neg++; $obj[]      = mb_substr($t, 0, 120); }
        elseif ($isP) { $pos++; $praise[] = mb_substr($t, 0, 120); }
        else { $neu++; }
    }
    $tot = max(count($comments), 1);
    return [
        'positive_pct'  => round(($pos/$tot)*100),
        'negative_pct'  => round(($neg/$tot)*100),
        'neutral_pct'   => round(($neu/$tot)*100),
        'questions_pct' => round(($q  /$tot)*100),
        'top_objections'=> array_slice(array_unique($obj), 0, 5),
        'top_praise'    => array_slice(array_unique($praise), 0, 5),
        'top_questions' => array_slice(array_unique($questions), 0, 5),
        'samples'       => array_slice($samples, 0, 15),
    ];
}}

if (!function_exists('_fbOpenaiSentimentSummary')) {
function _fbOpenaiSentimentSummary(array $comments, array $cfg): ?array {
    $key   = $cfg['apis']['openai_key'] ?? '';
    $model = $cfg['apis']['openai_model'] ?? 'gpt-4o-mini';
    if (!$key) return null;

    $sample = array_slice($comments, 0, 30);
    $list = '';
    foreach ($sample as $i => $c) {
        $t = preg_replace("/\s+/", ' ', $c['text']);
        $list .= ($i+1) . ". " . mb_substr($t, 0, 200) . "\n";
    }

    $prompt = "حلّل تعليقات صفحة Facebook التالية باللغة العربية وأجب JSON فقط بدون أي شرح إضافي.\n"
            . "أعطني الكائن التالي:\n"
            . "{\n"
            . "  \"overall\": \"positive|mixed|negative|neutral\",\n"
            . "  \"positive_pct\": 0,\n"
            . "  \"negative_pct\": 0,\n"
            . "  \"neutral_pct\": 0,\n"
            . "  \"main_objections\": [\"...\"],\n"
            . "  \"main_praise\": [\"...\"],\n"
            . "  \"top_questions\": [\"...\"],\n"
            . "  \"recommendations\": [\"...\"]\n"
            . "}\n\nالتعليقات:\n" . $list;

    $payload = json_encode([
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => 'You are a careful Facebook page sentiment analyst for Arabic content. Reply ONLY with valid JSON.'],
            ['role' => 'user',   'content' => $prompt],
        ],
        'response_format' => ['type' => 'json_object'],
        'temperature' => 0.2,
        'max_tokens'  => 800,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => (int)($cfg['apis']['openai_timeout'] ?? 60),
        CURLOPT_CONNECTTIMEOUT => (int)($cfg['apis']['openai_connect_timeout'] ?? 15),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$resp) return null;
    $arr = json_decode($resp, true);
    $content = $arr['choices'][0]['message']['content'] ?? '';
    if (!$content) return null;
    $parsed = json_decode($content, true);
    return is_array($parsed) ? $parsed : null;
}}

// ============================================================
// 11) Vision AI — تحليل الصور (OpenAI gpt-4o-mini Vision)
// ============================================================
if (!function_exists('analyzeFBImagesVision')) {
/**
 * يحلّل أفضل N صور (الأعلى تفاعلاً) عبر OpenAI Vision.
 * يُرجع: tags, ocr_text, has_logo/price/offer, branding_consistency.
 */
function analyzeFBImagesVision(array $topPosts, array $cfg): array {
    $key   = $cfg['apis']['openai_key'] ?? '';
    if (!$key) return ['success' => false, 'reason' => 'OPENAI_KEY غير مضبوط'];
    $model = $cfg['apis']['openai_model'] ?? 'gpt-4o-mini';
    $maxImg = (int)($cfg['analysis']['fb_vision_top_images'] ?? 5);

    $images = [];
    foreach ($topPosts as $p) {
        if (count($images) >= $maxImg) break;
        $u = $p['displayUrl']
          ?? $p['imageUrl']
          ?? $p['thumbnailUrl']
          ?? $p['image']
          ?? $p['media'][0]['image']['src']
          ?? '';
        if (is_array($u)) $u = $u['uri'] ?? $u['src'] ?? '';
        if (!is_string($u) || $u === '') continue;
        $caption = (string)($p['caption'] ?? $p['text'] ?? $p['message'] ?? '');
        $images[] = ['url' => $u, 'caption' => mb_substr($caption, 0, 200)];
    }
    if (empty($images)) return ['success' => false, 'reason' => 'لا توجد صور صالحة'];

    $analyses = [];
    foreach ($images as $img) {
        $messages = [
            ['role' => 'system', 'content' => 'You analyze Facebook page images for marketing audits. Answer in Arabic and ONLY JSON.'],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => "حلّل الصورة وأرجع JSON فقط:\n{\n  \"description\": \"وصف موجز\",\n  \"tags\": [\"...\"],\n  \"ocr_text\": \"النص الظاهر بالصورة (إن وجد)\",\n  \"language\": \"arabic|english|mixed|none\",\n  \"has_logo\": false,\n  \"has_price\": false,\n  \"has_offer\": false,\n  \"product_focus\": \"\",\n  \"image_quality\": \"low|medium|high\",\n  \"branding_consistency\": \"weak|ok|strong\"\n}\n\nالكابشن:" . ($img['caption'] ?: '(بدون كابشن)')],
                ['type' => 'image_url', 'image_url' => ['url' => $img['url']]],
            ]],
        ];
        $payload = json_encode([
            'model' => $model,
            'messages' => $messages,
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.2,
            'max_tokens'  => 500,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => (int)($cfg['apis']['openai_timeout'] ?? 90),
            CURLOPT_CONNECTTIMEOUT => (int)($cfg['apis']['openai_connect_timeout'] ?? 15),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
            ],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$resp) continue;
        $arr = json_decode($resp, true);
        $content = $arr['choices'][0]['message']['content'] ?? '';
        $parsed = $content ? json_decode($content, true) : null;
        if (is_array($parsed)) {
            $parsed['image_url'] = $img['url'];
            $analyses[] = $parsed;
        }
    }

    // ملخص شامل
    $tagBag = []; $hasLogo = 0; $hasPrice = 0; $hasOffer = 0; $qualityBag = [];
    foreach ($analyses as $a) {
        foreach (($a['tags'] ?? []) as $t) {
            if (!is_string($t)) continue;
            $tt = mb_strtolower(trim($t));
            $tagBag[$tt] = ($tagBag[$tt] ?? 0) + 1;
        }
        if (!empty($a['has_logo']))  $hasLogo++;
        if (!empty($a['has_price'])) $hasPrice++;
        if (!empty($a['has_offer'])) $hasOffer++;
        $q = $a['image_quality'] ?? 'medium';
        $qualityBag[$q] = ($qualityBag[$q] ?? 0) + 1;
    }
    arsort($tagBag);

    return [
        'success'              => true,
        'analyzed_count'       => count($analyses),
        'images'               => $analyses,
        'top_tags'             => array_slice(array_keys($tagBag), 0, 10),
        'logos_present'        => $hasLogo,
        'prices_present'       => $hasPrice,
        'offers_present'       => $hasOffer,
        'quality_distribution' => $qualityBag,
    ];
}}
