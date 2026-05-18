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
