<?php
/**
 * Google Places Discovery — STEP 1A
 * يستخدم Apify Actor: LmLOOMYKuCUrYsda2 (Google Places Crawler)
 * Schema المرجعي:
 * {
 *   "keyword": "coffee shops",
 *   "location": "Brooklyn, NY",
 *   "maxResults": 100,
 *   "minRating": 0,
 *   "extractContactDetails": true,
 *   "extractSocialMedia": true,
 *   "extractGeographic": true
 * }
 */

declare(strict_types=1);

require_once __DIR__ . '/../apify-scraper.php'; // _apifyStartRun, _apifyWaitAndFetch
require_once __DIR__ . '/../logger.php';

/**
 * @return array {
 *   success: bool,
 *   places: array[] {
 *     name, address, phone, website, rating, reviews_count,
 *     category, place_id, lat, lng, social: {facebook, instagram, ...},
 *     business_status, hours, _source: 'google_places'
 *   },
 *   error?: string
 * }
 */
function discoverViaGooglePlaces(
    string $keyword,
    string $location,
    array  $cfg,
    int    $maxResults = 30
): array {
    $token = function_exists('getValidApifyToken') ? getValidApifyToken($cfg) : '';
    if (!$token) {
        return ['success' => false, 'error' => 'No Apify token', 'places' => []];
    }

    $actorId = $cfg['apis']['apify_actor_google_places']
            ?? 'LmLOOMYKuCUrYsda2';

    if (empty(trim($keyword)) || empty(trim($location))) {
        return ['success' => false, 'error' => 'keyword/location empty', 'places' => []];
    }

    // ── Schema الفعلي للـ Actor (مأخوذ من Apify Console) ──
    $input = [
        'keyword'                      => $keyword,
        'location'                     => $location,
        'maxResults'                   => max(10, min(100, $maxResults)),
        'minRating'                    => 0,
        'minReviews'                   => 0,
        'filterTemporarilyClosed'      => true,
        'filterPermanentlyClosed'      => true,
        'extractContactDetails'        => true,
        'extractAmenities'             => false,
        'extractAtmosphere'            => false,
        'extractPhotos'                => false,
        'extractSocialMedia'           => true,                  // ⭐ مهم
        'extractSocialMediaFromWebsite'=> true,                  // ⭐ مهم جداً
        'socialMediaPlatforms'         => ['facebook','instagram','twitter','linkedin','tiktok','youtube'],
        'extractWebsiteEmails'         => false,
        'extractReviews'               => false,                 // مراجعات في step منفصل
        'extractGeographic'            => true,
        'extractCountry'               => true,
        'extractTravel'                => false,
        'concurrency'                  => 2,
        'detailsConcurrency'           => 10,
        'exportToCsv'                  => false,
    ];

    logInfo('Google Places discovery start', [
        'keyword'  => $keyword,
        'location' => $location,
        'max'      => $maxResults,
        'actor'    => $actorId,
    ]);

    $runId = _apifyStartRun($actorId, json_encode($input), $token);
    if (!$runId) {
        logError('Google Places: failed to start run', ['actor' => $actorId]);
        return ['success' => false, 'error' => 'فشل تشغيل Google Places Actor', 'places' => []];
    }

    // 180 ثانية: Google Places يستغرق وقتاً لاستخراج social media
    $items = _apifyWaitAndFetch($runId, $token, 180, $maxResults);
    if ($items === null) {
        logError('Google Places: timeout', ['runId' => $runId]);
        return ['success' => false, 'error' => 'انتهت مهلة Google Places', 'places' => []];
    }

    if (empty($items)) {
        return ['success' => true, 'places' => []];
    }

    // ── تطبيع البيانات إلى schema موحد ──
    $places = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;

        $place = _normalizeGooglePlace($item);
        if ($place === null) continue;

        $places[] = $place;
    }

    logInfo('Google Places discovery complete', [
        'returned' => count($places),
        'requested' => $maxResults,
    ]);

    return [
        'success' => true,
        'source'  => 'google_places',
        'places'  => $places,
    ];
}

/**
 * تطبيع response Google Places إلى schema موحد
 * يدعم variations في أسماء الحقول
 */
function _normalizeGooglePlace(array $item): ?array {
    $name = $item['title'] ?? $item['name'] ?? '';
    if (empty($name)) return null;

    // العنوان قد يكون object أو string
    $address = '';
    if (isset($item['address'])) {
        $address = is_string($item['address']) ? $item['address'] : '';
    }
    if (empty($address) && isset($item['fullAddress'])) {
        $address = (string)$item['fullAddress'];
    }

    // إحداثيات
    $lat = $item['location']['lat'] ?? $item['lat'] ?? null;
    $lng = $item['location']['lng'] ?? $item['lng'] ?? null;

    // social media (قد تأتي بأشكال متعددة)
    $social = [];
    if (isset($item['socialMedia']) && is_array($item['socialMedia'])) {
        foreach ($item['socialMedia'] as $platform => $url) {
            if (is_string($url) && !empty($url)) {
                $social[$platform] = $url;
            }
        }
    }
    // بعض الـ actors يضعها كحقول منفصلة
    foreach (['facebook','instagram','twitter','tiktok','linkedin','youtube'] as $p) {
        if (!empty($item[$p]) && is_string($item[$p])) {
            $social[$p] = $item[$p];
        }
    }

    // hours
    $hours = $item['openingHours'] ?? $item['hours'] ?? null;

    return [
        // ── معرفات ──
        'name'            => trim($name),
        'place_id'        => $item['placeId'] ?? $item['place_id'] ?? null,
        'cid'             => $item['cid'] ?? null,

        // ── جغرافيا ──
        'address'         => $address,
        'city'            => $item['city'] ?? null,
        'country'         => $item['country'] ?? $item['countryCode'] ?? null,
        'lat'             => is_numeric($lat) ? (float)$lat : null,
        'lng'             => is_numeric($lng) ? (float)$lng : null,

        // ── معلومات النشاط ──
        'category'        => $item['category'] ?? $item['categoryName'] ?? '',
        'subcategories'   => $item['categories'] ?? [],
        'phone'           => $item['phone'] ?? $item['phoneNumber'] ?? null,
        'website'         => $item['website'] ?? null,
        'business_status' => $item['businessStatus'] ?? 'OPERATIONAL',

        // ── إشارات الجودة ──
        'rating'          => is_numeric($item['rating'] ?? null) ? (float)$item['rating'] : null,
        'reviews_count'   => is_numeric($item['reviewsCount'] ?? null) ? (int)$item['reviewsCount'] : 0,
        'price_level'     => $item['priceLevel'] ?? null,

        // ── سوشيال ──
        'social'          => $social,

        // ── ساعات ──
        'hours'           => $hours,

        // ── meta ──
        '_source'         => 'google_places',
        '_raw_keys'       => array_keys($item),
    ];
}
