<?php
// ============================================================
// instagram-deep.php — الطبقة العميقة لفحص Instagram
// ------------------------------------------------------------
// يحتوي على:
//   1) دوال التحليل المتقدمة (Hashtags / Mentions / Heatmap /
//      Content Distribution / Bio Optimization / Account Health)
//   2) دالة سحب التعليقات + تحليل المشاعر (heuristic + OpenAI)
//   3) دالة Vision AI (gpt-4o-mini) للصور
//   4) دالة Stories + Highlights (Actor مخصص اختياري)
//
// كل الدوال محمية بـ function_exists() لتجنّب الـ redeclare لو
// أُدرج الملف مرتين.
// ============================================================

if (!function_exists('logInfo')) {
    require_once __DIR__ . '/logger.php';
}
require_once __DIR__ . '/diagnostics.php';

// ============================================================
// 1) Hashtags + Mentions Extraction
// ============================================================
if (!function_exists('extractHashtagsFromPosts')) {
function extractHashtagsFromPosts(array $posts): array {
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
        // (ب) regex على نص الكابشن
        $text = $p['caption'] ?? $p['text'] ?? '';
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

if (!function_exists('extractMentionsFromPosts')) {
function extractMentionsFromPosts(array $posts): array {
    $bag = [];
    $tagged = [];
    foreach ($posts as $p) {
        // (أ) mentions المباشرة
        if (!empty($p['mentions']) && is_array($p['mentions'])) {
            foreach ($p['mentions'] as $u) {
                if (!is_string($u)) continue;
                $clean = mb_strtolower(ltrim(trim($u), '@'));
                if ($clean !== '') $bag[$clean] = ($bag[$clean] ?? 0) + 1;
            }
        }
        // (ب) من نص الكابشن
        $text = $p['caption'] ?? $p['text'] ?? '';
        if (is_string($text) && $text !== '') {
            if (preg_match_all('/@([A-Za-z0-9_\.]{2,30})/', $text, $m)) {
                foreach ($m[1] as $u) {
                    $clean = mb_strtolower($u);
                    $bag[$clean] = ($bag[$clean] ?? 0) + 1;
                }
            }
        }
        // (ج) Tagged Users داخل الصورة (Apify)
        if (!empty($p['taggedUsers']) && is_array($p['taggedUsers'])) {
            foreach ($p['taggedUsers'] as $tu) {
                $u = is_array($tu) ? ($tu['username'] ?? $tu['user']['username'] ?? '') : (is_string($tu) ? $tu : '');
                $clean = mb_strtolower(ltrim(trim($u), '@'));
                if ($clean !== '') $tagged[$clean] = ($tagged[$clean] ?? 0) + 1;
            }
        }
    }
    arsort($bag);
    arsort($tagged);
    $topMentions = [];
    foreach (array_slice($bag, 0, 15, true) as $u => $c) $topMentions[] = ['user' => $u, 'count' => $c];
    $topTagged = [];
    foreach (array_slice($tagged, 0, 15, true) as $u => $c) $topTagged[] = ['user' => $u, 'count' => $c];
    return [
        'unique_mentions' => count($bag),
        'total_mentions'  => array_sum($bag),
        'top_mentions'    => $topMentions,
        'unique_tagged'   => count($tagged),
        'total_tagged'    => array_sum($tagged),
        'top_tagged'      => $topTagged,
    ];
}}

// ============================================================
// 2) Content Type Distribution
// ============================================================
if (!function_exists('calcContentTypeDistribution')) {
function calcContentTypeDistribution(array $posts): array {
    if (empty($posts)) return ['video' => 0, 'image' => 0, 'carousel' => 0, 'reel' => 0];
    $counts = ['video' => 0, 'image' => 0, 'carousel' => 0, 'reel' => 0];
    $slidesTotal = 0; $slidesCount = 0;
    foreach ($posts as $p) {
        $type = strtolower($p['type'] ?? $p['mediaType'] ?? $p['productType'] ?? '');
        if (str_contains($type, 'reel') || (!empty($p['isReel']))) {
            $counts['reel']++;
        } elseif (str_contains($type, 'video')) {
            $counts['video']++;
        } elseif (str_contains($type, 'sidecar') || str_contains($type, 'album') || str_contains($type, 'carousel')) {
            $counts['carousel']++;
            // متوسط عدد الشرائح في الكاروسيل
            if (!empty($p['images']) && is_array($p['images'])) {
                $slidesTotal += count($p['images']); $slidesCount++;
            } elseif (!empty($p['childPosts']) && is_array($p['childPosts'])) {
                $slidesTotal += count($p['childPosts']); $slidesCount++;
            }
        } else {
            $counts['image']++;
        }
    }
    $total = max(array_sum($counts), 1);
    $pct = [];
    foreach ($counts as $k => $v) $pct[$k] = round(($v / $total) * 100, 1);
    return [
        'counts'         => $counts,
        'percent'        => $pct,
        'avg_carousel_slides' => $slidesCount > 0 ? round($slidesTotal / $slidesCount, 1) : 0,
    ];
}}

// ============================================================
// 3) Posting Heatmap (Day x Hour)
// ============================================================
if (!function_exists('calcPostingHeatmap')) {
function calcPostingHeatmap(array $posts, string $tz = 'Asia/Riyadh'): array {
    $days  = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    $grid  = []; // grid[day][hour] = engagement
    $count = []; // عدد المنشورات لكل (يوم,ساعة)
    foreach ($days as $d) {
        $grid[$d]  = array_fill(0, 24, 0);
        $count[$d] = array_fill(0, 24, 0);
    }
    $hourTotal = array_fill(0, 24, ['posts'=>0,'eng'=>0]);
    $dayTotal  = []; foreach ($days as $d) $dayTotal[$d] = ['posts'=>0,'eng'=>0];
    try { $tzObj = new DateTimeZone($tz); } catch (\Throwable $e) { $tzObj = new DateTimeZone('UTC'); }

    foreach ($posts as $p) {
        $ts = $p['timestamp'] ?? $p['takenAt'] ?? $p['takenAtTimestamp'] ?? null;
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
        $likes    = (int)($p['likesCount']    ?? $p['likes']    ?? 0);
        $comments = (int)($p['commentsCount'] ?? $p['comments'] ?? 0);
        $eng = $likes + $comments;

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
        'timezone'      => $tz,
        'grid_engagement' => $grid,    // مجموع التفاعل
        'grid_count'      => $count,   // عدد المنشورات
        'best_hour'     => $bestHour,
        'best_hour_avg_engagement' => $bestHour !== null ? round($bestHourAvg, 1) : null,
        'best_day'      => $bestDay,
        'best_day_avg_engagement'  => $bestDay  !== null ? round($bestDayAvg, 1)  : null,
        'hour_totals'   => $hourTotal,
        'day_totals'    => $dayTotal,
    ];
}}

// ============================================================
// 4) Bio Optimization Analyzer (0-100)
// ============================================================
if (!function_exists('analyzeBioOptimization')) {
function analyzeBioOptimization(string $bio, ?string $externalUrl = '', bool $isBusiness = false, ?string $category = ''): array {
    $bio = (string)$bio;
    $score = 0; $issues = []; $strengths = [];

    $len = mb_strlen($bio);
    // 1) طول البايو (15 نقطة) — المثالي 80-150
    if ($len === 0) { $issues[] = 'البايو فارغ'; }
    elseif ($len < 30)  { $score += 5;  $issues[]  = 'البايو قصير جداً (<30 حرف)'; }
    elseif ($len < 80)  { $score += 10; $strengths[] = 'طول مقبول'; }
    elseif ($len <= 150){ $score += 15; $strengths[] = 'طول مثالي للبايو'; }
    else                 { $score += 8;  $issues[]  = 'البايو طويل جداً (>150)'; }

    // 2) رابط خارجي (20)
    if (!empty($externalUrl)) { $score += 20; $strengths[] = 'يوجد رابط في البايو'; }
    else $issues[] = 'لا يوجد رابط خارجي في البايو';

    // 3) CTA واضح (15)
    $ctaWords = ['اضغط','احجز','اطلب','اتصل','تواصل','رابط','بايو','زور','تابعنا','اشترك','سجّل','سجل','احصل','اطلب الآن','order','book','call','whatsapp','dm','link','click','shop'];
    $hasCta = false;
    foreach ($ctaWords as $w) { if (mb_stripos($bio, $w) !== false) { $hasCta = true; break; } }
    if ($hasCta) { $score += 15; $strengths[] = 'يحتوي CTA واضح'; }
    else $issues[] = 'لا يوجد CTA (دعوة لإجراء)';

    // 4) معلومات تواصل (15)
    $hasPhone = (bool)preg_match('/(?:\+?\d[\d\s\-]{6,})/', $bio);
    $hasEmail = (bool)preg_match('/[\w\.\-]+@[\w\.\-]+\.[A-Za-z]{2,}/', $bio);
    $hasWa    = (bool)preg_match('/wa\.me|whatsapp|واتساب|واتس/i', $bio);
    $contactPts = ($hasPhone ? 5 : 0) + ($hasEmail ? 5 : 0) + ($hasWa ? 5 : 0);
    $score += $contactPts;
    if ($contactPts > 0) $strengths[] = 'يوجد تواصل: ' . implode(', ', array_filter([$hasPhone?'هاتف':null,$hasEmail?'إيميل':null,$hasWa?'واتساب':null]));
    else $issues[] = 'لا يحتوي وسيلة تواصل';

    // 5) إيموجي (5)
    $hasEmoji = (bool)preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $bio);
    if ($hasEmoji) { $score += 5; $strengths[] = 'يستخدم إيموجي'; }
    else $issues[] = 'بدون إيموجي (أقل جاذبية)';

    // 6) سطر منظم (10)
    $lines = preg_split('/\r\n|\n|\r/', $bio);
    $lineCount = count(array_filter($lines, fn($l) => trim($l) !== ''));
    if ($lineCount >= 2 && $lineCount <= 5) { $score += 10; $strengths[] = 'منظم على عدة أسطر'; }
    elseif ($lineCount === 1 && $len > 50) $issues[] = 'سطر واحد طويل (يفضل تقسيمه)';

    // 7) ذكر القيمة المضافة (10) - كلمات تعريفية بسيطة
    $valueWords = ['أفضل','أول','رقم','جودة','سرعة','توصيل','أصلي','معتمد','رسمي','best','official','original','authentic','licensed'];
    $hasValue = false;
    foreach ($valueWords as $w) { if (mb_stripos($bio, $w) !== false) { $hasValue = true; break; } }
    if ($hasValue) { $score += 10; $strengths[] = 'يوضح القيمة المضافة'; }

    // 8) وضوح النشاط (10)
    if (!empty($category)) { $score += 10; $strengths[] = "تصنيف واضح: $category"; }
    elseif ($isBusiness) { $score += 5; }

    $score = min(100, $score);
    $grade = $score >= 80 ? 'A' : ($score >= 65 ? 'B' : ($score >= 50 ? 'C' : ($score >= 35 ? 'D' : 'F')));

    return [
        'score'      => $score,
        'grade'      => $grade,
        'length'     => $len,
        'has_link'   => !empty($externalUrl),
        'has_cta'    => $hasCta,
        'has_phone'  => $hasPhone,
        'has_email'  => $hasEmail,
        'has_whatsapp'=> $hasWa,
        'has_emoji'  => $hasEmoji,
        'line_count' => $lineCount,
        'strengths'  => $strengths,
        'issues'     => $issues,
    ];
}}

// ============================================================
// 5) Account Health Score (0-100)
// ============================================================
if (!function_exists('calcAccountHealthScore')) {
function calcAccountHealthScore(array $data): array {
    $score = 0; $issues = []; $strengths = [];
    $followers   = (int)($data['followers'] ?? 0);
    $following   = (int)($data['following'] ?? 0);
    $posts       = (int)($data['posts_count'] ?? 0);
    $eng         = (float)($data['engagement_rate'] ?? 0);
    $verified    = (bool)($data['is_verified'] ?? false);
    $business    = (bool)($data['is_business'] ?? false);
    $isPrivate   = (bool)($data['private'] ?? false);
    $hasReels    = (bool)($data['has_reels'] ?? false);
    $hasWebsite  = !empty($data['website'] ?? '');
    $bioLen      = (int)($data['bio_length'] ?? 0);
    $postsPerWeek= (float)($data['posts_per_week'] ?? 0);
    $lastPostDays= $data['last_post_days'] ?? null;
    $highlights  = (int)($data['highlight_reel_count'] ?? $data['highlights'] ?? 0);

    // 1) Activity (20) — انتظام النشر
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

    // 3) Engagement Rate (25)
    if ($eng >= 6)      { $score += 25; $strengths[] = 'تفاعل ممتاز (>=6%)'; }
    elseif ($eng >= 3)  { $score += 20; $strengths[] = 'تفاعل جيد'; }
    elseif ($eng >= 1)  { $score += 12; }
    elseif ($eng > 0)   { $score += 5;  $issues[] = 'تفاعل منخفض (<1%)'; }
    else                  $issues[] = 'لا يوجد تفاعل ملموس';

    // 4) Followers/Following ratio (10)
    if ($followers > 0 && $following > 0) {
        $ratio = $followers / $following;
        if ($ratio >= 5)      { $score += 10; $strengths[] = 'نسبة متابعين/متابَعين ممتازة'; }
        elseif ($ratio >= 1.5){ $score += 7; }
        elseif ($ratio >= 0.5){ $score += 3; }
        else                  $issues[] = 'يتابع أكثر مما يُتابَع';
    }

    // 5) Bio + Link (10)
    if ($bioLen >= 50)   { $score += 5; }
    if ($hasWebsite)     { $score += 5; $strengths[] = 'يوجد رابط خارجي'; }
    else                   $issues[] = 'لا يوجد رابط خارجي';

    // 6) Trust signals (10)
    if ($verified)       { $score += 5; $strengths[] = 'حساب موثق'; }
    if ($business)       { $score += 3; $strengths[] = 'حساب أعمال'; }
    if (!$isPrivate)     { $score += 2; }
    else                   $issues[] = 'حساب خاص (Private) — لا يمكن الفحص العميق';

    // 7) Content variety (10)
    if ($hasReels)       { $score += 5; $strengths[] = 'يستخدم Reels'; }
    else                   $issues[] = 'لا يستخدم Reels (محتوى مرئي قصير)';
    if ($highlights >= 3) { $score += 5; $strengths[] = "يستخدم Highlights ($highlights)"; }
    elseif ($highlights > 0) { $score += 2; }
    else                   $issues[] = 'لا يستخدم Highlights';

    // 8) Reach (5) — حجم الجمهور
    if ($followers >= 10000)       $score += 5;
    elseif ($followers >= 1000)    $score += 3;
    elseif ($followers >= 100)     $score += 1;

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
// 6) Languages Distribution (Arabic / English / Mixed)
// ============================================================
if (!function_exists('detectPostsLanguageMix')) {
function detectPostsLanguageMix(array $posts): array {
    $ar = 0; $en = 0; $mix = 0; $other = 0; $empty = 0;
    foreach ($posts as $p) {
        $t = $p['caption'] ?? $p['text'] ?? '';
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
// 7) Locations distribution
// ============================================================
if (!function_exists('extractTopLocations')) {
function extractTopLocations(array $posts): array {
    $bag = [];
    foreach ($posts as $p) {
        $loc = $p['locationName'] ?? $p['location']['name'] ?? null;
        if (!is_string($loc) || trim($loc) === '') continue;
        $bag[$loc] = ($bag[$loc] ?? 0) + 1;
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
// 8) Sponsored / Partnership ratio
// ============================================================
if (!function_exists('calcSponsoredRatio')) {
function calcSponsoredRatio(array $posts): array {
    if (empty($posts)) return ['sponsored_count' => 0, 'percent' => 0];
    $count = 0;
    foreach ($posts as $p) {
        if (!empty($p['isSponsored']) || !empty($p['is_paid_partnership']) || !empty($p['paid_partnership'])) $count++;
    }
    return [
        'sponsored_count' => $count,
        'percent'         => round(($count / count($posts)) * 100, 1),
    ];
}}

// ============================================================
// 9) Reels Performance (avg watch metrics)
// ============================================================
if (!function_exists('analyzeReelsPerformance')) {
function analyzeReelsPerformance(array $posts): array {
    $reels = [];
    foreach ($posts as $p) {
        $type = strtolower($p['type'] ?? $p['mediaType'] ?? '');
        if (str_contains($type, 'video') || str_contains($type, 'reel') || !empty($p['videoUrl']) || !empty($p['videoPlayCount'])) {
            $reels[] = $p;
        }
    }
    if (empty($reels)) return ['count' => 0];
    $totalPlays = 0; $totalViews = 0; $totalLikes = 0; $totalComments = 0; $totalDur = 0; $durCount = 0;
    foreach ($reels as $r) {
        $totalPlays    += (int)($r['videoPlayCount'] ?? 0);
        $totalViews    += (int)($r['videoViewCount'] ?? 0);
        $totalLikes    += (int)($r['likesCount']     ?? 0);
        $totalComments += (int)($r['commentsCount']  ?? 0);
        $dur = (float)($r['videoDuration'] ?? 0);
        if ($dur > 0) { $totalDur += $dur; $durCount++; }
    }
    $cnt = count($reels);
    return [
        'count'         => $cnt,
        'avg_plays'     => round($totalPlays / $cnt),
        'avg_views'     => round($totalViews / $cnt),
        'avg_likes'     => round($totalLikes / $cnt, 1),
        'avg_comments'  => round($totalComments / $cnt, 1),
        'avg_duration_sec' => $durCount > 0 ? round($totalDur / $durCount, 1) : null,
        // نسبة الإحتفاظ التقريبية (likes/plays)
        'engagement_per_play' => $totalPlays > 0 ? round((($totalLikes + $totalComments) / $totalPlays) * 100, 2) : null,
    ];
}}

// ============================================================
// 10) Sentiment Analysis (heuristic + optional OpenAI)
// ============================================================
if (!function_exists('analyzeIGCommentsSentiment')) {
/**
 * يجمع تعليقات أفضل N منشورات عبر Apify Comments Actor
 * ثم يحلل المشاعر: heuristic أولاً + OpenAI (اختياري) للدقة.
 *
 * @param array  $topPosts قائمة منشورات (يجب أن تحتوي url/postUrl)
 * @param string $token    Apify token صالح
 * @param array  $cfg      config كامل
 * @return array {
 *   total_comments, positive_pct, negative_pct, neutral_pct, questions_pct,
 *   top_objections, top_praise, top_questions, samples, response_rate
 * }
 */
function analyzeIGCommentsSentiment(array $topPosts, string $token, array $cfg): array {
    $maxPosts = (int)($cfg['analysis']['ig_comments_top_posts'] ?? 5);
    $actorId  = $cfg['apis']['apify_actor_ig_comments'] ?? 'SbK00X0JYCPblD2wp';
    $useAi    = !empty($cfg['analysis']['enable_ig_sentiment_ai']);

    $allComments = [];
    $repliesByOwner = 0; $totalComments = 0;
    $picked = 0;

    foreach ($topPosts as $p) {
        if ($picked >= $maxPosts) break;
        $url = $p['url'] ?? $p['postUrl'] ?? null;
        if (!$url) continue;

        $input = json_encode(['directUrls' => [$url], 'resultsLimit' => 30]);
        $runId = _apifyStartRun($actorId, $input, $token);
        if (!$runId) continue;
        $items = _apifyWaitAndFetch($runId, $token, 90);
        if (!is_array($items) || empty($items)) continue;
        $picked++;

        $ownerUsername = $p['ownerUsername'] ?? $p['owner']['username'] ?? '';

        foreach ($items as $c) {
            $text = trim((string)($c['text'] ?? $c['comment'] ?? $c['message'] ?? ''));
            if ($text === '') continue;
            $u = $c['ownerUsername'] ?? $c['owner']['username'] ?? '';
            $allComments[] = [
                'text'       => $text,
                'owner'      => $u,
                'likes'      => (int)($c['likesCount'] ?? 0),
                'timestamp'  => $c['timestamp'] ?? null,
                'post_url'   => $url,
                'is_owner'   => $ownerUsername && strcasecmp($u, $ownerUsername) === 0,
            ];
            $totalComments++;
            if ($ownerUsername && strcasecmp($u, $ownerUsername) === 0) $repliesByOwner++;
        }
    }

    if (empty($allComments)) {
        return [
            'success'        => false,
            'reason'         => 'لم تُسحب أي تعليقات',
            'total_comments' => 0,
        ];
    }

    // (1) heuristic — يعطي تصنيفاً مبدئياً سريعاً
    $heur = _heuristicSentiment($allComments);

    // (2) OpenAI overlay (اختياري) — يصحح التصنيف
    $aiSummary = null;
    if ($useAi && !empty($cfg['apis']['openai_key']) && count($allComments) >= 5) {
        $aiSummary = _openaiSentimentSummary($allComments, $cfg);
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
        'response_rate'   => $totalComments > 0 ? round(($repliesByOwner / $totalComments) * 100, 1) : 0,
        'ai_summary'      => $aiSummary, // ['overall','main_objections','main_praise','recommendations']
    ];
}}

if (!function_exists('_heuristicSentiment')) {
function _heuristicSentiment(array $comments): array {
    // SENT-1 FIX: أزلنا "لا" — كلمة عامة جداً تسبب false positives
    $negWords = ['غالي','مكلف','سيء','وحش','رديء','ضعيف','فاشل','تأخر','تعطل','مزعج','خايس','خداع','نصب','احتيال','bad','worst','expensive','scam','poor','rude','slow','terrible'];
    $posWords = ['رائع','ممتاز','جيد','حلو','عظيم','جميل','شكر','الله يعطيكم','يعطيك','مبدع','أفضل','ممتازة','كفو','great','love','excellent','amazing','perfect','awesome'];
    $qMarkers = ['?','؟','كيف','متى','كم','هل','وين','أين','الأسعار','السعر','price','how','when','where'];
    // SENT-1 FIX: كلمات استثناء سياقية
    $negExceptions = ['يستاهل','يستحق','بس','لكن الجودة','worth','ولكن'];

    $pos=0; $neg=0; $q=0; $neu=0;
    $obj=[]; $praise=[]; $questions=[]; $samples=[];
    foreach ($comments as $c) {
        $t = $c['text'];
        $samples[] = mb_substr($t, 0, 100);
        $isQ=false;$isN=false;$isP=false;
        foreach ($qMarkers as $w) if (mb_stripos($t,$w)!==false) { $isQ=true; break; }
        foreach ($negWords as $w) {
            if (mb_stripos($t,$w)!==false) {
                // SENT-1 FIX: فحص سياقي
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

if (!function_exists('_openaiSentimentSummary')) {
function _openaiSentimentSummary(array $comments, array $cfg): ?array {
    $key   = $cfg['apis']['openai_key'] ?? '';
    $model = $cfg['apis']['openai_model'] ?? 'gpt-4o-mini';
    if (!$key) return null;

    // نأخذ 30 تعليق كحد أقصى لتقليل التكلفة
    $sample = array_slice($comments, 0, 30);
    $list = '';
    foreach ($sample as $i => $c) {
        $t = preg_replace("/\s+/", ' ', $c['text']);
        $list .= ($i+1) . ". " . mb_substr($t, 0, 200) . "\n";
    }

    $prompt = "حلّل تعليقات Instagram التالية باللغة العربية وأجب JSON فقط بدون أي شرح إضافي.\n"
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
            ['role' => 'system', 'content' => 'You are a careful social media sentiment analyst for Arabic content. Reply ONLY with valid JSON.'],
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
if (!function_exists('analyzeIGImagesVision')) {
/**
 * يحلّل أفضل N صور (الأعلى تفاعلاً) عبر OpenAI Vision.
 * يُرجع: tags, ocr_text (نص داخل الصورة), brand_elements, product_focus.
 */
function analyzeIGImagesVision(array $topPosts, array $cfg): array {
    $key   = $cfg['apis']['openai_key'] ?? '';
    if (!$key) return ['success' => false, 'reason' => 'OPENAI_KEY غير مضبوط'];
    $model = $cfg['apis']['openai_model'] ?? 'gpt-4o-mini';
    $maxImg = (int)($cfg['analysis']['ig_vision_top_images'] ?? 5);

    $images = [];
    foreach ($topPosts as $p) {
        if (count($images) >= $maxImg) break;
        $u = $p['displayUrl'] ?? $p['imageUrl'] ?? $p['thumbnailUrl'] ?? $p['image'] ?? '';
        if (is_array($u)) $u = $u['uri'] ?? '';
        if (!is_string($u) || $u === '') continue;
        $images[] = ['url' => $u, 'caption' => mb_substr((string)($p['caption'] ?? ''), 0, 200)];
    }
    if (empty($images)) return ['success' => false, 'reason' => 'لا توجد صور صالحة'];

    $analyses = [];
    foreach ($images as $img) {
        $messages = [
            ['role' => 'system', 'content' => 'You analyze Instagram images for marketing audits. Answer in Arabic and ONLY JSON.'],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => "حلّل الصورة وأرجع JSON فقط:\n{\n  \"description\": \"وصف موجز\",\n  \"tags\": [\"...\"] ,\n  \"ocr_text\": \"النص الظاهر بالصورة (إن وجد)\",\n  \"language\": \"arabic|english|mixed|none\",\n  \"has_logo\": false,\n  \"has_price\": false,\n  \"has_offer\": false,\n  \"product_focus\": \"\",\n  \"image_quality\": \"low|medium|high\",\n  \"branding_consistency\": \"weak|ok|strong\"\n}\n\nالكابشن:" . ($img['caption'] ?: '(بدون كابشن)')],
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
        'success'         => true,
        'analyzed_count'  => count($analyses),
        'images'          => $analyses,
        'top_tags'        => array_slice(array_keys($tagBag), 0, 10),
        'logos_present'   => $hasLogo,
        'prices_present'  => $hasPrice,
        'offers_present'  => $hasOffer,
        'quality_distribution' => $qualityBag,
    ];
}}

// ============================================================
// 12) Stories + Highlights (اختياري — يحتاج Actor مخصص)
// ============================================================
if (!function_exists('scrapeIGStoriesAndHighlights')) {
function scrapeIGStoriesAndHighlights(string $username, string $token, array $cfg): array {
    $actorId = $cfg['apis']['apify_actor_ig_stories'] ?? 'apify/instagram-stories-scraper';
    $input = json_encode([
        'usernames' => [$username],
        'resultsLimit' => 50,
    ]);
    $runId = _apifyStartRun($actorId, $input, $token);
    if (!$runId) {
        Diag::snapshot('instagram.stories.scrape.result', [
            'username' => $username,
            'actor_id' => $actorId,
            'success'  => false,
            'reason'   => 'فشل تشغيل Stories actor',
        ]);
        return ['success' => false, 'reason' => 'فشل تشغيل Stories actor'];
    }
    $items = _apifyWaitAndFetch($runId, $token, 90);
    if (!is_array($items)) {
        Diag::snapshot('instagram.stories.scrape.result', [
            'username' => $username,
            'actor_id' => $actorId,
            'success'  => false,
            'reason'   => 'انتهت مهلة Stories actor',
        ]);
        return ['success' => false, 'reason' => 'انتهت مهلة Stories actor'];
    }

    $stories = []; $highlights = [];
    foreach ($items as $it) {
        // الـ schema يختلف حسب الـ actor؛ نقرأ بمرونة
        $isHL = !empty($it['highlight']) || !empty($it['highlightId']) || ($it['type'] ?? '') === 'highlight';
        $entry = [
            'id'         => $it['id'] ?? null,
            'media_type' => $it['mediaType'] ?? $it['type'] ?? '',
            'image'      => $it['displayUrl'] ?? $it['imageUrl'] ?? $it['thumbnail'] ?? '',
            'video'      => $it['videoUrl']   ?? '',
            'timestamp'  => $it['timestamp']  ?? $it['takenAt'] ?? null,
            'duration'   => $it['videoDuration'] ?? null,
        ];
        if ($isHL) {
            $entry['title'] = $it['title'] ?? $it['highlightTitle'] ?? '';
            $entry['cover'] = $it['coverUrl'] ?? '';
            $highlights[]   = $entry;
        } else {
            $stories[] = $entry;
        }
    }
    $igStoriesResult = [
        'success'          => true,
        'stories_count'    => count($stories),
        'highlights_count' => count($highlights),
        'stories'          => array_slice($stories, 0, 30),
        'highlights'       => array_slice($highlights, 0, 30),
    ];
    Diag::snapshot('instagram.stories.scrape.result', [
        'username'         => $username,
        'actor_id'         => $actorId,
        'success'          => true,
        'stories_count'    => $igStoriesResult['stories_count'],
        'highlights_count' => $igStoriesResult['highlights_count'],
        'raw_item_count'   => count($items),
    ]);
    return $igStoriesResult;
}}
