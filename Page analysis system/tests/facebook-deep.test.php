<?php
// ============================================================
// facebook-deep.test.php — اختبار قائم بذاته لـ facebook-deep.php
// ------------------------------------------------------------
// لا يحتاج Apify ولا قاعدة بيانات. يولّد بيانات وهمية تُحاكي
// مخرجات apify/facebook-pages-scraper ثم يستدعي كل الدوال ويتحقق
// من أن المخرجات بالشكل المتوقع. للتشغيل:
//   php "Page analysis system/tests/facebook-deep.test.php"
// ============================================================

// stub logger functions used by facebook-deep
if (!function_exists('logInfo'))   { function logInfo()  {} }
if (!function_exists('logError'))  { function logError() {} }

require_once __DIR__ . '/../api/facebook-deep.php';

$pass = 0; $fail = 0; $errors = [];
function fbAssert($cond, string $name) {
    global $pass, $fail, $errors;
    if ($cond) { echo "  ✓ {$name}\n"; $pass++; }
    else       { echo "  ✗ {$name}\n"; $fail++; $errors[] = $name; }
}

// ---- mock Facebook posts (10 posts) ----
$now = time();
$samplePosts = [
    ['type' => 'photo',     'caption' => 'مرحبا بكم في فرعنا الجديد! #افتتاح #الرياض @brand_partner', 'likes' => 240, 'comments' => 18,  'shares' => 5,  'hours' => 1*24],
    ['type' => 'video',     'caption' => 'شاهد إعلاننا الجديد #فيديو',                                'likes' => 1100,'comments' => 45,  'shares' => 30, 'hours' => 2*24],
    ['type' => 'album',     'caption' => 'أفضل العروض هذا الأسبوع! اطلب الآن #عرض #خصم',              'likes' => 580, 'comments' => 32,  'shares' => 12, 'hours' => 3*24],
    ['type' => 'reel',      'caption' => 'best moments today #travel #saudi',                         'likes' => 2300,'comments' => 110, 'shares' => 80, 'hours' => 5*24],
    ['type' => 'photo',     'caption' => 'تواصل معنا واتساب 0500000000 #خدمات',                       'likes' => 150, 'comments' => 12,  'shares' => 3,  'hours' => 7*24],
    ['type' => 'video',     'caption' => 'amazing recipe today @chef.kareem #food #cooking',          'likes' => 1850,'comments' => 80,  'shares' => 40, 'hours' => 9*24],
    ['type' => 'status',    'caption' => 'منشور بدون هاشتاجات',                                      'likes' => 80,  'comments' => 4,   'shares' => 0,  'hours' => 12*24],
    ['type' => 'album',     'caption' => 'كاروسيل عرض جديد #products',                                'likes' => 420, 'comments' => 22,  'shares' => 8,  'hours' => 14*24],
    ['type' => 'photo',     'caption' => 'Limited offer! Click bio link 🔥',                           'likes' => 320, 'comments' => 18,  'shares' => 6,  'hours' => 16*24],
    ['type' => 'reel',      'caption' => 'وصفة جديدة #cooking #ramadan @sponsor_brand',               'likes' => 3100,'comments' => 220, 'shares' => 90, 'hours' => 20*24, 'isSponsored' => true],
];
$posts = [];
foreach ($samplePosts as $i => $sp) {
    $posts[] = [
        'id'             => 'p' . ($i + 1),
        'url'            => 'https://www.facebook.com/page/posts/' . ($i + 1),
        'type'           => $sp['type'],
        'caption'        => $sp['caption'],
        'likesCount'     => $sp['likes'],
        'commentsCount'  => $sp['comments'],
        'sharesCount'    => $sp['shares'],
        'videoViewCount' => str_contains(strtolower($sp['type']), 'reel') || str_contains(strtolower($sp['type']), 'video') ? $sp['likes'] * 8 : 0,
        'image'          => 'https://example.com/img' . ($i + 1) . '.jpg',
        'photos'         => $sp['type'] === 'album' ? ['p1.jpg', 'p2.jpg', 'p3.jpg', 'p4.jpg'] : [],
        'place'          => $i % 3 === 0 ? ['name' => 'الرياض'] : null,
        'isSponsored'    => $sp['isSponsored'] ?? false,
        'taggedPages'    => $i === 5 ? [['name' => 'partner_co']] : [],
        'timestamp'      => date('c', $now - $sp['hours'] * 3600),
    ];
}

echo "═══════════════════════════════════════════════════\n";
echo "  Facebook Deep Layer — Test Suite\n";
echo "═══════════════════════════════════════════════════\n\n";

echo "── 1) extractFBHashtagsFromPosts ──────────────\n";
$h = extractFBHashtagsFromPosts($posts);
fbAssert(is_array($h) && isset($h['top']),                                       'returns array with top');
fbAssert($h['unique_count'] >= 8,                                                'unique_count >= 8 (got ' . $h['unique_count'] . ')');
fbAssert(count($h['top']) > 0,                                                   'top has entries');
fbAssert(in_array('cooking', array_column($h['top'], 'tag'), true),              'cooking tag found');
fbAssert(isset($h['total_uses']) && $h['total_uses'] > 0,                        'total_uses computed');

echo "\n── 2) extractFBMentionsAnalysis ───────────────\n";
$m = extractFBMentionsAnalysis($posts);
fbAssert($m['total_mentions'] >= 2,                                              'mentions >= 2');
fbAssert($m['total_tagged']   >= 1,                                              'tagged pages >= 1');
fbAssert(count($m['top_mentions']) > 0,                                          'top_mentions has entries');

echo "\n── 3) calcFBContentDistribution ───────────────\n";
$cd = calcFBContentDistribution($posts);
fbAssert(isset($cd['percent']['reel'])    && $cd['percent']['reel']    > 0,      'reel pct > 0');
fbAssert(isset($cd['percent']['album'])   && $cd['percent']['album']   > 0,      'album pct > 0');
fbAssert(isset($cd['percent']['video'])   && $cd['percent']['video']   > 0,      'video pct > 0');
fbAssert(isset($cd['percent']['photo'])   && $cd['percent']['photo']   > 0,      'photo pct > 0');
fbAssert($cd['avg_album_photos'] > 0,                                            'avg_album_photos > 0');

echo "\n── 4) calcFBPostingHeatmap ────────────────────\n";
$hm = calcFBPostingHeatmap($posts);
fbAssert(isset($hm['grid_engagement']['Sun']),                                   'grid_engagement has Sun row');
fbAssert($hm['best_hour'] !== null,                                              'best_hour computed');
fbAssert($hm['best_day']  !== null,                                              'best_day computed');
fbAssert(count($hm['grid_engagement']) === 7,                                    '7 days in grid');
fbAssert(count($hm['grid_engagement']['Sun']) === 24,                            '24 hours per day');

echo "\n── 5) analyzeFBPageOptimization (full) ────────\n";
$page = [
    'about'         => 'أفضل وكيل معتمد رقم 1 في الرياض. اطلب الآن عبر الواتساب أو زر موقعنا.',
    'category'      => 'Restaurant',
    'website'       => 'https://example.com',
    'phone'         => '+966500000000',
    'whatsapp'      => '+966500000000',
    'email'         => 'info@example.com',
    'address'       => 'الرياض',
    'cover_photo'   => 'cover.jpg',
    'profile_pic'   => 'pp.jpg',
    'is_verified'   => true,
    'rating'        => 4.7,
    'opening_hours' => ['monday' => '09:00-22:00'],
    'services'      => [['name' => 'حلاقة'], ['name' => 'كوافير'], ['name' => 'تجميل']],
];
$opt = analyzeFBPageOptimization($page);
fbAssert($opt['score'] >= 80,                                                    'optimal page: score >= 80 (got ' . $opt['score'] . ')');
fbAssert($opt['grade'] === 'A',                                                  'grade A');
fbAssert($opt['has_phone'] && $opt['has_whatsapp'] && $opt['has_email'],         'contact channels detected');
fbAssert($opt['has_cta'] === true,                                               'CTA detected in about');

echo "\n── 5b) analyzeFBPageOptimization (empty page) ─\n";
$empty = analyzeFBPageOptimization([]);
fbAssert($empty['score'] === 0,                                                  'empty page → score 0');
fbAssert($empty['grade'] === 'F',                                                'grade F');
fbAssert(!empty($empty['issues']),                                               'issues populated');

echo "\n── 6) calcFBPageHealthScore (healthy page) ────\n";
$h1 = calcFBPageHealthScore([
    'followers'       => 50000,
    'likes'           => 48000,
    'posts_count'     => 50,
    'engagement_rate' => 4.5,
    'is_verified'     => true,
    'has_reviews'     => true,
    'rating'          => 4.6,
    'reviews_count'   => 120,
    'has_shop'        => true,
    'website'         => 'https://example.com',
    'about_length'    => 180,
    'posts_per_week'  => 5,
    'last_post_days'  => 1,
    'ads_running'     => true,
    'content_distribution' => ['percent' => ['video' => 35, 'reel' => 10]],
]);
fbAssert($h1['score'] >= 85,                                                     'healthy page: score >= 85 (got ' . $h1['score'] . ')');
fbAssert($h1['grade'] === 'A',                                                   'grade A');

echo "\n── 6b) calcFBPageHealthScore (empty) ──────────\n";
$h2 = calcFBPageHealthScore([
    'followers'=>0,'likes'=>0,'posts_count'=>0,'engagement_rate'=>0,
    'is_verified'=>false,'has_reviews'=>false,'has_shop'=>false,
    'website'=>'','about_length'=>0,'posts_per_week'=>0,
    'last_post_days'=>null,'ads_running'=>false,
]);
fbAssert($h2['score'] < 30,                                                      'empty page: low score (got ' . $h2['score'] . ')');
fbAssert(!empty($h2['issues']),                                                  'issues populated');

echo "\n── 7) detectFBPostsLanguageMix ────────────────\n";
$lm = detectFBPostsLanguageMix($posts);
fbAssert($lm['arabic_pct'] > 0,                                                  'arabic_pct > 0');
fbAssert($lm['english_pct'] > 0,                                                 'english_pct > 0');
fbAssert(in_array($lm['dominant'], ['arabic','english','mixed']),                'dominant valid');

echo "\n── 8) extractFBLocations ──────────────────────\n";
$loc = extractFBLocations($posts);
fbAssert($loc['unique_locations'] >= 1,                                          'has location entries');
fbAssert($loc['top'][0]['name'] === 'الرياض',                                    'top location is Riyadh');

echo "\n── 9) calcFBSponsoredRatio ────────────────────\n";
$sp = calcFBSponsoredRatio($posts);
fbAssert($sp['sponsored_count'] === 1,                                           'sponsored_count = 1');
fbAssert($sp['percent'] > 0,                                                     'sponsored pct > 0');

echo "\n── 10) Heuristic sentiment via _fbHeuristicSentiment ─\n";
$comments = [
    ['text' => 'رائع جداً! منتج ممتاز شكراً'],
    ['text' => 'كم سعر هذا المنتج؟'],
    ['text' => 'سيء جداً غالي بشكل مزعج'],
    ['text' => 'متى يصل التوصيل؟'],
    ['text' => 'love it! great product'],
    ['text' => 'تعليق محايد بدون رأي واضح'],
];
$hs = _fbHeuristicSentiment($comments);
fbAssert($hs['positive_pct'] > 0,                                                'positive_pct > 0');
fbAssert($hs['negative_pct'] > 0,                                                'negative_pct > 0');
fbAssert($hs['questions_pct'] > 0,                                               'questions_pct > 0');

echo "\n── 11) Edge cases ─────────────────────────────\n";
fbAssert(extractFBHashtagsFromPosts([])['unique_count'] === 0,                   'empty posts → hashtags 0');
fbAssert(calcFBContentDistribution([])['avg_album_photos'] === 0,                'empty posts → avg_album_photos 0');
fbAssert(calcFBSponsoredRatio([])['percent'] === 0,                              'empty posts → sponsored pct 0');
fbAssert(extractFBLocations([])['unique_locations'] === 0,                       'empty posts → locations 0');
fbAssert(detectFBPostsLanguageMix([])['arabic_pct'] === 0.0,                     'empty posts → lang mix 0');

echo "\n══════════════════════════════════════════════════\n";
echo "  النتيجة: نجح {$pass} / فشل {$fail}\n";
echo "══════════════════════════════════════════════════\n";
if ($fail > 0) {
    echo "\nالأخطاء: \n - " . implode("\n - ", $errors) . "\n";
    exit(1);
}
echo "✅ كل اختبارات طبقة Facebook العميقة نجحت\n";
