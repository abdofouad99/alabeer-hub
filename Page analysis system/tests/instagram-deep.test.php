<?php
// ============================================================
// instagram-deep.test.php — اختبار قائم بذاته لـ instagram-deep.php
// ------------------------------------------------------------
// لا يحتاج Apify ولا قاعدة بيانات. يولّد بيانات وهمية تُحاكي
// مخرجات apify/instagram-scraper ثم يستدعي كل الدوال ويتحقق من
// أن المخرجات بالشكل المتوقع. للتشغيل:
//   php "Page analysis system/tests/instagram-deep.test.php"
// ============================================================

// stub logger functions used by instagram-deep
if (!function_exists('logInfo')) {
    function logInfo() { /* noop in tests */ }
}
if (!function_exists('logError')) {
    function logError() { /* noop in tests */ }
}

require_once __DIR__ . '/../api/instagram-deep.php';

$pass = 0; $fail = 0; $errors = [];
function assertTrue($cond, string $name) {
    global $pass, $fail, $errors;
    if ($cond) { echo "  ✓ {$name}\n"; $pass++; }
    else       { echo "  ✗ {$name}\n"; $fail++; $errors[] = $name; }
}

// ---- mock posts (10 posts) ----
$now = time();
$posts = [];
$samplePosts = [
    ['type' => 'Image',     'caption' => 'مرحبا بكم! #افتتاح #الرياض @brand_partner',                         'likes' => 240, 'comments' => 18,  'hours' => 1*24],
    ['type' => 'Video',     'caption' => 'شاهد ريلز رائع #ريلز #fitness',                                     'likes' => 1100,'comments' => 45,  'hours' => 2*24],
    ['type' => 'Sidecar',   'caption' => 'أفضل العروض هذا الأسبوع! اطلب الآن #عرض #خصم',                       'likes' => 580, 'comments' => 32,  'hours' => 3*24],
    ['type' => 'Reel',      'caption' => 'best moments today #travel #saudi',                                  'likes' => 2300,'comments' => 110, 'hours' => 5*24],
    ['type' => 'Image',     'caption' => 'تواصل معنا واتساب 0500000000 #خدمات',                                'likes' => 150, 'comments' => 12,  'hours' => 7*24],
    ['type' => 'Reel',      'caption' => 'amazing recipe today @chef.kareem #food #cooking',                    'likes' => 1850,'comments' => 80,  'hours' => 9*24],
    ['type' => 'Image',     'caption' => 'منشور بدون هاشتاجات',                                                'likes' => 80,  'comments' => 4,   'hours' => 12*24],
    ['type' => 'Sidecar',   'caption' => 'كاروسيل عرض جديد #products',                                         'likes' => 420, 'comments' => 22,  'hours' => 14*24],
    ['type' => 'Image',     'caption' => 'Limited offer! Click bio link 🔥',                                    'likes' => 320, 'comments' => 18,  'hours' => 16*24],
    ['type' => 'Reel',      'caption' => 'وصفة جديدة #cooking #ramadan @sponsor_brand',                        'likes' => 3100,'comments' => 220, 'hours' => 20*24, 'isSponsored' => true],
];
foreach ($samplePosts as $i => $sp) {
    $posts[] = [
        'id'             => 'p' . ($i + 1),
        'shortCode'      => 'C' . sprintf('%03d', $i + 1),
        'url'            => 'https://www.instagram.com/p/C' . sprintf('%03d', $i + 1) . '/',
        'type'           => $sp['type'],
        'caption'        => $sp['caption'],
        'hashtags'       => [],
        'mentions'       => [],
        'likesCount'     => $sp['likes'],
        'commentsCount'  => $sp['comments'],
        'savesCount'     => intval($sp['likes'] * 0.05),
        'videoViewCount' => str_contains(strtolower($sp['type']), 'reel') || str_contains(strtolower($sp['type']), 'video') ? $sp['likes'] * 8 : 0,
        'videoPlayCount' => str_contains(strtolower($sp['type']), 'reel') || str_contains(strtolower($sp['type']), 'video') ? $sp['likes'] * 12 : 0,
        'videoDuration'  => str_contains(strtolower($sp['type']), 'reel') ? 28.5 : null,
        'displayUrl'     => 'https://example.com/img' . ($i + 1) . '.jpg',
        'images'         => $sp['type'] === 'Sidecar' ? ['https://example.com/img.jpg', 'https://example.com/img2.jpg', 'https://example.com/img3.jpg'] : [],
        'alt'            => 'Image may contain: ' . $sp['type'],
        'locationName'   => $i % 2 === 0 ? 'الرياض' : '',
        'isSponsored'    => $sp['isSponsored'] ?? false,
        'taggedUsers'    => $i === 5 ? [['username' => 'partner_co']] : [],
        'latestComments' => [],
        'timestamp'      => date('c', $now - $sp['hours'] * 3600),
    ];
}

echo "── 1) extractHashtagsFromPosts ────────────────\n";
$h = extractHashtagsFromPosts($posts);
assertTrue(is_array($h) && isset($h['top']),                  'returns array with top');
assertTrue($h['unique_count'] >= 8,                           'unique_count >= 8 (got ' . $h['unique_count'] . ')');
assertTrue(count($h['top']) > 0,                              'top has entries');
assertTrue(in_array('cooking', array_column($h['top'], 'tag'), true), 'cooking tag found');

echo "\n── 2) extractMentionsFromPosts ────────────────\n";
$m = extractMentionsFromPosts($posts);
assertTrue($m['total_mentions'] >= 2,                         'mentions >= 2');
assertTrue($m['total_tagged']   >= 1,                         'tagged users >= 1');

echo "\n── 3) calcContentTypeDistribution ─────────────\n";
$cd = calcContentTypeDistribution($posts);
assertTrue(isset($cd['percent']['reel']) && $cd['percent']['reel'] > 0,        'reel pct > 0');
assertTrue(isset($cd['percent']['carousel']) && $cd['percent']['carousel'] > 0,'carousel pct > 0');
assertTrue($cd['avg_carousel_slides'] > 0,                                     'avg_carousel_slides > 0');

echo "\n── 4) calcPostingHeatmap ──────────────────────\n";
$hm = calcPostingHeatmap($posts);
assertTrue(isset($hm['grid_engagement']['Sun']),  'grid_engagement has Sun row');
assertTrue($hm['best_hour'] !== null,             'best_hour computed');
assertTrue($hm['best_day']  !== null,             'best_day computed');

echo "\n── 5) analyzeBioOptimization ──────────────────\n";
$bio = "🔥 وكيل معتمد رقم 1 في الرياض\nاطلب عبر الواتساب\nwa.me/9665xxxxxxxx\nhttps://example.com";
$bo  = analyzeBioOptimization($bio, 'https://example.com', true, 'Restaurant');
assertTrue($bo['score'] >= 60,                    'bio score >= 60 (got ' . $bo['score'] . ')');
assertTrue($bo['has_link'] === true,              'has_link');
assertTrue($bo['has_cta']  === true,              'has_cta');
assertTrue($bo['has_whatsapp'] === true,          'has_whatsapp');
assertTrue($bo['has_emoji'] === true,             'has_emoji');
assertTrue(in_array($bo['grade'], ['A','B','C']), 'grade reasonable');

echo "\n── 6) calcAccountHealthScore ──────────────────\n";
$health = calcAccountHealthScore([
    'followers' => 12500, 'following' => 800, 'posts_count' => 220,
    'engagement_rate' => 4.2, 'is_verified' => true, 'is_business' => true,
    'private' => false, 'has_reels' => true, 'website' => 'https://example.com',
    'bio_length' => 110, 'posts_per_week' => 3.5, 'last_post_days' => 2,
    'highlight_reel_count' => 6,
]);
assertTrue($health['score'] >= 70,                'health score >= 70 (got ' . $health['score'] . ')');
assertTrue(in_array($health['grade'], ['A','B']), 'grade A or B');

echo "\n── 7) detectPostsLanguageMix ──────────────────\n";
$lm = detectPostsLanguageMix($posts);
assertTrue($lm['arabic_pct'] > 0,                 'arabic_pct > 0');
assertTrue($lm['english_pct'] > 0,                'english_pct > 0');
assertTrue(in_array($lm['dominant'], ['arabic','english','mixed']), 'dominant valid');

echo "\n── 8) extractTopLocations ─────────────────────\n";
$loc = extractTopLocations($posts);
assertTrue($loc['unique_locations'] >= 1,         'has location entries');
assertTrue($loc['top'][0]['name'] === 'الرياض',   'top location is Riyadh');

echo "\n── 9) calcSponsoredRatio ──────────────────────\n";
$sp = calcSponsoredRatio($posts);
assertTrue($sp['sponsored_count'] === 1,          'sponsored_count = 1');
assertTrue($sp['percent'] > 0,                    'sponsored pct > 0');

echo "\n── 10) analyzeReelsPerformance ────────────────\n";
$rp = analyzeReelsPerformance($posts);
assertTrue($rp['count'] >= 3,                     'reels count >= 3');
assertTrue($rp['avg_plays'] > 0,                  'avg_plays > 0');
assertTrue($rp['engagement_per_play'] !== null,   'engagement_per_play computed');

echo "\n── 11) Heuristic sentiment via _heuristicSentiment ──\n";
$comments = [
    ['text' => 'رائع جداً! منتج ممتاز شكراً'],
    ['text' => 'كم سعر هذا المنتج؟'],
    ['text' => 'سيء جداً غالي بشكل مزعج'],
    ['text' => 'متى يصل التوصيل؟'],
    ['text' => 'love it! great product'],
    ['text' => 'تعليق محايد بدون رأي واضح'],
];
$hs = _heuristicSentiment($comments);
assertTrue($hs['positive_pct'] > 0,               'positive_pct > 0');
assertTrue($hs['negative_pct'] > 0,               'negative_pct > 0');
assertTrue($hs['questions_pct'] > 0,              'questions_pct > 0');

echo "\n──────────────────────────────────────────────\n";
echo "النتيجة: نجح {$pass} / فشل {$fail}\n";
if ($fail > 0) {
    echo "الأخطاء: \n - " . implode("\n - ", $errors) . "\n";
    exit(1);
}
echo "✅ كل اختبارات طبقة Instagram العميقة نجحت\n";
