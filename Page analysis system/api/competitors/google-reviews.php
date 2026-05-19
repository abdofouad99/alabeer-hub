<?php
/**
 * Google Maps Reviews for Competitors
 * Actor: Xb8osYTtOjlsgI6k9
 * Schema:
 *   { startUrls?: [{url}], placeIds?: [], maxReviews, reviewsSort }
 */

declare(strict_types=1);

require_once __DIR__ . '/../apify-scraper.php';
require_once __DIR__ . '/../logger.php';

/**
 * @param string $placeId Google Place ID
 * @param string $placeUrl URL Google Maps (بديل)
 * @return array {
 *   success: bool,
 *   reviews: array[],
 *   summary: { avg_rating, total, by_stars, sentiment }
 * }
 */
function fetchCompetitorGoogleReviews(
    string $placeId,
    string $placeUrl,
    array  $cfg,
    int    $maxReviews = 20
): array {
    $token = function_exists('getValidApifyToken') ? getValidApifyToken($cfg) : '';
    if (!$token) {
        return ['success' => false, 'error' => 'No Apify token', 'reviews' => []];
    }

    $actorId = $cfg['apis']['apify_actor_google_maps_reviews']
            ?? 'Xb8osYTtOjlsgI6k9';

    if (empty($placeId) && empty($placeUrl)) {
        return ['success' => false, 'error' => 'No placeId or URL', 'reviews' => []];
    }

    // ── Schema الفعلي ──
    $input = [
        'maxReviews'      => max(1, min(100, $maxReviews)),
        'reviewsSort'     => 'newest',
        'language'        => 'ar',
        'reviewsOrigin'   => 'all',
        'personalData'    => false,
    ];

    if (!empty($placeId)) {
        $input['placeIds'] = [$placeId];
    } elseif (!empty($placeUrl)) {
        $input['startUrls'] = [['url' => $placeUrl]];
    }

    logInfo('Google Reviews fetch start', [
        'place_id' => $placeId,
        'max'      => $maxReviews,
    ]);

    $runId = _apifyStartRun($actorId, json_encode($input), $token);
    if (!$runId) {
        return ['success' => false, 'error' => 'Failed to start actor', 'reviews' => []];
    }

    $items = _apifyWaitAndFetch($runId, $token, 90, $maxReviews);
    if ($items === null || empty($items)) {
        return ['success' => true, 'reviews' => [], 'summary' => null];
    }

    // ── تطبيع المراجعات ──
    $reviews = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;

        // الـ actor قد يُرجع مراجعة واحدة في item أو مصفوفة reviews داخل item
        $reviewsList = $item['reviews'] ?? null;
        if (is_array($reviewsList)) {
            foreach ($reviewsList as $r) {
                $normalized = _compNormalizeGoogleReview($r);
                if ($normalized) $reviews[] = $normalized;
            }
        } else {
            $normalized = _compNormalizeGoogleReview($item);
            if ($normalized) $reviews[] = $normalized;
        }
    }

    // ── حساب summary ──
    $summary = _compSummarizeReviews($reviews);

    logInfo('Google Reviews fetch complete', [
        'count'      => count($reviews),
        'avg_rating' => $summary['avg_rating'],
    ]);

    return [
        'success' => true,
        'reviews' => $reviews,
        'summary' => $summary,
    ];
}

function _compNormalizeGoogleReview(array $r): ?array {
    $rating = $r['rating'] ?? $r['stars'] ?? null;
    $text   = $r['text'] ?? $r['snippet'] ?? '';

    if (!is_numeric($rating)) return null;

    return [
        'rating'    => (float)$rating,
        'text'      => trim((string)$text),
        'date'      => $r['publishedAtDate'] ?? $r['publishAt'] ?? $r['date'] ?? null,
        'reviewer'  => $r['name'] ?? $r['reviewerName'] ?? '',
        'lang'      => $r['language'] ?? null,
    ];
}

function _compSummarizeReviews(array $reviews): array {
    $count = count($reviews);
    if ($count === 0) {
        return ['avg_rating' => null, 'total' => 0, 'by_stars' => [], 'sentiment' => null];
    }

    $sum = 0;
    $byStars = [1=>0, 2=>0, 3=>0, 4=>0, 5=>0];
    foreach ($reviews as $r) {
        $sum += $r['rating'];
        $star = (int)round($r['rating']);
        if (isset($byStars[$star])) $byStars[$star]++;
    }

    $avg = round($sum / $count, 2);

    // sentiment heuristic
    $positive = $byStars[4] + $byStars[5];
    $negative = $byStars[1] + $byStars[2];
    $neutral  = $byStars[3];

    $sentiment = null;
    if ($count > 0) {
        $sentiment = [
            'positive_pct' => round(($positive / $count) * 100, 1),
            'negative_pct' => round(($negative / $count) * 100, 1),
            'neutral_pct'  => round(($neutral / $count) * 100, 1),
            'overall'      => $positive > $negative * 2 ? 'positive'
                            : ($negative > $positive * 2 ? 'negative' : 'mixed'),
        ];
    }

    return [
        'avg_rating' => $avg,
        'total'      => $count,
        'by_stars'   => $byStars,
        'sentiment'  => $sentiment,
    ];
}
