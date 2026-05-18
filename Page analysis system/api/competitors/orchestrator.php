<?php
/**
 * Competitors Orchestrator — STEP 2 entry point
 * المُوصِّل الرئيسي لكل Discovery
 *
 * Usage:
 *   $result = runCompetitorDiscovery($clientData, $cfg);
 *   if ($result['success']) {
 *       $competitors = $result['top_competitors']; // 5 منافسين جاهزين للـ Enrichment
 *   }
 */

declare(strict_types=1);

require_once __DIR__ . '/profile-detector.php';
require_once __DIR__ . '/discovery-google-places.php';
require_once __DIR__ . '/discovery-google-search.php';
require_once __DIR__ . '/discovery-fb-pages.php';
require_once __DIR__ . '/candidates-merger.php';
require_once __DIR__ . '/../logger.php';

/**
 * @param array $clientData بيانات العميل من runPageScan
 * @return array نتيجة شاملة
 */
function runCompetitorDiscovery(array $clientData, array $cfg): array {
    $startTime = microtime(true);

    // ── STEP 0: Profile Detection ──
    $profile = detectClientProfile($clientData);

    // إضافة client_website للـ profile (يُستخدم في الفلترة)
    $profile['client_website'] = $clientData['website']
                              ?? $clientData['website_scan']['final_url']
                              ?? '';

    logInfo('Competitor discovery started', [
        'profile_type' => $profile['profile_type'],
        'business_kw'  => $profile['business_keyword'],
        'location'     => $profile['location_query'],
        'country'      => $profile['country_code'],
    ]);

    if (empty($profile['business_keyword']) && empty($profile['company_name'])) {
        return [
            'success' => false,
            'error'   => 'لا يمكن تحديد اسم/فئة النشاط — discovery غير ممكن',
            'profile' => $profile,
        ];
    }

    // ── STEP 1: Discovery من 3 مصادر (متوازي إن أمكن) ──
    $googlePlacesResults = ['places' => []];
    $googleSearchResults = ['places' => []];
    $fbPagesResults      = ['places' => []];

    $maxCandidates = (int)($cfg['analysis']['competitor_max_candidates'] ?? 30);

    // Google Places (للمحلي + Hybrid)
    if (in_array($profile['profile_type'], ['local', 'hybrid'], true)) {
        if (!empty($profile['business_keyword'])) {
            $googlePlacesResults = discoverViaGooglePlaces(
                $profile['business_keyword'],
                $profile['location_query'],
                $cfg,
                $maxCandidates
            );
            logInfo('Google Places returned', [
                'count' => count($googlePlacesResults['places'] ?? []),
            ]);
        }
    }

    // Google Search (للرقمي + Hybrid)
    if (in_array($profile['profile_type'], ['digital', 'hybrid'], true)
        || count($googlePlacesResults['places'] ?? []) < 5
    ) {
        $queries = buildCommercialSearchQueries(
            $profile['company_name'] ?: $profile['business_keyword'],
            $profile['city']
        );
        if (!empty($queries)) {
            $googleSearchResults = discoverViaGoogleSearch(
                $queries,
                $profile['country_code'],
                $cfg,
                10 // per query
            );
            logInfo('Google Search returned', [
                'count' => count($googleSearchResults['places'] ?? []),
            ]);
        }
    }

    // Facebook Pages (دائماً للحالات التي للعميل FB)
    if ($profile['has_facebook']
        || in_array($profile['profile_type'], ['local', 'hybrid'], true)
    ) {
        $fbQuery = $profile['business_keyword'] . ' ' . $profile['city'];
        $fbPagesResults = discoverViaFacebookPages(
            $fbQuery,
            $profile['country_code'],
            $cfg,
            15
        );
        logInfo('FB Pages returned', [
            'count' => count($fbPagesResults['places'] ?? []),
        ]);
    }

    // ── STEP 2: Merge & Rank ──
    $merged = mergeAndRankCandidates(
        $googlePlacesResults,
        $googleSearchResults,
        $fbPagesResults,
        $profile,
        $cfg
    );

    if (!$merged['success'] || empty($merged['top_competitors'])) {
        return [
            'success'   => false,
            'error'     => 'لم يتم العثور على منافسين بعد الفلترة',
            'profile'   => $profile,
            'diagnostics' => [
                'google_places_count' => count($googlePlacesResults['places'] ?? []),
                'google_search_count' => count($googleSearchResults['places'] ?? []),
                'fb_pages_count'      => count($fbPagesResults['places'] ?? []),
                'after_dedup'         => $merged['after_dedup']  ?? 0,
                'after_filter'        => $merged['after_filter'] ?? 0,
                'excluded_count'      => count($merged['excluded'] ?? []),
            ],
        ];
    }

    $duration = round(microtime(true) - $startTime, 2);
    logInfo('Competitor discovery completed', [
        'top_n'      => count($merged['top_competitors']),
        'duration_s' => $duration,
    ]);

    return [
        'success'         => true,
        'profile'         => $profile,
        'top_competitors' => $merged['top_competitors'],
        'metadata' => [
            'discovery_duration_s' => $duration,
            'sources_used'         => [
                'google_places' => count($googlePlacesResults['places'] ?? []),
                'google_search' => count($googleSearchResults['places'] ?? []),
                'fb_pages'      => count($fbPagesResults['places'] ?? []),
            ],
            'total_candidates' => $merged['total_candidates'],
            'after_dedup'      => $merged['after_dedup'],
            'after_filter'     => $merged['after_filter'],
            'excluded'         => $merged['excluded'],
        ],
    ];
}
