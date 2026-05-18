<?php
/**
 * Google Search Discovery — STEP 1B
 * يستخدم Apify Actor: YNcgn7yiLc72ayYeB (الأساسي) + V8SFJw3gKgULelpok (احتياطي)
 *
 * Schema الأساسي:
 * {
 *   "maxItems": 10,
 *   "query": "fitness apps",
 *   "country": "us",
 *   "language": "en"
 * }
 */

declare(strict_types=1);

require_once __DIR__ . '/../apify-scraper.php';
require_once __DIR__ . '/../logger.php';

/**
 * @param array $queries مصفوفة من الاستعلامات (تجارية فقط)
 * @return array places[] (نفس schema من Google Places للتوحيد)
 */
function discoverViaGoogleSearch(
    array  $queries,
    string $countryCode,
    array  $cfg,
    int    $maxItemsPerQuery = 15
): array {
    $token = function_exists('getValidApifyToken') ? getValidApifyToken($cfg) : '';
    if (!$token) {
        return ['success' => false, 'error' => 'No Apify token', 'places' => []];
    }

    if (empty($queries)) {
        return ['success' => false, 'error' => 'No queries', 'places' => []];
    }

    $primary = $cfg['apis']['apify_actor_google_search']
            ?? 'YNcgn7yiLc72ayYeB';
    $fallback = $cfg['apis']['apify_actor_google_search_fallback']
            ?? 'V8SFJw3gKgULelpok';

    $allPlaces = [];

    foreach ($queries as $query) {
        if (empty(trim($query))) continue;

        // المحاولة الأولى: Actor الأساسي
        $items = _runGoogleSearchActor($primary, $query, $countryCode, $maxItemsPerQuery, $token);

        // المحاولة الثانية: Actor الاحتياطي
        if ($items === null || empty($items)) {
            logInfo('Falling back to alternate Google Search actor', ['actor' => $fallback]);
            $items = _runGoogleSearchActor($fallback, $query, $countryCode, $maxItemsPerQuery, $token, true);
        }

        if (is_array($items)) {
            foreach ($items as $item) {
                $normalized = _normalizeGoogleSearchResult($item);
                if ($normalized !== null) $allPlaces[] = $normalized;
            }
        }
    }

    return [
        'success' => true,
        'source'  => 'google_search',
        'places'  => $allPlaces,
    ];
}

/**
 * @param bool $isFallback لو true → استخدم schema الـ V8SFJw3gKgULelpok (مختلف)
 */
function _runGoogleSearchActor(
    string $actorId,
    string $query,
    string $countryCode,
    int    $maxItems,
    string $token,
    bool   $isFallback = false
): ?array {
    if ($isFallback) {
        // Schema للـ V8SFJw3gKgULelpok
        $input = [
            'queries'                  => [$query],
            'csvFriendlyOutput'        => false,
            'countryCode'              => mb_strtolower($countryCode),
            'languageCode'             => 'ar',
            'maxItems'                 => $maxItems,
            'resultsPerPage'           => '10',
            'includeUnfilteredResults' => false,
            'includePeopleAlsoAsk'     => false,
            'endPage'                  => 1,
            'proxy'                    => ['useApifyProxy' => true],
        ];
    } else {
        // Schema للـ YNcgn7yiLc72ayYeB (الأساسي)
        $input = [
            'maxItems' => $maxItems,
            'query'    => $query,
            'country'  => mb_strtolower($countryCode),
            'language' => 'ar',
            'domain'   => 'google.com',
        ];
    }

    logInfo('Google Search start', [
        'query'   => $query,
        'country' => $countryCode,
        'actor'   => $actorId,
    ]);

    $runId = _apifyStartRun($actorId, json_encode($input), $token);
    if (!$runId) return null;

    $items = _apifyWaitAndFetch($runId, $token, 60, $maxItems);
    return $items;
}

/**
 * تطبيع نتيجة Google Search لتطابق schema Google Places
 */
function _normalizeGoogleSearchResult(array $item): ?array {
    // نتائج Google تأتي بأشكال مختلفة بحسب الـ actor
    $title = $item['title']
          ?? $item['metadataTitle']
          ?? '';
    $url   = $item['url'] ?? $item['link'] ?? '';
    $desc  = $item['description']
          ?? $item['metadataDescription']
          ?? $item['snippet']
          ?? '';

    if (empty($title) || empty($url)) return null;

    // فلتر domain من الـ URL
    $domain = parse_url($url, PHP_URL_HOST) ?? '';

    return [
        'name'            => trim((string)$title),
        'website'         => $url,
        'address'         => '',
        'phone'           => null,
        'category'        => '',
        'place_id'        => null,
        'lat'             => null,
        'lng'             => null,
        'rating'          => null,
        'reviews_count'   => 0,
        'business_status' => 'OPERATIONAL',
        'social'          => [],
        'description'     => trim((string)$desc),
        'domain'          => $domain,
        '_source'         => 'google_search',
        '_meta_query'     => $item['_query'] ?? null,
    ];
}

/**
 * بناء استعلامات تجارية متعددة (لتفادي مشكلة "أهم منافسين..." التي تُرجع مقالات)
 *
 * @return string[]
 */
function buildCommercialSearchQueries(string $companyName, string $location): array {
    $companyName = trim($companyName);
    $location = trim($location);

    if (empty($companyName)) return [];

    // استعلامات مُصمّمة لتُرجع مواقع شركات/خدمات منافسة
    $queries = [
        "شركات مثل \"{$companyName}\" {$location}",
        "بدائل {$companyName} {$location}",
        "\"{$companyName}\" vs",
        "{$companyName} alternative {$location}",
    ];

    // فلترة الفارغين
    return array_values(array_filter($queries, fn($q) => mb_strlen(trim($q)) > 5));
}
