<?php
// ============================================================
// api/ads-fetch.php v3.0 — محسّن مع OpenAI كمسار التحليل الأساسي
// التحليل: OpenAI — مع تحليل محلي احتياطي فقط عند تعذر الوصول
// البيانات: Apify (إعلانات) + Meta Ads Library API + Public Scraping
// ============================================================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

$cfg    = require __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logger.php';     // ✅ تحميل دوال التسجيل
require_once __DIR__ . '/apify-scraper.php';   // for getValidApifyToken()

$envPath = __DIR__ . '/../.env';
$env     = file_exists($envPath) ? parse_ini_file($envPath) : [];
$getEnv  = fn($k, $d='') => $env[$k] ?? getenv($k) ?: $d;

// ── قراءة الطلب ───────────────────────────────────────────────
$scanId    = intval($_GET['id'] ?? $_POST['id'] ?? 0);
$action    = $_GET['action'] ?? 'fetch';          // fetch | link-status | real-metrics
$metaToken = $_GET['meta_token'] ?? $_POST['meta_token'] ?? '';
$force     = isset($_GET['force']);               // تجاوز الـ cache

if (!$scanId) {
    echo json_encode(['success'=>false,'error'=>'معرّف الفحص مطلوب']);
    exit;
}

// ── جلب بيانات العميل ────────────────────────────────────────
try {
    $pdo  = getDB();
    $stmt = $pdo->prepare(
        "SELECT a.id, a.scan_result,
                l.full_name, l.company_name,
                l.facebook_url, l.website_url
           FROM assessments a
      LEFT JOIN leads l ON l.id = a.lead_id
          WHERE a.id = ?"
    );
    $stmt->execute([$scanId]);
    $scan = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$scan) { echo json_encode(['success'=>false,'error'=>'لا يوجد سجل']); exit; }
} catch (Exception $e) {
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]); exit;
}

$scanResult = json_decode($scan['scan_result'] ?? '{}', true) ?? [];
$clientName = $scan['full_name'] ?? $scan['company_name'] ?? 'العميل';
$fbUrl      = $scan['facebook_url'] ?? $scanResult['facebook']['page_url'] ?? '';
$websiteUrl = $scan['website_url']  ?? $scanResult['website_scan']['final_url'] ?? '';

// ══════════════════════════════════════════════════════════════
// ACTION: real-metrics — جلب أرقام حقيقية من Meta Ads Manager
// ══════════════════════════════════════════════════════════════
if ($action === 'real-metrics' && $metaToken) {
    $realMetrics = fetchRealMetaMetrics($metaToken, $clientName);
    if (!empty($realMetrics['connected'])) {
        $scanResult['ads_library'] = $scanResult['ads_library'] ?? [];
        $scanResult['ads_library']['real_metrics'] = $realMetrics;
        $scanResult['ads_library']['metrics_source'] = 'meta_ads_manager';
        $scanResult['ads_library']['metrics_connected_at'] = date('Y-m-d H:i:s');
        try {
            $pdo->prepare("UPDATE assessments SET scan_result=? WHERE id=?")
                ->execute([json_encode($scanResult, JSON_UNESCAPED_UNICODE), $scanId]);
        } catch (Exception $e) {
            error_log('[ads-fetch real-metrics] DB: '.$e->getMessage());
        }
    }
    echo json_encode(['success'=>true, 'real_metrics'=>$realMetrics], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── هل البيانات موجودة مسبقاً؟ ───────────────────────────────
$existing = $scanResult['ads_library'] ?? [];
if (!empty($existing['deep_analysis']) && !empty($existing['ads']) && !$force) {
    echo json_encode([
        'success' => true,
        'source'  => 'cache',
        'data'    => buildFrontendPayload($existing, $clientName),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── تحديد معرفات البحث ─────────────────────────────────────────
$pageId   = $scanResult['facebook']['page_id'] ?? $scanResult['og']['page_id'] ?? '';
$pageName = $scanResult['facebook']['page_name'] ?? $clientName;

// ── 1. جلب الإعلانات من مصادر متعددة ─────────────────────────
$adsData    = [];
$entityData = [];
$source     = 'none';

// المصدر الأول: Meta Ads Library API (مباشر، مجاني)
if (empty($adsData)) {
    $metaResult = fetchAdsFromMetaAPI($pageId ?: $pageName, $cfg);
    if (!empty($metaResult['ads'])) {
        $adsData = $metaResult['ads'];
        $entityData = $metaResult['entity'] ?? [];
        $source = 'meta_api';
        logInfo('Ads fetched from Meta API', ['total' => count($adsData)]);
    }
}

// المصدر الثاني: Apify (إذا فشل Meta API أو أعطى نتائج ضئيلة)
if (empty($adsData) || count($adsData) < 5) {
    $apifyToken = function_exists('getValidApifyToken') ? getValidApifyToken($cfg) : '';
    if ($apifyToken && ($fbUrl || $pageId)) {
        $searchParam = $pageId ? "ID:{$pageId}" : ($fbUrl ?: $pageName);
        $result = apifyFetchAdsEnhanced($apifyToken, $cfg, $searchParam, $clientName);
        if (!empty($result['ads'])) {
            $adsData = $result['ads'];
            $entityData = $result['entity'] ?? [];
            $source = 'apify';
            logInfo('Ads fetched from Apify', ['total' => count($adsData)]);
        }
    }
}

// المصدر الثالث: Public Scraping (fallback نهائي)
if (empty($adsData) && $fbUrl) {
    $publicResult = fetchAdsPublicScraping($fbUrl);
    if (!empty($publicResult['ads'])) {
        $adsData = $publicResult['ads'];
        $entityData = $publicResult['entity'] ?? [];
        $source = 'public_scrape';
        logInfo('Ads fetched from public scraping', ['total' => count($adsData)]);
    }
}

// ── 2. التحليل العميق بـ AI ───────
$deepReport = '';
$openaiKey  = $getEnv('OPENAI_KEY');

if ($openaiKey && !empty($adsData)) {
    $deepReport = callOpenAIDeepAnalysis($openaiKey, $cfg, $adsData, $entityData, $clientName, $websiteUrl);
}
// Fallback أخير: تحليل محلي إذا تعذر OpenAI
if (!$deepReport && !empty($adsData)) {
    $deepReport = generateLocalAdsAnalysis($adsData, $entityData, $clientName);
}

// ── 3. تحويل التقرير Markdown → JSON منظم للواجهة ────────────
$structuredAI = parseDeepReportToJson($deepReport, $adsData, $entityData);

// ── 4. حفظ في DB ──────────────────────────────────────────────
$adsLibrary = [
    'total_ads'    => count($adsData),
    'active_ads'   => count(array_filter($adsData, fn($a)=>($a['is_active']??true)!==false)),
    'ads'          => $adsData,
    'entity'       => $entityData,
    'deep_analysis'=> $deepReport,
    'ai_analysis'  => $structuredAI,
    'source'       => $source,
    'fetched_at'   => date('Y-m-d H:i:s'),
];

try {
    $scanResult['ads_library'] = $adsLibrary;
    $pdo->prepare("UPDATE assessments SET scan_result=? WHERE id=?")
        ->execute([json_encode($scanResult, JSON_UNESCAPED_UNICODE), $scanId]);
} catch (Exception $e) {
    error_log('[ads-fetch] DB: '.$e->getMessage());
}

echo json_encode([
    'success' => true,
    'source'  => $source,
    'data'    => buildFrontendPayload($adsLibrary, $clientName),
], JSON_UNESCAPED_UNICODE);
exit;

// ══════════════════════════════════════════════════════════════
// FUNCTIONS — المصادر المتعددة
// ══════════════════════════════════════════════════════════════

/**
 * المصدر الأول: Meta Ads Library API المباشر
 */
function fetchAdsFromMetaAPI(string $pageIdOrName, array $cfg): array {
    $token = $cfg['apis']['meta_ads_token'] ?? $cfg['apis']['facebook_access_token'] ?? '';
    if (empty($token) || str_contains($token, 'YOUR')) {
        return ['ads' => [], 'entity' => []];
    }

    $isPageId = is_numeric($pageIdOrName) || str_starts_with($pageIdOrName, 'ID:');
    $cleanId = str_replace('ID:', '', $pageIdOrName);

    // بناء الـ endpoint
    $fields = 'id,ad_creative_body,ad_creative_link_caption,ad_creative_link_description,ad_creative_link_title,ad_creation_time,ad_delivery_start_time,ad_snapshot_url,page_id,page_name,demo_entity_type,display_format,editable_dynamic_ad_fields,effective_status,eu_data_controls,exported_as_video,features,format,has_video,impression_count,is_beneficiary,is_payer,is_targeting_country,languages,page_is_profile_plus_role_allowed,page_wants_in_stream_video,partner_app_id,partner_name,post,privacy_policy_url,destination_url,publisher_platforms,regional_reach,retro_ua_event_count,reward_entity_type,schedule_info,seller_entity_type,source_app_id,target_achievement_type,target_achievement_type_name,target_age_max,target_age_min,target_gender,target_reach,target_regions,thumbnail_url,ui_data_controls,video_thumbnail_url,click_insights';

    $endpoint = "https://graph.facebook.com/v19.0/ads_archive";
    $params = [
        'ad_active_status' => 'ALL',
        'ad_type' => 'ALL',
        'ad_reached_countries' => "['SA','AE','KW','QA','BH','OM','EG']",
        'fields' => 'id,ad_creative_body,page_name,ad_creation_time,display_format,thumbnail_url,publisher_platforms',
        'limit' => 50,
        'access_token' => $token,
    ];

    if ($isPageId) {
        $params['search_page_ids'] = $cleanId;
    } else {
        $params['search_terms'] = $pageIdOrName;
    }

    $url = $endpoint . '?' . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        logError('Meta Ads API failed', ['code' => $code, 'response' => $res]);
        return ['ads' => [], 'entity' => []];
    }

    $data = json_decode($res, true);
    $rawAds = $data['data'] ?? [];

    if (empty($rawAds)) {
        return ['ads' => [], 'entity' => []];
    }

    // تحويل البيانات للصيغة الموحدة
    $ads = [];
    foreach ($rawAds as $ad) {
        $ads[] = [
            'id'            => $ad['id'] ?? null,
            'page_name'     => $ad['page_name'] ?? '',
            'title'         => $ad['ad_creative_link_title'] ?? '',
            'text'          => $ad['ad_creative_body'] ?? '',
            'image_url'     => $ad['thumbnail_url'] ?? '',
            'start_date'    => $ad['ad_creation_time'] ?? null,
            'is_active'     => ($ad['ad_delivery_start_time'] ?? null) !== null,
            'platforms'     => $ad['publisher_platforms'] ?? ['facebook'],
            'format'        => $ad['display_format'] ?? 'image',
        ];
    }

    return [
        'ads' => $ads,
        'entity' => [
            'page_name' => $rawAds[0]['page_name'] ?? $pageIdOrName,
            'platform' => 'facebook',
        ],
    ];
}

/**
 * المصدر الثاني: Apify محسّن
 */
function apifyFetchAdsEnhanced(string $token, array $cfg, string $searchParam, string $clientName): array {
    if (!function_exists('scrapeAdsLibrary')) {
        return ['ads' => [], 'entity' => []];
    }

    $result = scrapeAdsLibrary($searchParam, $token, $cfg, 'ALL', []);

    if (!($result['success'] ?? false)) {
        return ['ads' => [], 'entity' => []];
    }

    return [
        'ads' => $result['ads'] ?? [],
        'entity' => [
            'page_name'  => $clientName,
            'page_likes' => 0,
            'platform'   => 'facebook',
        ],
    ];
}

/**
 * المصدر الثالث: Public Scraping
 */
function fetchAdsPublicScraping(string $fbUrl): array {
    // محاولة جلب من صفحة الإعلانات العامة
    $adsUrl = "https://www.facebook.com/ads/library/?active_status=active&ad_type=all&country=SA&q=" . urlencode(basename($fbUrl)) . "&search_type=keyword_unordered";

    $html = fetchHtmlContent($adsUrl, 20);
    if (!$html) {
        return ['ads' => [], 'entity' => []];
    }

    // استخراج ما يمكن من الصفحة
    $ads = [];
    // البحث عن بيانات JSON مضمنة
    if (preg_match('/"ads":\s*(\[.*?\])\s*,/s', $html, $m)) {
        $decoded = json_decode($m[1], true);
        if (is_array($decoded)) {
            foreach ($decoded as $ad) {
                $ads[] = [
                    'id' => $ad['adArchiveID'] ?? $ad['id'] ?? null,
                    'page_name' => $ad['pageName'] ?? '',
                    'text' => $ad['adBody'] ?? '',
                    'is_active' => true,
                ];
            }
        }
    }

    return [
        'ads' => $ads,
        'entity' => [],
    ];
}

/**
 * تحليل محلي عند فشل AI
 */
function generateLocalAdsAnalysis(array $ads, array $entity, string $clientName): string {
    $total = count($ads);
    $active = count(array_filter($ads, fn($a) => $a['is_active'] ?? true));
    $withVideo = count(array_filter($ads, fn($a) => !empty($a['video_url'])));
    $withImage = count(array_filter($ads, fn($a) => !empty($a['image_url'])));

    $report = "# تحليل الإعلانات لـ {$clientName}\n\n";
    $report .= "## نظرة عامة\n";
    $report .= "- إجمالي الإعلانات: {$total}\n";
    $report .= "- الإعلانات النشطة: {$active}\n";
    $report .= "- إعلانات مصورة: {$withImage}\n";
    $report .= "- إعلانات فيديو: {$withVideo}\n\n";

    $report .= "## التوصيات\n";
    if ($total === 0) {
        $report .= "1. **عاجل**: لا توجد إعلانات — ابدأ بحملة تجريبية بـ 50 ريال/يوم\n";
        $report .= "2. ركّز على إعلانات الوصول لبناء الوعي أولاً\n";
    } elseif ($active < 3) {
        $report .= "1. **مهم**: إعلاناتك قليلة — وسّع الحملة لزيادة الوصول\n";
        $report .= "2. اختبر 3 إعلانات مختلفة في وقت واحد\n";
    } else {
        $report .= "1. نشاطك الإعلاني جيد — راقب ROAS أسبوعياً\n";
        $report .= "2. استخدم Lookalike Audience للتوسع\n";
    }

    if ($withVideo < $withImage) {
        $report .= "3. **فرصة**: أضف المزيد من الفيديوهات — تفاعل أعلى بـ 48%\n";
    }

    return $report;
}

/**
 * مساعد: جلب محتوى HTML
 */
function fetchHtmlContent(string $url, int $timeout = 15): string|false {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    return $html ?: false;
}

// ══════════════════════════════════════════════════════════════
// FUNCTIONS
// ══════════════════════════════════════════════════════════════

/**
 * OpenAI deep analysis — نفس الـ 12 سؤال من الأداة الأصلية
 */
function callOpenAIDeepAnalysis(string $key, array $cfg, array $ads, array $entity, string $clientName, string $websiteUrl=''): string {
    $adsContext = implode("\n---\n", array_map(
        fn($ad, $i) => "الإعلان ".($i + 1).": ".($ad['text'] ?? $ad['title'] ?? '(بدون نص)'),
        $ads,
        array_keys($ads)
    ));
    $followers     = $entity['page_likes'] ?? $entity['followers_count'] ?? 0;
    $platform      = $entity['platform'] ?? 'facebook';
    $entityContext = "المعلن: {$clientName}، المتابعين: {$followers}، المنصة: {$platform}";
    $websiteCtx    = $websiteUrl ? "معلومات الموقع: {$websiteUrl}" : 'لا توجد بيانات موقع';

    $prompt = "بوصفك خبير استراتيجيات تسويق رقمي ومحلل بيانات إعلانية، قم بتحليل البيانات التالية لـ 30 إعلان (أو المتاح منها) وقدّم تحليلاً عميقاً:\n\n"
        . "بيانات المعلن: {$entityContext}\n{$websiteCtx}\n\nنصوص الإعلانات:\n{$adsContext}\n\n"
        . "المطلوب تحليل دقيق للإجابة على الأسئلة التالية باللغة العربية:\n"
        . "1. ما هو نوع الحملة الحالية؟ (وعي، تفاعل، مبيعات... إلخ)\n"
        . "2. ما هو الهدف الإعلاني الواضح من المحتوى؟\n"
        . "3. مدى توافق الحملة مع الأهداف التجارية المنطقية لهذا النشاط.\n"
        . "4. مدى توافق الإعلان مع الصفحة.\n"
        . "5. هل الإعلانات ترسل العميل لصفحة جاهزة للتحويل (Landing Page Readiness)؟\n"
        . "6. هل الإعلان يذهب للبيع المباشر قبل بناء الثقة؟ حلل رحلة العميل.\n"
        . "7. هل هناك هدر محتمل في الرسائل أو الاستهداف أو الميزانية بناءً على تحليل المحتوى؟\n"
        . "8. هل الميزانية تبدو مناسبة لهذا النوع من الإعلانات؟\n"
        . "9. هل المنصة الإعلانية المختارة مناسبة لهذا المنتج/الخدمة؟\n"
        . "10. ما هي التعديلات المقترحة فوراً على الحملة الحالية؟\n"
        . "11. حلل الرسائل التسويقية (Messages) المستخدمة ووقتها.\n"
        . "12. هل الجمهور المستهدف (Audience) المستنبط من النصوص مناسب؟\n\n"
        . "اجعل التقرير احترافياً، نقدياً، وموجهاً لاتخاذ قرارات (Actionable). استخدم تنسيق Markdown.";

    $body = json_encode([
        'model'       => $cfg['apis']['openai_model'] ?? 'gpt-4o-mini',
        'messages'    => [
            ['role' => 'system', 'content' => 'أنت خبير استراتيجيات تسويق رقمي. أجب بالعربية فقط وبصيغة Markdown فقط، وابدأ مباشرة بالإجابة دون أي مقدمات أو تعليقات خارج التقرير.'],
            ['role' => 'user', 'content' => $prompt],
        ],
        'temperature' => 0.5,
        'max_tokens'  => 3000,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer '.$key],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) { error_log('[ads-fetch] OpenAI error '.$code.': '.$res); return ''; }
    $data = json_decode($res, true);
    return $data['choices'][0]['message']['content'] ?? '';
}

/**
 * NVIDIA AI — نفس الـ 12 سؤال من الأداة الأصلية (legacy)
 */
function callNvidiaDeepAnalysis(string $key, array $ads, array $entity, string $clientName, string $websiteUrl=''): string {
    $adsContext    = implode("\n---\n", array_map(
        fn($ad,$i) => "الإعلان ".($i+1).": ".($ad['text']??$ad['title']??'(بدون نص)'),
        $ads, array_keys($ads)
    ));
    $followers     = $entity['page_likes'] ?? $entity['followers_count'] ?? 0;
    $platform      = $entity['platform'] ?? 'facebook';
    $entityContext = "المعلن: {$clientName}، المتابعين: {$followers}، المنصة: {$platform}";
    $websiteCtx    = $websiteUrl ? "معلومات الموقع: {$websiteUrl}" : 'لا توجد بيانات موقع';

    // نفس البرامبت من aiService.js بالضبط
    $prompt = "بصفتك خبير استراتيجيات تسويق رقمي ومحلل بيانات إعلانية، قم بتحليل البيانات التالية لـ 30 إعلان (أو المتاح منها) وقدم تحليلاً عميقاً:\n\n"
        . "بيانات المعلن: {$entityContext}\n{$websiteCtx}\n\nنصوص الإعلانات:\n{$adsContext}\n\n"
        . "المطلوب تحليل دقيق للإجابة على الأسئلة التالية باللغة العربية:\n"
        . "1. ما هو نوع الحملة الحالية؟ (وعي، تفاعل، مبيعات... إلخ)\n"
        . "2. ما هو الهدف الإعلاني الواضح من المحتوى؟\n"
        . "3. مدى توافق الحملة مع الأهداف التجارية المنطقية لهذا النشاط.\n"
        . "4. مدى توافق الإعلان مع الصفحة (Consistency).\n"
        . "5. هل الإعلانات ترسل العميل لصفحة جاهزة للتحويل (Landing Page Readiness)؟\n"
        . "6. هل الإعلان يذهب للبيع المباشر قبل بناء الثقة؟ حلل رحلة العميل.\n"
        . "7. هل هناك هدر محتمل في الرسائل أو الاستهداف أو الميزانية بناءً على تحليل المحتوى؟\n"
        . "8. هل الميزانية تبدو مناسبة لهذا النوع من الإعلانات؟\n"
        . "9. هل المنصة الإعلانية المختارة مناسبة لهذا المنتج/الخدمة؟\n"
        . "10. ما هي التعديلات المقترحة فوراً على الحملة الحالية؟\n"
        . "11. حلل الرسائل التسويقية (Messages) المستخدمة وقوتها.\n"
        . "12. هل الجمهور المستهدف (Audience) المستنبط من النصوص مناسب؟\n\n"
        . "اجعل التقرير احترافياً، نقدياً، وموجهاً لاتخاذ قرارات (Actionable). استخدم تنسيق Markdown.";

    $body = json_encode([
        'model'       => 'meta/llama-3.1-70b-instruct',
        'messages'    => [['role'=>'user','content'=>$prompt]],
        'temperature' => 0.6,
        'max_tokens'  => 3000,
    ]);

    $ch = curl_init('https://integrate.api.nvidia.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json','Authorization: Bearer '.$key],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) { error_log('[ads-fetch] NVIDIA error '.$code.': '.$res); return ''; }
    $data = json_decode($res, true);
    return $data['choices'][0]['message']['content'] ?? '';
}

/**
 * Gemini Fallback إذا فشل NVIDIA
 */
function callGeminiDeepAnalysis(array $cfg, array $ads, array $entity, string $clientName): string {
    $keys  = $cfg['apis']['gemini_keys'] ?? [];
    $model = $cfg['apis']['gemini_model'] ?? 'gemini-2.0-flash';
    if (empty($keys) || empty($ads)) return '';

    $adsCtx  = implode("\n---\n", array_map(fn($ad,$i)=>"إعلان ".($i+1).": ".($ad['text']??''), $ads, array_keys($ads)));
    $prompt  = "حلل هذه الإعلانات لـ {$clientName} وأجب على 12 سؤال تسويقي: نوع الحملة، الهدف، الاتساق، رحلة العميل، الهدر، الميزانية، المنصة، التعديلات، الرسائل، الجمهور. اجعل الرد Markdown احترافياً.\nالإعلانات:\n{$adsCtx}";

    foreach ($keys as $key) {
        $url  = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";
        $body = json_encode(['contents'=>[['parts'=>[['text'=>$prompt]]]],'generationConfig'=>['temperature'=>0.6,'maxOutputTokens'=>2000]]);
        $ch   = curl_init($url);
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200) {
            $d = json_decode($res, true);
            $t = $d['candidates'][0]['content']['parts'][0]['text'] ?? '';
            if ($t) return $t;
        }
    }
    return '';
}

/**
 * تحويل تقرير Markdown إلى JSON منظم للواجهة
 */
function parseDeepReportToJson(string $report, array $ads, array $entity): array {
    $totalAds  = count($ads);
    $activeAds = count(array_filter($ads, fn($a)=>($a['is_active']??true)!==false));

    // استخراج نوع الحملة من التقرير
    $campaignType = 'مبيعات';
    if (preg_match('/نوع الحملة[^:]*:\s*([^\n]+)/u', $report, $m)) $campaignType = trim($m[1]);
    elseif (stripos($report,'وعي')!==false) $campaignType = 'وعي (Awareness)';
    elseif (stripos($report,'تفاعل')!==false) $campaignType = 'تفاعل (Engagement)';

    // تقدير الدرجة من محتوى التقرير
    $negWords  = ['هدر','ضعيف','غياب','مشكلة','خطأ','فادح','رديء','بطيء'];
    $posWords  = ['قوي','ممتاز','احترافي','جيد','متميز','فعّال'];
    $neg = 0; $pos = 0;
    foreach ($negWords as $w) $neg += substr_count($report, $w);
    foreach ($posWords as $w) $pos += substr_count($report, $w);
    $score = max(10, min(90, 55 + ($pos*3) - ($neg*3)));

    // استخراج التعديلات المقترحة (السؤال 10)
    $steps = [];
    if (preg_match('/10[\.\.]\s*([^#]+?)(?=11\.|##|$)/su', $report, $m)) {
        $block = trim($m[1]);
        foreach (preg_split('/[\n\r]+[-*•]\s*/u', $block) as $line) {
            $line = trim($line);
            if (strlen($line) > 10) $steps[] = $line;
        }
    }
    if (empty($steps)) {
        $steps = ['راجع التقرير الكامل أدناه للحصول على التوصيات التفصيلية.'];
    }
    $steps = array_slice($steps, 0, 3);

    // استخراج نقاط من التقرير (هدر، رحلة العميل، الرسائل)
    $pointers = [];
    $q7 = extractQuestion($report, 7, 8);
    $q6 = extractQuestion($report, 6, 7);
    $q11= extractQuestion($report, 11, 12);
    if ($q7) $pointers[] = ['type'=>'red',   'icon'=>'❌', 'title'=>'الهدر الإعلاني',         'desc'=>substr($q7, 0, 200)];
    if ($q6) $pointers[] = ['type'=>'yellow', 'icon'=>'⚠️', 'title'=>'رحلة العميل',            'desc'=>substr($q6, 0, 200)];
    if ($q11)$pointers[] = ['type'=>'yellow', 'icon'=>'⚠️', 'title'=>'قوة الرسائل التسويقية', 'desc'=>substr($q11,0, 200)];
    if (empty($pointers)) {
        $pointers[] = ['type'=>'yellow','icon'=>'⚠️','title'=>'راجع التقرير الكامل','desc'=>'التحليل العميق متاح في قسم التقرير أدناه.'];
    }

    return [
        'score'  => $score,
        'status' => $score >= 70 ? '✅ أداء جيد' : ($score >= 45 ? '⚠️ يحتاج تحسين' : '❌ يحتاج تدخل عاجل'),
        'desc'   => "تم تحليل {$totalAds} إعلان ({$activeAds} نشط) لـ " . ($entity['page_name'] ?? 'العميل') . " عبر OpenAI.",
        'metrics'=> [
            ['title'=>'نوع الحملة',       'val'=>$campaignType,              'status'=>'▶ تم التحليل','status_class'=>'status-yellow','val_class'=>'val-yellow','desc'=>extractQuestion($report,1,2)],
            ['title'=>'إجمالي الإعلانات', 'val'=>(string)$totalAds,          'status'=>$totalAds>5?'▲ نشط':'▼ محدود','status_class'=>$totalAds>5?'status-green':'status-red','val_class'=>$totalAds>5?'val-green':'val-red','desc'=>"رُصد {$totalAds} إعلان في مكتبة Meta."],
            ['title'=>'المنصة المناسبة',  'val'=>extractPlatformScore($report),'status'=>'▶ تم التقييم','status_class'=>'status-yellow','val_class'=>'val-yellow','desc'=>substr(extractQuestion($report,9,10),0,150)],
        ],
        'creative_pointers' => $pointers,
        'strategy' => [
            'desc'  => 'التعديلات العاجلة المقترحة بناءً على تحليل OpenAI:',
            'steps' => $steps,
        ],
        'full_report' => $report,   // التقرير الكامل Markdown
    ];
}

function extractQuestion(string $report, int $q, int $next): string {
    if (preg_match("/{$q}[\.\.]\s*(.+?)(?={$next}[\.\.] |##|$)/su", $report, $m))
        return trim(strip_tags($m[1]));
    return '';
}

function extractPlatformScore(string $report): string {
    $block = extractQuestion($report, 9, 10);
    if (stripos($block,'مناسب')!==false && stripos($block,'غير')===false) return 'مناسبة ✅';
    if (stripos($block,'غير مناسب')!==false) return 'غير مناسبة ❌';
    return 'متوسطة ⚠️';
}

/**
 * جلب أرقام حقيقية من Meta Ads Manager API (ROAS، Spend، CPC)
 */
function fetchRealMetaMetrics(string $token, string $clientName): array {
    // أولاً: جلب Ad Accounts
    $ch = curl_init("https://graph.facebook.com/v18.0/me/adaccounts?fields=id,name,account_status&access_token={$token}");
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) return ['error'=>'فشل الاتصال بـ Meta API. تحقق من صحة التوكن.','connected'=>false];

    $accounts = json_decode($res, true);
    if (empty($accounts['data'])) return ['error'=>'لا يوجد حسابات إعلانية مرتبطة.','connected'=>false];

    $accountId = $accounts['data'][0]['id'];
    $metrics   = [];

    // جلب إحصاءات آخر 30 يوم
    $fields  = 'spend,impressions,clicks,cpc,cpm,ctr,actions,action_values,cost_per_action_type';
    $insight = "https://graph.facebook.com/v18.0/{$accountId}/insights?fields={$fields}&date_preset=last_30d&access_token={$token}";
    $ch2 = curl_init($insight);
    curl_setopt_array($ch2,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);
    $res2 = curl_exec($ch2);
    curl_close($ch2);

    $insightData = json_decode($res2, true);
    $d = $insightData['data'][0] ?? [];

    // حساب ROAS
    $spend   = floatval($d['spend'] ?? 0);
    $revenue = 0;
    foreach (($d['action_values'] ?? []) as $av) {
        if (in_array($av['action_type'], ['purchase','offsite_conversion.fb_pixel_purchase']))
            $revenue += floatval($av['value'] ?? 0);
    }
    $roas = $spend > 0 ? round($revenue / $spend, 2) : 0;

    return [
        'connected'   => true,
        'account_id'  => $accountId,
        'account_name'=> $accounts['data'][0]['name'] ?? $clientName,
        'period'      => 'آخر 30 يوم',
        'spend'       => '$'.number_format($spend, 2),
        'revenue'     => '$'.number_format($revenue, 2),
        'roas'        => $roas.'x',
        'roas_raw'    => $roas,
        'impressions' => number_format(intval($d['impressions'] ?? 0)),
        'clicks'      => number_format(intval($d['clicks'] ?? 0)),
        'cpc'         => '$'.number_format(floatval($d['cpc'] ?? 0), 2),
        'cpm'         => '$'.number_format(floatval($d['cpm'] ?? 0), 2),
        'ctr'         => round(floatval($d['ctr'] ?? 0), 2).'%',
        'status_label'=> $roas >= 3 ? '✅ ممتاز' : ($roas >= 1.5 ? '⚠️ متوسط' : '❌ خسارة'),
        'status_class'=> $roas >= 3 ? 'status-green' : ($roas >= 1.5 ? 'status-yellow' : 'status-red'),
    ];
}

/**
 * جلب الإعلانات من Apify
 */
function apifyFetchAds(string $token, string $actor, string $fbUrl, string $clientName): array {
    $input = ['searchPageOrAdLibraryUrl'=>$fbUrl,'maxResults'=>30,'locale'=>'ar_AR'];
    $base  = 'https://api.apify.com/v2';
    $hdrs  = ['Content-Type: application/json','Authorization: Bearer '.$token];

    $ch = curl_init("{$base}/acts/{$actor}/runs");
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($input),CURLOPT_HTTPHEADER=>$hdrs,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30]);
    $run = json_decode(curl_exec($ch),true); curl_close($ch);
    $runId = $run['data']['id'] ?? null;
    if (!$runId) return ['ads'=>[],'entity'=>[]];

    $waited=0; $datasetId=null;
    while ($waited < 120) {
        sleep(5); $waited+=5;
        $ch2 = curl_init("{$base}/actor-runs/{$runId}");
        curl_setopt_array($ch2,[CURLOPT_HTTPHEADER=>$hdrs,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]);
        $st = json_decode(curl_exec($ch2),true); curl_close($ch2);
        $status = $st['data']['status'] ?? '';
        if ($status==='SUCCEEDED') { $datasetId=$st['data']['defaultDatasetId']??null; break; }
        if (in_array($status,['FAILED','ABORTED','TIMED-OUT'])) break;
    }
    if (!$datasetId) return ['ads'=>[],'entity'=>[]];

    $ch3 = curl_init("{$base}/datasets/{$datasetId}/items?format=json&limit=30");
    curl_setopt_array($ch3,[CURLOPT_HTTPHEADER=>$hdrs,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30]);
    $items = json_decode(curl_exec($ch3),true); curl_close($ch3);
    if (!is_array($items)||empty($items)) return ['ads'=>[],'entity'=>[]];

    $ads = [];
    foreach ($items as $i=>$item) {
        $snap = $item['snapshot'] ?? $item;
        $ads[]=[
            'id'        =>$item['adArchiveId']??$item['id']??'ad_'.$i,
            'page_name' =>$item['pageName']??$clientName,
            'is_active' =>($item['isActive']??true)!==false,
            'start_date'=>$item['startDate']??null,
            'title'     =>$snap['title']??'',
            'text'      =>$snap['body']['text']??$item['adBodyText']??$item['text']??'',
            'image_url' =>$snap['images'][0]['resizedImageUrl']??$item['imageUrl']??'',
            'video_url' =>$snap['videos'][0]['videoHdUrl']??$item['videoUrl']??'',
            'cta'       =>$snap['callToActionType']??'',
            'platforms' =>$item['publisherPlatforms']??['facebook'],
        ];
    }

    return [
        'ads'    => $ads,
        'entity' => [
            'page_name'  =>$items[0]['pageName']??$clientName,
            'page_likes' =>$items[0]['pageLikeCount']??0,
            'platform'   =>'facebook',
        ],
    ];
}

/**
 * بناء payload للـ Frontend
 */
function buildFrontendPayload(array $lib, string $clientName): array {
    return [
        'client_name'  => $clientName,
        'total_ads'    => $lib['total_ads']   ?? count($lib['ads']??[]),
        'active_ads'   => $lib['active_ads']  ?? 0,
        'ads'          => $lib['ads']         ?? [],
        'ai'           => $lib['ai_analysis'] ?? [],
        'full_report'  => $lib['deep_analysis']?? '',
        'has_ads'      => !empty($lib['ads']),
        'fetched_at'   => $lib['fetched_at']  ?? '',
    ];
}
