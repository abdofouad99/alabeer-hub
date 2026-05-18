# 🔍 Sprint 2: Discovery — اكتشاف المنافسين الحقيقيين

## الهدف
استبدال البحث الحالي عبر Google Search فقط بنظام **متعدد المصادر** يكتشف 5 منافسين حقيقيين 100%.

## المخرجات النهائية
- مجلد جديد: `api/competitors/`
- 6 ملفات جديدة بـ ~800 سطر كود
- إصلاح ربط `discovery` بـ `analyze.php` و `page-scan.php`
- 0 actor جديد للـ Enrichment (موجود سابقاً)

## مدة العمل المتوقعة
3-4 أيام

---

# 📁 الملفات الجديدة

## 1. `api/competitors/profile-detector.php`

### الوظيفة
يحلل بيانات العميل ويقرر مسار Discovery الأنسب.

### الكود الكامل

```php
<?php
/**
 * Profile Detector — STEP 0
 * يحدد ما إذا كان نشاط العميل: محلي / رقمي / مختلط
 * بناءً على: عنوان فعلي، Google Place، فئة، وجود سوشيال
 *
 * @author  Competitors v2 system
 * @since   2025
 */

declare(strict_types=1);

/**
 * @param array $clientData البيانات المُجمّعة من runPageScan
 * @return array {
 *   profile_type: 'local' | 'digital' | 'hybrid',
 *   business_keyword: string,    اسم النشاط للبحث
 *   location_query: string,      الموقع للبحث المحلي
 *   country_code: string,        ISO-3166 alpha-2
 *   category: string,            فئة النشاط
 *   has_facebook: bool,
 *   has_instagram: bool,
 *   social_platforms: string[],
 *   confidence: int (0-100),
 *   reasoning: string
 * }
 */
function detectClientProfile(array $clientData): array {
    $reasoning = [];
    $score_local = 0;
    $score_digital = 0;

    // ── 1. استخراج البيانات الخام ──
    $companyName  = $clientData['company_name']
                 ?? $clientData['social']['page_name']
                 ?? $clientData['facebook']['page_name']
                 ?? $clientData['website_scan']['title']
                 ?? '';
    $companyName = trim((string)$companyName);

    $address = $clientData['facebook']['address']
            ?? $clientData['google_place']['address']
            ?? '';
    $address = is_string($address) ? trim($address) : '';

    $category = $clientData['facebook']['category']
             ?? $clientData['google_place']['category']
             ?? $clientData['website_scan']['business_type']
             ?? '';

    $targetAudience = $clientData['lead_audience']
                   ?? $clientData['target_audience']
                   ?? '';

    // ── 2. مؤشرات النشاط المحلي ──
    if (!empty($address)) {
        $score_local += 30;
        $reasoning[] = 'له عنوان فعلي (+30)';
    }
    if (!empty($clientData['google_place']['place_id'])) {
        $score_local += 25;
        $reasoning[] = 'له Google Place (+25)';
    }
    if (!empty($clientData['facebook']['phone'])
        || !empty($clientData['facebook']['whatsapp'])) {
        $score_local += 10;
        $reasoning[] = 'له هاتف/واتساب (+10)';
    }
    if (!empty($clientData['facebook']['opening_hours'])) {
        $score_local += 15;
        $reasoning[] = 'له ساعات عمل (+15)';
    }

    // فئات محلية صريحة
    $localKeywords = [
        'مطعم','restaurant','cafe','مقهى','عيادة','clinic','صالون','salon',
        'متجر','shop','store','محل','بقالة','grocery','بيت','اكل','محل تجاري',
        'مركز طبي','medical center','دكتور','doctor','طبيب','مستشفى','hospital',
        'فندق','hotel','شقق','apartments','spa','نادي','gym','fitness',
        'مغسلة','laundry','ورشة','workshop','مكتب عقاري','real estate',
    ];
    $catLower = mb_strtolower($category . ' ' . $companyName);
    foreach ($localKeywords as $kw) {
        if (mb_stripos($catLower, $kw) !== false) {
            $score_local += 20;
            $reasoning[] = "كلمة محلية: \"$kw\" (+20)";
            break;
        }
    }

    // ── 3. مؤشرات النشاط الرقمي ──
    $digitalKeywords = [
        'app','application','تطبيق','منصة','platform','software','برمجة',
        'saas','online','الكتروني','رقمي','digital','إنترنت','تجارة الكترونية',
        'ecommerce','marketing agency','وكالة تسويق','agency','consulting',
        'استشارات','tech','تقني','startup','شركة ناشئة','b2b','b2c',
    ];
    foreach ($digitalKeywords as $kw) {
        if (mb_stripos($catLower, $kw) !== false) {
            $score_digital += 25;
            $reasoning[] = "كلمة رقمية: \"$kw\" (+25)";
            break;
        }
    }

    if (!empty($clientData['website_scan']['has_pixel'])
        || !empty($clientData['website_scan']['has_ga'])) {
        $score_digital += 10;
        $reasoning[] = 'له Pixel/GA = توجه رقمي (+10)';
    }
    if (!empty($clientData['website_scan']['has_ssl'])
        && empty($address)) {
        $score_digital += 15;
        $reasoning[] = 'موقع آمن بدون عنوان (+15)';
    }

    // ── 4. تحديد النوع ──
    if ($score_local >= 30 && $score_digital >= 20) {
        $profile_type = 'hybrid';
    } elseif ($score_local > $score_digital) {
        $profile_type = 'local';
    } elseif ($score_digital > 0) {
        $profile_type = 'digital';
    } else {
        // افتراضي: hybrid لتغطية أوسع
        $profile_type = 'hybrid';
        $reasoning[] = 'افتراضي hybrid (لا مؤشرات قوية)';
    }

    // ── 5. استخراج keyword للبحث ──
    $business_keyword = '';
    if (!empty($category) && mb_strlen($category) > 3) {
        $business_keyword = $category;
    } elseif (!empty($companyName) && mb_strlen($companyName) > 3) {
        // محاولة استخراج الفئة من الاسم
        $business_keyword = $companyName;
    }

    // ── 6. تطبيع location و country ──
    $locationData = _extractLocation($address, $targetAudience);

    // ── 7. اكتشاف منصات السوشيال ──
    $platforms = [];
    if (!empty($clientData['facebook']['url'])) $platforms[] = 'facebook';
    if (!empty($clientData['instagram']['url'])) $platforms[] = 'instagram';
    if (!empty($clientData['tiktok']['url'])) $platforms[] = 'tiktok';
    if (!empty($clientData['twitter']['url'])) $platforms[] = 'twitter';

    $confidence = min(100, max($score_local, $score_digital));

    return [
        'profile_type'     => $profile_type,
        'business_keyword' => $business_keyword,
        'company_name'     => $companyName,
        'location_query'   => $locationData['query'],
        'country_code'     => $locationData['country'],
        'city'             => $locationData['city'],
        'category'         => $category,
        'has_facebook'     => in_array('facebook', $platforms, true),
        'has_instagram'    => in_array('instagram', $platforms, true),
        'social_platforms' => $platforms,
        'confidence'       => $confidence,
        'score_local'      => $score_local,
        'score_digital'    => $score_digital,
        'reasoning'        => implode(' | ', $reasoning),
    ];
}

/**
 * استخراج موقع جغرافي مع تطبيع رمز الدولة
 */
function _extractLocation(string $address, string $targetAudience): array {
    $combined = trim($address . ' ' . $targetAudience);

    // ── خريطة دول الخليج + مصر (الأكثر شيوعاً) ──
    $countryMap = [
        // السعودية
        'السعودية' => ['SA', 'السعودية'],
        'saudi'    => ['SA', 'Saudi Arabia'],
        'الرياض'   => ['SA', 'الرياض، السعودية'],
        'riyadh'   => ['SA', 'Riyadh, Saudi Arabia'],
        'جدة'      => ['SA', 'جدة، السعودية'],
        'jeddah'   => ['SA', 'Jeddah, Saudi Arabia'],
        'مكة'      => ['SA', 'مكة، السعودية'],
        'mecca'    => ['SA', 'Mecca, Saudi Arabia'],
        'المدينة'  => ['SA', 'المدينة، السعودية'],
        'medina'   => ['SA', 'Medina, Saudi Arabia'],
        'الدمام'   => ['SA', 'الدمام، السعودية'],
        'dammam'   => ['SA', 'Dammam, Saudi Arabia'],
        'الخبر'    => ['SA', 'الخبر، السعودية'],
        'khobar'   => ['SA', 'Khobar, Saudi Arabia'],
        'تبوك'     => ['SA', 'تبوك، السعودية'],
        'أبها'     => ['SA', 'أبها، السعودية'],

        // الإمارات
        'الإمارات' => ['AE', 'الإمارات'],
        'uae'      => ['AE', 'UAE'],
        'دبي'      => ['AE', 'دبي، الإمارات'],
        'dubai'    => ['AE', 'Dubai, UAE'],
        'أبوظبي'   => ['AE', 'أبوظبي، الإمارات'],
        'abu dhabi'=> ['AE', 'Abu Dhabi, UAE'],
        'الشارقة'  => ['AE', 'الشارقة، الإمارات'],
        'sharjah'  => ['AE', 'Sharjah, UAE'],
        'عجمان'    => ['AE', 'عجمان، الإمارات'],

        // الكويت
        'الكويت'   => ['KW', 'الكويت'],
        'kuwait'   => ['KW', 'Kuwait'],

        // قطر
        'قطر'      => ['QA', 'قطر'],
        'qatar'    => ['QA', 'Qatar'],
        'الدوحة'   => ['QA', 'الدوحة، قطر'],
        'doha'     => ['QA', 'Doha, Qatar'],

        // البحرين
        'البحرين'  => ['BH', 'البحرين'],
        'bahrain'  => ['BH', 'Bahrain'],

        // عمان
        'عمان'     => ['OM', 'عُمان'],
        'oman'     => ['OM', 'Oman'],
        'مسقط'     => ['OM', 'مسقط، عُمان'],
        'muscat'   => ['OM', 'Muscat, Oman'],

        // مصر
        'مصر'      => ['EG', 'مصر'],
        'egypt'    => ['EG', 'Egypt'],
        'القاهرة'  => ['EG', 'القاهرة، مصر'],
        'cairo'    => ['EG', 'Cairo, Egypt'],
        'الإسكندرية' => ['EG', 'الإسكندرية، مصر'],

        // الأردن
        'الأردن'   => ['JO', 'الأردن'],
        'jordan'   => ['JO', 'Jordan'],
        'عمّان'    => ['JO', 'عمّان، الأردن'],
        'amman'    => ['JO', 'Amman, Jordan'],
    ];

    $combinedLower = mb_strtolower($combined);
    foreach ($countryMap as $kw => [$code, $fullQuery]) {
        if (mb_stripos($combinedLower, mb_strtolower($kw)) !== false) {
            return [
                'country' => $code,
                'query'   => $fullQuery,
                'city'    => $fullQuery,
            ];
        }
    }

    // افتراضي: السعودية
    return [
        'country' => 'SA',
        'query'   => 'السعودية',
        'city'    => 'السعودية',
    ];
}
```

### معيار القبول للملف
- استدعاء `detectClientProfile($mockData)` يُرجع profile_type صحيح في 5 سيناريوهات
- ROW: `profile_type`, `country_code`, `business_keyword` لا تكون فارغة

---

## 2. `api/competitors/discovery-google-places.php`

### الوظيفة
استدعاء Google Places Crawler لاكتشاف الأنشطة المحلية.

### الكود الكامل

```php
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
```

### معيار القبول
- استدعاء بـ `keyword="مطعم برجر"`, `location="الرياض، السعودية"` يُرجع ≥ 10 places
- 80%+ من النتائج تحتوي على `name + address + rating`
- ≥ 30% تحتوي على رابط `social`

---

## 3. `api/competitors/discovery-google-search.php`

### الكود الكامل

```php
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
```

### معيار القبول
- 4 استعلامات → ≥ 20 نتيجة بعد إزالة التكرار
- لا أخطاء 400 من Apify
- Fallback يعمل لو Actor الأساسي فشل

---

## 4. `api/competitors/discovery-fb-pages.php`

### الكود الكامل

```php
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
```

### معيار القبول
- استدعاء بـ `query="مطعم برجر الرياض"` يُرجع ≥ 5 صفحات
- كل نتيجة فيها `social.facebook` صحيح

---

## 5. `api/competitors/candidates-merger.php`

### الوظيفة
دمج النتائج من 3 مصادر، إزالة التكرار، scoring، ترتيب، أخذ Top 5.

### الكود الكامل

```php
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
```

### معيار القبول
- لا تكرارات في النتيجة (نفس domain مرتين)
- موقع العميل غير موجود
- لا يوتيوب/ويكيبيديا/أي نطاق مستبعد
- Top 5 لها score ≥ 40

---

## 6. `api/competitors/orchestrator.php`

### الوظيفة
المُوصِّل الرئيسي لـ Sprint 2. يستدعي كل ما سبق بالترتيب.

### الكود الكامل

```php
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
```

---

# 🔌 الربط بالنظام الحالي

## تعديل `api/analyze.php`

استبدل المنطق الحالي للمنافسين (السطور 982-1014):

```php
// ─── 7) رادار المنافسين v2 ─────────────────────────────────
require_once __DIR__ . '/competitors/orchestrator.php';

$compResult = null;
if (($cfg['analysis']['enable_apify'] ?? false)) {
    try {
        $compResult = runCompetitorDiscovery($scanResult, $cfg);
        if ($compResult['success'] ?? false) {
            $saveScanProgress('competitor_discovery', $compResult);
            // الـ enrichment يأتي في Sprint 3 — حالياً نحفظ Discovery فقط
            $compRadar = $compResult['top_competitors'];
        } else {
            logError('Competitor discovery failed', [
                'error' => $compResult['error'] ?? 'Unknown',
                'diagnostics' => $compResult['diagnostics'] ?? [],
            ]);
        }
    } catch (\Throwable $e) {
        logError('Competitor discovery exception', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}

// لاحقاً السطر 1048 - الإصلاح المهم (BUG-COMP-B2 السابق):
// لا تدوس على نتيجة page-scan بـ null
if (!empty($compRadar)) {
    $scanResult['competitor_radar'] = $compRadar;
}
// ابقِ القيمة الموجودة من runPageScan إن نجحت
```

## تعديل `api/page-scan.php`

استبدل المنطق الحالي للمنافسين (السطور 241-280):

```php
// ── Step 4: تحليل المنافسين v2 ─────────────────────────
if ($cfg['analysis']['enable_apify'] ?? false) {
    try {
        require_once __DIR__ . '/competitors/orchestrator.php';
        $compResult = runCompetitorDiscovery($result, $cfg);
        if ($compResult['success'] ?? false) {
            $result['competitors']      = $compResult['top_competitors'];
            $result['competitor_radar'] = $compResult['top_competitors'];
            $result['competitor_meta']  = $compResult['metadata'] ?? [];
        }
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('Page-scan competitor v2 exception', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

## تعديل `api/config.example.php`

إضافة هذه الحقول داخل `'apis' => [...]`:

```php
// ── Competitors v2 ──
'apify_actor_google_places'           => $get('APIFY_ACTOR_GOOGLE_PLACES', 'LmLOOMYKuCUrYsda2'),
'apify_actor_google_search'           => $get('APIFY_ACTOR_GOOGLE_SEARCH', 'YNcgn7yiLc72ayYeB'),
'apify_actor_google_search_fallback'  => $get('APIFY_ACTOR_GOOGLE_SEARCH_FALLBACK', 'V8SFJw3gKgULelpok'),
'apify_actor_fb_pages_search'         => $get('APIFY_ACTOR_FB_PAGES_SEARCH', 'YAg3YuPbbASz7JzWG'),
'apify_actor_fb_pages_search_fallback'=> $get('APIFY_ACTOR_FB_PAGES_SEARCH_FALLBACK', 'HBdQuY0Qwd2bDGM4a'),
'apify_actor_google_maps_reviews'     => $get('APIFY_ACTOR_GOOGLE_MAPS_REVIEWS', 'Xb8osYTtOjlsgI6k9'),
```

وداخل `'analysis' => [...]`:

```php
// ── Competitors v2 ──
'competitor_discovery_mode'     => $get('COMPETITOR_DISCOVERY_MODE', 'auto'),
'competitor_max_candidates'     => (int)$get('COMPETITOR_MAX_CANDIDATES', '30'),
'competitor_top_n'              => (int)$get('COMPETITOR_TOP_N', '5'),
'competitor_min_validation_score' => (int)$get('COMPETITOR_MIN_VALIDATION_SCORE', '40'),
'competitor_retry_if_less_than'   => (int)$get('COMPETITOR_RETRY_IF_LESS_THAN', '3'),
```

---

# 🧪 خطة الاختبار

## اختبار 1: نشاط محلي
```bash
# عميل: مطعم في الرياض
url=https://example-restaurant.com
audience="عملاء الرياض"

# توقع:
# - profile_type = "local"
# - country_code = "SA"
# - location_query = "الرياض، السعودية"
# - top_competitors فيه 5 مطاعم محلية
# - 80%+ من المصدر "google_places"
```

## اختبار 2: نشاط رقمي
```bash
# عميل: تطبيق توصيل
url=https://example-app.com
audience="السعودية والإمارات"

# توقع:
# - profile_type = "digital"
# - top_competitors فيه 5 تطبيقات/خدمات رقمية
# - أغلبها من "google_search"
```

## اختبار 3: نشاط مختلط
```bash
# عميل: عيادة بحضور رقمي
# توقع:
# - profile_type = "hybrid"
# - مصادر متنوعة (Maps + Search + FB)
```

## اختبار 4: استبعاد الموقع الأصلي
```bash
# عميل: example.com
# توقع: لا يظهر example.com في top_competitors
# تحقق من $compResult['metadata']['excluded'] لرؤية السبب
```

## اختبار 5: لا توجد بيانات كافية
```bash
# عميل بدون اسم وبدون URL
# توقع:
# - success: false
# - error: 'لا يمكن تحديد اسم/فئة النشاط...'
```

---

# ✅ Checklist للـ Coder Agent

- [ ] إنشاء مجلد `Page analysis system/api/competitors/`
- [ ] إنشاء `profile-detector.php` كاملاً
- [ ] إنشاء `discovery-google-places.php` كاملاً
- [ ] إنشاء `discovery-google-search.php` كاملاً
- [ ] إنشاء `discovery-fb-pages.php` كاملاً
- [ ] إنشاء `candidates-merger.php` كاملاً
- [ ] إنشاء `orchestrator.php` كاملاً
- [ ] تعديل `api/analyze.php` بربط orchestrator
- [ ] تعديل `api/page-scan.php` بربط orchestrator
- [ ] تعديل `api/config.example.php` بإضافة الحقول الجديدة
- [ ] تعديل `.env.example` بإضافة المتغيرات الجديدة
- [ ] تشغيل `php -l` على كل ملف جديد (lint)
- [ ] commit بعنوان: `feat(competitors): add Sprint 2 — multi-source discovery system`
- [ ] PR إلى main بعنوان: "Sprint 2: Real Competitor Discovery System"

---

# ⏭️ بعد إكمال Sprint 2

اقرأ `SPRINT-3-ENRICHMENT.md` لمعرفة كيف نسحب بيانات كل منافس.
