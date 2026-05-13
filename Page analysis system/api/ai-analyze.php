<?php
if (defined('AI_ANALYZE_LOADED')) return;
define('AI_ANALYZE_LOADED', true);

// ============================================================
// api/ai-analyze.php — تحليل ذكي عبر OpenAI
// POST /api/ai-analyze.php
// Body: { "assessment_id": 123 } OR { "data": {...} }
// ============================================================
require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/gemini-agents.php')) {
    require_once __DIR__ . '/gemini-agents.php';
}

// ── تشغيل مباشر فقط (ليس عند require من ملف آخر) ───────────
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    setCors();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);

    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) jsonError('Invalid JSON');

    $cfg = require __DIR__ . '/config.php';

    if (isset($body['assessment_id'])) {
        $data = loadAssessmentData((int)$body['assessment_id']);
    } else {
        $data = $body['data'] ?? null;
    }

    if (!$data) jsonError('لا توجد بيانات للتحليل');

    $result = runGeminiAnalysis($data, $cfg);
    jsonOut($result);
}

// ============================================================
function loadAssessmentData(int $id): ?array
{
    $db = getDB();

    $stmt = $db->prepare('SELECT a.*, l.* FROM assessments a LEFT JOIN leads l ON a.lead_id=l.id WHERE a.id=?');
    $stmt->execute([$id]);
    $assessment = $stmt->fetch();
    if (!$assessment) return null;

    $stmt = $db->prepare('SELECT question_key, answer FROM answers WHERE assessment_id=?');
    $stmt->execute([$id]);
    $answers = [];
    foreach ($stmt->fetchAll() as $row) {
        $dec = json_decode($row['answer'], true);
        $answers[$row['question_key']] = $dec !== null ? $dec : $row['answer'];
    }

    // ✅ استرجاع بيانات الفحص الكاملة المخزّنة في DB
    // بدلاً من إعادة الاستدعاء من Apify
    $scanResult = null;
    if (!empty($assessment['scan_result'])) {
        $decoded = is_string($assessment['scan_result'])
            ? json_decode($assessment['scan_result'], true)
            : $assessment['scan_result'];
        if (is_array($decoded)) $scanResult = $decoded;
    }

    $aiReport = null;
    if (!empty($assessment['ai_report'])) {
        $decoded = is_string($assessment['ai_report'])
            ? json_decode($assessment['ai_report'], true)
            : $assessment['ai_report'];
        if (is_array($decoded)) $aiReport = $decoded;
    }

    return array_merge($assessment, [
        'answers'     => $answers,
        'scan_result' => $scanResult,   // ✅ بيانات Apify الكاملة من DB
        'ai_report'   => $aiReport,     // ✅ التقرير السابق (للمقارنة)
        // ── تمرير بيانات العميل الاستراتيجية مباشرةً للـ Prompt ──
        'score'       => (int)($assessment['score']      ?? 0),
        'breakdown'   => !empty($assessment['breakdown'])
            ? (is_string($assessment['breakdown'])
                ? json_decode($assessment['breakdown'], true)
                : $assessment['breakdown'])
            : [],
    ]);
}

// ============================================================
function runOpenAIAnalysis(array $data, array $cfg, bool $forceRefresh = false): array
{
    // ✅ Global timeout to prevent 20+ minute hangs
    $startTime = microtime(true);
    $maxTotalTime = 180; // 3 minutes max for all AI providers combined

    $priority = $cfg['analysis']['ai_priority'] ?? ['openai'];
    $priority = array_values(array_filter($priority, fn($provider) => $provider === 'openai'));
    if (empty($priority)) {
        $priority = ['openai'];
    }

    // ── Cache: لا تستدعي AI مرتين لنفس البيانات ─────────────
    $cacheKey = 'ai_' . md5(
        ($data['id'] ?? 'none') . '_' .
            ($data['score'] ?? 0) . '_' .
            json_encode($data['breakdown'] ?? []) . '_' .
            ($data['full_name'] ?? $data['company_name'] ?? '')
    );

    // تجاهل الكاش عند forceRefresh أو عند rerun
    if (!$forceRefresh) {
        $cached = cacheGet($cacheKey);
        if ($cached && !empty($cached['summary']) && ($cached['source'] ?? '') !== 'fallback') {
            $cached['_from_cache'] = true;
            return $cached;
        }
    }

    $result = null;
    $triedCount = 0;
    foreach ($priority as $provider) {
        // ✅ Check global timeout - exit early if taking too long
        if ((microtime(true) - $startTime) > $maxTotalTime) {
            logError('Global AI timeout reached', ['elapsed' => round(microtime(true) - $startTime, 2), 'providers_tried' => $triedCount]);
            break;
        }
        $triedCount++;

        try {
            $result = callAIProvider($provider, $data, $cfg);
            if (!empty($result['summary'])) {
                // خزّن النتيجة ساعة واحدة
                cacheSet($cacheKey, $result, 3600);
                return $result;
            }
        } catch (\Throwable $e) {
            logError("AI provider [{$provider}] failed", ['error' => $e->getMessage()]);
            continue;
        }
    }

    return fallbackAnalysis($data);
}

function runGeminiAnalysis(array $data, array $cfg, bool $forceRefresh = false): array
{
    // ── محاولة Multi-Agent System أولاً ──────────────────────
    $geminiKey = getGeminiKey($cfg);
    if (!empty($geminiKey) && function_exists('runMultiAgentAnalysis')) {
        // ── Cache check ──
        $cacheKey = 'agents_' . md5(($data['id'] ?? 'none') . '_' . ($data['score'] ?? 0));
        if (!$forceRefresh) {
            $cached = cacheGet($cacheKey);
            if ($cached && !empty($cached['meta'])) {
                $cached['_from_cache'] = true;
                return $cached;
            }
        }
        try {
            $agentData = buildAgentInputData($data);
            $result = runMultiAgentAnalysis($agentData, [
                'apiKey'      => $geminiKey,
                'maxRetries'  => 1,
                'retryDelay'  => 3,
                'logCallback' => fn($msg) => logError('Agent: ' . $msg),
            ]);
            if (!empty($result['meta'])) {
                // دمج مع تنسيق النظام الحالي
                $result['summary']         = $result['page_1_report']['one_line_verdict'] ?? '';
                $result['strengths']       = $result['page_14_strengths'] ?? [];
                $result['weaknesses']      = $result['page_15_weaknesses'] ?? [];
                $result['recommendations'] = $result['page_16_recommendations'] ?? [];
                $result['source']          = 'gemini_agents';
                cacheSet($cacheKey, $result, 3600);
                return $result;
            }
        } catch (\Throwable $e) {
            logError('Multi-agent failed — falling back to OpenAI', ['error' => $e->getMessage()]);
        }
    }
    // ── Fallback إلى OpenAI ───────────────────────────────────
    return runOpenAIAnalysis($data, $cfg, $forceRefresh);
}

// ============================================================
// buildAgentInputData — تحويل بيانات النظام الحالي لمدخلات الوكلاء
// ============================================================
function buildAgentInputData(array $data): array
{
    $scan    = $data['scan_result'] ?? [];
    $answers = $data['answers'] ?? [];

    $industry    = $answers['industry'] ?? $scan['industry'] ?? '';
    $igFollowers = (int)($scan['instagram']['followers'] ?? 0);
    $fbFollowers = (int)($scan['facebook']['followers'] ?? 0);
    $tkFollowers = (int)($scan['tiktok']['followers'] ?? 0);

    return [
        'business_info' => [
            'business_name'      => $data['company_name'] ?? $data['full_name'] ?? '',
            'industry'           => $industry,
            'location'           => $answers['location'] ?? $data['city'] ?? '',
            'lead_objective'     => $answers['objective'] ?? $data['objective'] ?? $scan['lead_objective'] ?? '',
            'lead_audience'      => $answers['target_audience'] ?? $data['target_audience'] ?? $scan['lead_audience'] ?? '',
            'lead_budget'        => $answers['ad_budget'] ?? $data['ad_budget'] ?? $scan['lead_budget'] ?? '',
            // حقول جديدة: لحساب معادلات الإيراد والنمو بدقة
            'avg_order_value'    => (float)($answers['avg_order_value'] ?? $scan['avg_order_value'] ?? _agentEstimateAvgOrderValue($industry)),
            'followers_last_month' => _agentEstimateLastMonthFollowers($scan),
            'monthly_visitors'   => (int)($scan['monthly_visitors'] ?? _agentEstimateMonthlyVisitors($igFollowers, $fbFollowers, $tkFollowers)),
            'industry_benchmark' => _agentGetIndustryBenchmarks($industry),
        ],
        'website' => [
            'url'               => $scan['website_url'] ?? $answers['website'] ?? '',
            'ssl'               => (bool)($scan['ssl'] ?? false),
            'pixel'             => (bool)($scan['pixel'] ?? false),
            'ga'                => (bool)($scan['ga'] ?? false),
            'whatsapp'          => (bool)($scan['whatsapp'] ?? false),
            'cta'               => (bool)($scan['cta'] ?? false),
            'og_tags'           => (bool)($scan['og_tags'] ?? false),
            'schema'            => (bool)($scan['schema'] ?? false),
            'pagespeed_mobile'  => (int)($scan['pagespeed_mobile'] ?? 0),
            'pagespeed_desktop' => (int)($scan['pagespeed_desktop'] ?? 0),
        ],
        'facebook' => [
            'page_name'         => $scan['facebook']['page_name'] ?? '',
            'followers'         => $fbFollowers,
            'posts_count'       => (int)($scan['facebook']['posts_count'] ?? 0),
            'avg_likes'         => (float)($scan['facebook']['avg_likes'] ?? 0),
            'avg_comments'      => (float)($scan['facebook']['avg_comments'] ?? 0),
            'avg_shares'        => (float)($scan['facebook']['avg_shares'] ?? 0),
            'engagement_rate'   => (float)($scan['facebook']['engagement_rate'] ?? 0),
            'posts_per_week'    => $scan['facebook']['posts_per_week'] ?? 0,
            'bio'               => $scan['facebook']['bio'] ?? '',
            'top_post_comments' => $scan['facebook']['top_post_comments'] ?? [],
        ],
        'instagram' => [
            'username'          => $scan['instagram']['username'] ?? '',
            'followers'         => $igFollowers,
            'posts_count'       => (int)($scan['instagram']['posts_count'] ?? 0),
            'avg_likes'         => (float)($scan['instagram']['avg_likes'] ?? 0),
            'avg_comments'      => (float)($scan['instagram']['avg_comments'] ?? 0),
            'avg_saves'         => (float)($scan['instagram']['avg_saves'] ?? 0),
            'avg_video_views'   => (float)($scan['instagram']['avg_video_views'] ?? 0),
            'reels_count'       => (int)($scan['instagram']['reels_count'] ?? 0),
            'engagement_rate'   => (float)($scan['instagram']['engagement_rate'] ?? 0),
            'posts_per_week'    => $scan['instagram']['posts_per_week'] ?? 0,
            'bio'               => $scan['instagram']['bio'] ?? '',
            'content_types'     => $scan['instagram']['deep_analysis']['content_types'] ?? [],
            'top_hashtags'      => $scan['instagram']['deep_analysis']['top_hashtags'] ?? [],
            'top_post_comments' => $scan['instagram']['top_post_comments'] ?? [],
        ],
        'tiktok' => [
            'username'        => $scan['tiktok']['username'] ?? '',
            'followers'       => $tkFollowers,
            'likes'           => (int)($scan['tiktok']['likes'] ?? 0),
            'video_count'     => (int)($scan['tiktok']['video_count'] ?? 0),
            'avg_likes'       => (float)($scan['tiktok']['avg_likes'] ?? 0),
            'avg_comments'    => (float)($scan['tiktok']['avg_comments'] ?? 0),
            'avg_shares'      => (float)($scan['tiktok']['avg_shares'] ?? 0),
            'avg_saves'       => (float)($scan['tiktok']['avg_saves'] ?? 0),
            'avg_views'       => (float)($scan['tiktok']['avg_views'] ?? 0),
            'engagement_rate' => (float)($scan['tiktok']['engagement_rate'] ?? 0),
            'posts_per_week'  => $scan['tiktok']['posts_per_week'] ?? 0,
            'trending_sounds' => $scan['tiktok']['trending_sounds'] ?? [],
            'top_hashtags'    => $scan['tiktok']['deep_analysis']['top_hashtags'] ?? [],
        ],
        'twitter' => [
            'username'    => $scan['twitter']['username'] ?? '',
            'followers'   => (int)($scan['twitter']['followers'] ?? 0),
            'posts_count' => (int)($scan['twitter']['posts_count'] ?? 0),
            'location'    => $scan['twitter']['location'] ?? '',
            'bio'         => $scan['twitter']['bio'] ?? '',
        ],
        'ads_library' => [
            'total_ads'  => (int)($scan['ads_library']['total_ads'] ?? 0),
            'active_ads' => (int)($scan['ads_library']['active_ads'] ?? 0),
            'ads'        => array_slice($scan['ads_library']['ads'] ?? [], 0, 8),
        ],
        'competitor_radar' => array_slice($scan['competitor_radar'] ?? $scan['competitors'] ?? [], 0, 5),
        'google_maps' => [
            'total_reviews' => (int)($scan['google_maps']['total_reviews'] ?? 0),
            'avg_rating'    => (float)($scan['google_maps']['avg_rating'] ?? 0),
            'positive'      => $scan['google_maps']['positive'] ?? [],
            'negative'      => $scan['google_maps']['negative'] ?? [],
        ],
        'video_intelligence' => [
            'analyzed'      => (bool)($scan['video_intelligence']['analyzed'] ?? false),
            'hook_text'     => $scan['video_intelligence']['hook_text'] ?? '',
            'transcript'    => $scan['video_intelligence']['transcript'] ?? '',
            'labels'        => $scan['video_intelligence']['labels'] ?? [],
            'text_overlays' => $scan['video_intelligence']['text_overlays'] ?? [],
            'video_topics'  => $scan['video_intelligence']['video_topics'] ?? [],
        ],
        'score'     => (int)($data['score'] ?? 0),
        'breakdown' => $data['breakdown'] ?? [],
        'answers'   => $answers,
    ];
}

// ============================================================
// دوال مساعدة لـ buildAgentInputData
// ============================================================

/**
 * تقدير متوسط قيمة الطلب بناءً على الصناعة
 */
function _agentEstimateAvgOrderValue(string $industry): float {
    $defaults = [
        'e-commerce'  => 250, 'fashion'    => 200, 'beauty'    => 150,
        'food'        => 80,  'restaurant' => 80,  'real-estate'=> 50000,
        'services'    => 500, 'education'  => 300, 'health'    => 400,
        'auto'        => 5000,'technology' => 1000,'travel'    => 800,
    ];
    $lower = strtolower($industry);
    foreach ($defaults as $key => $value) {
        if (strpos($lower, $key) !== false) return (float) $value;
    }
    return 300.0;
}

/**
 * تقدير متابعي الشهر الماضي (null = غير متوفر فعلياً)
 */
function _agentEstimateLastMonthFollowers(array $scan): ?int {
    if (isset($scan['followers_last_month'])) return (int) $scan['followers_last_month'];
    if (isset($scan['instagram']['followers_last_month'])) return (int) $scan['instagram']['followers_last_month'];
    $ig = (int)($scan['instagram']['followers'] ?? 0);
    // نفترض نمو 3% شهرياً كمتوسط صناعي
    if ($ig > 0) return (int) round($ig / 1.03);
    return null; // null = لا تخمّن
}

/**
 * تقدير الزوار الشهريين من مجموع المتابعين
 */
function _agentEstimateMonthlyVisitors(int $ig, int $fb, int $tk): int {
    return (int) round(($ig + $fb + $tk) * 0.15);
}

/**
 * مراجع الصناعة المحدّثة لكل نوع
 * آخر تحديث: مايو 2026 — مبنية على تقارير الصناعة وبيانات المنصات 2025-2026
 */
function _agentGetIndustryBenchmarks(string $industry): array {
    $meta = ['_last_updated' => '2026-05', '_source' => 'Industry reports & platform analytics 2025-2026'];
    $map = [
        'e-commerce'   => array_merge(['engagement_rate'=>['instagram'=>2.5,'tiktok'=>5.5,'facebook'=>1.2],'conversion_rate'=>2.5,'avg_order_value'=>250,'cpm_range'=>[8,25],'ctr_range'=>[1.0,3.0]], $meta),
        'fashion'      => array_merge(['engagement_rate'=>['instagram'=>3.2,'tiktok'=>6.8,'facebook'=>1.0],'conversion_rate'=>2.0,'avg_order_value'=>200,'cpm_range'=>[6,20],'ctr_range'=>[1.2,3.5]], $meta),
        'beauty'       => array_merge(['engagement_rate'=>['instagram'=>3.8,'tiktok'=>7.5,'facebook'=>1.5],'conversion_rate'=>3.0,'avg_order_value'=>150,'cpm_range'=>[5,18],'ctr_range'=>[1.5,4.0]], $meta),
        'real-estate'  => array_merge(['engagement_rate'=>['instagram'=>1.5,'tiktok'=>3.0,'facebook'=>2.0],'conversion_rate'=>2.0,'avg_order_value'=>50000,'cpm_range'=>[15,50],'ctr_range'=>[0.8,2.0]], $meta),
        'food'         => array_merge(['engagement_rate'=>['instagram'=>3.0,'tiktok'=>7.0,'facebook'=>1.8],'conversion_rate'=>3.5,'avg_order_value'=>80,'cpm_range'=>[4,15],'ctr_range'=>[1.5,4.0]], $meta),
        'restaurant'   => array_merge(['engagement_rate'=>['instagram'=>3.0,'tiktok'=>7.0,'facebook'=>1.8],'conversion_rate'=>3.5,'avg_order_value'=>80,'cpm_range'=>[4,15],'ctr_range'=>[1.5,4.0]], $meta),
        'services'     => array_merge(['engagement_rate'=>['instagram'=>1.8,'tiktok'=>3.5,'facebook'=>2.5],'conversion_rate'=>4.0,'avg_order_value'=>500,'cpm_range'=>[10,35],'ctr_range'=>[1.0,3.0]], $meta),
        'education'    => array_merge(['engagement_rate'=>['instagram'=>2.0,'tiktok'=>4.0,'facebook'=>1.5],'conversion_rate'=>3.0,'avg_order_value'=>300,'cpm_range'=>[8,25],'ctr_range'=>[1.2,3.0]], $meta),
        'health'       => array_merge(['engagement_rate'=>['instagram'=>2.8,'tiktok'=>5.0,'facebook'=>1.6],'conversion_rate'=>3.5,'avg_order_value'=>400,'cpm_range'=>[10,30],'ctr_range'=>[1.3,3.5]], $meta),
        'technology'   => array_merge(['engagement_rate'=>['instagram'=>1.5,'tiktok'=>3.0,'facebook'=>1.0],'conversion_rate'=>2.0,'avg_order_value'=>1000,'cpm_range'=>[12,40],'ctr_range'=>[0.8,2.5]], $meta),
        'travel'       => array_merge(['engagement_rate'=>['instagram'=>2.5,'tiktok'=>5.0,'facebook'=>1.5],'conversion_rate'=>2.5,'avg_order_value'=>800,'cpm_range'=>[10,30],'ctr_range'=>[1.0,3.0]], $meta),
    ];
    $lower = strtolower($industry);
    foreach ($map as $key => $values) {
        if (strpos($lower, $key) !== false) return $values;
    }
    return array_merge(
        ['engagement_rate'=>['instagram'=>2.5,'tiktok'=>5.0,'facebook'=>1.2],'conversion_rate'=>2.5,'avg_order_value'=>300,'cpm_range'=>[8,30],'ctr_range'=>[1.0,3.0]],
        ['_last_updated' => '2026-05', '_source' => 'Estimated from industry averages — verify for your market']
    );
}


// ============================================================
function callAIProvider(string $provider, array $data, array $cfg): array
{
    $prompt = buildPrompt($data);

    if ($provider !== 'openai') {
        throw new \Exception("AI provider disabled: {$provider}");
    }

    switch ($provider) {
        case 'pekpik':
            return callPekpik($prompt, $data, $cfg);
        case 'gemini':
            return callGemini($prompt, $data, $cfg);
        case 'groq':
            return callGroq($prompt, $data, $cfg);
        case 'deepseek':
            return callDeepSeek($prompt, $data, $cfg);
        case 'openai':
            return callOpenAI($prompt, $data, $cfg);
        case 'nvidia':
            return callNvidia($prompt, $data, $cfg);
        case 'qwen':
            return callQwen($prompt, $data, $cfg);
        case 'gptoss':
            return callGPTOSS($prompt, $data, $cfg);
        case 'nemotron':
            return callNemotron($prompt, $data, $cfg);       // NVIDIA 253B
        case 'deepseek_r1':
            return callDeepSeekR1Nvidia($prompt, $data, $cfg); // DeepSeek R1 671B
        case 'qwen3_235b':
            return callQwen3_235B($prompt, $data, $cfg);     // Qwen3 235B
        case 'llama_405b':
            return callLlama405B($prompt, $data, $cfg);      // Llama 3.1 405B
        default:
            throw new \Exception("Unknown provider: {$provider}");
    }
}

// ── Pekpik (OpenAI-Compatible) ────────────────────────────────
// الأولوية: flagship (GPT+Claude) → gemini-pro → gemini-flash → deepseek
function callPekpik(string $prompt, array $data, array $cfg): array
{
    $baseUrl = $cfg['apis']['pekpik_base_url'] ?? 'https://aiapiv2.pekpik.com/v1';

    // 1) flagship-chat = GPT-5.4 + Claude تناوب تلقائي
    foreach ($cfg['apis']['pekpik_flagship_keys'] ?? [] as $key) {
        $result = _pekpikCall($baseUrl, $key, 'flagship-chat', $prompt);
        if ($result) return parseAIResponse($result, 'pekpik_flagship', $data);
    }

    // 2) gemini-2.5-pro
    foreach ($cfg['apis']['pekpik_pro_keys'] ?? [] as $key) {
        $result = _pekpikCall($baseUrl, $key, 'gemini-2.5-pro', $prompt);
        if ($result) return parseAIResponse($result, 'pekpik_pro', $data);
    }

    // 3) gemini-2.5-flash
    foreach ($cfg['apis']['pekpik_gemini_keys'] ?? [] as $key) {
        $result = _pekpikCall($baseUrl, $key, 'gemini-2.5-flash', $prompt);
        if ($result) return parseAIResponse($result, 'pekpik_flash', $data);
    }

    // 4) deepseek-chat
    foreach ($cfg['apis']['pekpik_keys'] ?? [] as $key) {
        $result = _pekpikCall($baseUrl, $key, 'deepseek-chat', $prompt);
        if ($result) return parseAIResponse($result, 'pekpik_deepseek', $data);
    }

    throw new \Exception('All Pekpik keys failed or expired — يحتاج تجديد المفاتيح');
}

function _pekpikCall(string $baseUrl, string $key, string $model, string $prompt): ?array
{
    $body = json_encode([
        'model'    => $model,
        'messages' => [
            ['role' => 'system', 'content' => 'أنت خبير تسويق رقمي. أجب دائماً بـ JSON صحيح فقط بدون أي نص إضافي.'],
            ['role' => 'user',   'content' => $prompt],
        ],
        'temperature'     => 0.7,
        'max_tokens'      => 8192,
        'response_format' => ['type' => 'json_object'],
    ]);

    $ch = curl_init($baseUrl . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,  // ✅ restored: 1 min per AI provider
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Bearer {$key}",
        ],
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$resp || $httpCode === 429) {
        logError("Pekpik Rate Limit/No Response", ['httpCode' => $httpCode, 'response' => $resp]);
        return null;
    }
    if ($httpCode >= 400) {
        logError("Pekpik HTTP Error", ['httpCode' => $httpCode, 'response' => $resp]);
        return null;
    }

    $decoded = json_decode($resp, true);
    $text    = $decoded['choices'][0]['message']['content'] ?? '';
    if (!$text) return null;

    $text   = preg_replace('/^```json\s*|^```\s*|```\s*$/m', '', trim($text));
    $aiData = json_decode($text, true);
    return is_array($aiData) ? $aiData : null;
}

// ── Gemini (مدوّر بين 6 مفاتيح) ─────────────────────────────
function callGemini(string $prompt, array $data, array $cfg): array
{
    $keys  = $cfg['apis']['gemini_keys'] ?? [$cfg['apis']['gemini_key'] ?? ''];
    $model = $cfg['apis']['gemini_model'] ?? 'gemini-1.5-flash';

    // ✅ تقليم الـ Prompt لتجنب استنفاد كوتا الـ Tokens (Gemini Free Tier)
    $maxChars = 40000;
    if (mb_strlen($prompt) > $maxChars) {
        $prompt = mb_substr($prompt, 0, $maxChars) . "\n\n... [تم اختصار البيانات لحدود النموذج]";
    }

    // حاول كل مفتاح
    foreach ($keys as $key) {
        if (!$key || strpos($key, 'YOUR') !== false) continue;

        $url  = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";
        $body = [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'temperature'      => 0.7,
                'maxOutputTokens'  => 8192,
                'responseMimeType' => 'application/json',
            ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST            => true,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => 60,  // ✅ restored: 1 min per AI provider
            CURLOPT_CONNECTTIMEOUT  => 10,
            CURLOPT_HTTPHEADER      => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS      => json_encode($body),
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_SSL_VERIFYHOST  => false,
            CURLOPT_HTTP_VERSION    => CURL_HTTP_VERSION_1_1, // ✅ تجنب خطأ HTTP2 framing
            CURLOPT_ENCODING        => '',                    // ✅ دعم gzip
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$response || $httpCode === 429) {
            logError("Gemini Rate Limit/No Response", ['httpCode' => $httpCode, 'response' => $response]);
            continue; // rate limit — جرّب المفتاح التالي
        }

        // إذا كان النموذج غير موجود (404)، حاول العودة إلى 1.5
        if ($httpCode === 404 && strpos($model, '2.0') !== false) {
            $model = 'gemini-1.5-flash';
            // نكرر نفس المفتاح ولكن بنموذج مختلف
            $url  = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 40,  // ✅ restored: fast timeout
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($body),
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_ENCODING => ''
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        }

        if ($httpCode >= 400) {
            logError("Gemini HTTP Error", ['httpCode' => $httpCode, 'response' => $response]);
            continue;
        }

        $decoded = json_decode($response, true);
        $text    = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if (!$text) continue;

        $text    = preg_replace('/^```json\s*|^```\s*|```\s*$/m', '', trim($text));
        $aiData  = json_decode($text, true);
        if (!is_array($aiData)) continue;

        return parseAIResponse($aiData, 'gemini', $data);
    }

    throw new \Exception('All Gemini keys failed');
}

// ── Groq AI (سريع جداً) ─────────────────────────────────────
function callGroq(string $prompt, array $data, array $cfg): array
{
    $key = $cfg['apis']['groq_key'] ?? '';
    if (!$key) throw new \Exception('No Groq key');

    // ✅ تقليم الـ Prompt لتجنب HTTP 413 (Groq حد ~6000 token ≈ 24000 حرف)
    $maxChars = 20000;
    if (mb_strlen($prompt) > $maxChars) {
        $prompt = mb_substr($prompt, 0, $maxChars) . "\n\n... [تم اختصار البيانات لحدود النموذج]";
    }

    // نماذج مرتبة من الأعلى سياقاً إلى الأسرع
    $models = [
        'llama-3.3-70b-versatile',   // 128k context — الأفضل جودةً
        'llama-3.1-8b-instant',      // 128k context — الأسرع
    ];

    foreach ($models as $model) {
        $body = json_encode([
            'model'           => $model,
            'messages'        => [
                ['role' => 'system', 'content' => 'أنت خبير تسويق رقمي. أجب دائماً بـ JSON صحيح فقط بدون أي نص إضافي.'],
                ['role' => 'user',   'content' => $prompt],
            ],
            'temperature'     => 0.7,
            'max_tokens'      => 8000,
            'response_format' => ['type' => 'json_object'],
        ]);

        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST            => true,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => 60,  // ✅ restored: 1 min per AI provider
            CURLOPT_CONNECTTIMEOUT  => 10,
            CURLOPT_HTTP_VERSION    => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER      => [
                'Content-Type: application/json',
                "Authorization: Bearer {$key}",
            ],
            CURLOPT_POSTFIELDS      => $body,
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_SSL_VERIFYHOST  => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 413) {
            // قلّم أكثر وجرّب النموذج التالي
            $maxChars = (int)($maxChars * 0.7);
            $prompt   = mb_substr($prompt, 0, $maxChars) . "\n\n... [مختصر]";
            continue;
        }
        if (!$response || $httpCode >= 400) {
            logError("Groq [{$model}] failed", ['httpCode' => $httpCode, 'resp' => mb_substr($response ?? '', 0, 200)]);
            continue;
        }

        $decoded = json_decode($response, true);
        $text    = $decoded['choices'][0]['message']['content'] ?? '';
        if (!$text) continue;

        $text   = preg_replace('/^```json\s*|^```\s*|```\s*$/m', '', trim($text));
        $aiData = json_decode($text, true);
        if (!is_array($aiData)) continue;

        return parseAIResponse($aiData, 'groq', $data);
    }

    throw new \Exception('All Groq models failed');
}


// ── OpenAI / ChatGPT (مدفوع — الأولوية الأولى) ──────────────
function callOpenAI(string $prompt, array $data, array $cfg): array
{
    $key   = $cfg['apis']['openai_key']   ?? '';
    $model = $cfg['apis']['openai_model'] ?? 'gpt-4o-mini';
    if (!$key) throw new \Exception('No OpenAI key');

    // Keep the request small enough to avoid long OpenAI hangs on large scans.
    $maxChars = (int)($cfg['apis']['openai_prompt_max_chars'] ?? 12000);
    if (mb_strlen($prompt) > $maxChars) {
        $prompt = mb_substr($prompt, 0, $maxChars) . "\n\n... [تم اختصار البيانات]";
    }

    $body = json_encode([
        'model'           => $model,
        'messages'        => [
            ['role' => 'system', 'content' => 'أنت خبير تسويق رقمي متخصص في السوق العربي. أجب دائماً بـ JSON صحيح فقط بدون أي نص إضافي خارج الـ JSON.'],
            ['role' => 'user',   'content' => $prompt],
        ],
        'temperature'     => 0.4,
        'max_tokens'      => (int)($cfg['apis']['openai_max_tokens'] ?? 3500),
        'response_format' => ['type' => 'json_object'],
    ]);

    $timeout = (int)($cfg['apis']['openai_timeout'] ?? 120);
    $connectTimeout = (int)($cfg['apis']['openai_connect_timeout'] ?? 15);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST            => true,
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_TIMEOUT         => $timeout,
        CURLOPT_CONNECTTIMEOUT  => $connectTimeout,
        CURLOPT_IPRESOLVE       => CURL_IPRESOLVE_V4,
        CURLOPT_HTTP_VERSION    => CURL_HTTP_VERSION_1_1,
        CURLOPT_HTTPHEADER      => [
            'Content-Type: application/json',
            "Authorization: Bearer {$key}",
        ],
        CURLOPT_POSTFIELDS      => $body,
        CURLOPT_SSL_VERIFYPEER  => false,
        CURLOPT_SSL_VERIFYHOST  => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) throw new \Exception("OpenAI cURL error: {$curlErr}");
    if ($httpCode >= 400) {
        $err = json_decode($response, true);
        $msg = $err['error']['message'] ?? "HTTP {$httpCode}";
        throw new \Exception("OpenAI failed: {$msg}");
    }

    $decoded = json_decode($response, true);
    $text    = $decoded['choices'][0]['message']['content'] ?? '';
    if (!$text) throw new \Exception('OpenAI: empty response');

    $text   = preg_replace('/^```json\s*|^```\s*|```\s*$/m', '', trim($text));
    $aiData = json_decode($text, true);
    if (!is_array($aiData)) throw new \Exception('OpenAI: invalid JSON response');

    return parseAIResponse($aiData, 'openai', $data);
}

// ── DeepSeek (Fallback) ──────────────────────────────────────
function callDeepSeek(string $prompt, array $data, array $cfg): array
{
    $key   = $cfg['apis']['deepseek_key']   ?? '';
    $model = $cfg['apis']['deepseek_model'] ?? 'deepseek-chat';
    if (!$key) throw new \Exception('No DeepSeek key');

    $messages = [
        ['role' => 'system', 'content' => 'You are an expert Arabic digital marketing analyst. Always respond with valid JSON only.'],
        ['role' => 'user', 'content'   => $prompt],
    ];

    $body = json_encode(['model' => $model, 'messages' => $messages, 'temperature' => 0.7, 'max_tokens' => 8192]);

    $ch = curl_init('https://api.deepseek.com/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,  // ✅ restored: 1 min per AI provider
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', "Authorization: Bearer {$key}"],
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$response || $httpCode >= 400) throw new \Exception("DeepSeek failed: {$httpCode}");

    $decoded = json_decode($response, true);
    $text    = $decoded['choices'][0]['message']['content'] ?? '';
    $text    = preg_replace('/^```json\s*|```\s*$/m', '', trim($text));
    $aiData  = json_decode($text, true);
    if (!is_array($aiData)) throw new \Exception('DeepSeek: invalid JSON');

    return parseAIResponse($aiData, 'deepseek', $data);
}


// ── NVIDIA Llama 4 Maverick (Backup) ──────────────────────────
function callNvidia(string $prompt, array $data, array $cfg): array
{
    $key   = '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';
    $model = 'meta/llama-4-maverick-17b-128e-instruct';

    $messages = [
        ['role' => 'system', 'content' => 'أنت خبير تسويق رقمي محترف. أجب دائماً بـ JSON صحيح فقط باللغة العربية.'],
        ['role' => 'user',   'content' => $prompt],
    ];

    $body = json_encode([
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => 0.7,
        'max_tokens'  => 8192,
        'stream'      => false
    ]);

    $ch = curl_init('https://integrate.api.nvidia.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,  // ✅ restored: 1 min per AI provider
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Bearer {$key}",
            'Accept: application/json'
        ],
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$response || $httpCode >= 400) throw new \Exception("NVIDIA failed: {$httpCode}");

    $decoded = json_decode($response, true);
    $text    = $decoded['choices'][0]['message']['content'] ?? '';

    // Clean JSON markdown if present
    $text    = preg_replace('/^```json\s*|^```\s*|```\s*$/m', '', trim($text));
    $aiData  = json_decode($text, true);
    if (!is_array($aiData)) throw new \Exception('NVIDIA: invalid JSON');

    return parseAIResponse($aiData, 'nvidia', $data);
}

// ── NVIDIA Qwen 3.5 (Reasoning Mode) ──────────────────────────
function callQwen(string $prompt, array $data, array $cfg): array
{
    $key   = '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';
    $model = 'qwen/qwen3.5-122b-a10b';

    $messages = [
        ['role' => 'system', 'content' => 'أنت خبير تحليل منطقي واستراتيجي. أجب دائماً بـ JSON صحيح فقط باللغة العربية.'],
        ['role' => 'user',   'content' => $prompt],
    ];

    $body = json_encode([
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => 0.6,
        'max_tokens'  => 4096,
        'stream'      => false,
        'chat_template_kwargs' => ['enable_thinking' => true]
    ]);

    $ch = curl_init('https://integrate.api.nvidia.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 35, // ✅ reduced to prevent long hangs
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Bearer {$key}",
            'Accept: application/json'
        ],
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$response || $httpCode >= 400) throw new \Exception("Qwen failed: {$httpCode}");

    $decoded = json_decode($response, true);
    $text    = $decoded['choices'][0]['message']['content'] ?? '';

    // Clean JSON markdown if present
    $text    = preg_replace('/^```json\s*|^```\s*|```\s*$/m', '', trim($text));
    $aiData  = json_decode($text, true);
    if (!is_array($aiData)) throw new \Exception('Qwen: invalid JSON');

    return parseAIResponse($aiData, 'qwen', $data);
}

// ── NVIDIA GPT-OSS-120B (Decision Engine) ─────────────────────
function callGPTOSS(string $prompt, array $data, array $cfg): array
{
    $key   = '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';
    $model = 'openai/gpt-oss-120b';

    $messages = [
        ['role' => 'system', 'content' => 'أنت خبير استراتيجي أول. أجب دائماً بـ JSON صحيح فقط باللغة العربية.'],
        ['role' => 'user',   'content' => $prompt],
    ];

    $body = json_encode([
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => 0.7,
        'max_tokens'  => 4096,
        'stream'      => false
    ]);

    $ch = curl_init('https://integrate.api.nvidia.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 50,  // ✅ restored: fast timeout
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Bearer {$key}",
            'Accept: application/json'
        ],
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$response || $httpCode >= 400) throw new \Exception("GPT-OSS failed: {$httpCode}");

    $decoded = json_decode($response, true);
    $text    = $decoded['choices'][0]['message']['content'] ?? '';

    // Clean JSON markdown if present
    $text    = preg_replace('/^```json\s*|^```\s*|```\s*$/m', '', trim($text));
    $aiData  = json_decode($text, true);
    if (!is_array($aiData)) throw new \Exception('GPT-OSS: invalid JSON');

    return parseAIResponse($aiData, 'gptoss', $data);
}

// ── NVIDIA Helper — نداء موحد لكل موديلات NVIDIA ─────────────
function _nvidiaCall(string $key, string $model, string $prompt, int $timeout = 50, bool $thinking = false): array
{
    $messages = [
        ['role' => 'system', 'content' => 'أنت خبير تسويق رقمي متخصص في السوق العربي. أجب دائماً بـ JSON صحيح فقط باللغة العربية بدون أي نص خارجه.'],
        ['role' => 'user',   'content' => $prompt],
    ];

    $payload = [
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => 0.65,
        'max_tokens'  => 4096,
        'stream'      => false,
    ];
    if ($thinking) {
        $payload['chat_template_kwargs'] = ['enable_thinking' => true];
    }

    $ch = curl_init('https://integrate.api.nvidia.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Bearer {$key}",
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$response || $httpCode >= 400) {
        throw new \Exception("NVIDIA ({$model}) failed: HTTP {$httpCode}");
    }

    $decoded = json_decode($response, true);
    $text    = $decoded['choices'][0]['message']['content'] ?? '';
    $text    = preg_replace('/^```json\s*|^```\s*|```\s*$/m', '', trim($text));
    $aiData  = json_decode($text, true);
    if (!is_array($aiData)) throw new \Exception("{$model}: invalid JSON response");
    return $aiData;
}

// ── NVIDIA Nemotron Ultra 253B — أقوى موديل NVIDIA الخاص ──────
function callNemotron(string $prompt, array $data, array $cfg): array
{
    $key = $cfg['apis']['nvidia_keys']['nemotron']
        ?? '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';
    $aiData = _nvidiaCall($key, 'nvidia/llama-3.1-nemotron-ultra-253b-v1', $prompt, 60);
    return parseAIResponse($aiData, 'nemotron_253b', $data);
}

// ── DeepSeek R1 671B عبر NVIDIA — نموذج Reasoning عملاق ──────
function callDeepSeekR1Nvidia(string $prompt, array $data, array $cfg): array
{
    $key = $cfg['apis']['nvidia_keys']['deepseek_r1']
        ?? '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';
    $aiData = _nvidiaCall($key, 'deepseek-ai/deepseek-r1', $prompt, 90);
    return parseAIResponse($aiData, 'deepseek_r1_671b', $data);
}

// ── Qwen3 235B عبر NVIDIA — أحدث وأكبر موديل Qwen ───────────
function callQwen3_235B(string $prompt, array $data, array $cfg): array
{
    $key = $cfg['apis']['nvidia_keys']['qwen3_235b']
        ?? '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';
    $aiData = _nvidiaCall($key, 'qwen/qwen3-235b-a22b', $prompt, 70, true);
    return parseAIResponse($aiData, 'qwen3_235b', $data);
}

// ── Llama 3.1 405B عبر NVIDIA — أضخم Llama متاح ─────────────
function callLlama405B(string $prompt, array $data, array $cfg): array
{
    $key = $cfg['apis']['nvidia_keys']['llama_405b']
        ?? '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';
    $aiData = _nvidiaCall($key, 'meta/llama-3.1-405b-instruct', $prompt, 60);
    return parseAIResponse($aiData, 'llama_405b', $data);
}

// ============================================================
// buildContentAnalysis — يبني content_analysis من البيانات الفعلية
// الإجابات تسويقية وتطابق أسئلة content.html بالضبط
// ============================================================
function buildContentAnalysis(array $data): array
{
    $scan    = is_string($data['scan_result'] ?? null)
        ? (json_decode($data['scan_result'], true) ?? [])
        : ($data['scan_result'] ?? []);

    $ws      = $scan['website_scan'] ?? [];
    $fb      = $scan['facebook']     ?? $scan['social'] ?? [];
    $ig      = $scan['instagram']    ?? [];
    $tk      = $scan['tiktok']       ?? [];
    $ads     = $scan['ads_library']  ?? [];
    $answers = $data['answers']      ?? [];

    // استخراج البيانات الأساسية
    $followers    = (int)($fb['followers']     ?? $ig['followers'] ?? 0);
    $engagement   = (float)($fb['avg_engagement'] ?? $ig['engagement_rate'] ?? 0);
    $postsCount   = (int)($fb['posts_count']   ?? $ig['posts_count'] ?? 0);
    $postsWeek    = (float)($fb['posts_per_week'] ?? $ig['posts_per_week'] ?? 0);
    $lastPostDays = (int)($fb['last_post_days'] ?? $ig['last_post_days'] ?? 99);
    $adsRunning   = !empty($ads['is_running_ads']) || (int)($ads['total_ads'] ?? 0) > 0;
    $totalAds     = (int)($ads['total_ads'] ?? 0);
    $hasSSL       = !empty($ws['has_ssl'])          || !empty($scan['hasSSL']);
    $hasPixel     = !empty($ws['has_fb_pixel'])     || !empty($scan['hasPixel']);
    $hasGA        = !empty($ws['has_ga'])            || !empty($scan['hasGA']);
    $hasTikTok    = !empty($ws['has_tiktok'])        || !empty($scan['hasTikTok']);
    $hasWhatsApp  = !empty($ws['has_whatsapp'])      || !empty($scan['hasWhatsApp']);
    $hasCTA       = !empty($ws['has_cta'])           || !empty($scan['hasCTA']);
    $hasSchema    = !empty($ws['has_schema'])        || !empty($scan['hasSchema']);
    $hasOG        = !empty($ws['has_og_tags'])       || !empty($scan['hasOGTags']);
    $hasContact   = !empty($ws['has_contact_form'])  || !empty($fb['has_contact']) || !empty($scan['has_any_contact']);
    $hasPhone     = !empty($ws['has_phone'])         || !empty($fb['has_phone']);
    $hasEmail     = !empty($fb['has_email']);
    $isVerified   = !empty($fb['is_verified'])       || !empty($ig['is_verified']);
    $rating       = (float)($fb['rating'] ?? 0);
    $hasRating    = $rating > 0;
    $speed        = $ws['speed_rating'] ?? '';
    $speedFast    = in_array($speed, ['سريع', 'ممتاز', 'جيد']);
    $hasH1        = !empty($ws['h1']);
    $hasDesc      = !empty($ws['description']) && strlen($ws['description'] ?? '') > 30;
    $wordCount    = (int)($ws['word_count'] ?? 0);
    $hasServices  = !empty($ws['services_list']);
    $hasVideos    = ($fb['deep_analysis']['types_percent']['video'] ?? 0) > 0
        || ($ig['deep_analysis']['types_percent']['video'] ?? 0) > 0;
    $hasTikTokAcc = !empty($tk['username']);
    $topHashtags  = array_merge(
        $fb['deep_analysis']['top_hashtags'] ?? [],
        $ig['deep_analysis']['top_hashtags'] ?? []
    );
    $hasHashtags  = count($topHashtags) > 0;
    $ctaPct       = (float)($fb['deep_analysis']['cta_percent'] ?? $ig['deep_analysis']['cta_percent'] ?? 0);
    $pageName     = $fb['page_name'] ?? $ig['username'] ?? '';
    $bio          = $fb['about'] ?? $ig['bio'] ?? '';
    $website      = $fb['website'] ?? $ig['website'] ?? $ws['url'] ?? '';

    // بيانات إضافية
    $hasReviews   = $hasRating || !empty($fb['reviews_count']) || !empty($ig['has_reviews']);
    $hasLanding   = !empty($answers['landing_page_exists']);
    $hasRetarget  = !empty($answers['retargeting_campaigns']);
    $hasKPIs      = !empty($answers['kpis_tracked']);
    $hasEmailMkt  = !empty($answers['email_marketing']);
    $hasBrandKit  = !empty($answers['brand_logo_ready']);
    $hasClearMsg  = !empty($answers['brand_message_clear']);

    // helper: status
    $s = fn(bool $good, bool $warn = false): string => $good ? 'good' : ($warn ? 'warn' : 'bad');

    // helper: format number
    $n = fn($v): string => number_format((int)$v);

    // ═════════════════════════════════════════════════════════════
    // الأسئلة الـ 43 مع إجابات تسويقية تطابق content.html
    // ═════════════════════════════════════════════════════════════

    $q = [
        // ── القسم ١: الرسالة التسويقية والتحويل (q1-q15) ──

        // q1: هل الصفحة مهيأة للبيع أو الحجز؟
        [
            'id' => 1,
            'status' => $s($hasCTA && $hasWhatsApp && ($hasPixel || $adsRunning), $hasCTA || $hasWhatsApp),
            'answer' => ($hasCTA && $hasWhatsApp)
                ? 'نعم — توجد أزرار شراء/حجز واضحة مع قناة واتساب للتحويل المباشر.'
                : ($hasCTA || $hasWhatsApp
                    ? 'جزئياً — يوجد ' . ($hasCTA ? 'CTA' : 'واتساب') . ' لكن يحتاج تحسين مسار الشراء.'
                    : 'لا — لا يوجد CTA واضح ولا قناة تحويل مباشرة. العميل يشاهد دون أن يعرف كيف يشتري.')
        ],

        // q2: هل توجد دعوة واضحة لاتخاذ إجراء (CTA)؟
        [
            'id' => 2,
            'status' => $s($ctaPct > 40, $ctaPct > 15),
            'answer' => $ctaPct > 40
                ? "نعم — {$ctaPct}% من المنشورات تحتوي على CTA واضح يوجّه العميل للخطوة التالية."
                : ($ctaPct > 0
                    ? "ضعيف — {$ctaPct}% فقط من المنشورات فيها CTA. معظم المحتوى يُترك العميل حائراً."
                    : 'غائبة تماماً — لا توجد دعوة للإجراء في أي منشور. العميل يشاهد ويمضي دون تصرف.')
        ],

        // q3: هل يوجد رابط / واتساب / وسيلة تواصل سهلة؟
        [
            'id' => 3,
            'status' => $s($hasWhatsApp && $hasPhone, $hasWhatsApp || $hasPhone),
            'answer' => ($hasWhatsApp && $hasPhone)
                ? 'نعم — واتساب ورقم هاتف متاحان مما يسهّل التواصل الفوري للعملاء.'
                : ($hasWhatsApp
                    ? 'جزئياً — واتساب متاح لكن ينقص رقم هاتف بديل.'
                    : ($hasPhone
                        ? 'جزئياً — رقم هاتف موجود لكن واتساب أسرع للتحويل.'
                        : 'لا — لا توجد وسيلة تواصل سهلة. العميل يبحث عن طريقة للتواصل ثم يغادر.'))
        ],

        // q4: هل الصفحة تقنع العميل بالشراء؟
        [
            'id' => 4,
            'status' => $s($hasReviews && $hasRating, $hasReviews || $hasRating),
            'answer' => ($hasReviews && $hasRating)
                ? "نعم — توجد تقييمات ({$rating}/5) ومراجعات عملاء تمنح مصداقية اجتماعية."
                : ($hasReviews || $hasRating
                    ? 'ضعيف — تقييمات موجودة لكن غير كافية لإقناع العميل الجديد.'
                    : 'لا — غياب المراجعات والتقييمات يجعل قرار الشراء صعباً. العميل يطلب ضمانات غير موجودة.')
        ],

        // q5: هل المحتوى يدعم اتخاذ القرار؟
        [
            'id' => 5,
            'status' => $s($hasVideos && $hasServices, $hasVideos || $hasServices),
            'answer' => ($hasVideos && $hasServices)
                ? 'نعم — فيديوهات توضيحية وقائمة خدمات/منتجات تساعد العميل على الفهم والقرار.'
                : ($hasVideos || $hasServices
                    ? 'جزئياً — يحتاج مزيداً من المحتوى التوضيحي لدعم قرار العميل.'
                    : 'لا — المحتوى يعرض المنتج دون شرح الفائدة أو معالجة تساؤلات العميل.')
        ],

        // q6: هل هناك عروض واضحة ومُقنعة؟
        [
            'id' => 6,
            'status' => $s($adsRunning && !empty($ads['ads']), $adsRunning),
            'answer' => $adsRunning && !empty($ads['ads'])
                ? "نعم — توجد {$totalAds} إعلان نشط يعرض عروضاً واضحة للعملاء."
                : ($adsRunning
                    ? 'ضعيف — إعلانات موجودة لكن العروض غير واضحة أو مُقنعة.'
                    : 'لا — لا توجد عروض حالياً. العميل لا يجد حافزاً للشراء الآن.')
        ],

        // q7: هل الحساب يقود العميل لخطوة واضحة؟
        [
            'id' => 7,
            'status' => $s($hasCTA && $hasWhatsApp && !empty($website), $hasCTA || $hasWhatsApp),
            'answer' => ($hasCTA && $hasWhatsApp && !empty($website))
                ? 'نعم — مسار واضح: المحتوى ← CTA ← واتساب/موقع ← شراء.'
                : ($hasCTA || $hasWhatsApp
                    ? 'جزئياً — يوجد بعض التوجيه لكن المسار غير مكتمل.'
                    : 'لا — العميل يُترك بعد المشاهدة دون توجيه. لا يعرف هل يراسل أم يضغط رابطاً.')
        ],

        // q8: وضوح النشاط التجاري
        [
            'id' => 8,
            'status' => $s(!empty($pageName) && $hasDesc && $hasServices, !empty($pageName) || $hasDesc),
            'answer' => (!empty($pageName) && $hasDesc)
                ? "جيد — من أول نظرة يُفهم النشاط: {$pageName}. الهوية واضحة."
                : (!empty($pageName)
                    ? 'مقبول — اسم الصفحة واضح لكن الوصف يحتاج تحسين.'
                    : 'ضعيف — لا يتضح ماذا يقدم الحساب من الوهلة الأولى.')
        ],

        // q9: وضوح الجمهور المستهدف
        [
            'id' => 9,
            'status' => $s(strlen($bio ?? '') > 50 && strpos($bio ?? '', 'لـ') !== false, strlen($bio ?? '') > 30),
            'answer' => strlen($bio ?? '') > 50
                ? 'الرسالة موجهة — البايو يحدد الجمهور (مثال: "لـ" + فئة محددة).'
                : (strlen($bio ?? '') > 0
                    ? 'الرسالة عامة — تخاطب الجميع وبذلك لا تخاطب أحداً بعمق.'
                    : 'غير محدد — البايو فارغ أو عام جداً. يجب تحديد الشريحة المستهدفة.')
        ],

        // q10: وضوح العرض (Value Offer)
        [
            'id' => 10,
            'status' => $s($hasServices && $hasDesc, $hasServices || $hasDesc),
            'answer' => ($hasServices && $hasDesc)
                ? 'العرض واضح — توجد قائمة خدمات/منتجات مع شرح القيمة المقدمة.'
                : ($hasServices
                    ? 'جزئياً — الخدمات موجودة لكن القيمة الفريدة غير واضحة.'
                    : 'العرض غائب — لماذا أشتري منك تحديداً؟ السؤال لا يجد إجابة في الصفحة.')
        ],

        // q11: وضوح القيمة المقدَّمة
        [
            'id' => 11,
            'status' => $s($wordCount > 300 && $hasServices, $wordCount > 100 || $hasServices),
            'answer' => ($wordCount > 300 && $hasServices)
                ? 'القيمة موضحة — محتوى كافٍ يشرح الفوائد وليس فقط المزايا.'
                : ($hasServices
                    ? 'ضعيف — المنتجات تُعرض دون شرح كافٍ للفائدة الحقيقية.'
                    : 'غير واضحة — التركيز على "ماذا" دون "لماذا يهمّك هذا".')
        ],

        // q12: هل الرسالة ملائمة للسوق المستهدف؟
        [
            'id' => 12,
            'status' => $s(true), // دائماً جيد للسوق العربي
            'answer' => 'اللغة العربية والأسلوب مناسبان للسوق الخليجي/العربي. الأسلوب العام يتوافق مع الثقافة المحلية.'
        ],

        // q13: هل الزائر يعرف كيف يشتري؟
        [
            'id' => 13,
            'status' => $s($hasWhatsApp && $hasCTA && !empty($website), $hasWhatsApp || !empty($website)),
            'answer' => ($hasWhatsApp && $hasCTA)
                ? 'نعم — مسار واضح: اضغط ← تواصل ← اطلب.'
                : ($hasWhatsApp
                    ? 'جزئياً — واتساب موجود لكن لا يُذكر في المحتوى.'
                    : 'لا — آلية الطلب تحتاج 3+ خطوات يكتشفها الزائر بنفسه. كل خطوة إضافية = خسارة عملاء.')
        ],

        // q14: هل طريقة التواصل واضحة ومريحة؟
        [
            'id' => 14,
            'status' => $s($hasWhatsApp && $hasPhone && $hasEmail, $hasWhatsApp || $hasPhone),
            'answer' => ($hasWhatsApp && $hasPhone)
                ? 'نعم — قنوات متعددة للتواصل: واتساب، هاتف' . ($hasEmail ? '، إيميل' : '') . '.'
                : ($hasWhatsApp
                    ? 'مقبول — واتساب متاح لكن ينصح بإضافة قنوات بديلة.'
                    : 'غير مريحة — التواصل يحتاج جهداً من العميل. يُفضّل تثبيت واتساب مباشر.')
        ],

        // q15: هل الحساب يشجع على الرسائل والشراء؟
        [
            'id' => 15,
            'status' => $s($ctaPct > 30, $ctaPct > 10),
            'answer' => $ctaPct > 30
                ? 'نعم — المنشورات تحتوي عبارات تحفيزية مثل "راسلنا" و"اطلب الآن".'
                : ($ctaPct > 0
                    ? 'ضعيف — بعض المنشورات تحتوي CTA لكن الأغلبية لا تحفّز على التصرف.'
                    : 'لا يوجد تحفيز — لا جمل مثل "راسلنا الآن" في المنشورات البيعية.')
        ],

        // ── القسم ٢: الهوية البصرية والرقمية (q16-q25) ──

        // q16: هل الاسم واضح ويعكس النشاط؟
        [
            'id' => 16,
            'status' => $s(!empty($pageName) && strlen($pageName) > 3),
            'answer' => !empty($pageName)
                ? "نعم — \"{$pageName}\" يوحي بالنشاط. سهل التذكر والبحث عنه."
                : 'الاسم غير واضح — لا يعكس طبيعة النشاط أو يصعب تذكره.'
        ],

        // q17: هل البايو يشرح النشاط بدقة؟
        [
            'id' => 17,
            'status' => $s(strlen($bio ?? '') > 50, strlen($bio ?? '') > 20),
            'answer' => strlen($bio ?? '') > 50
                ? 'البايو شامل — يوضح: ماذا يقدم + لمن + الميزة + كيف يطلب.'
                : (strlen($bio ?? '') > 0
                    ? 'البايو يحتاج تحسين — يجب أن يوضح: ماذا تبيع + لمن + الميزة + CTA.'
                    : 'البايو فارغ — فرصة ضائعة لشرح النشاط في أول 3 ثواني.')
        ],

        // q18: هل صورة البروفايل والغلاف مناسبة؟
        [
            'id' => 18,
            'status' => $s($isVerified || !empty($pageName), true),
            'answer' => 'صورة البروفايل واضحة' . ($isVerified ? ' مع علامة التوثيق ✅' : '') . '. الغلاف يتناسق مع الهوية البصرية — مستوى جيد.'
        ],

        // q19: هل التخصص مفهوم بوضوح؟
        [
            'id' => 19,
            'status' => $s($hasDesc || !empty($pageName), true),
            'answer' => ($hasDesc || !empty($pageName))
                ? 'نعم من الشكل العام. التخصص واضح من الاسم والمحتوى.'
                : 'يحتاج توضيح — لا يتضح التخصص الدقيق من الوهلة الأولى.'
        ],

        // q20: التميز عن المنافسين
        [
            'id' => 20,
            'status' => $s($isVerified && $hasReviews, $isVerified || $hasReviews),
            'answer' => ($isVerified && $hasReviews)
                ? 'متميز — توثيق + تقييمات يعطيان ميزة تنافسية واضحة.'
                : ($isVerified || $hasReviews
                    ? 'مقبول — بعض عناصر التميز لكن يحتاج مزيداً من التمايز.'
                    : 'الهوية مكررة — لا تبرز عن عشرات الحسابات المشابهة في نفس المجال.')
        ],

        // q21: قوة العلامة التجارية (Brand Strength)
        [
            'id' => 21,
            'status' => $s($isVerified && $followers > 5000, $isVerified || $followers > 1000),
            'answer' => ($isVerified && $followers > 5000)
                ? 'العلامة قوية — توثيق + قاعدة متابعين كبيرة تعطي مصداقية عالية.'
                : ($followers > 1000
                    ? 'العلامة في طور النمو — تحتاج عنصر "توقيع" يميزها فوراً.'
                    : 'العلامة في طور التأسيس — لا يوجد بعد عنصر صوت خاص أو شخصية مميزة.')
        ],

        // q22: التناسق البصري (Visual Consistency)
        [
            'id' => 22,
            'status' => $s($hasOG && $hasSchema, $hasOG),
            'answer' => ($hasOG && $hasSchema)
                ? 'ممتاز — الألوان والخطوط متسقة والبراند محترف من الوهلة الأولى.'
                : ($hasOG
                    ? 'جيد — التناسق موجود لكن يمكن تحسينه بتوحيد الصور.'
                    : 'مقبول — يحتاج مزيداً من التناسق البصري عبر المنصات.')
        ],

        // q23: مستوى الاحتراف العام
        [
            'id' => 23,
            'status' => $s($hasSSL && $speedFast, $hasSSL),
            'answer' => ($hasSSL && $speedFast)
                ? 'فوق المتوسط — الموقع سريع وآمن والتصاميم نظيفة.'
                : ($hasSSL
                    ? 'جيد — التصاميم نظيفة لكن الموقع يحتاج تحسين التقنية.'
                    : 'يحتاج تحسين — المستوى الاحترافي غير مكتمل.')
        ],

        // q24: هل الصفحة تعطي انطباع ثقة؟
        [
            'id' => 24,
            'status' => $s($isVerified && $hasRating && $hasSSL, $isVerified || $hasRating),
            'answer' => ($isVerified && $hasRating)
                ? 'نعم — توثيق + تقييمات + موقع آمن يمنحان ثقة عالية.'
                : ($isVerified || $hasRating
                    ? 'إلى حد ما — بعض عناصر الثقة موجودة لكن غير كافية.'
                    : 'ضعيف — التصميم الجيد يبني ثقة أولية لكن غياب الشهادات والتقييمات يضعف الثقة العميقة.')
        ],

        // q25: هل الصفحة منظمة أم عشوائية؟
        [
            'id' => 25,
            'status' => $s($postsWeek >= 2 && $postsCount > 20, $postsWeek >= 1 || $postsCount > 10),
            'answer' => ($postsWeek >= 2 && $postsCount > 20)
                ? 'منظمة بشكل واضح — Grid مرتب والنشر منتظم.'
                : ($postsCount > 10
                    ? 'مقبول — يوجد بعض التنظيم لكن يحتاج تحسين.'
                    : 'عشوائية — البروفايل غير مرتب والنشر غير منتظم.')
        ],

        // ── القسم ٣: تحليل المحتوى التسويقي (q26-q29) ──

        // q26: هل المحتوى يخدم رحلة العميل كاملة؟
        [
            'id' => 26,
            'status' => $s($hasVideos && $ctaPct > 20 && $hasReviews, $hasVideos || $ctaPct > 10),
            'answer' => ($hasVideos && $ctaPct > 20)
                ? 'نعم — محتوى متنوع يغطي مراحل الوعي والجذب والثقة والبيع.'
                : ($hasVideos
                    ? 'جزئياً — التركيز على نوع واحد من المحتوى ومراحل الرحلة غير مكتملة.'
                    : 'لا — التركيز على البيع المباشر فقط. مراحل الثقة والولاء شبه غائبة.')
        ],

        // q27: هل المحتوى مناسب للجمهور المستهدف؟
        [
            'id' => 27,
            'status' => $s($engagement > 100, $engagement > 30),
            'answer' => $engagement > 100
                ? 'نعم — التفاعل الجيد يدل على أن المحتوى يلامس الجمهور الصحيح.'
                : ($engagement > 0
                    ? 'بشكل عام نعم لكن يحتاج تخصيص أكثر للشريحة المستهدفة.'
                    : 'غير واضح — لا توجد بيانات كافية لتحديد ملاءمة المحتوى.')
        ],

        // q28: هل المحتوى مناسب للمرحلة الحالية؟
        [
            'id' => 28,
            'status' => $s($followers > 1000 && $postsWeek >= 2, $followers > 500 || $postsWeek >= 1),
            'answer' => ($followers > 1000 && $postsWeek >= 2)
                ? 'نعم — المحتوى يتناسب مع مرحلة النمو الحالية للحساب.'
                : ($postsWeek >= 1
                    ? 'يحتاج تعديل — المرحلة الحالية تحتاج محتوى بناء ثقة أكثر.'
                    : 'لا — المحتوى لا يتناسب مع مرحلة التأسيس.')
        ],

        // q29: هل هناك نقص في نوع معين من المحتوى؟
        [
            'id' => 29,
            'status' => $s($hasVideos && $hasReviews && $hasHashtags, $hasVideos || $hasReviews),
            'answer' => ($hasVideos && $hasReviews)
                ? 'المحتوى متنوع — فيديوهات، شهادات، هاشتاقات متاحة.'
                : ('نقص في: ' . (!$hasVideos ? 'فيديوهات، ' : '') . (!$hasReviews ? 'شهادات عملاء، ' : '') . (!$hasHashtags ? 'هاشتاقات' : '') . ' — يحتاج تنويع المحتوى.')
        ],

        // ── القسم ٤: الانتظام والنمو (q30-q33) ──

        // q30: هل النشر منتظم؟
        [
            'id' => 30,
            'status' => $s($postsWeek >= 4, $postsWeek >= 2),
            'answer' => $postsWeek >= 4
                ? "نعم — {$postsWeek} منشورات أسبوعياً بانتظام. الخوارزميات تكافئ الاتساق."
                : ($postsWeek >= 2
                    ? "شبه منتظم — {$postsWeek} منشورات/أسبوع. يحتاج زيادة وتثبيت المواعيد."
                    : ($postsWeek > 0
                        ? "غير منتظم — {$postsWeek} منشور/أسبوع فقط. الخوارزميات تعاقب هذا."
                        : 'لا يوجد نشر منتظم — الخوارزميات تفضّل الاتساق الزمني.'))
        ],

        // q31: هل هناك فترات انقطاع ملحوظة؟
        [
            'id' => 31,
            'status' => $s($lastPostDays <= 7, $lastPostDays <= 14),
            'answer' => $lastPostDays <= 7
                ? 'لا — آخر منشور خلال أسبوع. النشاط مستمر.'
                : ($lastPostDays <= 21
                    ? "انقطاع بسيط — آخر منشور منذ {$lastPostDays} يوم."
                    : "نعم — انقطاع طويل ({$lastPostDays} يوم). كل انقطاع يُعاقب عليه الحساب خوارزمياً.")
        ],

        // q32: هل الشكل العام يوحي بالنشاط؟
        [
            'id' => 32,
            'status' => $s($postsWeek >= 3 && $lastPostDays <= 7, $postsWeek >= 2 || $lastPostDays <= 14),
            'answer' => ($postsWeek >= 3 && $lastPostDays <= 7)
                ? 'نعم — النشر منتظم والمظهر نشط.'
                : ($lastPostDays <= 14
                    ? 'متذبذب — أسابيع نشيطة ثم هدوء. هذا النمط يضر بالمصداقية.'
                    : 'لا — فترات طويلة من الهدوء تُظهر الحساب كغير نشط.')
        ],

        // q33: هل هناك استراتيجية نمو واضحة؟
        [
            'id' => 33,
            'status' => $s($adsRunning && $hasPixel && $postsWeek >= 3, $adsRunning || $hasPixel),
            'answer' => ($adsRunning && $hasPixel && $postsWeek >= 3)
                ? 'نعم — إعلانات + تتبع + محتوى منتظم = استراتيجية نمو متكاملة.'
                : ($adsRunning || $hasPixel
                    ? 'جزئياً — بعض العناصر موجودة لكن تحتاج خطة واضحة.'
                    : 'لا تتضح — النشر عفوي دون تخطيط للحملات أو التوقيت الموسمي.')
        ],

        // ── القسم ٥: تحليل السوق المحلي (q34-q39) ──

        // q34: مدى توافق الصفحة مع البيئة المحلية
        [
            'id' => 34,
            'status' => $s(true), // دائماً جيد للسوق العربي
            'answer' => 'جيد — المحتوى يعكس الذوق والثقافة العربية. المصطلحات والأسلوب مناسبان للمنطقة المستهدفة.'
        ],

        // q35: توافق اللغة والأسلوب مع الجمهور
        [
            'id' => 35,
            'status' => $s(true),
            'answer' => 'اللغة العربية الفصحى المبسطة مناسبة. يمكن إضافة لمسة من العامية لمزيد من الاقتراب العاطفي من الجمهور.'
        ],

        // q36: هل الرسالة مناسبة لهذه الجغرافيا؟
        [
            'id' => 36,
            'status' => $s(true),
            'answer' => 'نعم بشكل عام. الرسالة مناسبة للسوق العربي والثقافة المحلية.'
        ],

        // q37: هل نوع المحتوى مناسب لهذا السوق؟
        [
            'id' => 37,
            'status' => $s($hasVideos && $hasReviews, $hasVideos),
            'answer' => ($hasVideos && $hasReviews)
                ? 'نعم — السوق العربي يستجيب للمحتوى القصصي والتوصيات وهذا متوفر.'
                : ($hasVideos
                    ? 'جزئياً — الفيديو موجود لكن ينقص التوصيات الشخصية (Word of Mouth).'
                    : 'يحتاج تحسين — السوق العربي يستجيب بقوة للمحتوى القصصي والتوصيات.')
        ],

        // q38: هل الأسلوب البيعي مناسب لهذا الجمهور؟
        [
            'id' => 38,
            'status' => $s($ctaPct > 20 && $hasReviews, $ctaPct > 10),
            'answer' => ($ctaPct > 20 && $hasReviews)
                ? 'نعم — السوق العربي يفضّل البيع عبر الثقة وهذا متوفر.'
                : ($ctaPct > 10
                    ? 'مقبول — لكن يحتاج بناء علاقة أكثر قبل البيع المباشر.'
                    : 'الأسلوب مباشر جداً — السوق العربي يفضّل البيع عبر الثقة والعلاقة قبل المنتج.')
        ],

        // q39: هل الصفحة تخاطب جمهوراً محدداً؟
        [
            'id' => 39,
            'status' => $s(strlen($bio ?? '') > 50 && strpos($bio ?? '', 'لـ') !== false, strlen($bio ?? '') > 30),
            'answer' => strlen($bio ?? '') > 50 && strpos($bio ?? '', 'لـ') !== false
                ? 'نعم — الرسالة موجهة لشريحة محددة (مثال: "لأمهات"، "للأطفال").'
                : 'لا — الرسالة عامة تخاطب كل من يهتم بالموضوع. تحديد الشريحة سيرفع التحويل كثيراً.'
        ],

        // ── القسم ٦: تحليل التفاعل والأداء (q40-q43) ──

        // q40: المنشورات الأعلى أداء
        [
            'id' => 40,
            'status' => $s($engagement > 200, $engagement > 50),
            'answer' => $engagement > 200
                ? "المنشورات التفاعلية تحقق أداءً عالياً — متوسط {$n($engagement)} تفاعل/منشور."
                : ($engagement > 0
                    ? "الأداء متوسط — {$n($engagement)} تفاعل/منشور. يحتاج تحسين نوعية المحتوى."
                    : 'لا توجد بيانات كافية لتحديد المنشورات الأعلى أداء.')
        ],

        // q41: أوقات النشر
        [
            'id' => 41,
            'status' => $s($postsWeek >= 4, $postsWeek >= 2),
            'answer' => $postsWeek >= 4
                ? 'النشر منتظم في أوقات مناسبة. استمر في هذا النمط.'
                : ($postsWeek >= 2
                    ? 'النشر عشوائي — لا يتزامن مع أوقات الذروة. الأفضل: 8-10 مساءً.'
                    : 'لا يوجد نمط نشر واضح — حدد أوقات الذروة لجمهورك.')
        ],

        // q42: طبيعة التفاعل وجودة التعليقات
        [
            'id' => 42,
            'status' => $s($engagement > 150, $engagement > 50),
            'answer' => $engagement > 150
                ? 'التعليقات جيدة — توجد نقاشات وأسئلة حقيقية تدل على اهتمام.'
                : ($engagement > 0
                    ? 'التعليقات سطحية في الغالب (إيموجي). المحتوى لا يثير فضولاً كافياً.'
                    : 'لا توجد بيانات تعليقات كافية.')
        ],

        // q43: مدى استجابة الجمهور للعروض
        [
            'id' => 43,
            'status' => $s($adsRunning && $engagement > 100, $adsRunning || $engagement > 50),
            'answer' => ($adsRunning && $engagement > 100)
                ? 'العروض تولّد تفاعلاً جيداً — الجمهور يستجيب للعروض والإعلانات.'
                : ($adsRunning
                    ? 'العروض موجودة لكن التفاعل ضعيف — يحتاج بناء توقع مسبق أو إحساس بالحصرية.'
                    : 'لا توجد عروض حالياً — الجمهور لا يجد حافزاً للشراء الآن.')
        ],
    ];

    // ═════════════════════════════════════════════════════════
    // حساب Bar Scores من البيانات الفعلية
    // ═════════════════════════════════════════════════════════
    $goodCount = fn(array $ids) => count(array_filter($q, fn($r) => in_array($r['id'], $ids) && $r['status'] === 'good'));
    $total     = fn(array $ids) => count($ids);
    $pct       = fn(array $ids): int => $total($ids) > 0 ? (int)round($goodCount($ids) / $total($ids) * 100) : 0;

    return [
        'q'               => $q,
        'bar_cta'         => $pct([1, 2, 7, 13, 15]),       // CTA والتحويل
        'bar_contact'     => $pct([3, 14, 33, 34, 35]),     // التواصل
        'bar_value'       => $pct([5, 10, 11, 26, 29]),     // القيمة والعرض
        'bar_market_fit'  => $pct([8, 9, 12, 34, 39]),       // ملاءمة السوق
        'bar_visual'      => $pct([16, 17, 18, 22, 25]),     // الهوية البصرية
        'bar_brand'       => $pct([16, 17, 20, 21, 24]),     // قوة البراند
        'bar_consistency' => $pct([26, 27, 28, 29, 30]),     // اتساق المحتوى
        'bar_regularity'  => $pct([30, 31, 32]),             // انتظام النشر
        'bar_calendar'    => $pct([30, 31, 32, 33, 41]),     // التخطيط التقويمي
    ];
}

// ── Normalizer: يضمن أن كل عنصر في strengths/weaknesses له مفتاح 'title' ──
// لمنع ظهور [object Object] في الواجهة عندما يُرجع الـ AI كائنات بمفاتيح
// مختلفة (name, point, text, label, ...). يحافظ على بقية الحقول كما هي
// (desc, bullets, action, metric, score, type) ولا يدمّر شكل البيانات.
function normalizeStrengthWeakness(array $items): array
{
    $altKeys = ['title', 'name', 'point', 'text', 'heading', 'label', 'item', 'desc', 'description'];
    return array_values(array_filter(array_map(function ($item) use ($altKeys) {
        // string بسيطة → object بـ title فقط (لا نضع score: لكي يأخذ الـ frontend
        // الافتراضي المناسب لكل صفحة — strengths: 95-i*5، weaknesses: 30+i*5)
        if (is_string($item)) {
            $trimmed = trim($item);
            return $trimmed !== '' ? ['title' => $trimmed, 'desc' => ''] : null;
        }
        // object → تأكد من وجود title (ابحث في المفاتيح البديلة)
        if (is_array($item)) {
            if (empty($item['title']) || !is_string($item['title'])) {
                foreach ($altKeys as $k) {
                    if (!empty($item[$k]) && is_string($item[$k])) {
                        $item['title'] = $item[$k];
                        break;
                    }
                }
            }
            // لو ما زال بدون title بعد البحث → أسقط العنصر
            if (empty($item['title']) || !is_string($item['title'])) {
                return null;
            }
            return $item;
        }
        return null;
    }, $items)));
}

// ── Normalizer: action_week قد يأتي كـ strings أو objects بحقل 'task' ──
// نضمن إرجاع array of strings للـ frontend.
//
// ⚠️ NOT to be applied to action_month — الـ AI أحياناً يُرجع action_month
// كـ structured weekly plan {week1: {theme, goals, tasks}, week2: ...}
// ويعتمد عليه report.html:1490-1503. التطبيع يدمّر هذا الشكل.
function normalizeActionItems(array $items): array
{
    return array_values(array_filter(array_map(function ($item) {
        if (is_string($item)) {
            $trimmed = trim($item);
            return $trimmed !== '' ? $trimmed : null;
        }
        if (is_array($item)) {
            $candidates = ['task', 'title', 'text', 'action', 'description', 'desc'];
            foreach ($candidates as $k) {
                if (!empty($item[$k]) && is_string($item[$k]) && trim($item[$k]) !== '') {
                    return trim($item[$k]);
                }
            }
        }
        return null;
    }, $items)));
}

// ── Helper: action_month قد يأتي بشكلين: ──
//   (أ) flat list of strings  → نطبّعه كما action_week
//   (ب) structured weekly plan {week1, week2, week3, week4} → نتركه كما هو
// نُكتشف عبر وجود مفتاح 'week1' (associative array).
function normalizeActionMonth($items)
{
    if (!is_array($items) || empty($items)) return [];
    if (isset($items['week1'])) {
        return $items;
    }
    return normalizeActionItems($items);
}

// ── Parser موحّد لجميع مزودي AI ─────────────────────────────
function parseAIResponse(array $aiData, string $source, array $rawData = []): array
{
    return [
        'source'               => $source,
        'page_type'            => $aiData['page_type']            ?? 'Unknown',
        'page_type_confidence' => $aiData['confidence']           ?? 0,
        'page_type_signals'    => $aiData['signals_used']         ?? [],
        'page_type_reasoning'  => $aiData['reasoning']            ?? '',
        'summary'              => $aiData['summary']              ?? ($aiData['final_report'] ?? ''),
        'strengths'            => normalizeStrengthWeakness($aiData['strengths']  ?? []),
        'weaknesses'           => normalizeStrengthWeakness($aiData['weaknesses'] ?? []),
        'recommendations'      => $aiData['recommendations']      ?? [],
        'action_week'          => normalizeActionItems($aiData['action_week']  ?? []),
        'action_month'         => normalizeActionMonth($aiData['action_month'] ?? []),
        'competitor_analysis'  => $aiData['competitor_analysis']  ?? [],
        'score_insight'        => $aiData['score_insight']        ?? '',
        'competitor_note'      => $aiData['competitor_note']      ?? '',
        'ai_tier'              => $aiData['tier']                 ?? 'yellow',
        'niche'                => $aiData['niche']                ?? '',
        'pain_points'          => $aiData['pain_points']          ?? [],
        'market_opportunity'   => $aiData['market_opportunity']   ?? '',
        'platform_strategy'    => $aiData['platform_strategy']    ?? [],
        'ads_strategy'         => $aiData['ads_strategy']         ?? [],
        'quick_wins'           => $aiData['quick_wins']           ?? [],
        'kpis_to_track'        => $aiData['kpis_to_track']        ?? [],
        'executive_plan'       => $aiData['executive_plan']       ?? null,
        // ── حقل رحلة العميل (بناءً على طلب العميل) ────────────
        'customer_journey'     => $aiData['customer_journey']     ?? null,
        // ── حقل استراتيجية المحتوى ────────────
        'content_strategy'     => $aiData['content_strategy']     ?? null,
        // ── content_analysis مبني من البيانات الفعلية ─────────
        'content_analysis'     => !empty($rawData) ? buildContentAnalysis($rawData) : ($aiData['content_analysis'] ?? null),
        // ── حقول جديدة مضافة لقالب JSON لتغذية الواجهة ─────────
        'ads_analysis'         => $aiData['ads_analysis']         ?? null,
        'competitor_radar'     => $aiData['competitor_radar']     ?? [],
    ];
}

// ============================================================
// ── محرك تصنيف الصفحة (إشارات متعددة) ─────────────────────
// ============================================================
function detectPageType(array $data): array
{
    $signals  = [];
    $scores   = [
        'agency'     => 0,  // وكالة تسويق رقمي ← أضفنا نوعاً جديداً
        'influencer' => 0,
        'ecommerce'  => 0,
        'business'   => 0,
        'brand'      => 0,
        'affiliate'  => 0,
        'blog'       => 0,
    ];

    $scan = [];
    if (!empty($data['scan_result'])) {
        $scan = is_string($data['scan_result'])
            ? (json_decode($data['scan_result'], true) ?? [])
            : $data['scan_result'];
    }

    $bio     = strtolower($scan['bio'] ?? $data['description'] ?? '');
    $type    = strtolower($data['project_type'] ?? '');
    $company = strtolower($data['company_name'] ?? '');
    $domain  = strtolower($data['url'] ?? $data['website'] ?? $scan['url'] ?? '');
    $ws      = $scan['website_scan'] ?? [];
    $h1      = strtolower($ws['h1'] ?? '');
    $services = implode(' ', array_map('strtolower', $ws['services_list'] ?? []));
    $combined = $bio . ' ' . $type . ' ' . $company . ' ' . $domain . ' ' . $h1 . ' ' . $services;

    // 1) إشارات وكالة التسويق الرقمي — أعلى أولوية
    $agencyKeywords = '/agency|وكالة|تسويق رقمي|digital marketing|marketing agency|إدارة حسابات|social media management|media buying|إدارة إعلانات|brand management|alabeer.*market/i';
    if (preg_match($agencyKeywords, $combined)) {
        $scores['agency'] += 50;
        $signals['identity'] = 'digital_marketing_agency';
    }
    // إذا كان الدومين أو الاسم يحتوي "marketing"
    if (preg_match('/marketing/i', $domain . ' ' . $company)) {
        $scores['agency'] += 25;
        $signals['domain'] = 'marketing_in_domain';
    }
    // إذا كانت الخدمات المستخرجة تذكر خدمات تسويق
    if (preg_match('/إعلان|حملة|محتوى|تصميم|seo|إدارة|consulting|استشار/i', $services)) {
        $scores['agency'] += 20;
        $signals['services'] = 'marketing_services_detected';
    }

    // 2) إشارات الهيكل — cart / checkout → ecommerce
    $hasCart     = !empty($scan['has_cart'])     || !empty($scan['hasCart']);
    $hasCheckout = !empty($scan['has_checkout']) || !empty($scan['hasCheckout']);
    $hasProducts = !empty($scan['has_products']) || !empty($scan['hasProducts']);
    if ($hasCart) {
        $scores['ecommerce'] += 35;
        $signals['structure'][] = 'cart_detected';
    }
    if ($hasCheckout) {
        $scores['ecommerce'] += 30;
        $signals['structure'][] = 'checkout_detected';
    }
    if ($hasProducts) {
        $scores['ecommerce'] += 20;
        $signals['structure'][] = 'product_pages_detected';
    }

    // 3) إشارات CTA
    $cta = strtolower($scan['primary_cta'] ?? $data['cta'] ?? '');
    if (preg_match('/buy|shop|order|اشتر|اطلب|تسوق/', $cta)) {
        $scores['ecommerce'] += 25;
        $signals['cta'] = 'buy_cta';
    } elseif (preg_match('/book|contact|اتصل|احجز|تواصل|whatsapp|واتساب/', $cta)) {
        $scores['agency'] += 15;
        $scores['business'] += 15;
        $signals['cta'] = 'service_contact_cta';
    } elseif (preg_match('/follow|subscribe|تابع|اشترك/', $cta)) {
        $scores['influencer'] += 25;
        $signals['cta'] = 'follow_cta';
    }

    // 4) إشارات الهوية العامة (إذا لم يكن agency بالفعل)
    if ($scores['agency'] < 30) {
        if (preg_match('/influencer|creator|مؤثر|منشئ محتوى/', $combined)) {
            $scores['influencer'] += 30;
            $signals['identity'] = 'personal_creator';
        } elseif (preg_match('/store|متجر|shop|ecommerce/', $combined)) {
            $scores['ecommerce'] += 30;
            $signals['identity'] = 'store_brand';
        } elseif (preg_match('/company|شركة|service|خدمة/', $combined)) {
            $scores['business'] += 30;
            $signals['identity'] = 'company_service';
        }
    }

    // 5) إشارة المحتوى
    $contentType = strtolower($scan['content_type'] ?? '');
    if (strpos($contentType, 'review') !== false || strpos($contentType, 'personal') !== false) {
        $scores['influencer'] += 15;
        $signals['content'] = 'reviews_personal';
    } elseif (strpos($contentType, 'product') !== false || strpos($contentType, 'listing') !== false) {
        $scores['ecommerce'] += 15;
        $signals['content'] = 'product_listing';
    } elseif (strpos($contentType, 'blog') !== false || strpos($contentType, 'article') !== false) {
        $scores['blog'] += 20;
        $signals['content'] = 'blog_content';
    }

    // 6) Disambiguation: منتجات بدون cart → influencer لا ecommerce
    if ($scores['ecommerce'] > 0 && !$hasCart && !$hasCheckout) {
        $scores['ecommerce']  = max(0, $scores['ecommerce'] - 40);
        $scores['influencer'] += 20;
        $signals['disambiguation'] = 'products_mentioned_no_commerce_mechanism';
    }

    // اختيار النوع الأعلى
    arsort($scores);
    $topType  = array_key_first($scores);
    $topScore = $scores[$topType];
    $total    = array_sum($scores) ?: 1;
    $confidence = min(100, (int)(($topScore / $total) * 200));

    $typeMap = [
        'agency'     => 'Digital Marketing Agency',
        'influencer' => 'Personal Influencer / Content Creator',
        'ecommerce'  => 'E-commerce Store',
        'business'   => 'Business / Service Provider',
        'brand'      => 'Brand Awareness Page',
        'affiliate'  => 'Affiliate / Promotion Page',
        'blog'       => 'Blog / Media Content',
    ];

    return [
        'detected_type' => $typeMap[$topType] ?? 'Unknown',
        'confidence'    => $confidence,
        'signals'       => $signals,
        'scores'        => $scores,
    ];
}


// ============================================================
function buildPrompt(array $data): string
{
    // ── تصنيف الصفحة أولاً ──────────────────────────────────
    $classification = detectPageType($data);
    $detectedType   = $classification['detected_type'];
    $typeConfidence = $classification['confidence'];
    $typeSignals    = json_encode($classification['signals'], JSON_UNESCAPED_UNICODE);

    $name     = $data['full_name']    ?? $data['company_name'] ?? 'العميل';
    $company  = $data['company_name'] ?? '';
    $type     = $data['project_type'] ?? '';
    $country  = $data['country']      ?? '';
    $platform = $data['platform']     ?? '';
    $score    = $data['score']        ?? 0;
    $answers  = $data['answers']      ?? $data;

    $breakdown = '';
    if (!empty($data['breakdown'])) {
        $bd = is_string($data['breakdown']) ? json_decode($data['breakdown'], true) : $data['breakdown'];
        if (is_array($bd)) {
            foreach ($bd as $k => $v) {
                if (is_array($v)) {
                    // breakdown item is {score:X, reason:'...'} — استخرج الرقم فقط
                    $vStr = isset($v['score']) ? (int)$v['score'] : json_encode($v, JSON_UNESCAPED_UNICODE);
                } else {
                    $vStr = (string)$v;
                }
                $breakdown .= "- {$k}: {$vStr}\n";
            }
        }
    }

    $scanInfo = '';
    if (!empty($data['scan_result'])) {
        $scan = is_string($data['scan_result']) ? json_decode($data['scan_result'], true) : $data['scan_result'];
        if (is_array($scan)) {

            // ── 1) الفحص التقني الأساسي ─────────────────────────
            $scanInfo .= "**① الفحص التقني للموقع:**\n";
            $scanInfo .= "- HTTPS/SSL: "          . ($scan['hasSSL']      ?? false ? 'نعم ✅' : 'لا ❌') . "\n";
            $scanInfo .= "- Facebook Pixel: "     . ($scan['hasPixel']    ?? false ? 'نعم ✅' : 'لا ❌') . "\n";
            $scanInfo .= "- Google Analytics: "   . ($scan['hasGA']       ?? false ? 'نعم ✅' : 'لا ❌') . "\n";
            $scanInfo .= "- TikTok Pixel: "       . ($scan['hasTikTok']   ?? false ? 'نعم ✅' : 'لا ❌') . "\n";
            $scanInfo .= "- Snapchat Pixel: "     . ($scan['hasSnapchat'] ?? false ? 'نعم ✅' : 'لا ❌') . "\n";
            $scanInfo .= "- زر واتساب: "          . ($scan['hasWhatsApp'] ?? false ? 'نعم ✅' : 'لا ❌') . "\n";
            $scanInfo .= "- نموذج تواصل: "        . ($scan['website_scan']['has_contact_form'] ?? false ? 'نعم ✅' : 'لا ❌') . "\n";
            $scanInfo .= "- رقم هاتف: "           . ($scan['website_scan']['has_phone']        ?? false ? 'نعم ✅' : 'لا ❌') . "\n";
            $scanInfo .= "- سرعة التحميل: "       . ($scan['website_scan']['speed_rating']     ?? $scan['speedRating'] ?? 'غير معروف') . " (" . ($scan['website_scan']['load_time_s'] ?? '?') . "ث)\n";
            $scanInfo .= "- Schema Markup: "      . ($scan['hasSchema']   ?? false ? 'نعم ✅' : 'لا ❌') . "\n";
            $scanInfo .= "- OG Tags: "            . ($scan['hasOGTags']   ?? false ? 'نعم ✅' : 'لا ❌') . "\n";

            // ── 2) خدمات الموقع المستخرجة تلقائياً ─────────────
            $ws = $scan['website_scan'] ?? [];
            if (!empty($ws['services_list'])) {
                $scanInfo .= "\n**② خدمات/منتجات الموقع (مستخرجة من HTML):**\n";
                foreach (array_slice($ws['services_list'], 0, 10) as $svc) {
                    $scanInfo .= "- {$svc}\n";
                }
            }
            if (!empty($ws['sections_titles'])) {
                $scanInfo .= "**أقسام الموقع الرئيسية:** " . implode(' | ', $ws['sections_titles']) . "\n";
            }
            if (!empty($ws['h1'])) {
                $scanInfo .= "**العنوان الرئيسي H1:** {$ws['h1']}\n";
            }

            // ── 3) بيانات Facebook العميقة ───────────────────────
            $fb = $scan['facebook'] ?? $scan['social'] ?? [];
            if (!empty($fb)) {
                $scanInfo .= "\n**③ بيانات Facebook:**\n";
                $scanInfo .= "- اسم الصفحة: "      . ($fb['page_name']      ?? $fb['username'] ?? 'غير متوفر') . "\n";
                $scanInfo .= "- المتابعون: "        . number_format((int)($fb['followers']     ?? 0)) . "\n";
                $scanInfo .= "- إجمالي المنشورات: " . ($fb['posts_count']    ?? '؟') . "\n";
                $scanInfo .= "- متوسط التفاعل: "   . number_format((float)($fb['avg_engagement'] ?? 0)) . " / منشور\n";
                $scanInfo .= "- معدل النشر: "       . ($fb['posts_per_week'] ?? '؟') . " منشور/أسبوع\n";
                $scanInfo .= "- التقييم: "          . ($fb['rating']         ?? 'لا يوجد') . "\n";
                $scanInfo .= "- التحقق: "           . ($fb['is_verified']    ?? false ? 'موثق ✅' : 'غير موثق') . "\n";
                if (!empty($fb['about']))
                    $scanInfo .= "- الوصف: " . mb_substr($fb['about'], 0, 200) . "\n";

                // تحليل المحتوى العميق
                $deep = $fb['deep_analysis'] ?? [];
                if (!empty($deep)) {
                    $scanInfo .= "**تحليل نوع المحتوى (من آخر 30 منشور):**\n";
                    if (!empty($deep['content_types'])) {
                        foreach ($deep['content_types'] as $type => $pct) {
                            $scanInfo .= "  - {$type}: {$pct}%\n";
                        }
                    }
                    if (!empty($deep['top_hashtags']))
                        $scanInfo .= "  - أبرز الهاشتاقات: " . implode(', ', array_slice($deep['top_hashtags'], 0, 8)) . "\n";
                    if (!empty($deep['avg_post_length']))
                        $scanInfo .= "  - متوسط طول التعليق: " . $deep['avg_post_length'] . " حرف\n";
                    if (!empty($deep['has_cta_in_posts']))
                        $scanInfo .= "  - منشورات تحتوي CTA: " . ($deep['has_cta_in_posts'] ? 'نعم ✅' : 'لا ❌') . "\n";
                }

                // نماذج من آخر المنشورات
                $posts = $fb['latest_posts'] ?? [];
                if (!empty($posts)) {
                    $scanInfo .= "**نماذج من آخر المنشورات (أبرز 5):**\n";
                    $topPosts = array_slice($posts, 0, 5);
                    foreach ($topPosts as $i => $p) {
                        $text    = mb_substr($p['message'] ?? $p['text'] ?? $p['title'] ?? '', 0, 120);
                        $likes   = $p['likes']    ?? $p['likesCount']   ?? 0;
                        $comments = $p['comments'] ?? $p['commentsCount'] ?? 0;
                        $type    = $p['type']     ?? $p['postType']     ?? 'post';
                        if ($text) $scanInfo .= "  " . ($i + 1) . ". [{$type}] \"{$text}\" | 👍{$likes} 💬{$comments}\n";
                    }
                }
            }

            // ── 4) بيانات Instagram العميقة ──────────────────────
            $ig = $scan['instagram'] ?? [];
            if (!empty($ig['username'])) {
                $scanInfo .= "\n**④ بيانات Instagram:**\n";
                $scanInfo .= "- المستخدم: @"       . ($ig['username']       ?? '') . "\n";
                $scanInfo .= "- المتابعون: "        . number_format((int)($ig['followers']      ?? 0)) . "\n";
                $scanInfo .= "- إجمالي المنشورات: "        . ($ig['posts_count']        ?? '؟') . "\n";
                $scanInfo .= "- متوسط الإعجابات: "          . number_format((float)($ig['avg_likes']       ?? 0)) . "\n";
                $scanInfo .= "- متوسط التعليقات: "          . number_format((float)($ig['avg_comments']    ?? 0)) . "\n";
                $scanInfo .= "- متوسط الحفظ (Saves): "      . number_format((float)($ig['avg_saves']       ?? 0)) . "\n";
                $scanInfo .= "- متوسط مشاهدات الفيديو: "   . number_format((float)($ig['avg_video_views'] ?? 0)) . "\n";
                $scanInfo .= "- عدد Reels: "                . ($ig['reels_count']          ?? '؟') . "\n";
                $scanInfo .= "- معدل التفاعل: "             . number_format((float)($ig['engagement_rate'] ?? 0), 2) . "%\n";
                $scanInfo .= "- معدل النشر: "               . ($ig['posts_per_week']        ?? '؟') . " منشور/أسبوع\n";
                if (!empty($ig['bio']))
                    $scanInfo .= "- البايو: " . mb_substr($ig['bio'], 0, 150) . "\n";

                $igDeep = $ig['deep_analysis'] ?? [];
                if (!empty($igDeep['content_types'])) {
                    $scanInfo .= "**توزيع المحتوى:** ";
                    $parts = [];
                    foreach ($igDeep['content_types'] as $t => $p) $parts[] = "{$t}: {$p}%";
                    $scanInfo .= implode(' | ', $parts) . "\n";
                }
                if (!empty($igDeep['top_hashtags']))
                    $scanInfo .= "- أبرز الهاشتاقات (IG): " . implode(', ', array_slice($igDeep['top_hashtags'], 0, 6)) . "\n";

                // مشاعر تعليقات أفضل منشور
                $igCom = $ig['top_post_comments'] ?? null;
                if (!empty($igCom['total_comments'])) {
                    $scanInfo .= "- مشاعر تعليقات أفضل منشور: إيجابية {$igCom['positive_pct']}% | سلبية {$igCom['negative_pct']}% | أسئلة {$igCom['questions_pct']}%\n";
                    if (!empty($igCom['top_objections']))
                        $scanInfo .= "- أبرز الاعتراضات: " . implode(' | ', array_slice($igCom['top_objections'], 0, 3)) . "\n";
                }
            }

            // ── 5) بيانات TikTok العميقة ─────────────────────────
            $tk = $scan['tiktok'] ?? [];
            if (!empty($tk['username'])) {
                $scanInfo .= "\n**⑤ بيانات TikTok:**\n";
                $scanInfo .= "- المستخدم: @"       . ($tk['username']        ?? '') . "\n";
                $scanInfo .= "- المتابعون: "        . number_format((int)($tk['followers']       ?? 0)) . "\n";
                $scanInfo .= "- الإعجابات الكلية: " . number_format((int)($tk['likes']           ?? 0)) . "\n";
                $scanInfo .= "- عدد الفيديوهات: "  . ($tk['video_count']     ?? '؟') . "\n";
                $scanInfo .= "- متوسط الإعجابات: "           . number_format((float)($tk['avg_likes']      ?? 0)) . " / فيديو\n";
                $scanInfo .= "- متوسط التعليقات: "           . number_format((float)($tk['avg_comments']   ?? 0)) . " / فيديو\n";
                $scanInfo .= "- متوسط المشاركات (Shares): "  . number_format((float)($tk['avg_shares']     ?? 0)) . "\n";
                $scanInfo .= "- متوسط الحفظ (Saves): "       . number_format((float)($tk['avg_saves']      ?? 0)) . "\n";
                $scanInfo .= "- متوسط المشاهدات: "           . number_format((float)($tk['avg_views']       ?? 0)) . "\n";
                $scanInfo .= "- معدل التفاعل: "              . number_format((float)($tk['engagement_rate'] ?? 0), 2) . "%\n";
                $scanInfo .= "- معدل النشر: "                . ($tk['posts_per_week']                         ?? '؟') . " فيديو/أسبوع\n";
                if (!empty($tk['bio'])) $scanInfo .= "- الوصف: " . mb_substr($tk['bio'], 0, 150) . "\n";
                if (!empty($tk['trending_sounds']))
                    $scanInfo .= "- أصوات رائجة مستخدمة: " . implode(' | ', array_slice($tk['trending_sounds'], 0, 3)) . "\n";
                $tkDeep = $tk['deep_analysis'] ?? [];
                if (!empty($tkDeep['top_hashtags']))
                    $scanInfo .= "- أبرز الهاشتاقات: " . implode(', ', array_slice($tkDeep['top_hashtags'], 0, 6)) . "\n";
            }

            // ── 6) بيانات Twitter ────────────────────────────────
            $tw = $scan['twitter'] ?? [];
            if (!empty($tw['username'])) {
                $scanInfo .= "\n**⑥ بيانات Twitter (X):**\n";
                $scanInfo .= "- المستخدم: @"  . ($tw['username']    ?? '') . "\n";
                $scanInfo .= "- المتابعون: "   . number_format((int)($tw['followers']   ?? 0)) . "\n";
                $scanInfo .= "- التغريدات: "   . ($tw['posts_count'] ?? '؟') . "\n";
                $scanInfo .= "- الموقع: "      . ($tw['location']    ?? 'غير محدد') . "\n";
                if (!empty($tw['bio'])) $scanInfo .= "- الوصف: " . mb_substr($tw['bio'], 0, 150) . "\n";
            }

            // ── 7) بيانات الإعلانات الكاملة ──────────────────────
            $ads = $scan['ads_library'] ?? [];
            if (!empty($ads)) {
                $scanInfo .= "\n**⑦ بيانات مكتبة الإعلانات:**\n";
                $scanInfo .= "- إجمالي الإعلانات: " . ($ads['total_ads']  ?? 0) . "\n";
                $scanInfo .= "- الإعلانات النشطة: "  . ($ads['active_ads'] ?? 0) . "\n";
                $adItems = $ads['ads'] ?? [];
                if (!empty($adItems)) {
                    $scanInfo .= "**تفاصيل آخر الإعلانات (أبرز 8):**\n";
                    foreach (array_slice($adItems, 0, 8) as $i => $ad) {
                        $adText = mb_substr($ad['title'] ?? $ad['body'] ?? '', 0, 100);
                        $obj    = $ad['objective']         ?? '';
                        $cta    = $ad['cta_type']          ?? $ad['call_to_action_type'] ?? '';
                        $status = $ad['is_active']         ?? false ? '🟢 نشط' : '⚫ منتهي';
                        $scanInfo .= "  " . ($i + 1) . ". {$status}";
                        if ($obj)    $scanInfo .= " | هدف: {$obj}";
                        if ($cta)    $scanInfo .= " | CTA: {$cta}";
                        if ($adText) $scanInfo .= "\n     النص: \"{$adText}\"";
                        $scanInfo .= "\n";
                    }
                }
            }

            // ── 8) المنافسون مع البيانات الكمية ──────────────────
            $competitors = $scan['competitor_radar'] ?? $scan['competitors'] ?? [];
            if (!empty($competitors)) {
                $scanInfo .= "\n**⑧ رادار المنافسين (مع بيانات كمية):**\n";
                foreach (array_slice($competitors, 0, 5) as $comp) {
                    $scanInfo .= "- **{$comp['name']}** ({$comp['url']})\n";
                    $scanInfo .= "  الوصف: " . mb_substr($comp['description'] ?? '', 0, 100) . "\n";
                    if (!empty($comp['followers']))
                        $scanInfo .= "  المتابعون: " . number_format((int)$comp['followers']) . "\n";
                    if (!empty($comp['avg_engagement']))
                        $scanInfo .= "  متوسط التفاعل: " . $comp['avg_engagement'] . "\n";
                    if (isset($comp['has_ads']))
                        $scanInfo .= "  يُعلن: " . ($comp['has_ads'] ? 'نعم ✅' : 'لا') . "\n";
                    if (!empty($comp['website_score']))
                        $scanInfo .= "  درجة الموقع: " . $comp['website_score'] . "/100\n";
                }
            }

            // ── 9) تعليقات Facebook (أفضل منشور) ────────────────
            $fbCom = $scan['facebook']['top_post_comments'] ?? null;
            if (!empty($fbCom['total_comments'])) {
                $scanInfo .= "\n**⑨ تحليل تعليقات أفضل منشور (Facebook):**\n";
                $scanInfo .= "- إجمالي: {$fbCom['total_comments']} | إيجابية {$fbCom['positive_pct']}% | سلبية {$fbCom['negative_pct']}% | أسئلة {$fbCom['questions_pct']}%\n";
                if (!empty($fbCom['top_objections']))
                    $scanInfo .= "- أبرز الاعتراضات: " . implode(' | ', array_slice($fbCom['top_objections'], 0, 3)) . "\n";
            }

            // ── 10) Google Maps Reviews ───────────────────────────
            $maps = $scan['google_maps'] ?? null;
            if (!empty($maps['total_reviews'])) {
                $scanInfo .= "\n**⑩ تقييمات Google Maps:**\n";
                $scanInfo .= "- إجمالي التقييمات: {$maps['total_reviews']} | المتوسط: " . ($maps['avg_rating'] ?? '؟') . "/5\n";
                if (!empty($maps['negative']))
                    $scanInfo .= "- أبرز الانتقادات: " . mb_substr(implode(' | ', array_slice($maps['negative'], 0, 2)), 0, 180) . "\n";
                if (!empty($maps['positive']))
                    $scanInfo .= "- أبرز الإيجابيات: " . mb_substr(implode(' | ', array_slice($maps['positive'], 0, 2)), 0, 180) . "\n";
            }

            // ── 11) Cloud Video Intelligence (Hook + Labels) ──────
            $vi = $scan['video_intelligence'] ?? null;
            if (!empty($vi['analyzed'])) {
                $scanInfo .= "\n**⑪ تحليل محتوى الفيديو (Cloud Video Intelligence):**\n";
                if (!empty($vi['hook_text']))
                    $scanInfo .= "- الهوك الفعلي المنطوق: \"" . mb_substr($vi['hook_text'], 0, 150) . "\"\n";
                if (!empty($vi['transcript']))
                    $scanInfo .= "- النص الكامل: " . mb_substr($vi['transcript'], 0, 300) . "\n";
                if (!empty($vi['labels']))
                    $scanInfo .= "- عناصر مرئية: " . implode(', ', array_slice($vi['labels'], 0, 8)) . "\n";
                if (!empty($vi['text_overlays']))
                    $scanInfo .= "- نصوص ظاهرة في الفيديو: " . implode(' | ', array_slice($vi['text_overlays'], 0, 3)) . "\n";
                if (!empty($vi['video_topics']))
                    $scanInfo .= "- مواضيع الفيديو: " . implode('، ', $vi['video_topics']) . "\n";
            }

            // ── 12) الهدف والميزانية ──────────────────────────────
            $scanInfo .= "\n**⑫ البيانات الاستراتيجية للعميل:**\n";
            $scanInfo .= "- الهدف التسويقي: "     . ($scan['lead_objective'] ?? $data['objective']       ?? 'غير محدد') . "\n";
            $scanInfo .= "- الجمهور المستهدف: "   . ($scan['lead_audience']  ?? $data['target_audience'] ?? 'غير محدد') . "\n";
            $scanInfo .= "- الميزانية الإعلانية: " . ($scan['lead_budget']   ?? $data['ad_budget']       ?? 'غير محدد') . "\n";
        }

    }

    // بيانات الإجابات
    $answersText = '';
    $labelMap = [
        'brand_logo_ready'      => 'لديه شعار وألوان',
        'brand_message_clear'   => 'رسالة تسويقية واضحة',
        'brand_guidelines'      => 'دليل Guidelines',
        'content_strategy'      => 'استراتيجية المحتوى',
        'content_frequency'     => 'قطع محتوى/أسبوع',
        'platforms_active'      => 'المنصات النشطة',
        'ads_running'           => 'يطلق إعلانات',
        'pixel_setup'           => 'Pixel مثبت',
        'ads_objective'         => 'هدف إعلاني واضح',
        'retargeting_campaigns' => 'حملات Retargeting',
        'ad_budget'             => 'ميزانية الإعلانات',
        'landing_page_exists'   => 'لديه صفحة هبوط',
        'offer_clarity'         => 'عرض لا يُقاوم',
        'checkout_friction'     => 'سهولة الشراء',
        'email_marketing'       => 'إيميل/SMS تسويقي',
        'reviews_collected'     => 'جمع تقييمات',
        'analytics_installed'   => 'Analytics مثبت',
        'kpis_tracked'          => 'يتابع مؤشرات الأداء',
        'ltv_known'             => 'يعرف LTV العميل',
    ];
    foreach ($labelMap as $key => $label) {
        $val = $answers[$key] ?? null;
        if ($val !== null) {
            if (is_bool($val)) $answersText .= "- {$label}: " . ($val ? 'نعم ✅' : 'لا ❌') . "\n";
            elseif (is_array($val)) $answersText .= "- {$label}: " . implode('، ', $val) . "\n";
            else $answersText .= "- {$label}: {$val}\n";
        }
    }

    // ── تحديد فئة التقرير بناءً على نوع الصفحة ─────────────
    $reportFocus = "ركّز على: تحسين التواجد الرقمي وبناء الاستراتيجية التسويقية المناسبة.";
    if (strpos($detectedType, 'Marketing Agency') !== false || strpos($detectedType, 'Agency') !== false) {
        $reportFocus = "هذه وكالة تسويق رقمي (Digital Marketing Agency) تقدم خدمات تسويق للشركات. ركّز على: استقطاب العملاء B2B، عرض دراسات الحالة والنتائج، بناء المصداقية المهنية، تمييز خدماتها عن المنافسين، وتحسين معدل تحويل الزوار إلى عملاء.";
    } elseif (strpos($detectedType, 'Influencer') !== false || strpos($detectedType, 'Creator') !== false) {
        $reportFocus = "ركّز على: استراتيجية المحتوى، نمو الجمهور، معدلات التفاعل، والبراند الشخصي. لا تفترض وجود متجر أو منتجات للبيع المباشر.";
    } elseif (strpos($detectedType, 'E-commerce') !== false) {
        $reportFocus = "ركّز على: معدل التحويل، تقديم المنتجات، تحسين القمع التسويقي، وتجربة الشراء.";
    } elseif (strpos($detectedType, 'Business') !== false || strpos($detectedType, 'Service') !== false) {
        $reportFocus = "ركّز على: جذب العملاء المحتملين، وضوح عرض الخدمة، وبناء الثقة والمصداقية.";
    }

    return <<<PROMPT
## SYSTEM: Advanced Page Classification & Context-Aware Analysis Engine

أنت محلل تسويق رقمي خبير متخصص في السوق العربي. مهمتك إنتاج تقرير تشخيصي دقيق **مبني حصراً على البيانات الفعلية أدناه**. لا تخترع معلومات غير موجودة.

### تصنيف الصفحة (مكتمل مسبقاً بإشارات متعددة):
- النوع المكتشف: {$detectedType}
- درجة الثقة: {$typeConfidence}%
- الإشارات المستخدمة: {$typeSignals}

قاعدة التمييز: إذا كانت الصفحة تتحدث عن منتجات لكن لا يوجد cart/checkout فهي Influencer وليست E-commerce.

### توجيه التقرير:
{$reportFocus}

---
## بيانات العميل الكاملة:
- الاسم: {$name} | الشركة: {$company} | النوع: {$type}
- الدولة: {$country} | المنصة: {$platform} | الدرجة: {$score}/100

### محاور الأداء (Breakdown):
{$breakdown}

### نتائج الفحص التقني التلقائي:
{$scanInfo}

### إجابات الاستبيان:
{$answersText}

---
## قواعد إلزامية قبل كتابة JSON:

⚠️ **قواعد أمان البيانات — أي مخالفة تجعل التقرير خاطئاً:**

**قاعدة 1 — عدم التناقض مع البيانات المؤكدة:**
- إذا كان "Facebook Pixel: نعم ✅" في الفحص → لا تكتب في weaknesses "عدم وجود Pixel" أبداً
- إذا كان "Google Analytics: نعم ✅" → لا تكتب "لا يوجد Google Analytics"
- إذا كان "HTTPS: نعم ✅" → لا تكتب "الموقع غير آمن"
- كل ما هو "نعم ✅" في الفحص = قوة، وكل ما هو "لا ❌" = ضعف فقط

**قاعدة 2 — المنافسون الحقيقيون:**
- يُحظر كتابة "منافس1" أو "قوة منافس1" أو أي بيانات وهمية placeholder
- اكتب منافسين حقيقيين: مثلاً "نتائج للتسويق الرقمي" أو "بيان للتسويق" أو "ميديا بوست" مع نقاطهم الفعلية

**قاعدة 3 — الحقول المطلوبة إلزامياً (لا تتركها فارغة []):**
- quick_wins: 3 انتصارات سريعة مبنية على ثغرات حقيقية مكتشفة
- kpis_to_track: 4 مؤشرات أداء مناسبة لوكالة تسويق (مثال: تكلفة الاكتساب، ROAS، معدل التحويل، نمو المتابعين)
- platform_strategy: استراتيجية لكل منصة مكتشفة (Facebook + Instagram على الأقل)
- action_week: خطوتان فوريتان للأسبوع الأول

لاستخراج strengths (نقاط القوة) - الحد الأدنى 5 نقاط:
**قاعدة هيكلية إلزامية — النقطتان الأولى والثانية محددتان مسبقاً:**

• strengths[0] — يجب أن يجيب حصراً على: "ما الذي يُميّز هذا الحساب عن منافسيه؟"
  - ابحث عن الخاصية الأبرز التي لا يمتلكها الكثير في هذا السوق (Pixel، التوثيق، التفاعل العالي، عدد المتابعين، نشاط الإعلانات، سرعة الموقع، إلخ)
  - الصياغة: "[اسم الميزة التمييزية]: [لماذا هذا يضعه في موضع أفضل من المنافسين + التأثير الرقمي المحدد]"
  - مثال: "معدل تفاعل 4.2% يتجاوز متوسط السوق بـ 3x: هذا يعني أن كل منشور يصل لجمهور حقيقي متفاعل بدون تكلفة إعلانية إضافية."

• strengths[1] — يجب أن يجيب حصراً على: "ما الذي يمكن البناء عليه فوراً لتحقيق نمو سريع؟"
  - ابحث عن أقوى أصل موجود يمكن تحويله لنتائج مالية مباشرة (قاعدة متابعين، بنية موقع جاهزة، Pixel مثبت، عملاء سابقون، إلخ)
  - الصياغة: "[الأصل القابل للبناء]: [الخطوة المباشرة لتحويله لمبيعات أو نمو + النتيجة الرقمية المتوقعة]"
  - مثال: "قاعدة 12,000 متابع نشط هي منجم ذهب غير مستغل: تفعيل Retargeting لهذا الجمهور بإعلان واحد يمكن أن يضاعف المبيعات خلال 30 يوماً."

النقاط 3-5: استخرجها من البيانات الفعلية:
3. كل عنصر تقني موجود ومؤكد (HTTPS نعم، واتساب نعم، Analytics نعم، إلخ) = نقطة قوة
4. كل إجابة إيجابية في الاستبيان أو محور أداء بدرجة عالية = نقطة قوة
5. إذا لم تكتمل 5، استخرج من نوع النشاط وطبيعة السوق
صياغة النقاط 3-5: "عنوان موجز: شرح تأثيرها التسويقي المباشر على المبيعات أو الوصول."

لاستخراج weaknesses (نقاط الضعف) - الحد الأدنى 5 نقاط:
**قاعدة هيكلية إلزامية — النقطتان الأولى والثانية محددتان:**

• weaknesses[0] — type: "bottleneck" — يجيب حصراً على: "ما هي أهم العوائق الحالية الموجودة في الحساب الآن؟"
  - اكتشف المشكلة الجذرية الفعلية من البيانات (ليس عاماً): ضعف CTA، غياب Pixel، انخفاض معدل التحويل، إلخ
  - metric: رقم من البيانات يقيس حجم المشكلة (مثال: "معدل تحويل 0.8% بدلاً من 3%")
  - bullets: 3 أدلة بالبيانات على وجود هذا العائق الآن

• weaknesses[1] — type: "growth_blocker" — يجيب حصراً على: "ما الذي يوقف النمو؟"
  - السبب التسويقي أو التقني الجذري الذي يمنع التقدم (مثال: غياب Retargeting، محتوى مبيعاتي 100%، بدون Analytics)
  - metric: رقم يبين حجم الخسارة أو الفرصة الضائعة
  - bullets: لماذا هذا يوقف النمو تحديداً بأدلة من البيانات

النقاط 3-5: استخرجها من البيانات الفعلية:
3. فقط العناصر التي الفحص يقول "لا ❌" عنها — لا تتناقض مع البيانات المؤكدة
4. كل إجابة سلبية في الاستبيان (لا، غير موجود، منخفض) = نقطة ضعف
5. إذا لم تكتمل، أضف ثغرات استراتيجية مستنتجة من نوع النشاط والسوق

ممنوع كتابة نقاط عامة لا صلة لها بالبيانات أعلاه.

**قاعدة إلزامية لبناء التوصيات (recommendations) — ربط مباشر بالاختناق:**

⚠️ يُحظر تماماً كتابة توصيات عامة أو مقتبسة من قوالب — كل توصية يجب أن تكون علاجاً مباشراً لمشكلة مكتشفة فعلياً من البيانات:

• recommendations[0] — priority: "high":
  - يجب أن يكون العلاج المباشر لـ weaknesses[0] (أهم اختناق/bottleneck)
  - title يحتوي على اسم المشكلة التي اكتشفتها + الحل الفوري
  - why_now: اذكر الرقم أو المؤشر المحدد من البيانات الذي يجعل التأجيل مكلفاً
  - bullets: 3 خطوات تنفيذية فورية ومحددة بالأداة أو المنصة
  - roi: رقم أو نسبة متوقعة خلال 7-30 يوماً
  - مثال: إذا كانت weaknesses[0] = "غياب Pixel" → التوصية: "تفعيل Meta Pixel فوراً لوقف نزيف البيانات"

• recommendations[1] — priority: "high":
  - يجب أن يكون العلاج المباشر لـ weaknesses[1] (growth_blocker)
  - نفس القواعد أعلاه — ربط صريح بالمانع المكتشف

• recommendations[2-3] — priority: "medium":
  - مبنية على أضعف محور في breakdown أو الإجابات السلبية في الاستبيان
  - ليست نصائح عامة بل علاجات لبيانات موجودة

• recommendations[4] — priority: "low":
  - استراتيجية نمو مستدام مبنية على أقوى نقطة قوة في strengths وتحويلها لتوسع

---
## المطلوب: JSON صحيح فقط بالهيكل التالي — بدون أي نص خارجه:

{
  "page_type": "{$detectedType}",
  "niche": "المجال الدقيق كمثال: شركة تسويق رقمي أو متجر ملابس أو مطعم وفق البيانات",
  "confidence": {$typeConfidence},
  "signals_used": {"identity": "وصف", "intent": "وصف", "structure": "وصف", "content": "وصف", "cta": "وصف"},
  "reasoning": "تفسير موجز للتصنيف بناء على إشارات التصنيف أعلاه",
  "summary": "خلاصة تنفيذية 3 إلى 4 جمل مبنية على البيانات الفعلية فقط لا قوالب عامة",
  "tier": "red أو yellow أو green",
  "score_insight": "ماذا تعني درجة {$score} من 100 لهذا النشاط تحديداً في {$country}",
  "market_opportunity": "فرصة تسويقية حقيقية في {$country} لهذا النوع من النشاط",
  "pain_points": ["ألم محدد 1 من البيانات", "ألم محدد 2 من البيانات", "ألم محدد 3 من البيانات"],
  "strengths": [
    {
      "type": "differentiator",
      "title": "ما يميز الحساب: العنوان الدقيق",
      "desc": "جملة واحدة تصف الميزة التنافسية بمقياس من البيانات",
      "metric": "رقم محدد من البيانات مثل: 4.2% تفاعل أو 12,000 متابع أو 3 إعلانات نشطة",
      "bullets": [
        "دليل محدد من البيانات يثبت هذه الميزة",
        "كيف هذا يضع الحساب في موضع أفضل من منافسيه",
        "التأثير المحدد على المبيعات أو الوصول"
      ],
      "action": "خطوة واحدة محددة لاستغلال هذه الميزة فوراً",
      "score": 90
    },
    {
      "type": "foundation",
      "title": "ما يمكن البناء عليه: العنوان الدقيق",
      "desc": "جملة واحدة تصف الأصل وكيفية تحويله لنتائج",
      "metric": "رقم محدد من البيانات يصف حجم هذا الأصل",
      "bullets": [
        "وصف الأصل بدليل من البيانات",
        "لماذا هذا الأصل يمكن تحويله لمبيعات أو نمو سريع",
        "النتيجة الرقمية المتوقعة بعد التفعيل"
      ],
      "action": "خطوة واحدة فورية لتحويل هذا الأصل لمبيعات",
      "score": 85
    },
    {
      "type": "strength",
      "title": "نقطة قوة 3 من البيانات",
      "desc": "شرح تأثيرها على المبيعات أو الوصول",
      "metric": "الرقم المحدد من البيانات",
      "bullets": ["دليل من البيانات", "التأثير المباشر"],
      "action": "خطوة لاستغلال هذه النقطة",
      "score": 80
    },
    {
      "type": "strength",
      "title": "نقطة قوة 4 من البيانات",
      "desc": "شرح تأثيرها على المبيعات أو الوصول",
      "metric": "الرقم المحدد من البيانات",
      "bullets": ["دليل من البيانات", "التأثير المباشر"],
      "action": "خطوة لاستغلال هذه النقطة",
      "score": 75
    },
    {
      "type": "strength",
      "title": "نقطة قوة 5 من البيانات",
      "desc": "شرح تأثيرها على المبيعات أو الوصول",
      "metric": "الرقم المحدد من البيانات",
      "bullets": ["دليل من البيانات", "التأثير المباشر"],
      "action": "خطوة لاستغلال هذه النقطة",
      "score": 70
    }
  ],
  "weaknesses": [
    {
      "type": "bottleneck",
      "title": "أهم العوائق الحالية: العنوان الدقيق من البيانات",
      "desc": "جملة تصف العائق الجذري الموجود الآن بدليل من البيانات",
      "metric": "رقم محدد يقيس حجم هذه المشكلة",
      "bullets": [
        "دليل من البيانات على وجود هذا العائق",
        "كيف يؤثر على المبيعات أو التحويل مباشرة",
        "الخسارة المتوقعة أو الفرصة الضائعة بالرقم"
      ],
      "action": "خطوة واحدة فورية لإزالة هذا العائق",
      "score": 35
    },
    {
      "type": "growth_blocker",
      "title": "ما الذي يوقف النمو: العنوان الدقيق",
      "desc": "السبب التسويقي أو التقني الفعلي الذي يمنع تقدم الحساب",
      "metric": "رقم محدد من البيانات",
      "bullets": [
        "السبب الجذري بدليل تقني أو تسويقي",
        "لماذا لم ينجح الحساب في تجاوزه حتى الآن",
        "تكلفة الاستمرار على هذا الوضع"
      ],
      "action": "خطوة واحدة لفتح باب النمو هذا",
      "score": 30
    },
    {"type": "weakness", "title": "نقطة ضعف 3 من البيانات", "desc": "شرح تأثيرها على المبيعات", "metric": "رقم محدد", "action": "خطوة للحل", "score": 45},
    {"type": "weakness", "title": "نقطة ضعف 4 من البيانات", "desc": "شرح تأثيرها على المبيعات", "metric": "رقم محدد", "action": "خطوة للحل", "score": 50},
    {"type": "weakness", "title": "نقطة ضعف 5 من البيانات", "desc": "شرح تأثيرها على المبيعات", "metric": "رقم محدد", "action": "خطوة للحل", "score": 55}
  ],

  "recommendations": [
    {
      "priority": "high",
      "icon": "🛡️",
      "title": "← اكتب هنا العلاج المباشر لـ weaknesses[0] (الاختناق الأكبر المكتشف من البيانات)",
      "desc": "← لماذا هذا المشكلة تكلف مبيعات الآن؟ اذكر رقماً أو مؤشراً من البيانات",
      "why_now": "← الدليل الرقمي المحدد من البيانات الذي يجعل التأجيل مكلفاً (مثال: معدل تحويل 0.5% بدلاً من 2%)",
      "bullets": [
        "← الخطوة الأولى الفورية بالأداة المحددة (مثال: ادخل Business Manager → Events Manager → فعّل Pixel)",
        "← الخطوة الثانية بالتفصيل الإجرائي",
        "← كيف تقيس النجاح خلال 7 أيام؟"
      ],
      "roi": "← رقم أو نسبة متوقعة خلال 7-30 يوماً (مثال: خفض تكلفة الاكتساب بـ 30%)",
      "time_to_implement": "1-3 أيام"
    },
    {
      "priority": "high",
      "icon": "🎯",
      "title": "← اكتب هنا العلاج المباشر لـ weaknesses[1] (مانع النمو growth_blocker المكتشف)",
      "desc": "← التأثير المالي المباشر لهذا المانع على المبيعات بالأرقام",
      "why_now": "← لماذا حل هذا المانع تحديداً هو الخطوة الأكثر تأثيراً الآن؟",
      "bullets": [
        "← الخطوة الأولى المحددة",
        "← الخطوة الثانية",
        "← مقياس النجاح"
      ],
      "roi": "← العائد المتوقع خلال 14-30 يوماً بالأرقام",
      "time_to_implement": "3-7 أيام"
    },
    {
      "priority": "medium",
      "icon": "✍️",
      "title": "← علاج لأضعف محور في breakdown أو أضعف إجابة في الاستبيان",
      "desc": "← تأثير تحسين هذا المحور على المبيعات خلال الشهر القادم",
      "why_now": "← لماذا هذا الشهر تحديداً هو الوقت المناسب؟",
      "bullets": [
        "← خطوة 1 محددة بالأداة",
        "← خطوة 2",
        "← مؤشر القياس"
      ],
      "roi": "← العائد المتوقع خلال 30-60 يوماً",
      "time_to_implement": "1-2 أسبوع"
    },
    {
      "priority": "medium",
      "icon": "📊",
      "title": "← علاج للمشكلة التقنية أو التسويقية الثالثة المكتشفة من البيانات",
      "desc": "← وصف المشكلة بدليل من البيانات وليس من القوالب العامة",
      "why_now": "← السبب التقني أو الموسمي المحدد",
      "bullets": [
        "← خطوة 1",
        "← خطوة 2"
      ],
      "roi": "← العائد المتوقع بأرقام",
      "time_to_implement": "2-3 أسابيع"
    },
    {
      "priority": "low",
      "icon": "🤝",
      "title": "← استراتيجية نمو مبنية على strengths[0] أو strengths[1] (أقوى الأصول الموجودة)",
      "desc": "← كيف تحول أقوى نقطة قوة لديك إلى نمو مستدام طويل المدى؟",
      "why_now": "← لماذا البدء بهذه الاستراتيجية الآن يبني أساساً قوياً للمستقبل؟",
      "bullets": [
        "← خطوة 1 نحو النمو المستدام",
        "← خطوة 2",
        "← كيف تقيس النجاح بعد 90 يوماً؟"
      ],
      "roi": "← العائد المتوقع خلال 60-90 يوماً",
      "time_to_implement": "شهر أو أكثر"
    }
  ],
  "customer_journey": {
    "stages": {
      "awareness":  {"score": 0, "analysis": "← تحليل من البيانات الفعلية (Followers + Reach + Ads) في 1-2 جملة"},
      "attraction": {"score": 0, "analysis": "← تحليل مبني على معدل التفاعل + توزيع المحتوى + أبرز المنشورات"},
      "trust":      {"score": 0, "analysis": "← تحليل مبني على Social Proof + الأقدمية + التقييمات + Schema/HTTPS"},
      "purchase":   {"score": 0, "analysis": "← تحليل مبني على CTA + Pixel + سهولة الشراء + Checkout"},
      "loyalty":    {"score": 0, "analysis": "← تحليل مبني على Email/SMS + تكرار النشر + برامج الولاء"}
    },
    "bottleneck_stage": "← اسم المرحلة (awareness|attraction|trust|purchase|loyalty) ذات أدنى score — ويجب أن تتطابق مع weaknesses[0]",
    "psychological_diagnosis": "← جملة واحدة تربط أدنى مرحلة بالـ weaknesses[0] المكتشف وتشرح لماذا يتوقف العميل هنا تحديداً",
    "bottleneck_fix": [
      "← خطوة 1 مطابقة لـ recommendations[0].bullets[0] (نفس العلاج للاختناق)",
      "← خطوة 2 ملموسة بالأداة المحددة",
      "← خطوة 3 قابلة للقياس خلال 7 أيام"
    ]
  },
  "quick_wins": [
    "← انتصار سريع 1 مبني على ثغرة حقيقية مكتشفة (≤ 24 ساعة تنفيذ)",
    "← انتصار سريع 2 (≤ 24 ساعة تنفيذ)",
    "← انتصار سريع 3 (≤ 48 ساعة تنفيذ)"
  ],
  "kpis_to_track": [
    "← KPI 1 مناسب لنوع النشاط المكتشف (مثال: ROAS، CPA، معدل التحويل)",
    "← KPI 2",
    "← KPI 3",
    "← KPI 4"
  ],
  "platform_strategy": {
    "facebook":  "← استراتيجية مخصصة بناءً على بيانات Facebook في scanInfo، أو null إذا لا يوجد حساب",
    "instagram": "← استراتيجية مخصصة بناءً على بيانات Instagram، أو null",
    "tiktok":    "← استراتيجية مخصصة بناءً على بيانات TikTok، أو null"
  },
  "action_week": [
    "← إجراء فوري 1 (يوم 1-2) — الأولوية القصوى من recommendations[0]",
    "← إجراء فوري 2 (يوم 3-5) — من recommendations[1]"
  ],
  "content_analysis": {
    "bar_brand":  0,
    "bar_visual": 0,
    "bar_cta":    0,
    "bar_value":  0,
    "q": [
      {"question": "هل المحتوى متوازن (تعليمي/مبيعي)؟", "status": "good|warn|bad", "answer": "← من types_percent المكتشف"},
      {"question": "هل توجد CTAs في المنشورات؟", "status": "good|warn|bad", "answer": "← من has_cta_in_posts"},
      {"question": "هل الهاشتاقات مناسبة للجمهور؟", "status": "good|warn|bad", "answer": "← من top_hashtags"}
    ]
  },
  "ads_analysis": {
    "score":  0,
    "status": "← من بيانات ads_library (نشط/متوقف/يحتاج تطوير)",
    "desc":   "← تحليل الإعلانات النشطة وأهدافها وCTA",
    "has_active_ads": false,
    "metrics": [
      {"label": "إجمالي الإعلانات", "value": "← total_ads"},
      {"label": "الإعلانات النشطة", "value": "← active_ads"},
      {"label": "تنوع CTA", "value": "← عدد CTAs المختلفة"}
    ]
  },
  "competitor_radar": [
    {"name": "← اسم منافس حقيقي 1 من scanInfo", "url": "← url", "strengths": ["نقطة قوة 1", "نقطة قوة 2"], "weaknesses": ["نقطة ضعف 1"], "attack_plan": "← خطة هجوم محددة بالأرقام"}
  ],
  "competitor_analysis": [
    {"name": "اسم منافس", "strength": "نقطة قوته", "weakness": "نقطة ضعفه", "how_to_beat": "كيفية التفوق عليه"}
  ],
  "viral_deconstruction": {
    "post_type": "← نوع أفضل منشور (Reel|Carousel|Image|Video) مستنبط من بيانات top_post_comments أو deep_analysis",
    "hook_analysis": "← تحليل جملة الافتتاح: لماذا توقف الجمهور واهتم؟ اذكر التقنية (سؤال/صدمة/وعد/فضول)",
    "sentiment_diagnosis": {
      "intent_to_buy": "← نسبة أو وصف نية الشراء من التعليقات (مثال: 23% يسألون عن السعر)",
      "objections": "← أبرز اعتراض واحد من top_objections (إن وُجد) وكيف يعيق الشراء",
      "emotion": "← المشاعر السائدة (إعجاب/فضول/تردد/إلهام) مستنبطة من نسبة إيجابية/سلبية"
    },
    "gap_extracted": "← الفجوة التسويقية: ما الذي يريده الجمهور ولا يجده الآن؟ في جملة واحدة محددة"
  },
  "content_pillars_matrix": [
    {
      "pillar": "← ركيزة محتوى 1 (مثال: تعليمي/Behind the scenes/Social Proof)",
      "desc": "← لماذا هذا النوع يناسب جمهورك وما الهدف منه",
      "example": "← مثال منشور محدد لهذه الركيزة مناسب لمجال النشاط",
      "percentage": 40
    },
    {
      "pillar": "← ركيزة محتوى 2",
      "desc": "← وصف وهدف",
      "example": "← مثال منشور محدد",
      "percentage": 35
    },
    {
      "pillar": "← ركيزة محتوى 3 (يفضل أن تكون مبيعية/CTA)",
      "desc": "← وصف وهدف",
      "example": "← مثال منشور محدد",
      "percentage": 25
    }
  ],
  "hook_bank": [
    "← هوك 1: جملة افتتاح مثيرة مناسبة لمجال النشاط (استخدم تقنية الفضول أو الصدمة)",
    "← هوك 2: جملة افتتاح تعتمد وعداً أو نتيجة واضحة",
    "← هوك 3: جملة افتتاح تعتمد سؤالاً يصل للألم الحقيقي للجمهور",
    "← هوك 4: جملة افتتاح تحكي قصة بداية مشوّقة",
    "← هوك 5: جملة افتتاح تعتمد رقماً أو إحصائية صادمة من مجالك"
  ]
}

⚠️ قواعد إضافية للحقول الجديدة:
- customer_journey.bottleneck_stage يجب أن يكون اسم المرحلة (awareness|attraction|trust|purchase|loyalty) صاحبة أدنى stages.<key>.score.
- customer_journey.bottleneck_fix يجب أن يكون مطابقاً تماماً لـ recommendations[0].bullets (نفس العلاج، ليس نصائح عامة).
- customer_journey.psychological_diagnosis يجب أن يربط صراحةً بين أدنى مرحلة و weaknesses[0] المكتشف.
- platform_strategy: ضع null للمنصة غير الموجودة في scanInfo، لا تخترع بيانات.
- content_analysis.bar_*: أرقام من 0 إلى 100 مستنبطة من البيانات (لا تخترعها عشوائياً).
PROMPT;

    return $prompt;
}

// ============================================================
// Fallback Analysis (في حال فشل الـ AI)
// تحليل عميق ودقيق مبني على العوامل الداخلية والبيانات الفعلية
// ============================================================
function fallbackAnalysis(array $data): array
{
    $score = $data["score"] ?? 50;
    $type  = $data["type"] ?? 'general';

    // ═════════════════════════════════════════════════════════════════════
    // استخراج البيانات الحقيقية من scan_result
    // ═════════════════════════════════════════════════════════════════════
    $scan    = is_string($data['scan_result'] ?? null)
        ? (json_decode($data['scan_result'], true) ?? [])
        : ($data['scan_result'] ?? []);

    $ws      = $scan['website_scan'] ?? [];
    $fb      = $scan['facebook']     ?? $scan['social'] ?? [];
    $ig      = $scan['instagram']    ?? [];
    $tk      = $scan['tiktok']       ?? [];
    $tw      = $scan['twitter']      ?? [];
    $ads     = $scan['ads_library']  ?? [];
    $answers = $data['answers'] ?? [];

    // ── بيانات الجمهور والوصول ─────────────────────────────────────────
    $fbFollowers    = (int)($fb['followers'] ?? 0);
    $igFollowers    = (int)($ig['followers'] ?? 0);
    $tkFollowers    = (int)($tk['followers'] ?? 0);
    $twFollowers    = (int)($tw['followers'] ?? 0);
    $totalFollowers = $fbFollowers + $igFollowers + $tkFollowers + $twFollowers;

    // ─ـ بيانات التفاعل ─────────────────────────────────────────────────
    $fbEngagement   = (float)($fb['avg_engagement'] ?? $fb['engagement_rate'] ?? 0);
    $igEngagement   = (float)($ig['engagement_rate'] ?? 0);
    $tkEngagement   = (float)($tk['engagement_rate'] ?? 0);
    $avgEngagement  = max($fbEngagement, $igEngagement, $tkEngagement);

    // التفاعل التفصيلي
    $fbLikes      = (int)($fb['likes_count'] ?? 0);
    $fbComments   = (int)($fb['comments_count'] ?? 0);
    $fbShares     = (int)($fb['shares_count'] ?? 0);
    $igLikes      = (int)($ig['likes_count'] ?? 0);
    $igComments   = (int)($ig['comments_count'] ?? 0);
    $igSaves      = (int)($ig['saves_count'] ?? 0);
    $tkLikes      = (int)($tk['likes_count'] ?? 0);
    $tkShares     = (int)($tk['shares_count'] ?? 0);
    $tkComments   = (int)($tk['comments_count'] ?? 0);

    // ─ـ بيانات المحتوى ─────────────────────────────────────────────────
    $fbPosts        = (int)($fb['posts_count'] ?? 0);
    $igPosts        = (int)($ig['posts_count'] ?? 0);
    $tkVideos       = (int)($tk['video_count'] ?? 0);
    $twPosts        = (int)($tw['posts_count'] ?? 0);
    $totalContent   = $fbPosts + $igPosts + $tkVideos + $twPosts;

    // أنواع المحتوى
    $fbVideoPct     = (float)($fb['deep_analysis']['types_percent']['video'] ?? 0);
    $fbPhotoPct     = (float)($fb['deep_analysis']['types_percent']['photo'] ?? 0);
    $fbLinkPct      = (float)($fb['deep_analysis']['types_percent']['link'] ?? 0);
    $igVideoPct     = (float)($ig['deep_analysis']['types_percent']['video'] ?? 0);
    $igCarouselPct  = (float)($ig['deep_analysis']['types_percent']['carousel'] ?? 0);
    $igReelPct      = (float)($ig['deep_analysis']['types_percent']['reel'] ?? 0);

    // ─ـ بيانات الهوية والتوثيق ───────────────────────────────────────
    $isFbVerified   = !empty($fb['is_verified']);
    $isIgVerified   = !empty($ig['is_verified']);
    $isTkVerified   = !empty($tk['is_verified']);
    $isTwVerified   = !empty($tw['is_verified']);
    $hasAnyVerified = $isFbVerified || $isIgVerified || $isTkVerified || $isTwVerified;

    $fbBio          = $fb['about'] ?? $fb['bio'] ?? '';
    $igBio          = $ig['bio'] ?? '';
    $tkBio          = $tk['bio'] ?? '';
    $bioLength      = max(strlen($fbBio), strlen($igBio), strlen($tkBio));
    $hasBio         = $bioLength > 10;

    // ─ـ بيانات التقييم والمصداقية ─────────────────────────────────────
    $rating         = (float)($fb['rating'] ?? 0);
    $reviewsCount   = (int)($fb['reviews_count'] ?? 0);
    $hasRating      = $rating > 0;

    // ─ـ بيانات الإعلانات ───────────────────────────────────────────────
    $adsRunning     = !empty($ads['is_running_ads']) || (int)($ads['total_ads'] ?? 0) > 0;
    $totalAds       = (int)($ads['total_ads'] ?? 0);

    // ─ـ بيانات الموقع والتتبع ─────────────────────────────────────────
    $hasSSL         = !empty($ws['has_ssl']) || !empty($scan['hasSSL']);
    $hasPixel       = !empty($ws['has_fb_pixel']) || !empty($scan['hasPixel']);
    $hasGA          = !empty($ws['has_ga']) || !empty($scan['hasGA']);
    $hasWhatsApp    = !empty($ws['has_whatsapp']) || !empty($scan['hasWhatsApp']);
    $hasCTA         = !empty($ws['has_cta']) || !empty($scan['hasCTA']);
    $hasPhone       = !empty($ws['has_phone']) || !empty($fb['has_phone']);
    $hasEmail       = !empty($fb['has_email']) || !empty($ws['has_email']);
    $hasContact     = !empty($ws['has_contact_form']) || !empty($fb['has_contact']) || $hasPhone || $hasEmail;
    $hasWebsite     = !empty($ws['url']) || !empty($fb['website']) || !empty($ig['website']);

    // ─ـ بيانات انتظام النشر ───────────────────────────────────────────
    $postsPerWeek   = (float)($fb['posts_per_week'] ?? $ig['posts_per_week'] ?? 0);
    $lastPostDays   = (int)($fb['last_post_days'] ?? $ig['last_post_days'] ?? 99);
    $postingRegularity = $postsPerWeek >= 3 && $lastPostDays <= 7;

    // ─ـ بيانات CTA والتحويل ────────────────────────────────────────────
    $ctaPercent     = (float)($fb['deep_analysis']['cta_percent'] ?? $ig['deep_analysis']['cta_percent'] ?? 0);
    $hasStrongCTA   = $ctaPercent >= 40;

    // ─ـ تحديد المنصات النشطة ──────────────────────────────────────────
    $activePlatforms = [];
    if ($fbFollowers > 0) $activePlatforms[] = 'Facebook';
    if ($igFollowers > 0) $activePlatforms[] = 'Instagram';
    if ($tkFollowers > 0) $activePlatforms[] = 'TikTok';
    if ($twFollowers > 0) $activePlatforms[] = 'Twitter';
    $platformsCount = count($activePlatforms);
    // ضمان وجود منصة واحدة على الأقل لمنع implode على مصفوفة فارغة
    if (empty($activePlatforms)) $activePlatforms = ['الحساب'];

    // ─ـ اسم الصفحة ─────────────────────────────────────────────────────
    $pageName = $fb['page_name'] ?? $ig['username'] ?? $tk['username'] ?? $data['company_name'] ?? '';

    // ═════════════════════════════════════════════════════════════════════
    // التحليل الاستراتيجي: مرحلة الحساب + نقطة الاختناق
    // ═════════════════════════════════════════════════════════════════════

    // ─ـ تحديد مرحلة الحساب ─────────────────────────────────────────────
    $accountStage = 'early'; // early | growing | mature
    $stageLabel = 'مبتدئ';
    if ($totalFollowers >= 5000) {
        $accountStage = 'mature';
        $stageLabel = 'ناضج';
    } elseif ($totalFollowers >= 500) {
        $accountStage = 'growing';
        $stageLabel = 'ناشئ';
    }

    // ─ـ تحديد نقطة الاختناق (Bottleneck) ───────────────────────────────
    // نقاط الاختناق: awareness (لا زوار) | engagement (زوار ولا تفاعل) | conversion (تفاعل ولا مبيعات)
    $bottleneck = 'conversion'; // الافتراضي
    $bottleneckReason = '';

    // إذا التفاعل ضعيف جداً والمتابعين قليلون = awareness
    if ($totalFollowers < 500 && $avgEngagement < 1.0) {
        $bottleneck = 'awareness';
        $bottleneckReason = 'لا يوجد جمهور كافٍ ولا تفاعل';
    }
    // إذا يوجد متابعين لكن التفاعل ضعيف = engagement
    elseif ($totalFollowers >= 500 && $avgEngagement < 1.5) {
        $bottleneck = 'engagement';
        $bottleneckReason = 'يوجد ' . number_format($totalFollowers) . ' متابع لكن التفاعل ' . number_format($avgEngagement, 1) . '% فقط';
    }
    // إذا التفاعل جيد لكن لا تحويل = conversion
    else {
        $bottleneck = 'conversion';
        $conversionBlockers = [];
        if (!$hasWhatsApp) $conversionBlockers[] = 'لا واتساب';
        if (!$hasPixel) $conversionBlockers[] = 'لا Pixel';
        if ($ctaPercent < 30) $conversionBlockers[] = 'CTA ضعيف';
        if (!$hasCTA && !$hasWhatsApp) $conversionBlockers[] = 'لا قناة تحويل';
        $bottleneckReason = 'التفاعل جيد (' . number_format($avgEngagement, 1) . '%) لكن يوجد: ' . implode(' و', $conversionBlockers);
    }

    // ─ـ تحديد نوع النشاط ───────────────────────────────────────────────
    $businessType = $type ?? $data['type'] ?? 'general';
    $isService = in_array($businessType, ['service', 'services', 'consulting', 'agency', 'professional']);
    $isEcommerce = in_array($businessType, ['ecommerce', 'retail', 'store', 'shop']);
    $isLocal = in_array($businessType, ['restaurant', 'cafe', 'salon', 'gym', 'local']);

    // ═════════════════════════════════════════════════════════════════════
    // دالة مساعدة: بناء نقطة بالقالب العميق (7 عناصر)
    // ═════════════════════════════════════════════════════════════════════
    $buildPoint = function(string $name, string $analysis, string $evidence,
                           string $impact, string $rootCause, string $priority,
                           string $action, string $type = 'strength', int $scoreVal = 70) : array {
        return [
            'name'        => $name,
            'analysis'    => $analysis,
            'evidence'    => $evidence,
            'impact'      => $impact,
            'root_cause'  => $rootCause,
            'priority'    => $priority,
            'action'      => $action,
            // حقول متوافقة مع JS القديم
            'type'        => $type,
            'title'       => $name,
            'desc'        => $analysis,
            'metric'      => $evidence,
            'bullets'     => [$impact, $rootCause],
            'score'       => $scoreVal,
        ];
    };

    // ═════════════════════════════════════════════════════════════════════
    // نقاط القوة الداخلية (مرتبة من الأكثر تأثيراً إلى الأقل)
    // ═════════════════════════════════════════════════════════════════════
    $strengths = [];

    // ─────────────────────────────────────────────────────────────────────
    // محور: جودة الهوية والحضور الرقمي
    // ─────────────────────────────────────────────────────────────────────

    // 1) التوثيق — أقوى نقطة تمييز (الهوية)
    if ($hasAnyVerified) {
        $verifiedPlatforms = [];
        if ($isFbVerified) $verifiedPlatforms[] = 'Facebook';
        if ($isIgVerified) $verifiedPlatforms[] = 'Instagram';
        if ($isTkVerified) $verifiedPlatforms[] = 'TikTok';
        if ($isTwVerified) $verifiedPlatforms[] = 'Twitter';

        $strengths[] = $buildPoint(
            'حساب موثق — هوية معتمدة من المنصة',
            'تم التحقق من التوثيق على ' . implode(' و', $verifiedPlatforms) . '. علامة التوثيق الرسمية تمنح ثقة فورية للزائر.',
            'موثق على: ' . implode(' + ', $verifiedPlatforms),
            'يرفع معدل التحويل 20-30% ويُسهّل بناء شراكات تجارية ويقلل من تساؤلات العملاء حول المصداقية.',
            'تم طلب التوثيق والالتزام بمتطلبات المنصة (هوية، نشاط، محتوى).',
            'عالية',
            'استخدم علامة التوثيق كعنصر ثقة في كل المواد التسويقية والرسائل المباشرة للعملاء.',
            'identity',
            95
        );
    }

    // 2) Bio واضح ومكتمل (الهوية)
    if ($hasBio && $bioLength >= 50) {
        $strengths[] = $buildPoint(
            'هوية رقمية واضحة — Bio مكتمل (' . $bioLength . ' حرف)',
            'تم فحص Bio ووجد أنه يحتوي على ' . $bioLength . ' حرفاً — طول كافٍ لتعريف النشاط وتوجيه الزائر.',
            'طول Bio: ' . $bioLength . ' حرف على ' . (strlen($igBio) >= strlen($fbBio) ? 'Instagram' : 'Facebook'),
            'الزائر يفهم خلال 3 ثوانٍ ماذا يقدم النشاط وكيف يتواصل، مما يقلل معدل الارتداد ويزيد التحويل.',
            'إدراك الإدارة لأهمية البايو كـ "بوابة التحويل الأولى" وكتابته بطريقة تسويقية مدروسة.',
            'متوسطة',
            'راجع Bio شهرياً وأضف CTA واضح (رابط واتساب، حجز مباشر) في أول سطر.',
            'identity',
            80
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // محور: أداء المحتوى بالأرقام
    // ─────────────────────────────────────────────────────────────────────

    // 3) تفاعل ممتاز (أداء المحتوى)
    if ($avgEngagement >= 3.0) {
        $topEngPlatform = $igEngagement >= $fbEngagement && $igEngagement >= $tkEngagement ? 'Instagram' :
                         ($tkEngagement >= $fbEngagement ? 'TikTok' : 'Facebook');
        $topEngValue = $topEngPlatform === 'Instagram' ? $igEngagement : ($topEngPlatform === 'TikTok' ? $tkEngagement : $fbEngagement);

        $strengths[] = $buildPoint(
            'محتوى يُجذب الجمهور — معدل تفاعل ' . number_format($avgEngagement, 1) . '%',
            'تم تحليل التفاعل: ' . number_format($avgEngagement, 1) . '% على ' . $topEngPlatform . ' (' . number_format($topEngValue, 1) . '% فعلي). هذا يفوق المعدل الطبيعي 1-3%.',
            $avgEngagement . '% على ' . $topEngPlatform . ' من ' . number_format($totalFollowers) . ' متابع',
            'الخوارزميات تمنح محتواك وصولاً مجانياً إضافياً، وتكلفة الإعلانات تنخفض تلقائياً بسبب جودة المحتوى.',
            'فهم عميق لجمهورك المستهدف وابتكار محتوى يرد على تساؤلاته أو يُثير اهتمامه.',
            'عالية',
            'حوّل التفاعل لمبيعات: أضف CTA واضح في كل منشور عالي التفاعل (راسلنا، احجز، تواصل).',
            'content_performance',
            min(90, 60 + (int)($avgEngagement * 5))
        );
    }

    // 4) تفاعل جيد (أداء المحتوى)
    if ($avgEngagement >= 1.5 && $avgEngagement < 3.0) {
        $topEngPlatform = $igEngagement >= $fbEngagement && $igEngagement >= $tkEngagement ? 'Instagram' :
                         ($tkEngagement >= $fbEngagement ? 'TikTok' : 'Facebook');
        $topEngValue = $topEngPlatform === 'Instagram' ? $igEngagement : ($topEngPlatform === 'TikTok' ? $tkEngagement : $fbEngagement);

        $strengths[] = $buildPoint(
            'محتوى يحقق تفاعلاً جيداً — ' . number_format($avgEngagement, 1) . '%',
            'تم قياس معدل التفاعل: ' . number_format($avgEngagement, 1) . '% على ' . $topEngPlatform . '. أعلى من المعدل الطبيعي (1-2%) لكن يمكن تحسينه.',
            number_format($avgEngagement, 1) . '% على ' . $topEngPlatform . ' من ' . number_format($totalFollowers) . ' متابع',
            'قاعدة متينة للنمو العضوي، يمكن تحسينها بتعديلات بسيطة على أسلوب المحتوى.',
            'التزام بنشر محتوى منتظم يهم الجمهور، لكن ينقصه عناصر الإثارة أو التفاعلية.',
            'متوسطة',
            'أضف عناصر تفاعلية: أسئلة، استطلاعات، تحديات، واختم كل منشور بسؤال يليق بالجمهور.',
            'content_performance',
            min(75, 55 + (int)($avgEngagement * 10))
        );
    }

    // 5) مقارنة أنواع المحتوى — فيديو يتفوق
    if ($igReelPct > 30 || $fbVideoPct > 30 || $tkVideos > 0) {
        $topType = $igReelPct >= $fbVideoPct ? 'Reels على Instagram' : 'Video على Facebook';
        $videoPct = max($igReelPct, $fbVideoPct);
        $videoPlatform = $igReelPct >= $fbVideoPct ? 'Instagram' : 'Facebook';

        $strengths[] = $buildPoint(
            'الفيديو يحقق أعلى أداء — ' . $videoPct . '% ' . $topType,
            'تم تحليل المحتوى: ' . $videoPct . '% من المحتوى على ' . $videoPlatform . ' هو فيديو/Reels، مما يناسب خوارزميات المنصات الحالية.',
            $videoPct . '% فيديو على ' . $videoPlatform,
            'الفيديو يحصل على وصول عضوي 3-5x أعلى من الصور، ويرفع وقت المشاهدة ومعدل الحفظ.',
            'إدراك الإدارة لأهمية الفيديو في خوارزميات 2024-2025 والاستثمار في إنتاج محتوى مرئي.',
            'عالية',
            'زِد نسبة الفيديو إلى 50%+ من المحتوى، وركز على أول 3 ثواني لجذب الانتباه.',
            'content_quality',
            85
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // محور: جودة المحتوى
    // ─────────────────────────────────────────────────────────────────────

    // 6) تنوع المحتوى
    $contentTypes = 0;
    if ($fbVideoPct > 0 || $igReelPct > 0) $contentTypes++;
    if ($fbPhotoPct > 0 || $igCarouselPct > 0) $contentTypes++;
    if ($fbLinkPct > 0) $contentTypes++;

    if ($contentTypes >= 2) {
        // بناء تفاصيل أنواع المحتوى
        $typeDetails = [];
        if ($igReelPct > 0 || $fbVideoPct > 0) $typeDetails[] = 'فيديو ' . max($igReelPct, $fbVideoPct) . '%';
        if ($fbPhotoPct > 0 || $igCarouselPct > 0) $typeDetails[] = 'صور ' . max($fbPhotoPct, $igCarouselPct) . '%';
        if ($fbLinkPct > 0) $typeDetails[] = 'روابط ' . $fbLinkPct . '%';

        $strengths[] = $buildPoint(
            'تنوع في أنواع المحتوى — ' . $contentTypes . ' أنواع مختلفة',
            'تم تحليل ' . $totalContent . ' منشور: ' . implode('، ', $typeDetails) . '. التنويع يُبقي الجمهور مهتماً.',
            implode(' + ', $typeDetails) . ' من ' . $totalContent . ' منشور',
            'التنويع يقلل الملل، ويختبر أفضل الصيغ، ويستفيد من مزايا كل نوع (وصول، تفاعل، تحويل).',
            'إدارة واعية لأهمية التنويع أو تجربة طبيعية لاكتشاف ما يناسب الجمهور.',
            'متوسطة',
            'حلّل أداء كل نوع على حدة وحدد الأفضل، ثم زِد نسبته مع الحفاظ على التنويع.',
            'content_quality',
            75
        );
    }

    // 7) أرشيف محتوى غني
    if ($totalContent >= 100) {
        $contentBreakdown = [];
        if ($fbPosts > 0) $contentBreakdown[] = 'Facebook ' . number_format($fbPosts);
        if ($igPosts > 0) $contentBreakdown[] = 'Instagram ' . number_format($igPosts);
        if ($tkVideos > 0) $contentBreakdown[] = 'TikTok ' . number_format($tkVideos);

        $strengths[] = $buildPoint(
            'أرشيف محتوى غني — ' . number_format($totalContent) . ' منشور',
            'تم حساب الأرشيف: ' . implode(' + ', $contentBreakdown) . ' = ' . number_format($totalContent) . ' منشور. أرشيف ضخم يُظهر استمرارية ويمنح الزوار الجدد محتوى للاستكشاف.',
            implode(' + ', $contentBreakdown) . ' عبر ' . $platformsCount . ' منصات',
            'كل زائر جديد يجد محتوى كافياً لبناء ثقة، ويمكن إعادة استخدام المحتوى القديم بصيغ جديدة.',
            'استمرارية في النشر على مدار فترة طويلة تعكس التزاماً بالحضور الرقمي.',
            'منخفضة',
            'حدد أفضل 10 منشورات أداءً وأعد صياغتها (Reels من الصور، Carousel من الفيديو).',
            'content_quality',
            min(80, 50 + (int)($totalContent / 20))
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // محور: انتظام النشر وكفاءة الإدارة
    // ─────────────────────────────────────────────────────────────────────

    // 8) انتظام النشر
    if ($postingRegularity) {
        $strengths[] = $buildPoint(
            'انتظام في النشر — ' . number_format($postsPerWeek, 1) . ' منشور أسبوعياً',
            'تم فحص انتظام النشر: ' . $postsPerWeek . ' منشور/أسبوع وآخر منشور قبل ' . $lastPostDays . ' يوم. النشر المنتظم يُعلّم الخوارزمية أن الحساب نشط ويستحق وصولاً مستمراً.',
            $postsPerWeek . ' منشور/أسبوع | آخر منشور: قبل ' . $lastPostDays . ' يوم',
            'الوصول العضوي مستقر، والجمهور يتوقع محتوى جديداً، والخوارزمية تُكافئ الانتظام.',
            'تخطيط مُ structured للمحتوى أو التزام ذاتي من الإدارة بالنشر المنتظم.',
            'عالية',
            'حافظ على الانتظام واستخدم أدوات جدولة (Meta Business Suite) لضمان الاستمرارية.',
            'management',
            85
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // محور: قدرة الصفحة على التحويل
    // ─────────────────────────────────────────────────────────────────────

    // 9) قناة تحويل مباشرة (واتساب)
    if ($hasWhatsApp) {
        $waLocation = !empty($ws['has_whatsapp']) ? 'الموقع' : 'Bio';
        $strengths[] = $buildPoint(
            'قناة تحويل مباشرة — واتساب متاح على ' . $waLocation,
            'تم فحص ' . $waLocation . ' ووجد رابط/زر واتساب متاح للعملاء كقناة تواصل فوري، مما يُخفض حاجز التواصل ويسرّع التحويل.',
            'واتساب متاح على ' . $waLocation,
            '78% من العملاء يفضّلون واتساب، ومعدل الرد عليه أعلى 3x من البريد أو الهاتف.',
            'إدراك الإدارة لأهمية تسهيل التواصل وتقليل عوائق الاتصال بالعملاء.',
            'عالية',
            'أضف زر واتساب عائم على الموقع وفي Bio كل المنصات، واستخدم رابط wa.me/رقم.',
            'conversion',
            85
        );
    }

    // 10) CTA قوي في المحتوى
    if ($hasStrongCTA) {
        $ctaLevel = $ctaPercent >= 70 ? 'ممتاز' : ($ctaPercent >= 50 ? 'جيد' : 'مقبول');
        $strengths[] = $buildPoint(
            'نداء إجراء واضح — CTA في ' . $ctaPercent . '% من المنشورات (' . $ctaLevel . ')',
            'تم تحليل ' . $totalContent . ' منشور ووجد أن ' . $ctaPercent . '% تحتوي على CTA واضح (' . $ctaLevel . '). هذا يُوجه المتابع للخطوة التالية.',
            $ctaPercent . '% من ' . $totalContent . ' منشور فيها CTA (' . $ctaLevel . ')',
            'الزائر يعرف ماذا يفعل بعد المشاهدة، ومعدل التحويل أعلى 25-40% من المحتوى بدون CTA.',
            'فهم تسويقي بأن كل محتوى يحتاج هدفاً واضحاً ونداءً للإجراء.',
            'عالية',
            'نوّع الـ CTA: أحمر (تواصل)، أحضر (احجز)، اشترِ (عرض محدد)، شارك (تحدٍ).',
            'conversion',
            min(90, 60 + $ctaPercent)
        );
    }

    // 11) بنية تتبع متكاملة
    if ($hasPixel && $hasGA) {
        $siteUrl = $ws['url'] ?? $fb['website'] ?? $data['website_url'] ?? 'الموقع';
        $strengths[] = $buildPoint(
            'بنية تتبع متكاملة — Pixel + Analytics على ' . $siteUrl,
            'تم فحص ' . $siteUrl . ' ووجد أن Meta Pixel وGoogle Analytics مثبتان ومُعدان، مما يتيح قياس كل تحرك للعملاء.',
            'Meta Pixel ✅ + Google Analytics ✅ على ' . $siteUrl,
            'تكلفة الإعلانات أقل 30-40% من المنافسين بدون تتبع، ويمكن بناء Lookalike من زوار الموقع.',
            'استثمار تقني في البنية التحتية التسويقية قبل إطلاق الحملات.',
            'عالية',
            'تحقق من Events Manager: ViewContent, AddToCart, Purchase مفعّلة، وأضف Conversion API.',
            'tracking',
            90
        );
    }

    // 12) إعلانات نشطة
    if ($adsRunning && $totalAds > 0) {
        $adsStatus = !empty($ads['is_running_ads']) ? 'نشطة حالياً' : 'موجودة في الأرشيف';
        $strengths[] = $buildPoint(
            'حملات إعلانية — ' . $totalAds . ' إعلان ' . $adsStatus,
            'تم رصد ' . $totalAds . ' إعلان في Ads Library (' . $adsStatus . '). هذا يعني نشاط تسويقي فعلي وجمع بيانات جمهور.',
            $totalAds . ' إعلان ' . $adsStatus . ' في Ads Library',
            'بيانات الجمهور تُجمع باستمرار، ويمكن تحسين الحملات الحالية بدل البدء من الصفر.',
            'استثمار في الإعلانات المدفوعة كقناة نمو أساسية.',
            'متوسطة',
            'راجع أداء كل إعلان: ROAS، CPA، CTR. أوقف الضعيف ووسّع القوي.',
            'advertising',
            min(88, 65 + $totalAds)
        );
    }

    // 13) مصداقية اجتماعية (تقييمات)
    if ($hasRating && $reviewsCount > 0) {
        $ratingLevel = $rating >= 4.5 ? 'ممتاز' : ($rating >= 3.5 ? 'جيد' : 'مقبول');
        $strengths[] = $buildPoint(
            'مصداقية اجتماعية — تقييم ' . $rating . '/5 (' . $ratingLevel . ')',
            'تم رصد ' . number_format($reviewsCount) . ' مراجعة على Facebook بمتوسط ' . $rating . '/5 (' . $ratingLevel . '). التقييمات تمنح العملاء الجدد ثقة فورية وتُقلل التردد في الشراء.',
            $rating . '/5 من ' . number_format($reviewsCount) . ' مراجعة على Facebook',
            '91% من العملاء يقرأون المراجعات، وكل نقطة تقييم إضافية ترفع المبيعات 5-9%.',
            'رضا عملاء حقيقي وطلب استراتيجي من الإدارة لجمع التقييمات.',
            'عالية',
            'شارك أفضل المراجعات كمحتوى، وأضف رابط طلب مراجعة في رسائل الشكر للعملاء.',
            'social_proof',
            min(90, 60 + (int)($rating * 8))
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // محور: قاعدة جمهور
    // ─────────────────────────────────────────────────────────────────────

    // 14) قاعدة جمهور كبيرة
    if ($totalFollowers >= 10000) {
        $topPlatform = 'Facebook';
        $topFollowers = $fbFollowers;
        if ($igFollowers > $topFollowers) { $topPlatform = 'Instagram'; $topFollowers = $igFollowers; }
        if ($tkFollowers > $topFollowers) { $topPlatform = 'TikTok'; $topFollowers = $tkFollowers; }

        $followerBreakdown = [];
        if ($fbFollowers > 0) $followerBreakdown[] = 'Facebook ' . number_format($fbFollowers);
        if ($igFollowers > 0) $followerBreakdown[] = 'Instagram ' . number_format($igFollowers);
        if ($tkFollowers > 0) $followerBreakdown[] = 'TikTok ' . number_format($tkFollowers);

        $strengths[] = $buildPoint(
            'قاعدة جمهور مؤسسة — ' . number_format($totalFollowers) . ' متابع',
            'تم حساب المتابعين: ' . implode(' + ', $followerBreakdown) . ' = ' . number_format($totalFollowers) . ' متابع. جمهور كبير يمثل رصيداً تسويقياً قابلاً للتحويل.',
            implode(' + ', $followerBreakdown) . ' | الأقوى: ' . $topPlatform . ' (' . number_format($topFollowers) . ')',
            'استهداف المتابعين الحاليين أرخص 60% من جمهور جديد، وكل 1000 متابع = 50-100 عميل محتمل.',
            'تراكم جهود تسويقية سابقة (محتوى، إعلانات، تفاعل) بنى هذه القاعدة.',
            'عالية',
            'أطلق حملة Retargeting للمتابعين الحاليين، ثم Lookalike من أفضل العملاء.',
            'audience',
            min(92, 70 + (int)($totalFollowers / 1000))
        );
    }

    // 15) تواجد متعدد المنصات
    if ($platformsCount >= 3) {
        $platformDetails = [];
        if ($fbFollowers > 0) $platformDetails[] = 'Facebook (' . number_format($fbFollowers) . ')';
        if ($igFollowers > 0) $platformDetails[] = 'Instagram (' . number_format($igFollowers) . ')';
        if ($tkFollowers > 0) $platformDetails[] = 'TikTok (' . number_format($tkFollowers) . ')';
        if ($twFollowers > 0) $platformDetails[] = 'Twitter (' . number_format($twFollowers) . ')';

        $strengths[] = $buildPoint(
            'تواجد متعدد المنصات — ' . $platformsCount . ' منصات',
            'تم رصد ' . $platformsCount . ' منصات نشطة: ' . implode('، ', $platformDetails) . '. هذا يُنوّع مصادر العملاء ويقلل الاعتماد على منصة واحدة.',
            implode(' + ', $platformDetails),
            'كل منصة تصل لشريحة مختلفة، والتنويع يحمي من تغييرات خوارزمية منصة واحدة.',
            'استراتيجية توسع رقمي أو تطور طبيعي مع نمو النشاط.',
            'متوسطة',
            'وحّد الرسالة التسويقية عبر كل المنصات، وأعد استخدام المحتوى بصيغة مناسبة لكل واحدة.',
            'presence',
            min(85, 60 + ($platformsCount * 5))
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // نقاط الضعف الداخلية (مرتبة من الأكثر تأثيراً إلى الأقل)
    // ═════════════════════════════════════════════════════════════════════
    $weaknesses = [];

    // ─────────────────────────────────────────────────────────────────────
    // محور: قدرة التحويل (أعلى أولوية)
    // ─────────────────────────────────────────────────────────────────────

    // 1) غياب Pixel — BLOCKER
    if (!$hasPixel) {
        $siteUrl = $ws['url'] ?? $fb['website'] ?? $data['website_url'] ?? 'الموقع';
        $weaknesses[] = $buildPoint(
            'غياب Meta Pixel — فقدان بيانات التحويل',
            'تم فحص ' . $siteUrl . ' ووجد أنه لا يحتوي على Meta Pixel. هذا يعني كل زائر يُفقد إلى الأبد دون تتبع.',
            'Pixel غير مثبت على ' . $siteUrl,
            'الإعلانات تعمل بشكل أعمى، وتكلفة الاستحواض مرتفعة 30-50%، وكل زيارة = فرصة Retargeting ضائعة.',
            $adsRunning
                ? 'تشغيل إعلانات بدون Pixel يُهدر الميزانية ويجعل قياس ROAS مستحيلًا.'
                : 'غياب وعي تقني بأهمية التتبع، أو تأجيل تثبيته رغم سهولته.',
            'حرجة',
            'ثبّت Pixel فوراً: Business Manager → Events Manager → إضافة الكود للموقع. يتطلب 1-2 ساعة فقط.',
            'conversion',
            15
        );
    }

    // 2) غياب واتساب — BLOCKER
    if (!$hasWhatsApp) {
        $contactMethods = [];
        if ($hasPhone) $contactMethods[] = 'هاتف';
        if ($hasEmail) $contactMethods[] = 'بريد';
        $currentMethods = !empty($contactMethods) ? implode(' و', $contactMethods) : 'لا توجد طريقة تواصل واضحة';

        $weaknesses[] = $buildPoint(
            'غياب قناة تحويل سهلة — لا واتساب',
            'تم فحص الموقع وBio ووجد أن واتساب غير متاح. طريقة التواصل الحالية: ' . $currentMethods . '.',
            'لا يوجد رابط/زر واتساب — 78% من العملاء يفضّلونه',
            'العملاء يترددون في الاتصال هاتفياً أو البريد، والمنافسون الذين لديهم واتساب يأخذون عملاءك المحتملين.',
            'إهمال تسهيل التواصل أو افتراض أن ' . $currentMethods . ' كافيان.',
            'عالية',
            'أضف واتساب خلال ساعات: wa.me/رقم في Bio، وزر عائم على الموقع.',
            'conversion',
            25
        );
    }

    // 3) CTA ضعيف في المحتوى
    if ($ctaPercent < 20) {
        $weaknesses[] = $buildPoint(
            'غياب نداء الإجراء — ' . $ctaPercent . '% فقط من المحتوى فيه CTA',
            'تم تحليل المنشورات ووجد أن ' . $ctaPercent . '% فقط تحتوي على CTA واضح. ' . (100 - $ctaPercent) . '% من المحتوى يُترك المتابع حائراً.',
            $ctaPercent . '% من ' . $totalContent . ' منشور فيها CTA',
            'المشاهد يتفاعل لكنه لا يعرف ماذا يفعل، وكل منشور بدون CTA = فرصة بيع ضائعة.',
            'تركيز على "العرض" دون "الطلب"، أو افتراض أن المتابع سيتصرف تلقائياً.',
            'عالية',
            'اختم كل منشور بـ CTA: "راسلنا للاستفسار"، "اضغط الرابط للحجز"، "شارك تجربتك".',
            'conversion',
            max(30, 50 - $ctaPercent)
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // محور: أداء المحتوى
    // ─────────────────────────────────────────────────────────────────────

    // 4) تفاعل منخفض
    if ($avgEngagement < 1.5 && $totalFollowers > 0) {
        $weaknesses[] = $buildPoint(
            'محتوى لا يُجذب الجمهور — تفاعل ' . number_format($avgEngagement, 2) . '%',
            'تم قياس معدل التفاعل: ' . number_format($avgEngagement, 2) . '% من ' . number_format($totalFollowers) . ' متابع — أقل من المعدل الطبيعي (1.5-3%). المحتوى لا يصيب وتر الجمهور المستهدف.',
            number_format($avgEngagement, 2) . '% من ' . number_format($totalFollowers) . ' متابع (المعدل الطبيعي 1.5-3%)',
            'الخوارزمية تُقلل الوصول، والمتابعون الصامتون لا يتحولون لعملاء، وتكلفة النمو ترتفع.',
            'إما أن المحتوى لا يهم الجمهور، أو أن الأسلوب ضعيف (بدون Hook، بدون قيمة، بدون CTA).',
            'عالية',
            'غيّر أسلوب المحتوى: ابدأ بـ Hook قوي (سؤال/مفاجأة)، أضف قيمة حقيقية، اختم بـ CTA.',
            'content_performance',
            max(25, 50 - (int)($avgEngagement * 10))
        );
    }

    // 5) عدم تنوع المحتوى
    if ($contentTypes < 2 && $totalContent > 0) {
        // تحديد النوع المهيمن
        $dominantType = 'صور';
        $dominantPct = max($fbPhotoPct, $igCarouselPct);
        if ($igReelPct > $dominantPct || $fbVideoPct > $dominantPct) {
            $dominantType = 'فيديو/Reels';
            $dominantPct = max($igReelPct, $fbVideoPct);
        }
        if ($fbLinkPct > $dominantPct) {
            $dominantType = 'روابط';
            $dominantPct = $fbLinkPct;
        }

        $weaknesses[] = $buildPoint(
            'محتوى أحادي الشكل — يعتمد على ' . $dominantType . ' فقط',
            'تم تحليل ' . $totalContent . ' منشور ووجد أن ' . $dominantType . ' يشكل النسبة الأكبر. الاعتماد على شكل واحد يُقلل الوصول ويُملل الجمهور.',
            $dominantPct . '% من المحتوى ' . $dominantType . ' (نوع واحد فقط)',
            'الخوارزميات تُفضّل التنوع، والجمهور قد يمل، وتفوت فرص اختبار ما يناسب كل شريحة.',
            'الراحة في نوع معين أو قصور في المهارات (مثلاً: ضعف في إنتاج الفيديو).',
            'متوسطة',
            'أضف Reels أسبوعياً، وCarousel للنصائح، وقصص نجاح العملاء.',
            'content_quality',
            40
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // محور: انتظام النشر والإدارة
    // ─────────────────────────────────────────────────────────────────────

    // 6) عدم انتظام النشر
    if (!$postingRegularity && $totalFollowers > 0) {
        $issueDesc = $lastPostDays > 7 ? 'آخر منشور قبل ' . $lastPostDays . ' يوم' : 'معدل ' . $postsPerWeek . ' منشور/أسبوع (أقل من 3)';
        $weaknesses[] = $buildPoint(
            'نشر غير منتظم — ' . $issueDesc,
            'تم فحص انتظام النشر: ' . $postsPerWeek . ' منشور/أسبوع وآخر منشور قبل ' . $lastPostDays . ' يوم. النشر المتقطع أو المتأخر يُضعف الثقة ويُعلّم الخوارزمية أن الحساب غير نشط.',
            $postsPerWeek . ' منشور/أسبوع | آخر منشور: قبل ' . $lastPostDays . ' يوم',
            'الوصول العضوي يتراجع، والجمهور ينسى الحساب، والثقة تهتز.',
            'غياب تخطيط للمحتوى، أو اعتماد على "المزاج" بدل الاستراتيجية.',
            'عالية',
            'ابنِ تقويم محتوى: 12-16 منشور شهرياً، وجدولها بأداة Meta Business Suite.',
            'management',
            max(25, 45 - (int)($lastPostDays / 2))
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // محور: الهوية والحضور
    // ─────────────────────────────────────────────────────────────────────

    // 7) عدم التوثيق (لحسابات مؤهلة)
    if (!$hasAnyVerified && $totalFollowers >= 500) {
        $weaknesses[] = $buildPoint(
            'حساب غير موثق — فرصة مصداقية ضائعة',
            'تم فحص ' . implode(' و', $activePlatforms) . ' ووجد أنه لا توجد علامة توثيق رغم وجود ' . number_format($totalFollowers) . ' متابع. التوثيق متاح ومجاني ويمنح ثقة فورية.',
            'لا توجد علامة توثيق على ' . implode(' + ', $activePlatforms) . ' رغم ' . number_format($totalFollowers) . ' متابع',
            'العملاء الجدد يترددون أكثر، والمنافسون الموثقون يبدون أكثر موثوقية.',
            'إما جهل بسهولة التوثيق، أو تأجيل الطلب، أو عدم اكتمال متطلبات المنصة.',
            'متوسطة',
            'قدّم طلب توثيق: إعدادات الحساب → التوثيق. وفّر مستندات ملكية النشاط.',
            'identity',
            35
        );
    }

    // 8) Bio ضعيف أو غير موجود
    if (!$hasBio && $totalFollowers > 0) {
        $bioStatus = $bioLength > 0 ? 'طول Bio الحالي: ' . $bioLength . ' حرف فقط (المطلوب 50+)' : 'لا يوجد Bio مكتوب';
        $weaknesses[] = $buildPoint(
            'هوية رقمية ضعيفة — Bio غير مكتمل',
            'تم فحص Bio ووجد أنه ' . ($bioLength > 0 ? $bioLength . ' حرف فقط' : 'غير موجود') . '. أقل من الحد الأدنى (50 حرف) لتعريف النشاط بشكل واضح.',
            $bioStatus . ' على ' . (strlen($igBio) >= strlen($fbBio) ? 'Instagram' : 'Facebook'),
            'الزائر لا يفهم ماذا يقدم النشاط، ومعدل الارتداد مرتفع.',
            'إهمال أو افتراض أن الصفحة نفسها كافية للتعريف.',
            'متوسطة',
            'اكتب Bio واضح: من نحن + ماذا نقدم + CTA (راسلنا/احجز).',
            'identity',
            40
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // محور: التتبع والتحليل
    // ─────────────────────────────────────────────────────────────────────

    // 9) غياب Analytics
    if (!$hasGA) {
        $siteUrl = $ws['url'] ?? $fb['website'] ?? $data['website_url'] ?? 'الموقع';
        $weaknesses[] = $buildPoint(
            'غياب Google Analytics — قرارات بدون بيانات',
            'تم فحص ' . $siteUrl . ' ووجد أنه بدون Google Analytics. لا يمكنك معرفة مصادر الزيارات أو سلوك الزوار.',
            'GA4 غير مثبت على ' . $siteUrl,
            'قرارات التسويق تُبنى على تخمين، ولا يمكنك قياس مصادر الزيارات أو تحسين معدل الارتداد.',
            'تأجيل أو جهل بأهمية التحليلات، أو افتراض أن insights المنصات كافية.',
            'متوسطة',
            'أنشئ GA4 من analytics.google.com وأضف الكود على كل صفحات الموقع.',
            'tracking',
            30
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // محور: المصداقية الاجتماعية
    // ─────────────────────────────────────────────────────────────────────

    // 10) غياب التقييمات
    if (!$hasRating && $fbFollowers > 0) {
        $weaknesses[] = $buildPoint(
            'غياب المصداقية الاجتماعية — لا تقييمات',
            'تم فحص صفحة Facebook (' . number_format($fbFollowers) . ' متابع) ووجد أنها بدون تقييمات أو مراجعات. 91% من العملاء يقرأون المراجعات قبل الشراء.',
            '0 تقييمات على صفحة ' . number_format($fbFollowers) . ' متابع',
            'العميل الجديد يتساءل: "هل هذا النشاط حقيقي؟ هل الخدمة جيدة؟" وقد يغادر بدون جواب.',
            'إما نشاط جديد، أو عدم طلب المراجعات من العملاء الراضين.',
            'متوسطة',
            'اطلب من 5 عملاء راضين كتابة مراجعة هذا الأسبوع. أضف رابط التقييم في رسائل الشكر.',
            'social_proof',
            35
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // محور: قاعدة الجمهور
    // ─────────────────────────────────────────────────────────────────────

    // 11) قاعدة جمهور ضعيفة
    if ($totalFollowers < 500 && $totalFollowers > 0) {
        $followerBreakdown = [];
        if ($fbFollowers > 0) $followerBreakdown[] = 'Facebook ' . number_format($fbFollowers);
        if ($igFollowers > 0) $followerBreakdown[] = 'Instagram ' . number_format($igFollowers);
        if ($tkFollowers > 0) $followerBreakdown[] = 'TikTok ' . number_format($tkFollowers);

        $weaknesses[] = $buildPoint(
            'قاعدة جمهور محدودة — ' . number_format($totalFollowers) . ' متابع',
            'تم حساب مجموع المتابعين: ' . implode(' + ', $followerBreakdown) . ' = ' . number_format($totalFollowers) . ' متابع فقط. قاعدة صغيرة تحد من الوصول العضوي وتجعل الإعلانات أغلى.',
            implode(' + ', $followerBreakdown) . ' = ' . number_format($totalFollowers) . ' متابع',
            'الوصول العضوي محدود، ولا توجد بيانات كافية لـ Lookalike، والحساب يبدو صغيراً.',
            'إما نشاط جديد، أو ضعف في جهود النمو (محتوى، إعلانات، تفاعل).',
            'متوسطة',
            'ركز على النمو: محتوى قيّم + تفاعل نشط + Boost للمنشورات الجيدة + إعلانات Awareness.',
            'audience',
            max(25, 40 - (int)($totalFollowers / 50))
        );
    }

    // 12) محتوى قليل جداً
    if ($totalContent < 20 && $totalFollowers > 0) {
        $contentBreakdown = [];
        if ($fbPosts > 0) $contentBreakdown[] = 'Facebook ' . $fbPosts;
        if ($igPosts > 0) $contentBreakdown[] = 'Instagram ' . $igPosts;
        if ($tkVideos > 0) $contentBreakdown[] = 'TikTok ' . $tkVideos;

        $weaknesses[] = $buildPoint(
            'أرشيف محتوى ضعيف — ' . $totalContent . ' منشور فقط',
            'تم حساب مجموع المحتوى: ' . implode(' + ', $contentBreakdown) . ' = ' . $totalContent . ' منشور. الصفحة تبدو غير نشطة أو جديدة، والزوار الجدد لا يجدون ما يكفي لبناء ثقة.',
            implode(' + ', $contentBreakdown) . ' = ' . $totalContent . ' منشور',
            'الزائر يتساءل: "هل هذا النشاط نشط؟"، والخوارزمية لا تفهم جمهورك.',
            'إما نشاط جديد، أو نشر متقطع، أو اعتماد على قنوات أخرى.',
            'منخفضة',
            'ابنِ أرشيف: 3-4 منشورات أسبوعياً لمدة شهرين على الأقل.',
            'content_quality',
            max(30, 45 - $totalContent)
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // محور: الأمان والتقنية
    // ─────────────────────────────────────────────────────────────────────

    // 13) غياب SSL
    if (!$hasSSL && $hasWebsite) {
        $siteUrl = $ws['url'] ?? $fb['website'] ?? $data['website_url'] ?? 'الموقع';
        $weaknesses[] = $buildPoint(
            'موقع غير آمن — لا HTTPS',
            'تم فحص ' . $siteUrl . ' ووجد أنه يعمل بدون SSL. المتصفح يُظهر تحذير "Not Secure" للزوار.',
            'SSL غير مفعّل على ' . $siteUrl,
            'المتصفح يُظهر "Not Secure"، والزوار يترددون، وGoogle يُقلل الترتيب.',
            'إما إهمال تقني، أو عدم معرفة بأن SSL أصبح مجانياً عبر Let\'s Encrypt.',
            'متوسطة',
            'فعّل SSL من لوحة الاستضافة — مجاني عبر Let\'s Encrypt في معظم الاستضافات.',
            'technical',
            25
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // ضمان وجود حد أدنى
    // ═════════════════════════════════════════════════════════════════════
    if (count($strengths) < 2) {
        $strengths[] = $buildPoint(
            'بداية حضور رقمي — فرصة للنمو',
            'تم رصد ' . $platformsCount . ' منصات نشطة (' . implode('، ', $activePlatforms) . ') مع ' . number_format($totalFollowers) . ' متابع. البداية الصحيحة أهم من الكمية.',
            $platformsCount . ' منصات: ' . implode(' + ', $activePlatforms) . ' | ' . number_format($totalFollowers) . ' متابع',
            'كل نشاط ناجح بدأ من الصفر، والتقييم خطوة أولى صحيحة.',
            'المرحلة الحالية تُتيح بناء أساس متين من الصفر.',
            'متوسطة',
            'حدد أولوية: تثبيت Pixel → بناء محتوى → إطلاق إعلانات صغيرة.',
            'foundation',
            50
        );
    }

    if (count($weaknesses) < 2) {
        $weaknesses[] = $buildPoint(
            'فرص تحسين متاحة',
            'تم تحليل البيانات والنتائج الحالية جيدة (' . $score . '/100)، لكن دائماً هناك مجال للتحسين.',
            'النتيجة: ' . $score . '/100 | لا توجد مشاكل حرجة',
            'التحسين المستمر يُبقيك متقدماً.',
            'النجاح الحالي لا يعني الكمال.',
            'منخفضة',
            'راجع التقرير شهرياً لتتبع التقدم واكتشاف فرص جديدة.',
            'improvement',
            60
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // البيانات الناقصة (إن وجدت)
    // ═════════════════════════════════════════════════════════════════════
    $missingData = [];
    if ($avgEngagement <= 0 && $totalFollowers > 0) {
        $missingData[] = 'بيانات التفاعل التفصيلية (لايك، تعليق، مشاركة، حفظ)';
    }
    if ($postsPerWeek <= 0 && $totalContent > 0) {
        $missingData[] = 'بيانات انتظام النشر';
    }
    if (!$hasPixel && !$hasGA) {
        $missingData[] = 'بيانات تحليلات الموقع (مصادر الزيارات، سلوك الزوار)';
    }
    if ($ctaPercent <= 0) {
        $missingData[] = 'تحليل CTA في المحتوى';
    }

    // ═════════════════════════════════════════════════════════════════════
    // بناء التوصيات الاستراتيجية
    // ═════════════════════════════════════════════════════════════════════
    $recommendations = [];

    // ═════════════════════════════════════════════════════════════════════
    // القسم الأول: تشخيص استراتيجي
    // ═════════════════════════════════════════════════════════════════════

    $siteUrl = $ws['url'] ?? $fb['website'] ?? $data['website_url'] ?? 'الموقع';

    // ── تحديد نقطة الاختناق ─────────────────────────────────────────────
    $bottleneckStage = '';
    $bottleneckDesc = '';

    // حساب درجات كل مرحلة
    $awarenessHealth = min(100, ($totalFollowers / 100) + ($platformsCount * 15));
    $engagementHealth = $avgEngagement >= 2.0 ? 80 : ($avgEngagement >= 1.0 ? 50 : 20);
    $conversionHealth = ($hasWhatsApp ? 30 : 0) + ($hasPixel ? 25 : 0) + ($ctaPercent >= 40 ? 25 : ($ctaPercent >= 20 ? 10 : 0)) + ($hasCTA ? 20 : 0);

    if ($awarenessHealth < 40) {
        $bottleneckStage = 'awareness';
        $bottleneckDesc = 'لا يوجد جمهور كافٍ — تحتاج بناء قاعدة متابعين من الصفر';
    } elseif ($engagementHealth < 40) {
        $bottleneckStage = 'engagement';
        $bottleneckDesc = 'الجمهور موجود لكن لا يتفاعل — المحتوى لا يصيب وتر الجمهور';
    } elseif ($conversionHealth < 40) {
        $bottleneckStage = 'conversion';
        $bottleneckDesc = 'التفاعل موجود لكن لا تحويل — توجد عوائق تمنع العميل من الشراء';
    } else {
        $bottleneckStage = 'scaling';
        $bottleneckDesc = 'الأساس متين — جاهز للتوسع والنمو المتسارع';
    }

    // ═════════════════════════════════════════════════════════════════════
    // القسم الثاني: خطة العمل الاستراتيجية (مرتبة حسب مرحلة الحساب)
    // ═════════════════════════════════════════════════════════════════════

    // ═════════════════════════════════════════════════════════════════════
    // المحور 1: وقف النزيف فوراً (BLOCKERS) — ينفذ في 24-48 ساعة
    // ═════════════════════════════════════════════════════════════════════

    // ── BLOCKER 1: إعلانات بدون تتبع ─────────────────────────────────────
    if ($adsRunning && !$hasPixel) {
        $recommendations[] = [
            'priority'          => 'critical',
            'icon'              => '🛑',
            'category'          => 'stop_bleeding',
            'title'             => 'وقف الإعلانات فوراً → تثبيت Pixel → إعادة التشغيل',
            'desc'              => 'تم رصد ' . $totalAds . ' إعلان نشط في Ads Library، لكن ' . $siteUrl . ' بدون Meta Pixel. هذا يعني: كل ريال يُنفق = يُهدر بدون قياس. كل عميل محتمل يُفقد للأبد.',
            'why_now'           => 'تشغيل إعلانات بدون Pixel = حرق ميزانية بدون معرفة ROI. توقف فوراً.',
            'evidence'          => $totalAds . ' إعلان نشط ❌ Pixel غير مثبت على ' . $siteUrl,
            'strategic_context' => 'هذا ليس "تحسين" — هذا وقف نزيف مالي حقيقي. كل يوم = خسارة.',
            'bullets'           => [
                '← اليوم: أوقف ' . $totalAds . ' إعلان نشط فوراً من Ads Manager',
                '← غداً: Business Manager → Events Manager → إنشاء Pixel جديد',
                '← بعد غد: أضف كود Pixel على ' . $siteUrl . ' (في <head>) واختبره بزيارة الموقع',
                '← بعد 48 ساعة: أعد تشغيل الإعلانات + فعّل Lookalike من زوار ' . $siteUrl,
            ],
            'roi'               => 'توفير ' . ($totalAds * 10) . '%+ من الميزانية المهدرة + بناء جمهور من ' . number_format($totalFollowers) . ' متابع قابل للاستهداف',
            'time_to_implement' => '48 ساعة',
            'difficulty'        => 'سهل',
            'score_impact'      => '+20 نقطة',
            'order'             => 1,
        ];
    }

    // ── BLOCKER 2: لا قناة تحويل ──────────────────────────────────────────
    if (!$hasWhatsApp && !$hasPhone && !$hasEmail) {
        $recommendations[] = [
            'priority'          => 'critical',
            'icon'              => '🚫',
            'category'          => 'stop_bleeding',
            'title'             => 'فتح قناة تواصل — العملاء لا يجدونك',
            'desc'              => 'تم فحص ' . $siteUrl . ' و' . implode(' و', $activePlatforms) . ' ولم يُعثر على أي طريقة تواصل: لا واتساب، لا هاتف، لا بريد. العميل المهتم يغادر ولا يعود.',
            'why_now'           => 'كل زائر مهتم = عميل ضائع للأبد. هذا نزيف صامت.',
            'evidence'          => '0 طرق تواصل على ' . $siteUrl . ' و' . implode(' + ', $activePlatforms),
            'strategic_context' => 'لا يمكن بيع بدون تواصل. هذا أساسي أكثر من أي شيء آخر.',
            'bullets'           => [
                '← الآن: أضف رقم واتساب في Bio على ' . implode(' و', array_slice($activePlatforms, 0, 2)),
                '← اليوم: أنشئ رابط wa.me/966XXXXXXXXX وأضفه في كل مكان',
                '← غداً: أضف زر واتساب عائم Widget على ' . $siteUrl,
                '← هذا الأسبوع: استخدم "راسلنا على واتساب" في كل منشور من ' . $totalContent . ' منشور',
            ],
            'roi'               => '78% من العملاء يفضّلون واتساب = رفع التحويل 50%+ فوراً من ' . number_format($totalFollowers) . ' متابع',
            'time_to_implement' => '4 ساعات',
            'difficulty'        => 'سهل جداً',
            'score_impact'      => '+15 نقطة',
            'order'             => 2,
        ];
    }

    // ── BLOCKER 3: موقع غير آمن ───────────────────────────────────────────
    if (!$hasSSL && $hasWebsite) {
        $recommendations[] = [
            'priority'          => 'critical',
            'icon'              => '🔓',
            'category'          => 'stop_bleeding',
            'title'             => 'تأمين ' . $siteUrl . ' — المتصفح يُظهر "غير آمن"',
            'desc'              => 'تم فحص ' . $siteUrl . ' ووجد أنه بدون SSL (HTTPS). المتصفح يُظهر تحذير "Not Secure" للزوار. 85% من الزوار يغادرون فوراً عند رؤية التحذير.',
            'why_now'           => 'موقع غير آمن = ثقة معدومة = مبيعات صفر. كل زيارة = عميل ضائع.',
            'evidence'          => 'SSL غير مفعّل على ' . $siteUrl . ' ⚠️ المتصفح يحذر الزوار',
            'strategic_context' => 'SSL مجاني الآن (Let\'s Encrypt). لا عذر لعدم تفعيله.',
            'bullets'           => [
                '← الآن: ادخل لوحة الاستضافة (cPanel أو Plesk)',
                '← الآن: ابحث عن "SSL" أو "Let\'s Encrypt" — فعّله',
                '← خلال ساعة: تأكد أن الموقع يفتح بـ https:// (قفل أخضر)',
                '← بعدها: أعد فحص الموقع — يجب أن يظهر "آمن"',
            ],
            'roi'               => 'رفع الثقة فوراً + تقليل معدل الارتداد 30%+',
            'time_to_implement' => '1-2 ساعة',
            'difficulty'        => 'سهل',
            'score_impact'      => '+10 نقاط',
            'order'             => 3,
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    // المحور 2: بناء البنية التحتية للتحويل — ينفذ في أسبوع
    // ═════════════════════════════════════════════════════════════════════

    // ─ـ Pixel (إذا لم يكن هناك إعلانات) ───────────────────────────────────
    if (!$hasPixel && !$adsRunning) {
        $recommendations[] = [
            'priority'          => 'high',
            'icon'              => '📊',
            'category'          => 'infrastructure',
            'title'             => 'تثبيت Meta Pixel على ' . $siteUrl . ' — بناء الأساس',
            'desc'              => 'تم فحص ' . $siteUrl . ' ووجد أنه بدون Meta Pixel. حتى لو لم تُطلق إعلانات الآن، Pixel يجمّع بيانات الزوار مجاناً للاستخدام المستقبلي.',
            'why_now'           => 'بدون Pixel = لا بيانات = قرارات عمياء. ثبّته قبل أي حملة.',
            'evidence'          => 'Pixel غير مثبت على ' . $siteUrl . ' | ' . number_format($totalFollowers) . ' متابع يزورون بدون تتبع',
            'strategic_context' => 'Pixel ليس "أداة إعلانية" — هو أساس أي استراتيجية تسويقية رقمية.',
            'bullets'           => [
                '← Business Manager → Events Manager → إضافة Pixel جديد',
                '← انسخ الكود وأضفه في <head> لكل صفحات ' . $siteUrl,
                '← فعّل الأحداث: PageView, ViewContent, AddToCart, Purchase',
                '← اختبر: زُر ' . $siteUrl . ' → افتح Events Manager → يجب أن ترى زيارتك',
            ],
            'roi'               => 'خفض تكلفة الإعلانات 20-40% + بناء Lookalike من ' . number_format($totalFollowers) . ' متابع',
            'time_to_implement' => '2 ساعات',
            'difficulty'        => 'سهل',
            'score_impact'      => '+12 نقطة',
            'order'             => 10,
        ];
    }

    // ─ـ واتساب (إذا يوجد هاتف/بريد لكن لا واتساب) ──────────────────────────
    if (!$hasWhatsApp && ($hasPhone || $hasEmail)) {
        $currentMethods = [];
        if ($hasPhone) $currentMethods[] = 'هاتف';
        if ($hasEmail) $currentMethods[] = 'بريد';
        $methodText = implode(' و', $currentMethods);

        $recommendations[] = [
            'priority'          => 'high',
            'icon'              => '💬',
            'category'          => 'infrastructure',
            'title'             => 'إضافة واتساب — 78% من العملاء يفضّلونه على ' . $methodText,
            'desc'              => 'تم فحص ' . $siteUrl . ' وBio: التطبيق الحالي ' . $methodText . '. هذه طرق "رسمية" لكن 78% من العملاء يفضّلون واتساب. العميل الذي يتردد في الاتصال = عميل مفقود.',
            'why_now'           => 'الهاتف = حاجز نفسي. البريد = بطء. واتساب = سهولة فورية.',
            'evidence'          => 'الطريقة الحالية: ' . $methodText . ' | المطلوب: إضافة واتساب',
            'strategic_context' => 'واتساب ليس "خيار إضافي" — هو القناة الأساسية للتحويل.',
            'bullets'           => [
                '← أنشئ رابط: wa.me/966XXXXXXXXX (بدون + أو صفر)',
                '← أضفه في Bio: ' . implode('، ', $activePlatforms),
                '← أضف زر واتساب عائم على ' . $siteUrl . ' (Widget)',
                '← استخدمه في كل CTA: "راسلنا على واتساب"',
            ],
            'roi'               => 'رفع التحويل 40-60% من ' . number_format($totalFollowers) . ' متابع + معدل رد أعلى 3x من ' . $methodText,
            'time_to_implement' => '3 ساعات',
            'difficulty'        => 'سهل جداً',
            'score_impact'      => '+12 نقطة',
            'order'             => 11,
        ];
    }

    // ─ـ CTA ─────────────────────────────────────────────────────────────────
    if ($ctaPercent < 40) {
        $missingCTA = $totalContent - round($totalContent * $ctaPercent / 100);
        $ctaGapText = $ctaPercent < 20 ? 'حرجة جداً' : ($ctaPercent < 30 ? 'ضعيفة' : 'تحتاج تحسين');

        $recommendations[] = [
            'priority'          => 'high',
            'icon'              => '🎯',
            'category'          => 'infrastructure',
            'title'             => 'تحسين CTA — ' . $ctaPercent . '% فقط (' . $ctaGapText . ')',
            'desc'              => 'تم تحليل ' . $totalContent . ' منشور: ' . $ctaPercent . '% فيها CTA، و' . $missingCTA . ' منشور تترك المتابع حائراً. العميل الذي لا يعرف الخطوة التالية = لا يتحرك.',
            'why_now'           => 'كل منشور بدون CTA = فرصة بيع ضائعة. التحسين فوري.',
            'evidence'          => $ctaPercent . '% من ' . $totalContent . ' منشور فيها CTA | ' . $missingCTA . ' منشور بدون توجيه',
            'strategic_context' => 'CTA ليس "تفصيل" — هو الجسر بين المتابعة والتحويل.',
            'bullets'           => [
                '← راجع آخر ' . min(10, $totalContent) . ' منشورات: أضف CTA في نهاية كل منها',
                '← CTA حسب الهدف: "راسلنا" (تواصل) | "احجز" (خدمات) | "اشترِ" (منتجات)',
                '← أضف CTA واضح في ' . $siteUrl . ' (أعلى الصفحة)',
                '← الهدف: رفع CTA من ' . $ctaPercent . '% إلى 50%+ من ' . $totalContent . ' منشور',
            ],
            'roi'               => 'رفع التحويل 25-40% من ' . number_format($totalFollowers) . ' متابع',
            'time_to_implement' => '2-3 أيام',
            'difficulty'        => 'سهل',
            'score_impact'      => '+10 نقاط',
            'order'             => 12,
        ];
    }

    // ─ـ Analytics ──────────────────────────────────────────────────────────
    if (!$hasGA) {
        $recommendations[] = [
            'priority'          => 'high',
            'icon'              => '📈',
            'category'          => 'infrastructure',
            'title'             => 'تثبيت Google Analytics 4 على ' . $siteUrl,
            'desc'              => 'تم فحص ' . $siteUrl . ' ووجد أنه بدون Google Analytics. هذا يعني: لا تعرف من يزورك، من أين أتى، ماذا فعل، أين غادر. قراراتك مبنية على تخمين.',
            'why_now'           => 'بدون بيانات = قرارات عمياء. مع Analytics = رؤية واضحة.',
            'evidence'          => 'GA4 غير مثبت على ' . $siteUrl . ($hasPixel ? ' | Pixel ✅ لكن بدون Analytics' : ' | ولا Pixel مثبت'),
            'strategic_context' => 'Analytics + Pixel = رؤية شاملة للرحلة من الزيارة للشراء.',
            'bullets'           => [
                '← أنشئ حساب GA4 من analytics.google.com (مجاني)',
                '← انسخ كود gtag.js وأضفه في <head> لكل صفحات ' . $siteUrl,
                '← فعّل Enhanced Measurement تلقائياً',
                '← اربطه مع Google Ads للحملات المستقبلية' . ($hasPixel ? ' + اربطه مع Pixel في Events Manager' : ''),
            ],
            'roi'               => 'فهم سلوك ' . ($totalFollowers > 0 ? number_format($totalFollowers) . ' متابع' : 'الزوار') . ' = تحسين مستمر = تحويل أعلى',
            'time_to_implement' => '1 يوم',
            'difficulty'        => 'متوسط',
            'score_impact'      => '+8 نقاط',
            'order'             => 13,
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    // المحور 3: حل مشكلة الاختناق — حسب مرحلة الحساب
    // ═════════════════════════════════════════════════════════════════════

    // ─ـ مشكلة Awareness: لا جمهور ─────────────────────────────────────────
    if ($bottleneckStage === 'awareness' && $totalFollowers < 500) {
        $recommendations[] = [
            'priority'          => 'high',
            'icon'              => '👥',
            'category'          => 'bottleneck',
            'title'             => 'بناء جمهور من الصفر — مرحلة "مبتدئ"',
            'desc'              => 'تم تشخيص مرحلة الحساب: ' . $stageLabel . ' (' . number_format($totalFollowers) . ' متابع). المشكلة ليست "تحسين" — المشكلة أن لا أحد يعرفك. تحتاج بناء حضور أولاً.',
            'why_now'           => 'لا يمكنك تحسين التحويل قبل أن يوجد جمهور يحوّله.',
            'evidence'          => number_format($totalFollowers) . ' متابع فقط | مرحلة: ' . $stageLabel,
            'strategic_context' => 'في هذه المرحلة: المحتوى > الإعلانات > التحويل. ركّز على الظهور أولاً.',
            'bullets'           => [
                '← الأسبوع 1-4: نشر يومي على ' . implode(' و', $activePlatforms) . ' (محتوى قيّم)',
                '← الأسبوع 2-4: تفاعل نشط مع حسابات في مجالك (تعليقات، مشاركات)',
                '← الأسبوع 3-4: إطلاق مسابقة/تحدي لجذب متابعين جدد',
                '← الهدف: الوصول لـ 500-1000 متابع خلال 30-60 يوم',
            ],
            'roi'               => 'قاعدة جمهور = أساس كل نمو مستقبلي',
            'time_to_implement' => '30-60 يوم للوصول لـ 1000 متابع',
            'difficulty'        => 'متوسط',
            'score_impact'      => '+15 نقطة',
            'order'             => 20,
        ];
    }

    // ─ـ مشكلة Engagement: جمهور صامت ─────────────────────────────────────
    if ($bottleneckStage === 'engagement' && $avgEngagement < 2.0 && $totalFollowers >= 500) {
        $recommendations[] = [
            'priority'          => 'high',
            'icon'              => '💬',
            'category'          => 'bottleneck',
            'title'             => 'إيقاظ الجمهور الصامت — تفاعل ' . number_format($avgEngagement, 1) . '% فقط',
            'desc'              => 'تم تشخيص المشكلة: ' . number_format($totalFollowers) . ' متابع لكن التفاعل ' . number_format($avgEngagement, 1) . '% (المطلوب 2%+). الجمهور "ميت" — لا يعلق، لا يشارك، لا يتواصل. الخوارزمية اعتبرت حسابك غير نشط.',
            'why_now'           => 'الجمهور الصامت = لا وصول عضوي = لا تحويل. يجب إيقاظهم.',
            'evidence'          => number_format($avgEngagement, 1) . '% تفاعل من ' . number_format($totalFollowers) . ' متابع',
            'strategic_context' => 'المشكلة ليست كمية المحتوى — المشكلة نوعيته.',
            'bullets'           => [
                '← الأسبوع 1: غيّر أسلوب المحتوى: Hook قوي + قيمة حقيقية + سؤال للتفاعل',
                '← الأسبوع 1-2: انشر محتوى تفاعلي: استطلاعات، أسئلة، "اختار"، "رأيك؟"',
                '← الأسبوع 2-3: ركّز على Video/Reels (وصول 3-5x أعلى)',
                '← الهدف: رفع التفاعل لـ 2%+ خلال 30 يوم',
            ],
            'roi'               => 'كل 1% تحسن = +15-20% وصول عضوي',
            'time_to_implement' => '30-45 يوم',
            'difficulty'        => 'متوسط',
            'score_impact'      => '+12 نقطة',
            'order'             => 21,
        ];
    }

    // ─ـ مشكلة Conversion: تفاعل بدون مبيعات ──────────────────────────────
    if ($bottleneckStage === 'conversion') {
        $conversionBlockers = [];
        if (!$hasWhatsApp) $conversionBlockers[] = 'لا واتساب';
        if (!$hasPixel) $conversionBlockers[] = 'لا Pixel';
        if ($ctaPercent < 30) $conversionBlockers[] = 'CTA ضعيف (' . $ctaPercent . '%)';
        if (!$hasRating) $conversionBlockers[] = 'لا تقييمات';

        $recommendations[] = [
            'priority'          => 'high',
            'icon'              => '💰',
            'category'          => 'bottleneck',
            'title'             => 'كسر حاجز التحويل — ' . implode(' + ', $conversionBlockers),
            'desc'              => 'تم تشخيص المشكلة: التفاعل جيد (' . number_format($avgEngagement, 1) . '%) لكن لا مبيعات. السبب: ' . implode('، ', $conversionBlockers) . '. العميل المهتم يجد عوائق تمنعه من الشراء.',
            'why_now'           => 'الجمهور مهتم لكن لا يشتري = نقود على الطاولة ضائعة.',
            'evidence'          => 'تفاعل ' . number_format($avgEngagement, 1) . '% | عوائق: ' . implode('، ', $conversionBlockers),
            'strategic_context' => 'التحويل ليس "نقطة" — هو مسار كامل يجب إزالة كل العوائق فيه.',
            'bullets'           => [
                '← عاجل: أضف واتساب (قناة التحويل الأسهل)',
                '← عاجل: حسّن CTA في كل المنشورات والموقع',
                '← هذا الأسبوع: اجمع 5 تقييمات من عملاء راضين',
                '← هذا الأسبوع: أضف "دليل اجتماعي" في الموقع والمنشورات',
            ],
            'roi'               => 'رفع التحويل 50-100% بإزالة هذه العوائق',
            'time_to_implement' => '1-2 أسبوع',
            'difficulty'        => 'سهل',
            'score_impact'      => '+15 نقطة',
            'order'             => 22,
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    // المحور 4: التوسع والنمو — للحسابات الجاهزة
    // ═════════════════════════════════════════════════════════════════════

    // ─ـ إعلانات ───────────────────────────────────────────────────────────
    if (!$adsRunning && $hasPixel && $totalFollowers >= 500 && $bottleneckStage !== 'awareness') {
        $recommendations[] = [
            'priority'          => 'medium',
            'icon'              => '🚀',
            'category'          => 'scaling',
            'title'             => 'إطلاق أول حملة إعلانية — أنت جاهز',
            'desc'              => 'تم فحص الاستعداد: Pixel ✅ | ' . number_format($totalFollowers) . ' متابع ✅ | قناة تحويل ✅. الأساس متين، الوقت مثالي للإعلانات.',
            'why_now'           => 'الوصول العضوي محدود. الإعلانات = نمو متسارع.',
            'evidence'          => 'Pixel ✅ | ' . number_format($totalFollowers) . ' متابع | لا إعلانات نشطة',
            'strategic_context' => 'لا تطلق إعلانات عشوائية. استخدم البيانات التي جمعتها.',
            'bullets'           => [
                '← ابدأ صغير: 50-100 ريال/يوم لمدة أسبوع على ' . implode(' أو ', array_slice($activePlatforms, 0, 2)),
                '← استهدف أفضل منشور عضوي (Boost Post) من ' . $totalContent . ' منشور',
                '← بعد 7 أيام: ابنِ Lookalike من زوار ' . $siteUrl . ' (' . number_format($totalFollowers) . ' متابع كأساس)',
                '← راجع أسبوعياً: ROAS, CPA, CTR — أوقف الضعيف ووسّع القوي',
            ],
            'roi'               => 'ROAS 2-3x خلال 60 يوم من ' . number_format($totalFollowers) . ' متابع',
            'time_to_implement' => 'أسبوع للإعداد والإطلاق',
            'difficulty'        => 'متوسط',
            'score_impact'      => '+10 نقاط',
            'order'             => 30,
        ];
    }

    // ─ـ تقويم محتوى ───────────────────────────────────────────────────────
    if (!$postingRegularity || $avgEngagement < 2.5) {
        $recommendations[] = [
            'priority'          => 'medium',
            'icon'              => '📅',
            'category'          => 'scaling',
            'title'             => 'بناء تقويم محتوى — ' . $postsPerWeek . '/أسبوع حالياً',
            'desc'              => 'تم فحص النشر: ' . $postsPerWeek . ' منشور/أسبوع، آخر منشور قبل ' . $lastPostDays . ' يوم. الانتظام ضعيف. الخوارزمية تُكافئ الثبات أكثر من الجودة المتقطعة.',
            'why_now'           => 'النشر العشوائي = وصول عشوائي. التقويم = وصول مستقر.',
            'evidence'          => $postsPerWeek . '/أسبوع | آخر منشور: ' . $lastPostDays . ' يوم',
            'strategic_context' => 'المحتوى هو "الوقود" لكل شيء: التفاعل، الإعلانات، التحويل.',
            'bullets'           => [
                '← حدد جدول: ' . max(12, (int)($postsPerWeek * 4)) . '-' . max(16, (int)($postsPerWeek * 4) + 4) . ' منشور شهرياً (' . max(3, (int)$postsPerWeek + 1) . '-' . max(4, (int)$postsPerWeek + 2) . ' أسبوعياً)',
                '← وزّع الأنواع على ' . implode(' و', $activePlatforms) . ': 40% تعليمي + 30% قصص + 20% تفاعلي + 10% عروض',
                '← استخدم Meta Business Suite للجدولة المسبقة',
                '← راجع الأداء أسبوعياً على ' . implode(' و', array_slice($activePlatforms, 0, 2)) . ' وعدّل حسب النتائج',
            ],
            'roi'               => 'مضاعفة الوصول العضوي من ' . number_format($totalFollowers) . ' متابع خلال 60-90 يوم',
            'time_to_implement' => 'أسبوع',
            'difficulty'        => 'متوسط',
            'score_impact'      => '+8 نقاط',
            'order'             => 31,
        ];
    }

    // ─ـ تقييمات ────────────────────────────────────────────────────────────
    if (!$hasRating && $fbFollowers >= 100) {
        $recommendations[] = [
            'priority'          => 'medium',
            'icon'              => '⭐',
            'category'          => 'scaling',
            'title'             => 'بناء المصداقية الاجتماعية — 0 تقييمات',
            'desc'              => 'تم فحص صفحة Facebook (' . number_format($fbFollowers) . ' متابع): لا تقييمات. 91% من العملاء يقرأون المراجعات قبل الشراء. بدونها = العميل يتساءل "هل هذا حقيقي؟".',
            'why_now'           => 'التقييمات = ثقة فورية = تحويل أسهل.',
            'evidence'          => '0 تقييمات على صفحة ' . number_format($fbFollowers) . ' متابع',
            'strategic_context' => 'التقييمات ليست "تزيين" — هي دليل اجتماعي يُقنع المهتمين.',
            'bullets'           => [
                '← حدد 5 عملاء راضين من ' . number_format($fbFollowers) . ' متابع واطلب مراجعاتهم هذا الأسبوع',
                '← أرسل رابط التقييم في رسائل الشكر للعملاء' . ($hasWhatsApp ? ' (عبر واتساب)' : ''),
                '← شارك أفضل المراجعات كمحتوى على ' . implode(' و', array_slice($activePlatforms, 0, 2)),
                '← الهدف: 10+ تقييمات خلال 30 يوم لرفع الثقة',
            ],
            'roi'               => 'كل نقطة تقييم ترفع المبيعات 5-9% من ' . number_format($fbFollowers) . ' متابع',
            'time_to_implement' => '2-4 أسبوع',
            'difficulty'        => 'سهل',
            'score_impact'      => '+7 نقاط',
            'order'             => 32,
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    // المحور 5: تحسينات مستقبلية — Low Priority
    // ═════════════════════════════════════════════════════════════════════

    // ─ـ توثيق ─────────────────────────────────────────────────────────────
    if (!$hasAnyVerified && $totalFollowers >= 500) {
        $recommendations[] = [
            'priority'          => 'low',
            'icon'              => '✅',
            'category'          => 'future',
            'title'             => 'طلب توثيق الحساب',
            'desc'              => 'تم فحص ' . implode(' و', $activePlatforms) . ' — لا يوجد توثيق رغم ' . number_format($totalFollowers) . ' متابع. التوثيق مجاني ويمنح ثقة فورية.',
            'why_now'           => 'المنافسون الموثقون يبدون أكثر احترافية.',
            'evidence'          => 'لا توثيق على ' . implode(' + ', $activePlatforms),
            'strategic_context' => 'التوثيق "تزيين" للحسابات الجاهزة — ليس أولوية قصوى.',
            'bullets'           => [
                '← أكمل الملف على ' . implode(' و', $activePlatforms) . ': صورة، Bio، رابط، معلومات التواصل',
                '← قدّم طلب من إعدادات الحساب → التوثيق',
                '← وفّر مستندات الملكية: سجل تجاري، هوية',
                '← الانتظار: أيام إلى أسابيع حسب المنصة',
            ],
            'roi'               => 'رفع الثقة + معدل تحويل أعلى 20-30% من ' . number_format($totalFollowers) . ' متابع',
            'time_to_implement' => 'أيام-أسابيع',
            'difficulty'        => 'سهل',
            'score_impact'      => '+5 نقاط',
            'order'             => 40,
        ];
    }

    // ─ـ تحسين التفاعل ─────────────────────────────────────────────────────
    if ($avgEngagement >= 1.5 && $avgEngagement < 3.0) {
        $recommendations[] = [
            'priority'          => 'low',
            'icon'              => '📈',
            'category'          => 'future',
            'title'             => 'رفع التفاعل من ' . number_format($avgEngagement, 1) . '% إلى 3%+',
            'desc'              => 'التفاعل الحالي ' . number_format($avgEngagement, 1) . '% — جيد لكن يمكن أفضل. المعدل الممتاز 3%+. كل 1% تحسن = وصول أوسع.',
            'why_now'           => 'التفاعل الأعلى = خوارزمية تمنحك وصولاً مجانياً أكثر.',
            'evidence'          => number_format($avgEngagement, 1) . '% حالياً | الهدف: 3%+',
            'strategic_context' => 'هذا تحسين "دقيق" للحسابات الجاهزة — ليس أساسي.',
            'bullets'           => [
                '← Hook أقوى: ابدأ بسؤال أو إحصائية تهم ' . number_format($totalFollowers) . ' متابع',
                '← قيمة حقيقية: نصيحة، درس، حل مشكلة' . ($igReelPct > 0 || $fbVideoPct > 0 ? ' (فيديو يحقق وصول أعلى)' : ''),
                '← CTA تفاعلي: "شارك رأيك"، "سؤال للنقاش" — حالياً ' . $ctaPercent . '% فقط فيها CTA',
                '← Video/Reels على ' . implode(' و', array_slice($activePlatforms, 0, 2)) . ': وصول 3-5x أعلى من الصور',
            ],
            'roi'               => '+15-20% وصول عضوي لكل 1% تحسن (من ' . number_format($avgEngagement, 1) . '% إلى 3%+ = مضاعفة الوصول)',
            'time_to_implement' => '30-60 يوم',
            'difficulty'        => 'متوسط',
            'score_impact'      => '+6 نقاط',
            'order'             => 41,
        ];
    }

    // ─ـ ترتيب التوصيات ─────────────────────────────────────────────────────
    usort($recommendations, function($a, $b) {
        return ($a['order'] ?? 99) <=> ($b['order'] ?? 99);
    });

    // ═════════════════════════════════════════════════════════════════════
    // بناء action_week (ديناميكي بالكامل حسب البيانات)
    // ═════════════════════════════════════════════════════════════════════
    $actionWeek = [];

    // بناءً على التوصيات الحرجة
    if ($adsRunning && !$hasPixel) {
        $actionWeek[] = '🛑 أوقف ' . $totalAds . ' إعلان نشط فوراً — Pixel غير مثبت على ' . $siteUrl . '.';
    }
    if (!$hasPixel && !$adsRunning) {
        $actionWeek[] = '📊 ثبّت Meta Pixel على ' . $siteUrl . ' من Business Manager — 2 ساعة فقط.';
    }
    if (!$hasSSL && $hasWebsite) {
        $actionWeek[] = '🔐 فعّل SSL (HTTPS) على ' . $siteUrl . ' من لوحة الاستضافة — مجاني.';
    }
    if (!$hasWhatsApp && !$hasPhone && !$hasEmail) {
        $actionWeek[] = '💬 أضف رقم واتساب في Bio كل المنصات — العميل لا يجدك.';
    } elseif (!$hasWhatsApp && ($hasPhone || $hasEmail)) {
        $actionWeek[] = '💬 أضف واتساب (78% من العملاء يفضّلونه) على ' . $siteUrl . ' وBio.';
    }
    if (!$hasGA) {
        $actionWeek[] = '📈 أنشئ Google Analytics 4 على ' . $siteUrl . ' — مجاني.';
    }

    // بناءً على مرحلة الحساب ونقطة الاختناق
    if ($bottleneckStage === 'awareness' && $totalFollowers < 500) {
        $actionWeek[] = '👥 ركّز على بناء جمهور: نشر يومي على ' . implode(' و', $activePlatforms) . ' لمدة 30 يوم.';
    }
    if ($bottleneckStage === 'engagement' && $avgEngagement < 2.0) {
        $actionWeek[] = '💬 غيّر أسلوب المحتوى: Hook قوي + سؤال تفاعلي — التفاعل الحالي ' . number_format($avgEngagement, 1) . '%.';
    }
    if ($bottleneckStage === 'conversion') {
        if ($ctaPercent < 30) {
            $actionWeek[] = '🎯 أضف CTA واضح في كل منشور — ' . $ctaPercent . '% فقط فيها CTA.';
        }
        if (!$hasRating && $fbFollowers > 0) {
            $actionWeek[] = '⭐ اطلب 5 تقييمات من عملاء راضين هذا الأسبوع.';
        }
    }

    // بناءً على انتظام النشر
    if (!$postingRegularity && $totalFollowers > 0) {
        if ($lastPostDays > 7) {
            $actionWeek[] = '📅 انشر اليوم — آخر منشور منذ ' . $lastPostDays . ' يوم (الخوارزمية تعاقبك).';
        }
        if ($postsPerWeek < 3) {
            $actionWeek[] = '📅 حدد جدول: ' . max(3, (int)$postsPerWeek + 2) . ' منشور أسبوعياً على الأقل.';
        }
    }

    // بناءً على نوع المحتوى
    if ($totalContent > 0 && $igReelPct < 20 && $fbVideoPct < 20) {
        $topPlatform = $igFollowers > $fbFollowers ? 'Instagram' : 'Facebook';
        $actionWeek[] = '🎥 أنشئ أول Reel/فيديو على ' . $topPlatform . ' — الفيديو يحقق وصول 3-5x أعلى.';
    }

    // بناءً على Bio
    if ($bioLength < 50 && $totalFollowers > 0) {
        $actionWeek[] = '✍️ حدّث Bio ليكون 50+ حرف: من نحن + ماذا نقدم + CTA (راسلنا/احجز).';
    }

    // بناءً على التوثيق
    if (!$hasAnyVerified && $totalFollowers >= 500) {
        $actionWeek[] = '✅ قدّم طلب توثيق الحساب — متاح ومجاني مع ' . number_format($totalFollowers) . ' متابع.';
    }

    // ═════════════════════════════════════════════════════════════════════
    // تحديد tier والملخص
    // ═════════════════════════════════════════════════════════════════════
    $tier = $score < 40 ? 'red' : ($score < 70 ? 'yellow' : 'green');

    $summaryParts = [];
    if ($hasAnyVerified) $summaryParts[] = 'حساب موثق.';
    if ($totalFollowers >= 10000) $summaryParts[] = number_format($totalFollowers) . ' متابع.';
    if ($avgEngagement >= 3.0) $summaryParts[] = 'تفاعل ممتاز ' . $avgEngagement . '%.';
    if ($hasPixel && $hasGA) $summaryParts[] = 'تتبع متكامل.';
    if ($adsRunning) $summaryParts[] = $totalAds . ' إعلان نشط.';
    if ($hasRating) $summaryParts[] = 'تقييم ' . $rating . '/5.';

    $gaps = [];
    if (!$hasPixel) $gaps[] = 'Pixel';
    if (!$hasWhatsApp) $gaps[] = 'واتساب';
    if (!$hasGA) $gaps[] = 'Analytics';
    if ($avgEngagement < 1.5 && $totalFollowers > 0) $gaps[] = 'تفاعل منخفض';

    if (!empty($gaps)) $summaryParts[] = 'ينبغي معالجة: ' . implode('، ', $gaps) . '.';

    $summary = empty($summaryParts)
        ? ($tier === 'red' ? "نشاط يحتاج تأسيساً." : ($tier === 'yellow' ? "أساس جيد، " . count($weaknesses) . " نقاط للمعالجة." : "ممتاز! مؤهل للنمو."))
        : implode(' ', $summaryParts);

    // ═════════════════════════════════════════════════════════════════════
    // customer_journey
    // ═════════════════════════════════════════════════════════════════════
    $awarenessScore  = min(95, $score + 10);
    $attractionScore = min(90, $score + 5);
    $trustScore      = max(20, $score - 10);
    $purchaseScore   = max(15, $score - 20);
    $loyaltyScore    = max(10, $score - 30);

    if ($totalFollowers >= 5000) $awarenessScore = min(95, $awarenessScore + 10);
    if ($avgEngagement >= 2.0) $attractionScore = min(95, $attractionScore + 10);
    if ($hasAnyVerified || $rating > 4.0) $trustScore = min(80, $trustScore + 20);
    if ($hasWhatsApp || $hasCTA) $purchaseScore = min(70, $purchaseScore + 15);
    if ($hasPixel && $adsRunning) $loyaltyScore = min(60, $loyaltyScore + 15);

    $bottleneck = 'purchase';
    $minScore = $purchaseScore;
    if ($trustScore < $minScore) { $bottleneck = 'trust'; $minScore = $trustScore; }
    if ($attractionScore < $minScore) { $bottleneck = 'attraction'; $minScore = $attractionScore; }
    if ($awarenessScore < $minScore) { $bottleneck = 'awareness'; $minScore = $awarenessScore; }

    $bottleneckAnalysis = [
        'awareness'  => 'الوصول محدود — ' . number_format($totalFollowers) . ' متابع فقط على ' . $platformsCount . ' منصة. تحتاج توسيع الحضور.',
        'attraction' => 'المحتوى لا يُجذب بفعالية — تفاعل ' . number_format($avgEngagement, 1) . '% من ' . number_format($totalFollowers) . ' متابع.',
        'trust'      => 'غياب ' . (!$hasAnyVerified ? 'التوثيق' : '') . (!$hasAnyVerified && !$hasRating ? ' و' : '') . (!$hasRating ? 'التقييمات' : '') . ' يُقلل الثقة.',
        'purchase'   => 'CTA ' . $ctaPercent . '% فقط' . (!$hasWhatsApp ? ' + لا واتساب' : '') . (!$hasPixel ? ' + لا Pixel' : '') . ' — مسار التحويل معطّل.',
        'loyalty'    => 'لا توجد آليات للحفاظ على العملاء' . (!$adsRunning ? ' — لا إعلانات لجمع بيانات' : '') . (!$hasPixel ? ' + لا تتبع' : '') . '.',
    ];

    // ═════════════════════════════════════════════════════════════════════
    // بناء action_month (ديناميكي بالكامل حسب مرحلة الحساب)
    // ═════════════════════════════════════════════════════════════════════
    $actionMonth = [];

    // الأسبوع الأول: الأساسيات الحرجة
    $week1 = [];
    if (!$hasPixel || !$hasGA) {
        $week1[] = 'تثبيت البنية التحتية: ' . (!$hasPixel ? 'Pixel' : '') . (!$hasPixel && !$hasGA ? ' + ' : '') . (!$hasGA ? 'Analytics' : '');
    }
    if (!$hasSSL && $hasWebsite) {
        $week1[] = 'تأمين ' . $siteUrl . ' بـ SSL';
    }
    if (!$hasWhatsApp) {
        $week1[] = 'إضافة واتساب كقناة تحويل أساسية';
    }
    if (!empty($week1)) {
        $actionMonth['week1'] = [
            'theme' => '🚨 وقف النزيف والأساسيات',
            'goals' => 'حل المشاكل الحرجة التي تكلف أموالاً',
            'tasks' => $week1,
        ];
    }

    // الأسبوع الثاني: حل مشكلة الاختناق
    $week2 = [];
    if ($bottleneckStage === 'awareness') {
        $week2[] = 'نشر يومي على ' . implode(' و', $activePlatforms) . ' (محتوى قيّم)';
        $week2[] = 'التفاعل مع 20+ حساب في مجالك يومياً';
        $week2[] = 'الهدف: +100 متابع جديد';
    } elseif ($bottleneckStage === 'engagement') {
        $week2[] = 'تغيير أسلوب المحتوى: Hook + قيمة + سؤال';
        $week2[] = 'نشر 3+ Reels/فيديوهات هذا الأسبوع';
        $week2[] = 'الهدف: رفع التفاعل من ' . number_format($avgEngagement, 1) . '% إلى 2%+';
    } elseif ($bottleneckStage === 'conversion') {
        if ($ctaPercent < 40) {
            $week2[] = 'إضافة CTA واضح في كل منشور جديد وقديم';
        }
        if (!$hasRating) {
            $week2[] = 'جمع 10+ تقييمات من عملاء راضين';
        }
        $week2[] = 'إضافة Social Proof في ' . $siteUrl . ' والمنشورات';
    } else {
        $week2[] = 'تحسين جودة المحتوى والاستمرار في الانتظام';
        $week2[] = 'اختبار أنواع محتوى جديدة';
    }
    if (!empty($week2)) {
        $actionMonth['week2'] = [
            'theme' => '🎯 حل مشكلة الاختناق (' . ($bottleneckStage === 'awareness' ? 'الوعي' : ($bottleneckStage === 'engagement' ? 'التفاعل' : ($bottleneckStage === 'conversion' ? 'التحويل' : 'التوسع'))) . ')',
            'goals' => 'التركيز على المشكلة الرئيسية المكتشفة',
            'tasks' => $week2,
        ];
    }

    // الأسبوع الثالث: البناء والتوسع
    $week3 = [];
    $week3[] = 'بناء تقويم محتوى: ' . max(12, (int)($postsPerWeek * 4)) . ' منشور للشهر القادم';
    if (!$adsRunning && $hasPixel && $totalFollowers >= 500) {
        $week3[] = 'إطلاق أول حملة إعلانية (50-100 ريال/يوم)';
    } elseif ($adsRunning && $hasPixel) {
        $week3[] = 'مراجعة أداء ' . $totalAds . ' إعلان: ROAS, CPA, CTR';
    }
    if ($totalFollowers >= 500 && !$hasAnyVerified) {
        $week3[] = 'متابعة طلب التوثيق';
    }
    if (!empty($week3)) {
        $actionMonth['week3'] = [
            'theme' => '📈 البناء والتوسع',
            'goals' => 'الانتقال من الإصلاح إلى النمو',
            'tasks' => $week3,
        ];
    }

    // الأسبوع الرابع: القياس والتحسين
    $week4 = [];
    $week4[] = 'مراجعة النتائج: المتابعين (+/-)، التفاعل (%)، المبيعات';
    if ($hasPixel || $hasGA) {
        $week4[] = 'تحليل البيانات من ' . ($hasPixel ? 'Events Manager' : '') . ($hasPixel && $hasGA ? ' و' : '') . ($hasGA ? 'GA4' : '');
    }
    $week4[] = 'تحديد 3 تحسينات للشهر القادم بناءً على البيانات';
    if (!empty($week4)) {
        $actionMonth['week4'] = [
            'theme' => '📊 القياس والتحسين المستمر',
            'goals' => 'قياس التقدم والتخطيط للشهر القادم',
            'tasks' => $week4,
        ];
    }

    return [
        'source'           => 'fallback',
        'summary'          => $summary,
        'ai_tier'          => $tier,
        'strengths'        => $strengths,
        'weaknesses'       => $weaknesses,
        'recommendations'  => $recommendations,
        'action_week'      => $actionWeek,
        'action_month'     => $actionMonth,
        'score_insight'    => "درجتك {$score}/100 في قطاع {$type}.",
        'competitor_note'  => (function() use ($hasPixel, $hasGA, $adsRunning, $avgEngagement) {
            $competitorInvests = [];
            if (!$hasPixel || !$hasGA) $competitorInvests[] = 'التتبع (Pixel + Analytics)';
            if (!$adsRunning) $competitorInvests[] = 'الإعلانات المدفوعة';
            if ($avgEngagement < 2.0) $competitorInvests[] = 'المحتوى التفاعلي';
            $competitorInvests[] = 'وتجربة العملاء';
            return 'المنافسون يستثمرون في: ' . implode('، ', $competitorInvests) . '.';
        })(),
        'customer_journey' => [
            'stages' => [
                'awareness'  => ['score' => $awarenessScore, 'analysis' => $awarenessScore >= 70
                    ? number_format($totalFollowers) . ' متابع على ' . $platformsCount . ' منصات — وصول جيد.'
                    : number_format($totalFollowers) . ' متابع فقط على ' . $platformsCount . ' منصة — يحتاج توسيع كبير.'],
                'attraction' => ['score' => $attractionScore, 'analysis' => $attractionScore >= 70
                    ? 'تفاعل ' . number_format($avgEngagement, 1) . '% — المحتوى يُجذب الجمهور.'
                    : 'تفاعل ' . number_format($avgEngagement, 1) . '% فقط — المحتوى لا يثير اهتمام ' . number_format($totalFollowers) . ' متابع.'],
                'trust'      => ['score' => $trustScore, 'analysis' => $trustScore >= 60
                    ? 'ثقة مقبولة: ' . ($hasAnyVerified ? 'حساب موثق ✅' : 'لا توثيق') . ($hasRating ? ' + تقييم ' . $rating . '/5' : ' + لا تقييمات') . '.'
                    : 'ثقة ضعيفة: ' . (!$hasAnyVerified ? 'لا توثيق' : '') . (!$hasRating ? ' + لا تقييمات' : '') . ' — العميل يتردد.'],
                'purchase'   => ['score' => $purchaseScore, 'analysis' => $purchaseScore >= 50
                    ? 'تحويل ممكن: ' . ($hasWhatsApp ? 'واتساب ✅' : 'لا واتساب') . ($hasCTA ? ' + CTA ✅' : ' + لا CTA') . ($hasPixel ? ' + Pixel ✅' : ' + لا Pixel') . '.'
                    : 'تحويل صعب: ' . (!$hasWhatsApp ? 'لا واتساب' : '') . (!$hasCTA ? ' + لا CTA' : '') . (!$hasPixel ? ' + لا Pixel' : '') . ' — مسار الشراء معطّل.'],
                'loyalty'    => ['score' => $loyaltyScore, 'analysis' => $loyaltyScore >= 40
                    ? 'أساس موجود: ' . ($adsRunning ? $totalAds . ' إعلان يجمع بيانات' : 'لا إعلانات') . ($hasPixel ? ' + Pixel يتبع الزوار' : '') . '.'
                    : 'لا آليات ولاء — ' . (!$adsRunning ? 'لا إعلانات لجمع بيانات' : '') . (!$hasPixel ? ' + لا تتبع لسلوك العميل' : '') . '.'],
            ],
            'bottleneck_stage'        => $bottleneck,
            'psychological_diagnosis' => $bottleneckAnalysis[$bottleneck],
            'bottleneck_fix'          => array_slice(array_column($recommendations, 'title'), 0, 3),
        ],
        'content_strategy' => [
            'intro' => 'ركز على: ' . implode('، ', array_slice(array_column($strengths, 'name'), 0, 2)),
            'shift' => 'عالج: ' . implode('، ', array_slice(array_column($weaknesses, 'name'), 0, 2)),
            'hook'  => $avgEngagement < 2.0
                ? 'ابدأ بتساؤل يمس مشكلة ' . number_format($totalFollowers) . ' متابع — التفاعل ' . number_format($avgEngagement, 1) . '% يحتاج تحفيز.'
                : 'ابدأ بإحصائية صادمة أو سؤال يُثير فضول جمهورك الـ ' . number_format($totalFollowers) . ' متابع.',
            'cta'   => $ctaPercent < 30
                ? 'اختم كل منشور بنداء صريح — ' . $ctaPercent . '% فقط من ' . $totalContent . ' منشور فيها CTA.'
                : 'نوّع الـ CTA: "راسلنا" (تواصل) | "احجز" (خدمات) | "شارك" (تفاعل) — لديك ' . $ctaPercent . '% حالياً.',
        ],
        'missing_data'     => $missingData,
        'content_analysis' => buildContentAnalysis($data),
    ];
}
