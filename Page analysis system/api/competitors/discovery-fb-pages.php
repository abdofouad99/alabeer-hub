<?php
/**
 * Facebook Pages Search Discovery — STEP 1C
 * Actor الأساسي: YAg3YuPbbASz7JzWG
 * Actor الاحتياطي: HBdQuY0Qwd2bDGM4a
 */

declare(strict_types=1);

require_once __DIR__ . '/../apify-scraper.php';
require_once __DIR__ . '/../logger.php';

function discoverViaFacebookPages(
    string $query,
    string $countryCode,
    array  $cfg,
    int    $maxResults = 15
): array {
    $token = function_exists('getValidApifyToken') ? getValidApifyToken($cfg) : '';
    if (!$token) {
        return ['success' => false, 'error' => 'No Apify token', 'places' => []];
    }

    if (empty(trim($query))) {
        return ['success' => false, 'error' => 'empty query', 'places' => []];
    }

    $primary  = $cfg['apis']['apify_actor_fb_pages_search']         ?? 'YAg3YuPbbASz7JzWG';
    $fallback = $cfg['apis']['apify_actor_fb_pages_search_fallback'] ?? 'HBdQuY0Qwd2bDGM4a';

    // المحاولة الأولى
    $items = _runFbPagesActor($primary, $query, $maxResults, $token, false);

    if ($items === null || empty($items)) {
        logInfo('FB Pages fallback', ['actor' => $fallback]);
        $items = _runFbPagesActor($fallback, $query, $maxResults, $token, true);
    }

    $places = [];
    foreach (($items ?: []) as $item) {
        $p = _normalizeFbPageResult($item);
        if ($p !== null) $places[] = $p;
    }

    return [
        'success' => true,
        'source'  => 'facebook_pages',
        'places'  => $places,
    ];
}

function _runFbPagesActor(
    string $actorId,
    string $query,
    int    $maxResults,
    string $token,
    bool   $isFallback
): ?array {
    if ($isFallback) {
        // Schema للـ HBdQuY0Qwd2bDGM4a (يستقبل startUrls)
        $searchUrl = 'https://www.facebook.com/search/pages/?q=' . urlencode($query);
        $input = [
            'startUrls'         => [$searchUrl],
            'location'          => '',
            'maxPages'          => max(1, ceil($maxResults / 10)),
            'minDelay'          => 5,
            'maxDelay'          => 10,
            'cookies'           => [],
            'proxyConfiguration'=> ['useApifyProxy' => true],
        ];
    } else {
        // Schema للـ YAg3YuPbbASz7JzWG (الأساسي)
        $input = [
            'query'      => $query,
            'maxResults' => $maxResults,
        ];
    }

    $runId = _apifyStartRun($actorId, json_encode($input), $token);
    if (!$runId) return null;

    return _apifyWaitAndFetch($runId, $token, 90, $maxResults);
}

function _normalizeFbPageResult(array $item): ?array {
    $name = $item['name'] ?? $item['pageName'] ?? $item['title'] ?? '';
    $url  = $item['url']  ?? $item['pageUrl']  ?? $item['link']  ?? '';

    if (empty($name) || empty($url)) return null;

    return [
        'name'            => trim((string)$name),
        'website'         => null,
        'address'         => $item['location'] ?? null,
        'phone'           => $item['phone'] ?? null,
        'category'        => $item['category'] ?? '',
        'place_id'        => null,
        'lat'             => null,
        'lng'             => null,
        'rating'          => null,
        'reviews_count'   => 0,
        'business_status' => 'OPERATIONAL',
        'social'          => [
            'facebook' => $url,
        ],
        'fb_likes'        => $item['likes'] ?? $item['likesCount'] ?? null,
        'fb_followers'    => $item['followers'] ?? $item['followersCount'] ?? null,
        'description'     => $item['description'] ?? $item['intro'] ?? '',
        '_source'         => 'facebook_pages',
    ];
}
