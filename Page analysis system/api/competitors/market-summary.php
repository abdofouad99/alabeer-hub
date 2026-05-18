<?php
/**
 * Market Summary — STEP 6
 * تحليل شامل لكل السوق (العميل + 5 منافسين)
 */

declare(strict_types=1);

require_once __DIR__ . '/../logger.php';

/**
 * @param array $competitors المنافسون بعد analyzeCompetitorDeep
 * @param array $clientData بيانات العميل
 * @return array {
 *   client_rank, client_position,
 *   market_averages, market_leader,
 *   blue_ocean_opportunities,
 *   biggest_threat, top_3_actions
 * }
 */
function buildMarketSummary(array $competitors, array $clientData, array $cfg): array {
    // ── 1. حساب متوسطات السوق ──
    $marketAverages = _calculateMarketAverages($competitors, $clientData);

    // ── 2. ترتيب العميل ──
    $ranking = _rankClientInMarket($competitors, $clientData);

    // ── 3. اكتشاف Blue Ocean (فرص لم يستغلها أحد) ──
    $blueOcean = _identifyBlueOceanOpportunities($competitors, $clientData);

    // ── 4. أكبر تهديد ──
    $biggestThreat = _identifyBiggestThreat($competitors);

    // ── 5. أهم 3 إجراءات (AI-generated) ──
    $top3Actions = _generateTop3Actions($competitors, $clientData, $marketAverages, $cfg);

    return [
        'client_rank'              => $ranking['rank'],
        'client_position'          => $ranking['position'], // "1st" | "2nd" | etc
        'total_competitors'        => count($competitors) + 1, // +1 للعميل
        'client_score_breakdown'   => $ranking['score_breakdown'],
        'market_averages'          => $marketAverages,
        'market_leader'            => $ranking['leader'],
        'blue_ocean_opportunities' => $blueOcean,
        'biggest_threat'           => $biggestThreat,
        'top_3_actions'            => $top3Actions,
        'generated_at'             => date('c'),
    ];
}

function _calculateMarketAverages(array $competitors, array $clientData): array {
    $allFollowers = [];
    $allEngagement = [];
    $allPostsPerWeek = [];
    $allRatings = [];

    // العميل
    foreach (['facebook','instagram','tiktok','twitter'] as $p) {
        if (!empty($clientData[$p]['followers']))       $allFollowers[]      = (int)$clientData[$p]['followers'];
        if (!empty($clientData[$p]['engagement_rate'])) $allEngagement[]     = (float)$clientData[$p]['engagement_rate'];
        if (!empty($clientData[$p]['posts_per_week']))  $allPostsPerWeek[]   = (float)$clientData[$p]['posts_per_week'];
    }

    // المنافسون
    foreach ($competitors as $comp) {
        if (!empty($comp['rating'])) $allRatings[] = (float)$comp['rating'];

        foreach (['facebook','instagram','tiktok','twitter'] as $p) {
            if (!empty($comp['platforms'][$p]['followers']))       $allFollowers[]      = (int)$comp['platforms'][$p]['followers'];
            if (!empty($comp['platforms'][$p]['engagement_rate'])) $allEngagement[]     = (float)$comp['platforms'][$p]['engagement_rate'];
            if (!empty($comp['platforms'][$p]['posts_per_week']))  $allPostsPerWeek[]   = (float)$comp['platforms'][$p]['posts_per_week'];
        }
    }

    return [
        'avg_followers'      => !empty($allFollowers) ? (int)round(array_sum($allFollowers) / count($allFollowers)) : null,
        'median_followers'   => !empty($allFollowers) ? _median($allFollowers) : null,
        'avg_engagement'     => !empty($allEngagement) ? round(array_sum($allEngagement) / count($allEngagement), 2) : null,
        'avg_posts_per_week' => !empty($allPostsPerWeek) ? round(array_sum($allPostsPerWeek) / count($allPostsPerWeek), 1) : null,
        'avg_rating'         => !empty($allRatings) ? round(array_sum($allRatings) / count($allRatings), 2) : null,
    ];
}

function _median(array $arr): float {
    sort($arr);
    $n = count($arr);
    if ($n === 0) return 0;
    if ($n % 2 === 1) return $arr[(int)($n / 2)];
    return ($arr[$n / 2 - 1] + $arr[$n / 2]) / 2;
}

function _rankClientInMarket(array $competitors, array $clientData): array {
    // حساب score للعميل
    $clientScore = _calculateAccountScore($clientData, true);

    // حساب scores للمنافسين
    $allScores = [['name' => 'CLIENT', 'score' => $clientScore['total'], 'is_client' => true]];
    foreach ($competitors as $idx => $comp) {
        $score = _calculateAccountScore($comp, false);
        $allScores[] = [
            'name'      => $comp['name'],
            'score'     => $score['total'],
            'is_client' => false,
            'idx'       => $idx,
        ];
    }

    // ترتيب
    usort($allScores, fn($a, $b) => $b['score'] <=> $a['score']);

    $rank = 1;
    foreach ($allScores as $item) {
        if (!empty($item['is_client'])) break;
        $rank++;
    }

    $positions = ['1st' => 'الأول', '2nd' => 'الثاني', '3rd' => 'الثالث', '4th' => 'الرابع', '5th' => 'الخامس', '6th' => 'الأخير'];
    $positionKey = match($rank) {
        1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', 5 => '5th', default => '6th'
    };

    return [
        'rank'            => $rank,
        'position'        => $positions[$positionKey] ?? "#{$rank}",
        'total'           => count($allScores),
        'leader'          => $allScores[0]['name'],
        'leader_score'    => $allScores[0]['score'],
        'client_score'    => $clientScore['total'],
        'score_breakdown' => $clientScore['breakdown'],
    ];
}

/**
 * scoring مبسط للحساب (للترتيب فقط)
 */
function _calculateAccountScore($entity, bool $isClient): array {
    $breakdown = [
        'followers' => 0,
        'engagement' => 0,
        'activity' => 0,
        'rating' => 0,
        'website' => 0,
        'ads' => 0,
    ];

    $platforms = $isClient ? $entity : ($entity['platforms'] ?? []);

    // followers (أعلى منصة)
    $maxFollowers = 0;
    foreach (['facebook','instagram','tiktok','twitter'] as $p) {
        $f = (int)($platforms[$p]['followers'] ?? 0);
        if ($f > $maxFollowers) $maxFollowers = $f;
    }
    if ($maxFollowers >= 100000)     $breakdown['followers'] = 25;
    elseif ($maxFollowers >= 50000)  $breakdown['followers'] = 20;
    elseif ($maxFollowers >= 10000)  $breakdown['followers'] = 15;
    elseif ($maxFollowers >= 1000)   $breakdown['followers'] = 10;
    elseif ($maxFollowers > 0)       $breakdown['followers'] = 5;

    // engagement (متوسط)
    $engagements = [];
    foreach (['facebook','instagram','tiktok','twitter'] as $p) {
        if (!empty($platforms[$p]['engagement_rate'])) {
            $engagements[] = (float)$platforms[$p]['engagement_rate'];
        }
    }
    $avgEng = !empty($engagements) ? array_sum($engagements) / count($engagements) : 0;
    if ($avgEng >= 5)        $breakdown['engagement'] = 25;
    elseif ($avgEng >= 3)    $breakdown['engagement'] = 20;
    elseif ($avgEng >= 1)    $breakdown['engagement'] = 15;
    elseif ($avgEng > 0)     $breakdown['engagement'] = 10;

    // posting activity
    $maxPostsPerWeek = 0;
    foreach (['facebook','instagram','tiktok','twitter'] as $p) {
        $ppw = (float)($platforms[$p]['posts_per_week'] ?? 0);
        if ($ppw > $maxPostsPerWeek) $maxPostsPerWeek = $ppw;
    }
    if ($maxPostsPerWeek >= 7)     $breakdown['activity'] = 15;
    elseif ($maxPostsPerWeek >= 3) $breakdown['activity'] = 10;
    elseif ($maxPostsPerWeek > 0)  $breakdown['activity'] = 5;

    // rating
    $rating = $isClient ? null : ($entity['rating'] ?? null);
    if ($rating >= 4.5)       $breakdown['rating'] = 15;
    elseif ($rating >= 4.0)   $breakdown['rating'] = 10;
    elseif ($rating >= 3.5)   $breakdown['rating'] = 5;

    // website quality
    $qa = $isClient ? ($entity['website_scan'] ?? null) : ($entity['quick_analysis'] ?? null);
    if (!empty($qa['has_ssl']))    $breakdown['website'] += 3;
    if (!empty($qa['has_pixel']))  $breakdown['website'] += 4;
    if (!empty($qa['has_ga']))     $breakdown['website'] += 3;
    if (!empty($qa['has_cta']))    $breakdown['website'] += 5;

    // ads
    $adsInfo = $isClient ? ($entity['ads_library'] ?? null) : ($entity['ads_info'] ?? null);
    if (!empty($adsInfo['is_running_ads'])) $breakdown['ads'] = 5;

    return [
        'total'     => array_sum($breakdown),
        'breakdown' => $breakdown,
    ];
}

function _identifyBlueOceanOpportunities(array $competitors, array $clientData): array {
    $opportunities = [];

    // 1. منصة لا أحد عليها
    $platformsUsed = ['facebook' => 0, 'instagram' => 0, 'tiktok' => 0, 'twitter' => 0];
    foreach ($competitors as $comp) {
        foreach ($platformsUsed as $p => &$count) {
            if (!empty($comp['platforms'][$p]['followers'])) $count++;
        }
    }

    foreach ($platformsUsed as $p => $count) {
        if ($count <= 1 && empty($clientData[$p]['followers'])) {
            $opportunities[] = [
                'type'        => 'platform_gap',
                'description' => "{$count} منافسين فقط على {$p} — فرصة للسيطرة المبكرة",
                'platform'    => $p,
            ];
        }
    }

    // 2. لا أحد يطلق إعلانات
    $adsRunningCount = 0;
    foreach ($competitors as $comp) {
        if (!empty($comp['ads_info']['is_running_ads'])) $adsRunningCount++;
    }
    if ($adsRunningCount === 0) {
        $opportunities[] = [
            'type'        => 'ads_gap',
            'description' => 'لا أحد من المنافسين يطلق إعلانات حالياً — فرصة لاحتكار المساحة الإعلانية',
        ];
    }

    // 3. متوسط التفاعل ضعيف
    $allEngagements = [];
    foreach ($competitors as $comp) {
        foreach ($comp['platforms'] ?? [] as $platformData) {
            if (!empty($platformData['engagement_rate'])) {
                $allEngagements[] = (float)$platformData['engagement_rate'];
            }
        }
    }
    if (!empty($allEngagements) && (array_sum($allEngagements) / count($allEngagements)) < 1.5) {
        $opportunities[] = [
            'type'        => 'engagement_gap',
            'description' => 'متوسط تفاعل المنافسين ضعيف (< 1.5%) — محتوى تفاعلي يمكن التميز به',
        ];
    }

    return $opportunities;
}

function _identifyBiggestThreat(array $competitors): ?array {
    $maxThreat = null;
    $maxScore  = 0;

    foreach ($competitors as $comp) {
        if (($comp['ai_analysis']['threat_level'] ?? '') === 'high') {
            // نختار الأعلى في data_completeness لو فيه أكثر من واحد
            $score = $comp['_meta']['data_completeness'] ?? 0;
            if ($score > $maxScore) {
                $maxScore = $score;
                $maxThreat = [
                    'name'         => $comp['name'],
                    'reason'       => 'تهديد عالي حسب AI',
                    'attack_plan'  => $comp['ai_analysis']['attack_plan'] ?? [],
                ];
            }
        }
    }

    return $maxThreat;
}

function _generateTop3Actions(array $competitors, array $clientData, array $marketAvg, array $cfg): array {
    // اعتمد على بيانات بسيطة — يمكن استخدام AI لاحقاً
    $actions = [];

    // 1. لو متوسط متابعي السوق أعلى من العميل
    $clientMaxFollowers = 0;
    foreach (['facebook','instagram','tiktok','twitter'] as $p) {
        $f = (int)($clientData[$p]['followers'] ?? 0);
        if ($f > $clientMaxFollowers) $clientMaxFollowers = $f;
    }
    $avgFollowers = $marketAvg['avg_followers'] ?? 0;
    if ($avgFollowers > 0 && $clientMaxFollowers < $avgFollowers * 0.7) {
        $actions[] = [
            'priority'    => 'high',
            'action'      => 'زيادة المتابعين لمستوى السوق',
            'description' => "متابعوك ({$clientMaxFollowers}) أقل من متوسط السوق ({$avgFollowers}). الهدف: زيادة 30% خلال 60 يوم.",
            'kpi'         => "متوسط السوق: {$avgFollowers}",
        ];
    }

    // 2. تفاعل
    $clientEng = 0;
    foreach (['facebook','instagram','tiktok','twitter'] as $p) {
        $e = (float)($clientData[$p]['engagement_rate'] ?? 0);
        if ($e > $clientEng) $clientEng = $e;
    }
    $avgEng = $marketAvg['avg_engagement'] ?? 0;
    if ($avgEng > 0 && $clientEng < $avgEng) {
        $actions[] = [
            'priority'    => 'medium',
            'action'      => 'رفع معدل التفاعل',
            'description' => "تفاعلك ({$clientEng}%) أقل من متوسط السوق ({$avgEng}%). جرب: محتوى تفاعلي، أسئلة، استطلاعات.",
            'kpi'         => "الهدف: {$avgEng}% خلال 30 يوم",
        ];
    }

    // 3. لو لا أحد يطلق إعلانات → أنت ابدأ
    $adsCount = 0;
    foreach ($competitors as $comp) {
        if (!empty($comp['ads_info']['is_running_ads'])) $adsCount++;
    }
    if ($adsCount === 0) {
        $actions[] = [
            'priority'    => 'high',
            'action'      => 'احتكار المساحة الإعلانية',
            'description' => 'لا منافس يطلق إعلانات حالياً. ابدأ حملة Awareness بـ 50 ريال/يوم لمدة 14 يوم لاحتكار الـ ad placements.',
            'kpi'         => 'هدف: 100K Reach خلال أسبوعين',
        ];
    }

    return array_slice($actions, 0, 3);
}
