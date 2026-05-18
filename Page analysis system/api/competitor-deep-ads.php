<?php
/**
 * api/competitor-deep-ads.php
 *
 * Endpoint لتحليل عميق لإعلانات منافس بضغطة زر
 * يستخدم scrapeAdsLibrary (المُصلَح في PR #58) + OpenAI للتحليل
 *
 * Request:
 *   POST /api/competitor-deep-ads.php
 *   {
 *     "scan_id": 123,
 *     "competitor_idx": 0,        // index في competitor_radar
 *     "competitor_url": "https://www.facebook.com/X"
 *   }
 *
 * Response:
 *   {
 *     "success": true,
 *     "competitor_name": "...",
 *     "ads_summary": {
 *       "total_ads": 12,
 *       "active_ads": 8,
 *       "running_since": "2024-01-15",
 *       "platforms": ["facebook", "instagram"]
 *     },
 *     "ai_analysis": {
 *       "messaging_pattern": "...",
 *       "primary_offers": [...],
 *       "target_audience_signals": "...",
 *       "cta_strategy": "...",
 *       "creative_style": "...",
 *       "weaknesses_in_ads": [...],
 *       "what_to_copy": [...],
 *       "what_to_avoid": [...]
 *     },
 *     "ads_sample": [...]
 *   }
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

set_time_limit(300);
ini_set('memory_limit', '256M');

$cfg = require __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/apify-scraper.php'; // scrapeAdsLibrary المُصلَح

// ── Rate limit مدمج ──
require_once __DIR__ . '/rate_limit.php';

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// ── قراءة الـ input ──
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$scanId = (int)($body['scan_id'] ?? 0);
$compIdx = (int)($body['competitor_idx'] ?? 0);
$compUrl = trim((string)($body['competitor_url'] ?? ''));
$force = !empty($body['force']); // تخطي cache

if (!$scanId || empty($compUrl)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'scan_id و competitor_url مطلوبان',
    ]);
    exit;
}

// ── فحص feature flag ──
$enabled = !empty($cfg['analysis']['competitor_deep_ads_enabled']);
if (!$enabled) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => 'هذه الميزة غير مفعّلة حالياً',
    ]);
    exit;
}

// ── Rate limit: 20 طلب/يوم لكل IP ──
$dailyCap = (int)($cfg['analysis']['competitor_deep_ads_max_per_day'] ?? 20);
$rateKey = "deep_ads_daily_{$ip}_" . date('Ymd');
$rateFile = sys_get_temp_dir() . '/' . md5($rateKey);
$rateCount = file_exists($rateFile) ? (int)file_get_contents($rateFile) : 0;
if ($rateCount >= $dailyCap) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error'   => "تجاوزت الحد اليومي ({$dailyCap} طلبات). حاول غداً.",
    ]);
    exit;
}

try {
    // ── 1. جلب بيانات الفحص ──
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT a.id, a.scan_result, l.full_name FROM assessments a LEFT JOIN leads l ON l.id = a.lead_id WHERE a.id = ?");
    $stmt->execute([$scanId]);
    $scan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$scan) {
        echo json_encode(['success' => false, 'error' => 'لا يوجد سجل بهذا الـ ID']);
        exit;
    }

    $scanResult = json_decode($scan['scan_result'] ?? '{}', true) ?? [];
    $competitors = $scanResult['competitor_radar'] ?? $scanResult['competitors'] ?? [];

    if (!isset($competitors[$compIdx])) {
        echo json_encode(['success' => false, 'error' => 'منافس غير موجود']);
        exit;
    }

    $competitor = $competitors[$compIdx];
    $compName = $competitor['name'] ?? 'منافس';

    // ── 2. فحص الـ cache ──
    $cacheHours = (int)($cfg['analysis']['competitor_deep_ads_cache_hours'] ?? 24);
    $cacheTtl = $cacheHours * 3600;
    $cacheFile = sys_get_temp_dir() . '/comp_deep_ads_' . md5($compUrl) . '.json';
    if (!$force && file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            $cached['from_cache'] = true;
            echo json_encode($cached, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // ── 3. سحب الإعلانات (scrapeAdsLibrary المُصلَح في PR #58) ──
    $token = function_exists('getValidApifyToken') ? getValidApifyToken($cfg) : '';
    if (empty($token)) {
        echo json_encode(['success' => false, 'error' => 'لا يوجد Apify token صالح']);
        exit;
    }

    logInfo('Deep ads analysis start', [
        'competitor' => $compName,
        'url'        => $compUrl,
    ]);

    // استخدام page_id إن توفر (أدق)
    $pageId = $competitor['platforms']['facebook']['page_id'] ?? '';
    $searchParam = $pageId ? "ID:{$pageId}" : $compUrl;

    $adsResult = scrapeAdsLibrary(
        $searchParam,
        $token,
        $cfg,
        $cfg['apis']['ads_default_country'] ?? 'SA',
        $competitor['platforms']['facebook'] ?? []
    );

    if (!($adsResult['success'] ?? false) || empty($adsResult['ads'])) {
        echo json_encode([
            'success'         => false,
            'error'           => 'لا توجد إعلانات لتحليلها',
            'competitor_name' => $compName,
            'ads_summary'     => [
                'total_ads'  => $adsResult['total_ads'] ?? 0,
                'active_ads' => $adsResult['active_ads'] ?? 0,
            ],
        ]);
        exit;
    }

    // ── 4. تحضير Ads summary ──
    $allAds = $adsResult['ads'];
    $activeAds = array_filter($allAds, fn($a) => !empty($a['is_active']));
    $platforms = [];
    $oldestDate = null;

    foreach ($allAds as $ad) {
        if (is_array($ad['platforms'] ?? null)) {
            foreach ($ad['platforms'] as $p) {
                if (!in_array($p, $platforms, true)) $platforms[] = $p;
            }
        }
        if (!empty($ad['start_date'])) {
            $ts = strtotime($ad['start_date']);
            if ($ts && (!$oldestDate || $ts < $oldestDate)) $oldestDate = $ts;
        }
    }

    $adsSummary = [
        'total_ads'      => $adsResult['total_ads']      ?? count($allAds),
        'active_ads'     => $adsResult['active_ads']     ?? count($activeAds),
        'is_running_ads' => $adsResult['is_running_ads'] ?? !empty($activeAds),
        'platforms'      => $platforms,
        'running_since'  => $oldestDate ? date('Y-m-d', $oldestDate) : null,
    ];

    // ── 5. تحليل بـ AI ──
    $aiAnalysis = analyzeCompetitorAdsWithAI($allAds, $compName, $scanResult, $cfg);

    // ── 6. تحضير الاستجابة ──
    $response = [
        'success'         => true,
        'competitor_name' => $compName,
        'competitor_url'  => $compUrl,
        'ads_summary'     => $adsSummary,
        'ai_analysis'     => $aiAnalysis,
        'ads_sample'      => array_slice($allAds, 0, 6),
        'analyzed_at'     => date('c'),
        'from_cache'      => false,
    ];

    // ── 7. حفظ في cache + DB ──
    @file_put_contents($cacheFile, json_encode($response, JSON_UNESCAPED_UNICODE));

    // حفظ النتيجة داخل scanResult تحت competitor_radar[idx]
    $competitors[$compIdx]['deep_ads_analysis'] = [
        'ads_summary' => $adsSummary,
        'ai_analysis' => $aiAnalysis,
        'analyzed_at' => $response['analyzed_at'],
    ];
    $scanResult['competitor_radar'] = $competitors;

    try {
        $pdo->prepare("UPDATE assessments SET scan_result = ? WHERE id = ?")
            ->execute([json_encode($scanResult, JSON_UNESCAPED_UNICODE), $scanId]);
    } catch (\Throwable $e) {
        logError('Failed to save deep ads to DB', ['error' => $e->getMessage()]);
    }

    // ── 8. تحديث rate counter ──
    @file_put_contents($rateFile, $rateCount + 1);

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    logError('Deep ads endpoint exception', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'حدث خطأ غير متوقع',
        'detail'  => $e->getMessage(),
    ]);
}

/**
 * تحليل إعلانات المنافس بـ OpenAI
 */
function analyzeCompetitorAdsWithAI(array $ads, string $compName, array $scanResult, array $cfg): array {
    $key = $cfg['apis']['openai_key'] ?? '';
    if (empty($key)) {
        return [
            'analyzed' => false,
            'reason'   => 'لا يوجد OpenAI key',
        ];
    }

    // ── تحضير ملخص الإعلانات للـ AI ──
    $adsSummaries = [];
    foreach (array_slice($ads, 0, 15) as $idx => $ad) {
        $adsSummaries[] = [
            'idx'       => $idx + 1,
            'text'      => mb_substr((string)($ad['title'] ?? ''), 0, 500),
            'cta'       => $ad['cta_type'] ?? '',
            'platforms' => $ad['platforms'] ?? [],
            'is_active' => $ad['is_active'] ?? false,
            'start'     => $ad['start_date'] ?? null,
        ];
    }

    $clientName = $scanResult['social']['page_name'] ?? '';

    $systemPrompt = <<<PROMPT
أنت محلل تسويقي محترف. مهمتك تحليل عميق لاستراتيجية إعلانات منافس وحيد بناءً على نصوص إعلاناته الفعلية.

⚠️ قواعد صارمة:

1. ممنوع اختراع تفاصيل لم ترد في النصوص.
2. لو نمط غير واضح → اكتب null.
3. كل ادعاء يجب يستند على نص إعلان محدد (اذكر idx).
4. ممنوع كلمات: "غالباً، يبدو، ربما".
5. لا تخمن الميزانية أو ROI.

📋 المخرجات: JSON صالح فقط:

{
  "messaging_pattern": "نمط الرسائل الأساسي مع أمثلة من 2-3 إعلانات (اذكر idx)",
  "primary_offers": [
    "العرض/الخصم #1 المتكرر مع idx الإعلانات",
    "العرض #2",
    ...
  ],
  "target_audience_signals": "علامات الجمهور المستهدف من النصوص (لغة، عمر، فئة)",
  "cta_strategy": "استراتيجية الدعوة لاتخاذ إجراء (احجز/اطلب/...)",
  "creative_style": "أسلوب الإبداع (عاطفي/عقلاني/كوميدي/...)",
  "frequency_pattern": "هل يكرر نفس الإعلان أم يتنوع (مع أمثلة)",
  "weaknesses_in_ads": [
    "نقطة ضعف #1 مع دليل من الإعلانات",
    "نقطة ضعف #2"
  ],
  "what_to_copy": [
    "ميزة قابلة للنسخ #1",
    "ميزة #2"
  ],
  "what_to_avoid": [
    "خطأ يرتكبه #1",
    "خطأ #2"
  ],
  "winning_hook": "أقوى hook استخدمه (مع نص الإعلان)"
}
PROMPT;

    $userPrompt = "اسم المنافس: {$compName}\nاسم العميل (للسياق): {$clientName}\n\nإعلانات المنافس:\n" .
                  json_encode($adsSummaries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) .
                  "\n\nحلّل بناءً على هذه النصوص فقط.";

    $payload = [
        'model'       => $cfg['analysis']['competitor_ai_model'] ?? 'gpt-4o-mini',
        'temperature' => 0.3,
        'response_format' => ['type' => 'json_object'],
        'messages'    => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userPrompt],
        ],
        'max_tokens'  => 2500,
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
        ],
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        return [
            'analyzed' => false,
            'reason'   => "OpenAI HTTP {$code}",
        ];
    }

    $data = json_decode($body, true);
    $text = $data['choices'][0]['message']['content'] ?? '';
    if (empty($text)) {
        return ['analyzed' => false, 'reason' => 'رد AI فارغ'];
    }

    $parsed = json_decode($text, true);
    if (!is_array($parsed)) {
        return ['analyzed' => false, 'reason' => 'فشل parse JSON'];
    }

    $parsed['analyzed'] = true;
    $parsed['_meta'] = [
        'model'         => $payload['model'],
        'ads_analyzed'  => count($adsSummaries),
        'analyzed_at'   => date('c'),
    ];

    return $parsed;
}
