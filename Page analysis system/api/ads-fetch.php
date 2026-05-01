<?php
// ============================================================
// api/ads-fetch.php v2.0
// التحليل: NVIDIA AI (Llama 3.1-70b) — نفس الأداة الأصلية
// البيانات: Apify (إعلانات) + Meta Ads Manager (ROAS حقيقي)
// ============================================================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

$cfg    = require __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$envPath = __DIR__ . '/../.env';
$env     = file_exists($envPath) ? parse_ini_file($envPath) : [];
$getEnv  = fn($k, $d='') => $env[$k] ?? getenv($k) ?: $d;

// ── قراءة الطلب ───────────────────────────────────────────────
$scanId    = intval($_GET['id'] ?? $_POST['id'] ?? 0);
$action    = $_GET['action'] ?? 'fetch';          // fetch | link-status | real-metrics
$metaToken = $_GET['meta_token'] ?? $_POST['meta_token'] ?? '';

if (!$scanId) {
    echo json_encode(['success'=>false,'error'=>'معرّف الفحص مطلوب']);
    exit;
}

// ── جلب بيانات العميل ────────────────────────────────────────
try {
    $pdo  = getDB();
    $stmt = $pdo->prepare("SELECT * FROM scans WHERE id = ?");
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
    echo json_encode(['success'=>true, 'real_metrics'=>$realMetrics], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── هل البيانات موجودة مسبقاً؟ ───────────────────────────────
$existing = $scanResult['ads_library'] ?? [];
if (!empty($existing['deep_analysis']) && !isset($_GET['force'])) {
    echo json_encode([
        'success' => true,
        'source'  => 'cache',
        'data'    => buildFrontendPayload($existing, $clientName),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 1. جلب الإعلانات من Apify ─────────────────────────────────
$apifyToken = getApifyToken($cfg);
$actor      = $cfg['apis']['apify_actor_ads_fb'] ?? 'JJghSZmShuco4j9gJ';
$adsData    = [];
$entityData = [];

if ($fbUrl && $apifyToken) {
    $result     = apifyFetchAds($apifyToken, $actor, $fbUrl, $clientName);
    $adsData    = $result['ads']    ?? [];
    $entityData = $result['entity'] ?? [];
}

// ── 2. التحليل العميق بـ NVIDIA AI (نفس الأداة الأصلية) ───────
$deepReport = '';
$nvidiaKey  = $getEnv('NVIDIA_AI_KEY');

if ($nvidiaKey && !empty($adsData)) {
    $deepReport = callNvidiaDeepAnalysis($nvidiaKey, $adsData, $entityData, $clientName, $websiteUrl);
}
// Fallback إذا فشل NVIDIA
if (!$deepReport) {
    $deepReport = callGeminiDeepAnalysis($cfg, $adsData, $entityData, $clientName);
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
    'fetched_at'   => date('Y-m-d H:i:s'),
];

try {
    $scanResult['ads_library'] = $adsLibrary;
    $pdo->prepare("UPDATE scans SET scan_result=?, updated_at=NOW() WHERE id=?")
        ->execute([json_encode($scanResult, JSON_UNESCAPED_UNICODE), $scanId]);
} catch (Exception $e) {
    error_log('[ads-fetch] DB: '.$e->getMessage());
}

echo json_encode([
    'success' => true,
    'source'  => 'live',
    'data'    => buildFrontendPayload($adsLibrary, $clientName),
], JSON_UNESCAPED_UNICODE);
exit;

// ══════════════════════════════════════════════════════════════
// FUNCTIONS
// ══════════════════════════════════════════════════════════════

/**
 * NVIDIA AI — نفس الـ 12 سؤال من الأداة الأصلية (aiService.js)
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
        'desc'   => "تم تحليل {$totalAds} إعلان ({$activeAds} نشط) لـ " . ($entity['page_name'] ?? 'العميل') . " عبر NVIDIA Llama 3.1-70b.",
        'metrics'=> [
            ['title'=>'نوع الحملة',       'val'=>$campaignType,              'status'=>'▶ تم التحليل','status_class'=>'status-yellow','val_class'=>'val-yellow','desc'=>extractQuestion($report,1,2)],
            ['title'=>'إجمالي الإعلانات', 'val'=>(string)$totalAds,          'status'=>$totalAds>5?'▲ نشط':'▼ محدود','status_class'=>$totalAds>5?'status-green':'status-red','val_class'=>$totalAds>5?'val-green':'val-red','desc'=>"رُصد {$totalAds} إعلان في مكتبة Meta."],
            ['title'=>'المنصة المناسبة',  'val'=>extractPlatformScore($report),'status'=>'▶ تم التقييم','status_class'=>'status-yellow','val_class'=>'val-yellow','desc'=>substr(extractQuestion($report,9,10),0,150)],
        ],
        'creative_pointers' => $pointers,
        'strategy' => [
            'desc'  => 'التعديلات العاجلة المقترحة بناءً على تحليل NVIDIA AI:',
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
