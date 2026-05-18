<?php
/**
 * Candidates Merger & Scorer — STEP 2
 * يدمج نتائج Discovery من 3 مصادر، يطبق scoring، يُرجع Top N
 */

declare(strict_types=1);

require_once __DIR__ . '/../logger.php';

/**
 * @param array $googlePlacesResults نتائج discoverViaGooglePlaces
 * @param array $googleSearchResults نتائج discoverViaGoogleSearch
 * @param array $fbPagesResults نتائج discoverViaFacebookPages
 * @param array $clientProfile من detectClientProfile
 * @return array {
 *   success: bool,
 *   total_candidates: int,
 *   top_competitors: array[],
 *   excluded: array[] {name, reason}
 * }
 */
function mergeAndRankCandidates(
    array $googlePlacesResults,
    array $googleSearchResults,
    array $fbPagesResults,
    array $clientProfile,
    array $cfg
): array {
    // ── 1. دمج كل النتائج في pool واحد ──
    $pool = array_merge(
        $googlePlacesResults['places'] ?? [],
        $googleSearchResults['places'] ?? [],
        $fbPagesResults['places']      ?? []
    );

    if (empty($pool)) {
        return [
            'success'         => false,
            'error'           => 'لا مرشحين من أي مصدر',
            'top_competitors' => [],
        ];
    }

    // ── 2. Deduplication حسب domain + name ──
    $deduplicated = _deduplicateCandidates($pool);

    // ── 3. Filter (المواقع المُستبعدة) ──
    $excluded = [];
    $filtered = _filterExcluded($deduplicated, $clientProfile, $excluded);

    // ── 4. Scoring لكل مرشح ──
    $scored = [];
    foreach ($filtered as $cand) {
        $cand['_validation_score'] = _scoreCandidate($cand, $clientProfile);
        $scored[] = $cand;
    }

    // ── 5. ترتيب تنازلي حسب score ──
    usort($scored, fn($a, $b) => $b['_validation_score'] <=> $a['_validation_score']);

    // ── 6. Quality Gate: لو أقل من العدد المطلوب، حاول مع threshold أقل ──
    $minScore = (int)($cfg['analysis']['competitor_min_validation_score'] ?? 40);
    $topN     = (int)($cfg['analysis']['competitor_top_n'] ?? 5);
    $retryThreshold = (int)($cfg['analysis']['competitor_retry_if_less_than'] ?? 3);

    $topCompetitors = array_filter($scored, fn($c) => $c['_validation_score'] >= $minScore);
    $topCompetitors = array_slice(array_values($topCompetitors), 0, $topN);

    // لو أقل من threshold، خفّف الشرط
    if (count($topCompetitors) < $retryThreshold && count($scored) >= $retryThreshold) {
        logInfo('Lowering validation threshold', [
            'original_min' => $minScore,
            'matched'      => count($topCompetitors),
        ]);
        $topCompetitors = array_slice($scored, 0, $topN);
    }

    return [
        'success'         => count($topCompetitors) > 0,
        'total_candidates'=> count($pool),
        'after_dedup'     => count($deduplicated),
        'after_filter'    => count($filtered),
        'top_competitors' => $topCompetitors,
        'excluded'        => $excluded,
    ];
}

/**
 * إزالة التكرار حسب domain أو name متطابق
 */
function _deduplicateCandidates(array $pool): array {
    $seenDomains = [];
    $seenNames   = [];
    $result = [];

    foreach ($pool as $cand) {
        $name = mb_strtolower(trim($cand['name'] ?? ''));
        $website = $cand['website'] ?? '';
        $fbUrl = $cand['social']['facebook'] ?? '';

        // domain key
        $domain = '';
        if (!empty($website)) {
            $h = parse_url($website, PHP_URL_HOST) ?? '';
            $domain = mb_strtolower(preg_replace('/^www\./', '', $h));
        } elseif (!empty($fbUrl)) {
            // استخدم slug Facebook
            preg_match('/facebook\.com\/([^\/\?#]+)/i', $fbUrl, $m);
            $domain = 'fb:' . mb_strtolower($m[1] ?? '');
        }

        // skip duplicates
        if (!empty($domain) && isset($seenDomains[$domain])) {
            // لكن، لو المرشح الجديد أغنى من الأقدم، استبدل
            if (_isRicherCandidate($cand, $seenDomains[$domain])) {
                $idx = array_search($seenDomains[$domain], $result, true);
                if ($idx !== false) $result[$idx] = $cand;
                $seenDomains[$domain] = $cand;
            }
            continue;
        }

        // skip name-only duplicates (اسم متطابق بدون domain)
        if (empty($domain) && !empty($name) && isset($seenNames[$name])) {
            continue;
        }

        if (!empty($domain)) $seenDomains[$domain] = $cand;
        if (!empty($name))   $seenNames[$name]     = $cand;

        $result[] = $cand;
    }

    return $result;
}

/**
 * مرشح "أغنى" = فيه بيانات أكثر
 */
function _isRicherCandidate(array $newer, array $older): bool {
    $newerScore = 0;
    $olderScore = 0;

    foreach (['rating','reviews_count','phone','website','category','address'] as $field) {
        if (!empty($newer[$field])) $newerScore++;
        if (!empty($older[$field])) $olderScore++;
    }

    return $newerScore > $olderScore;
}

/**
 * فلترة الموقع الأصلي + المواقع العامة
 */
function _filterExcluded(array $candidates, array $clientProfile, array &$excluded): array {
    // استخراج domain العميل
    $clientWebsite = $clientProfile['client_website'] ?? '';
    $clientDomain  = '';
    if (!empty($clientWebsite)) {
        $h = parse_url($clientWebsite, PHP_URL_HOST) ?? '';
        $clientDomain = mb_strtolower(preg_replace('/^www\./', '', $h));
    }

    // قائمة النطاقات المستبعدة
    $excludedDomains = [
        'youtube.com','wikipedia.org','linkedin.com','twitter.com','x.com',
        'instagram.com','facebook.com','tiktok.com','pinterest.com',
        'reddit.com','quora.com','medium.com','wordpress.com','blogger.com',
        'amazon.com','noon.com','ebay.com',
        // مواقع المراجعات
        'tripadvisor.com','yelp.com','foursquare.com','zomato.com','talabat.com',
        // محركات البحث
        'google.com','bing.com','yahoo.com','duckduckgo.com',
    ];

    $result = [];
    foreach ($candidates as $cand) {
        $website = $cand['website'] ?? '';
        $domain = '';
        if (!empty($website)) {
            $h = parse_url($website, PHP_URL_HOST) ?? '';
            $domain = mb_strtolower(preg_replace('/^www\./', '', $h));
        }

        // ── استبعاد الموقع الأصلي ──
        if (!empty($clientDomain) && !empty($domain)) {
            if ($domain === $clientDomain || str_ends_with($domain, '.' . $clientDomain)) {
                $excluded[] = ['name' => $cand['name'], 'reason' => 'الموقع الأصلي للعميل'];
                continue;
            }
        }

        // ── استبعاد النطاقات العامة ──
        $isExcluded = false;
        foreach ($excludedDomains as $exc) {
            if (!empty($domain) && (
                $domain === $exc ||
                str_ends_with($domain, '.' . $exc)
            )) {
                $excluded[] = ['name' => $cand['name'], 'reason' => "نطاق عام: $exc"];
                $isExcluded = true;
                break;
            }
        }
        if ($isExcluded) continue;

        // ── استبعاد بدون اسم أو URL ──
        if (empty($cand['name']) || (empty($cand['website']) && empty($cand['social']['facebook']))) {
            $excluded[] = ['name' => $cand['name'] ?? '?', 'reason' => 'بيانات ناقصة'];
            continue;
        }

        $result[] = $cand;
    }

    return $result;
}

/**
 * scoring algorithm (0-100)
 *
 * عوامل:
 *   +30 نفس الفئة
 *   +25 قرب جغرافي (نفس البلد/المدينة)
 *   +15 تقييم Google ≥ 4.0
 *   +15 مراجعات ≥ 50
 *   +10 موقع نشط (HTTP 200)
 *   +5  له social media
 *   +20 مصدر Google Places (موثوق للأنشطة المحلية)
 *   +10 مصدر Facebook Pages (موثوق للسوشيال)
 *   +5  مصدر Google Search
 */
function _scoreCandidate(array $cand, array $clientProfile): int {
    $score = 0;

    // 1. مصدر البيانات
    $source = $cand['_source'] ?? '';
    if ($source === 'google_places')   $score += 20;
    elseif ($source === 'facebook_pages') $score += 10;
    else                                  $score += 5;

    // 2. تطابق الفئة
    if (!empty($cand['category']) && !empty($clientProfile['category'])) {
        $candCat   = mb_strtolower($cand['category']);
        $clientCat = mb_strtolower($clientProfile['category']);
        if ($candCat === $clientCat) {
            $score += 30;
        } elseif (mb_stripos($candCat, $clientCat) !== false || mb_stripos($clientCat, $candCat) !== false) {
            $score += 20;
        }
    }

    // 3. قرب جغرافي
    if (!empty($cand['country']) && !empty($clientProfile['country_code'])) {
        if (mb_strtoupper($cand['country']) === mb_strtoupper($clientProfile['country_code'])) {
            $score += 25;
        }
    }

    // 4. جودة (Google rating)
    if (!empty($cand['rating']) && $cand['rating'] >= 4.0) $score += 15;
    elseif (!empty($cand['rating']) && $cand['rating'] >= 3.5) $score += 10;

    // 5. مراجعات
    if (!empty($cand['reviews_count']) && $cand['reviews_count'] >= 100) $score += 15;
    elseif (!empty($cand['reviews_count']) && $cand['reviews_count'] >= 50) $score += 10;
    elseif (!empty($cand['reviews_count']) && $cand['reviews_count'] >= 10) $score += 5;

    // 6. موقع موجود
    if (!empty($cand['website'])) $score += 10;

    // 7. social media
    if (!empty($cand['social']) && count($cand['social']) >= 1) $score += 5;
    if (!empty($cand['social']) && count($cand['social']) >= 3) $score += 5; // bonus

    // 8. حالة عمل
    if (($cand['business_status'] ?? '') === 'OPERATIONAL') $score += 5;

    return min(100, $score);
}
