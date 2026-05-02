<?php
if (defined('AI_ANALYZE_LOADED')) return;
define('AI_ANALYZE_LOADED', true);

// ============================================================
// api/ai-analyze.php — تحليل ذكي بـ Gemini AI
// POST /api/ai-analyze.php
// Body: { "assessment_id": 123 } OR { "data": {...} }
// ============================================================
require_once __DIR__ . '/db.php';

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
function loadAssessmentData(int $id): ?array {
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
function runGeminiAnalysis(array $data, array $cfg, bool $forceRefresh = false): array {
    $priority = $cfg['analysis']['ai_priority'] ?? ['gemini', 'groq', 'pekpik'];

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
    foreach ($priority as $provider) {
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

// ============================================================
function callAIProvider(string $provider, array $data, array $cfg): array {
    $prompt = buildPrompt($data);

    switch ($provider) {
        case 'pekpik':          return callPekpik($prompt, $data, $cfg);
        case 'gemini':          return callGemini($prompt, $data, $cfg);
        case 'groq':            return callGroq($prompt, $data, $cfg);
        case 'deepseek':        return callDeepSeek($prompt, $data, $cfg);
        case 'openai':          return callOpenAI($prompt, $data, $cfg);
        case 'nvidia':          return callNvidia($prompt, $data, $cfg);
        case 'qwen':            return callQwen($prompt, $data, $cfg);
        case 'gptoss':          return callGPTOSS($prompt, $data, $cfg);
        case 'nemotron':        return callNemotron($prompt, $data, $cfg);       // NVIDIA 253B
        case 'deepseek_r1':     return callDeepSeekR1Nvidia($prompt, $data, $cfg); // DeepSeek R1 671B
        case 'qwen3_235b':      return callQwen3_235B($prompt, $data, $cfg);     // Qwen3 235B
        case 'llama_405b':      return callLlama405B($prompt, $data, $cfg);      // Llama 3.1 405B
        default:                throw new \Exception("Unknown provider: {$provider}");
    }
}

// ── Pekpik (OpenAI-Compatible) ────────────────────────────────
// الأولوية: flagship (GPT+Claude) → gemini-pro → gemini-flash → deepseek
function callPekpik(string $prompt, array $data, array $cfg): array {
    $baseUrl = $cfg['apis']['pekpik_base_url'] ?? 'https://aiapiv2.pekpik.com/v1';

    // 1) flagship-chat = GPT-5.4 + Claude تناوب تلقائي
    foreach ($cfg['apis']['pekpik_flagship_keys'] ?? [] as $key) {
        $result = _pekpikCall($baseUrl, $key, 'flagship-chat', $prompt);
        if ($result) return parseAIResponse($result, 'pekpik_flagship');
    }

    // 2) gemini-2.5-pro
    foreach ($cfg['apis']['pekpik_pro_keys'] ?? [] as $key) {
        $result = _pekpikCall($baseUrl, $key, 'gemini-2.5-pro', $prompt);
        if ($result) return parseAIResponse($result, 'pekpik_pro');
    }

    // 3) gemini-2.5-flash
    foreach ($cfg['apis']['pekpik_gemini_keys'] ?? [] as $key) {
        $result = _pekpikCall($baseUrl, $key, 'gemini-2.5-flash', $prompt);
        if ($result) return parseAIResponse($result, 'pekpik_flash');
    }

    // 4) deepseek-chat
    foreach ($cfg['apis']['pekpik_keys'] ?? [] as $key) {
        $result = _pekpikCall($baseUrl, $key, 'deepseek-chat', $prompt);
        if ($result) return parseAIResponse($result, 'pekpik_deepseek');
    }

    throw new \Exception('All Pekpik keys failed or expired — يحتاج تجديد المفاتيح');
}

function _pekpikCall(string $baseUrl, string $key, string $model, string $prompt): ?array {
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
        CURLOPT_TIMEOUT        => 120,
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
function callGemini(string $prompt, array $data, array $cfg): array {
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
            CURLOPT_TIMEOUT         => 120,
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
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
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
function callGroq(string $prompt, array $data, array $cfg): array {
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
            CURLOPT_TIMEOUT         => 120,
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
function callOpenAI(string $prompt, array $data, array $cfg): array {
    $key   = $cfg['apis']['openai_key']   ?? '';
    $model = $cfg['apis']['openai_model'] ?? 'gpt-4o-mini';
    if (!$key) throw new \Exception('No OpenAI key');

    // تقليم الـ Prompt (OpenAI حد 128k token لكن نحافظ على السرعة)
    $maxChars = 24000;
    if (mb_strlen($prompt) > $maxChars) {
        $prompt = mb_substr($prompt, 0, $maxChars) . "\n\n... [تم اختصار البيانات]";
    }

    $body = json_encode([
        'model'           => $model,
        'messages'        => [
            ['role' => 'system', 'content' => 'أنت خبير تسويق رقمي متخصص في السوق العربي. أجب دائماً بـ JSON صحيح فقط بدون أي نص إضافي خارج الـ JSON.'],
            ['role' => 'user',   'content' => $prompt],
        ],
        'temperature'     => 0.7,
        'max_tokens'      => 8000,
        'response_format' => ['type' => 'json_object'],
    ]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST            => true,
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_TIMEOUT         => 120,
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
function callDeepSeek(string $prompt, array $data, array $cfg): array {
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
        CURLOPT_TIMEOUT        => 120,
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
function callNvidia(string $prompt, array $data, array $cfg): array {
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
        CURLOPT_TIMEOUT        => 120,
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
function callQwen(string $prompt, array $data, array $cfg): array {
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
        CURLOPT_TIMEOUT        => 60, // Qwen thinking takes more time
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
function callGPTOSS(string $prompt, array $data, array $cfg): array {
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
        CURLOPT_TIMEOUT        => 45,
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
function _nvidiaCall(string $key, string $model, string $prompt, int $timeout = 50, bool $thinking = false): array {
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
function callNemotron(string $prompt, array $data, array $cfg): array {
    $key = $cfg['apis']['nvidia_keys']['nemotron']
        ?? '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';
    $aiData = _nvidiaCall($key, 'nvidia/llama-3.1-nemotron-ultra-253b-v1', $prompt, 60);
    return parseAIResponse($aiData, 'nemotron_253b', $data);
}

// ── DeepSeek R1 671B عبر NVIDIA — نموذج Reasoning عملاق ──────
function callDeepSeekR1Nvidia(string $prompt, array $data, array $cfg): array {
    $key = $cfg['apis']['nvidia_keys']['deepseek_r1']
        ?? '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';
    $aiData = _nvidiaCall($key, 'deepseek-ai/deepseek-r1', $prompt, 90);
    return parseAIResponse($aiData, 'deepseek_r1_671b', $data);
}

// ── Qwen3 235B عبر NVIDIA — أحدث وأكبر موديل Qwen ───────────
function callQwen3_235B(string $prompt, array $data, array $cfg): array {
    $key = $cfg['apis']['nvidia_keys']['qwen3_235b']
        ?? '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';
    $aiData = _nvidiaCall($key, 'qwen/qwen3-235b-a22b', $prompt, 70, true);
    return parseAIResponse($aiData, 'qwen3_235b', $data);
}

// ── Llama 3.1 405B عبر NVIDIA — أضخم Llama متاح ─────────────
function callLlama405B(string $prompt, array $data, array $cfg): array {
    $key = $cfg['apis']['nvidia_keys']['llama_405b']
        ?? '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';
    $aiData = _nvidiaCall($key, 'meta/llama-3.1-405b-instruct', $prompt, 60);
    return parseAIResponse($aiData, 'llama_405b', $data);
}

// ============================================================
// buildContentAnalysis — يبني content_analysis من البيانات الفعلية
// ============================================================
function buildContentAnalysis(array $data): array {
    $scan    = is_string($data['scan_result'] ?? null)
        ? (json_decode($data['scan_result'], true) ?? [])
        : ($data['scan_result'] ?? []);

    $ws      = $scan['website_scan'] ?? [];
    $fb      = $scan['facebook']     ?? $scan['social'] ?? [];
    $ig      = $scan['instagram']    ?? [];
    $tk      = $scan['tiktok']       ?? [];
    $ads     = $scan['ads_library']  ?? [];
    $answers = $data['answers']      ?? [];

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

    // helper: status
    $s = fn(bool $good, bool $warn = false): string => $good ? 'good' : ($warn ? 'warn' : 'bad');

    // helper: format number
    $n = fn($v): string => number_format((int)$v);

    // الأسئلة الـ 43 مع إجابات حقيقية
    $q = [
        // ── الهوية والبراند ──
        ['id'=>1,  'status'=> $s(!empty($fb['page_name'])||!empty($ws['title'])),
         'answer'=> !empty($fb['page_name']) ? "اسم الصفحة: {$fb['page_name']}" : (!empty($ws['title']) ? "عنوان الموقع: ".mb_substr($ws['title'],0,50) : 'لم يُكتشف اسم واضح للصفحة')],

        ['id'=>2,  'status'=> $s(!empty($ws['description']) && strlen($ws['description']??'')>50),
         'answer'=> $hasDesc ? 'وصف الموقع واضح ومناسب ('.strlen($ws['description']??'').' حرف)' : 'وصف الموقع غائب أو قصير جداً — يضعف ظهورك في جوجل'],

        ['id'=>3,  'status'=> $s($isVerified, false),
         'answer'=> $isVerified ? 'الصفحة موثّقة بشارة التحقق ✅' : 'الصفحة غير موثّقة — التوثيق يرفع الثقة ويحسن الوصول العضوي'],

        ['id'=>4,  'status'=> $s($hasOG),
         'answer'=> $hasOG ? 'OG Tags موجودة — المشاركات تظهر بشكل احترافي' : 'OG Tags غائبة — مشاركة الروابط تبدو بدون صورة أو وصف'],

        ['id'=>5,  'status'=> $s($hasSchema),
         'answer'=> $hasSchema ? 'Schema Markup مثبّت — يحسن ظهور الموقع في نتائج البحث' : 'Schema Markup غائب — يفقدك ميزة Rich Snippets في جوجل'],

        // ── الموقع الإلكتروني ──
        ['id'=>6,  'status'=> $s($hasSSL),
         'answer'=> $hasSSL ? 'HTTPS مفعّل — الموقع آمن وموثوق' : 'الموقع بدون HTTPS — يُعطّل الثقة ويضر بترتيب جوجل'],

        ['id'=>7,  'status'=> $s($speedFast, $speed==='متوسط'),
         'answer'=> $speed ? "سرعة التحميل: {$speed}" : 'لم يُقيَّم وقت التحميل — يُنصح بفحص PageSpeed'],

        ['id'=>8,  'status'=> $s($hasH1),
         'answer'=> $hasH1 ? 'H1 موجود: "'.mb_substr($ws['h1']??'',0,60).'"' : 'H1 غائب — يضعف السيو ووضوح رسالة الصفحة'],

        ['id'=>9,  'status'=> $s($wordCount>800, $wordCount>300),
         'answer'=> $wordCount>0 ? "عدد الكلمات: {$n($wordCount)} — ".($wordCount>800?'محتوى وفير يدعم السيو':($wordCount>300?'محتوى متوسط':'محتوى شحيح يضر بالترتيب')) : 'لم يُحسب حجم المحتوى'],

        ['id'=>10, 'status'=> $s($hasServices),
         'answer'=> $hasServices ? 'قائمة خدمات واضحة ('.count($ws['services_list']).' خدمة)' : 'لا توجد قائمة خدمات واضحة في الموقع'],

        ['id'=>11, 'status'=> $s($hasCTA),
         'answer'=> $hasCTA ? 'يوجد زر CTA واضح في الموقع' : 'لا يوجد CTA واضح — الزوار لا يعرفون الخطوة التالية'],

        ['id'=>12, 'status'=> $s($hasContact),
         'answer'=> $hasContact ? 'نموذج تواصل أو معلومات الاتصال متاحة' : 'معلومات التواصل غائبة أو مخفية'],

        // ── التواصل الاجتماعي ──
        ['id'=>13, 'status'=> $s($followers>10000, $followers>2000),
         'answer'=> $followers>0 ? "إجمالي المتابعين: {$n($followers)}" : 'لم يُكتشف حساب اجتماعي نشط'],

        ['id'=>14, 'status'=> $s($engagement>100, $engagement>30),
         'answer'=> $engagement>0 ? "متوسط التفاعل: {$n($engagement)} / منشور" : 'لم يُحسب متوسط التفاعل'],

        ['id'=>15, 'status'=> $s($postsWeek>=3, $postsWeek>=1),
         'answer'=> $postsWeek>0 ? "معدل النشر: {$postsWeek} منشور/أسبوع" : 'لم يُحدد معدل النشر'],

        ['id'=>16, 'status'=> $s($lastPostDays<=7, $lastPostDays<=21),
         'answer'=> $lastPostDays<99 ? "آخر منشور منذ: {$lastPostDays} يوم" : 'لم يُحدد تاريخ آخر منشور'],

        ['id'=>17, 'status'=> $s($postsCount>=50, $postsCount>=15),
         'answer'=> $postsCount>0 ? "إجمالي المنشورات المرصودة: {$n($postsCount)}" : 'لم يُرصد أي منشور'],

        ['id'=>18, 'status'=> $s($hasVideos),
         'answer'=> $hasVideos ? 'يوجد محتوى فيديو — الفيديو يحقق وصولاً أعلى بـ 3x' : 'لا يوجد محتوى فيديو — الفيديو القصير الأعلى تفاعلاً حالياً'],

        ['id'=>19, 'status'=> $s($ctaPct>20, $ctaPct>5),
         'answer'=> $ctaPct>0 ? "{$ctaPct}% من المنشورات تحتوي CTA" : 'المنشورات لا تحتوي CTA — لا توجيه للجمهور للشراء أو التواصل'],

        ['id'=>20, 'status'=> $s($hasHashtags),
         'answer'=> $hasHashtags ? 'يستخدم هاشتاقات: #'.implode(' #', array_slice(array_unique($topHashtags),0,5)) : 'لا يستخدم هاشتاقات — يفقد اكتشافية ذاتية'],

        // ── إنستقرام ──
        ['id'=>21, 'status'=> $s(!empty($ig['username'])),
         'answer'=> !empty($ig['username']) ? "حساب إنستقرام: @{$ig['username']} ({$n($ig['followers']??0)} متابع)" : 'لم يُكتشف حساب إنستقرام'],

        ['id'=>22, 'status'=> $s(($ig['followers']??0)>5000, ($ig['followers']??0)>500),
         'answer'=> !empty($ig['followers']) ? "متابعو إنستقرام: {$n($ig['followers'])}" : 'متابعو إنستقرام غير معروفين'],

        ['id'=>23, 'status'=> $s(($ig['engagement_rate']??0)>3, ($ig['engagement_rate']??0)>1),
         'answer'=> !empty($ig['engagement_rate']) ? "معدل تفاعل إنستقرام: {$ig['engagement_rate']}%" : 'لم يُحسب معدل التفاعل على إنستقرام'],

        ['id'=>24, 'status'=> $s(!empty($ig['is_business'])),
         'answer'=> !empty($ig['is_business']) ? 'حساب إنستقرام تجاري (Business Account) ✅' : 'حساب إنستقرام شخصي — التحويل لتجاري يفتح إحصائيات وأدوات إضافية'],

        ['id'=>25, 'status'=> $s(!empty($ig['highlights_count']) && $ig['highlights_count']>0),
         'answer'=> !empty($ig['highlights_count']) ? "Highlights: {$ig['highlights_count']} مجموعة" : 'لا توجد Highlights — فرصة لعرض الخدمات والشهادات'],

        // ── TikTok ──
        ['id'=>26, 'status'=> $s($hasTikTokAcc),
         'answer'=> $hasTikTokAcc ? "حساب TikTok: @{$tk['username']} ({$n($tk['followers']??0)} متابع)" : 'لا يوجد حساب TikTok — منصة نمو عضوي سريع'],

        ['id'=>27, 'status'=> $s($hasTikTok),
         'answer'=> $hasTikTok ? 'TikTok Pixel مثبّت ✅' : 'TikTok Pixel غائب — لا يمكن تتبع زوار TikTok'],

        // ── الإعلانات ──
        ['id'=>28, 'status'=> $s($adsRunning),
         'answer'=> $adsRunning ? "يُعلن بنشاط في مكتبة Meta ({$n($totalAds)} إعلان)" : 'لا إعلانات نشطة في مكتبة Meta — فرصة وصول مدفوع ضائعة'],

        ['id'=>29, 'status'=> $s($hasPixel),
         'answer'=> $hasPixel ? 'Meta Pixel مثبّت ✅ — بيانات جاهزة للاستهداف الذكي' : 'Meta Pixel غائب — لا يمكن إنشاء جماهير مخصصة أو Retargeting'],

        ['id'=>30, 'status'=> $s($hasPixel && !$adsRunning, false),
         'answer'=> $hasPixel && !$adsRunning ? 'Pixel موجود لكن لا إعلانات — يمكن البدء بـ Retargeting فوراً' : ($adsRunning ? 'إعلانات + Pixel = استهداف مثالي ✅' : 'Pixel وإعلانات كلاهما غائبان')],

        ['id'=>31, 'status'=> $s($answers['retargeting_campaigns']??false),
         'answer'=> !empty($answers['retargeting_campaigns']) ? 'يطبق حملات Retargeting ✅' : 'لا توجد حملات Retargeting — يفقد العملاء الذين زاروا الموقع'],

        ['id'=>32, 'status'=> $s(!empty($answers['ads_objective'])),
         'answer'=> !empty($answers['ads_objective']) ? "هدف الإعلانات: {$answers['ads_objective']}" : 'هدف الإعلانات غير محدد — يُشتت الميزانية'],

        // ── التحويل والمبيعات ──
        ['id'=>33, 'status'=> $s($hasWhatsApp),
         'answer'=> $hasWhatsApp ? 'زر واتساب موجود ✅ — قناة تحويل مباشرة' : 'لا يوجد زر واتساب — العملاء يفقدون مسار التواصل السريع'],

        ['id'=>34, 'status'=> $s($hasPhone),
         'answer'=> $hasPhone ? 'رقم هاتف ظاهر: '.($fb['phone']??'') : 'رقم هاتف غير ظاهر — عائق أمام العملاء'],

        ['id'=>35, 'status'=> $s($hasEmail),
         'answer'=> $hasEmail ? 'بريد إلكتروني متاح: '.($fb['email']??'') : 'البريد الإلكتروني غائب'],

        ['id'=>36, 'status'=> $s($answers['landing_page_exists']??false, false),
         'answer'=> !empty($answers['landing_page_exists']) ? 'تتوفر صفحة هبوط مخصصة ✅' : 'لا توجد صفحة هبوط — يفقد المعلن قدرة تحسين التحويل'],

        ['id'=>37, 'status'=> $s($hasRating, false),
         'answer'=> $hasRating ? "تقييم الصفحة: {$rating}/5 ⭐" : 'لا توجد تقييمات — يضعف المصداقية الاجتماعية'],

        // ── التحليل والقياس ──
        ['id'=>38, 'status'=> $s($hasGA),
         'answer'=> $hasGA ? 'Google Analytics نشط ✅ — رؤية كاملة لسلوك الزوار' : 'Google Analytics غائب — لا بيانات عن الزوار'],

        ['id'=>39, 'status'=> $s($hasPixel && $hasGA),
         'answer'=> ($hasPixel && $hasGA) ? 'Pixel + GA مثبّتان معاً ✅ — منظومة قياس متكاملة' : 'منظومة القياس ناقصة — '.(!$hasPixel&&!$hasGA?'كلاهما غائبان':(!$hasPixel?'Pixel غائب':'GA غائب'))],

        ['id'=>40, 'status'=> $s($answers['kpis_tracked']??false),
         'answer'=> !empty($answers['kpis_tracked']) ? 'يتابع مؤشرات الأداء KPIs ✅' : 'لا يتابع KPIs — القرارات بدون بيانات'],

        ['id'=>41, 'status'=> $s($answers['email_marketing']??false),
         'answer'=> !empty($answers['email_marketing']) ? 'يستخدم Email/SMS تسويقي ✅' : 'لا يستخدم Email Marketing — قناة ROI عالية مهملة'],

        // ── إضافي ──
        ['id'=>42, 'status'=> $s(!empty($ig['website']) || !empty($fb['website'])),
         'answer'=> !empty($fb['website']??$ig['website']??'') ? 'رابط الموقع في الحسابات الاجتماعية ✅' : 'رابط الموقع غائب من حسابات السوشيال — يحد من حركة المرور'],

        ['id'=>43, 'status'=> $s($followers>0 && $engagement>0 && ($hasPixel||$hasGA)),
         'answer'=> ($followers>0 && $engagement>0 && ($hasPixel||$hasGA))
            ? 'المنظومة التسويقية متكاملة: جمهور + تفاعل + تتبع ✅'
            : 'المنظومة التسويقية غير مكتملة — '.($followers==0?'لا جمهور':($engagement==0?'لا تفاعل':'أدوات التتبع غائبة'))],
    ];

    // حساب Bar Scores من البيانات الفعلية
    $goodCount = fn(array $ids) => count(array_filter($q, fn($r) => in_array($r['id'],$ids) && $r['status']==='good'));
    $total     = fn(array $ids) => count($ids);
    $pct       = fn(array $ids): int => $total($ids)>0 ? (int)round($goodCount($ids)/$total($ids)*100) : 0;

    return [
        'q'              => $q,
        'bar_cta'        => $pct([11,19,33,34,36]),
        'bar_contact'    => $pct([12,33,34,35,42]),
        'bar_value'      => $pct([2,8,9,10,18]),
        'bar_market_fit' => $pct([1,3,13,14,28]),
        'bar_visual'     => $pct([4,5,18,20,25]),
        'bar_brand'      => $pct([1,2,3,4,5]),
        'bar_consistency'=> $pct([15,16,17,19,20]),
        'bar_regularity' => $pct([15,16,17]),
        'bar_calendar'   => $pct([15,16,17,18,19]),
    ];
}

// ── Normalizer: يضمن أن كل عنصر في strengths/weaknesses له مفتاح 'title' ──
// لمنع ظهور [object Object] في الواجهة عندما يُرجع الـ AI كائنات بمفاتيح
// مختلفة (name, point, text, label, ...). يحافظ على بقية الحقول كما هي
// (desc, bullets, action, metric, score, type) ولا يدمّر شكل البيانات.
function normalizeStrengthWeakness(array $items): array {
    $altKeys = ['title', 'name', 'point', 'text', 'heading', 'label', 'item', 'desc', 'description'];
    return array_values(array_filter(array_map(function($item) use ($altKeys) {
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
function normalizeActionItems(array $items): array {
    return array_values(array_filter(array_map(function($item) {
        if (is_string($item)) {
            $trimmed = trim($item);
            return $trimmed !== '' ? $trimmed : null;
        }
        if (is_array($item)) {
            $candidates = ['task','title','text','action','description','desc'];
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
function normalizeActionMonth($items) {
    if (!is_array($items) || empty($items)) return [];
    if (isset($items['week1'])) {
        return $items;
    }
    return normalizeActionItems($items);
}

// ── Parser موحّد لجميع مزودي AI ─────────────────────────────
function parseAIResponse(array $aiData, string $source, array $rawData = []): array {
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
function detectPageType(array $data): array {
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
    $services= implode(' ', array_map('strtolower', $ws['services_list'] ?? []));
    $combined = $bio . ' ' . $type . ' ' . $company . ' ' . $domain . ' ' . $h1 . ' ' . $services;

    // 1) إشارات وكالة التسويق الرقمي — أعلى أولوية
    $agencyKeywords = '/agency|وكالة|تسويق رقمي|digital marketing|marketing agency|إدارة حسابات|social media management|media buying|إدارة إعلانات|brand management|alabeer.*market/i';
    if (preg_match($agencyKeywords, $combined)) {
        $scores['agency'] += 50; $signals['identity'] = 'digital_marketing_agency';
    }
    // إذا كان الدومين أو الاسم يحتوي "marketing"
    if (preg_match('/marketing/i', $domain . ' ' . $company)) {
        $scores['agency'] += 25; $signals['domain'] = 'marketing_in_domain';
    }
    // إذا كانت الخدمات المستخرجة تذكر خدمات تسويق
    if (preg_match('/إعلان|حملة|محتوى|تصميم|seo|إدارة|consulting|استشار/i', $services)) {
        $scores['agency'] += 20; $signals['services'] = 'marketing_services_detected';
    }

    // 2) إشارات الهيكل — cart / checkout → ecommerce
    $hasCart     = !empty($scan['has_cart'])     || !empty($scan['hasCart']);
    $hasCheckout = !empty($scan['has_checkout']) || !empty($scan['hasCheckout']);
    $hasProducts = !empty($scan['has_products']) || !empty($scan['hasProducts']);
    if ($hasCart)     { $scores['ecommerce'] += 35; $signals['structure'][] = 'cart_detected'; }
    if ($hasCheckout) { $scores['ecommerce'] += 30; $signals['structure'][] = 'checkout_detected'; }
    if ($hasProducts) { $scores['ecommerce'] += 20; $signals['structure'][] = 'product_pages_detected'; }

    // 3) إشارات CTA
    $cta = strtolower($scan['primary_cta'] ?? $data['cta'] ?? '');
    if (preg_match('/buy|shop|order|اشتر|اطلب|تسوق/', $cta)) {
        $scores['ecommerce'] += 25; $signals['cta'] = 'buy_cta';
    } elseif (preg_match('/book|contact|اتصل|احجز|تواصل|whatsapp|واتساب/', $cta)) {
        $scores['agency'] += 15; $scores['business'] += 15; $signals['cta'] = 'service_contact_cta';
    } elseif (preg_match('/follow|subscribe|تابع|اشترك/', $cta)) {
        $scores['influencer'] += 25; $signals['cta'] = 'follow_cta';
    }

    // 4) إشارات الهوية العامة (إذا لم يكن agency بالفعل)
    if ($scores['agency'] < 30) {
        if (preg_match('/influencer|creator|مؤثر|منشئ محتوى/', $combined)) {
            $scores['influencer'] += 30; $signals['identity'] = 'personal_creator';
        } elseif (preg_match('/store|متجر|shop|ecommerce/', $combined)) {
            $scores['ecommerce'] += 30; $signals['identity'] = 'store_brand';
        } elseif (preg_match('/company|شركة|service|خدمة/', $combined)) {
            $scores['business'] += 30; $signals['identity'] = 'company_service';
        }
    }

    // 5) إشارة المحتوى
    $contentType = strtolower($scan['content_type'] ?? '');
    if (strpos($contentType, 'review') !== false || strpos($contentType, 'personal') !== false) {
        $scores['influencer'] += 15; $signals['content'] = 'reviews_personal';
    } elseif (strpos($contentType, 'product') !== false || strpos($contentType, 'listing') !== false) {
        $scores['ecommerce'] += 15; $signals['content'] = 'product_listing';
    } elseif (strpos($contentType, 'blog') !== false || strpos($contentType, 'article') !== false) {
        $scores['blog'] += 20; $signals['content'] = 'blog_content';
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
function buildPrompt(array $data): string {
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
                        $comments= $p['comments'] ?? $p['commentsCount']?? 0;
                        $type    = $p['type']     ?? $p['postType']     ?? 'post';
                        if ($text) $scanInfo .= "  " . ($i+1) . ". [{$type}] \"{$text}\" | 👍{$likes} 💬{$comments}\n";
                    }
                }
            }

            // ── 4) بيانات Instagram العميقة ──────────────────────
            $ig = $scan['instagram'] ?? [];
            if (!empty($ig['username'])) {
                $scanInfo .= "\n**④ بيانات Instagram:**\n";
                $scanInfo .= "- المستخدم: @"       . ($ig['username']       ?? '') . "\n";
                $scanInfo .= "- المتابعون: "        . number_format((int)($ig['followers']      ?? 0)) . "\n";
                $scanInfo .= "- إجمالي المنشورات: " . ($ig['posts_count']    ?? '؟') . "\n";
                $scanInfo .= "- متوسط الإعجابات: "  . number_format((float)($ig['avg_likes']    ?? 0)) . "\n";
                $scanInfo .= "- معدل التفاعل: "     . number_format((float)($ig['engagement_rate'] ?? 0), 2) . "%\n";
                $scanInfo .= "- معدل النشر: "       . ($ig['posts_per_week'] ?? '؟') . " منشور/أسبوع\n";
                if (!empty($ig['bio']))
                    $scanInfo .= "- البايو: " . mb_substr($ig['bio'], 0, 150) . "\n";

                $igDeep = $ig['deep_analysis'] ?? [];
                if (!empty($igDeep['content_types'])) {
                    $scanInfo .= "**توزيع المحتوى:** ";
                    $parts = [];
                    foreach ($igDeep['content_types'] as $t => $p) $parts[] = "{$t}: {$p}%";
                    $scanInfo .= implode(' | ', $parts) . "\n";
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
                $scanInfo .= "- متوسط الإعجابات: "  . number_format((float)($tk['avg_likes']     ?? 0)) . " / فيديو\n";
                $scanInfo .= "- متوسط التعليقات: "  . number_format((float)($tk['avg_comments']  ?? 0)) . " / فيديو\n";
                $scanInfo .= "- معدل التفاعل: "     . number_format((float)($tk['engagement_rate']?? 0), 2) . "%\n";
                $scanInfo .= "- معدل النشر: "       . ($tk['posts_per_week'] ?? '؟') . " فيديو/أسبوع\n";
                if (!empty($tk['bio'])) $scanInfo .= "- الوصف: " . mb_substr($tk['bio'], 0, 150) . "\n";
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
                        $scanInfo .= "  " . ($i+1) . ". {$status}";
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

            // ── 9) الهدف والميزانية ───────────────────────────────
            $scanInfo .= "\n**⑨ البيانات الاستراتيجية للعميل:**\n";
            $scanInfo .= "- الهدف التسويقي: "    . ($scan['lead_objective'] ?? $data['objective']       ?? 'غير محدد') . "\n";
            $scanInfo .= "- الجمهور المستهدف: "  . ($scan['lead_audience']  ?? $data['target_audience'] ?? 'غير محدد') . "\n";
            $scanInfo .= "- الميزانية الإعلانية: ". ($scan['lead_budget']   ?? $data['ad_budget']       ?? 'غير محدد') . "\n";
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
// ============================================================
function fallbackAnalysis(array $data): array {
    $score = $data["score"] ?? 50;
    $type  = $data["type"] ?? 'general';

    $tier = $score < 40 ? 'red' : ($score < 70 ? 'yellow' : 'green');

    $summaryMap = [
        'red'    => "نشاطك التجاري يملك إمكانات حقيقية، لكن يحتاج إلى تأسيس سليم في عدة محاور قبل البدء بالإعلانات أو التوسع.",
        'yellow' => "لديك أساس جيد ومحاور تسويقية تعمل، لكن 3-4 نقاط تحتاج معالجة سريعة حتى تصل للمستوى الاحترافي.",
        'green'  => "ممتاز! نشاطك مؤهل للنمو السريع. التركيز الآن على تحسين الكفاءة وتوسيع الوصول.",
    ];

    $strengths = [
        [
            'type'   => 'strength',
    'title'  => 'الرغبة الواضحة في التطور والتحسين',
            'desc'   => 'إجراء هذا التقييم الشامل يُظهر وعياً تسويقياً عالياً وجاهزية للنمو.',
            'metric' => "درجة {$score}/100",
            'bullets' => [
                'اتخاذ خطوة للتقييم دليل على الجدية في المنافسة',
                'الوعي بنقاط الضعف هو نصف الحل الفعلي',
                'النشاطات التي تقيّم نفسها تنمو 2x أسرع من غيرها',
            ],
            'action' => 'استخدم نتائج هذا التقرير لبناء خطة 90 يوماً واضحة',
            'score'  => min(90, $score + 20),
        ],
        [
            'type'   => 'foundation',
            'title'  => 'حضور على منصات التواصل الاجتماعي الأساسية',
            'desc'   => 'وجود حسابات نشطة يُشكّل قاعدة جمهور قابلة للتحويل.',
            'metric' => 'حساب نشط على المنصات',
            'bullets' => [
                'الجمهور الموجود يمكن إعادة استهدافه بتكلفة أقل من جمهور جديد',
                'المنصات النشطة تُعطي بيانات تفاعل حقيقية للتحسين',
                'يمكن تحويل المتابعين لعملاء خلال 30 يوماً بالاستراتيجية الصحيحة',
            ],
            'action' => 'حدّث الـ Bio بـ CTA واضح وأضف رابط تواصل مباشر',
            'score'  => min(85, $score + 15),
        ],
        [
            'type'   => 'strength',
            'title'  => 'وضوح طبيعة النشاط التجاري والخدمات المقدمة',
            'desc'   => 'وضوح ما تقدمه يُسهّل بناء رسائل تسويقية مركّزة.',
            'metric' => "نوع النشاط: {$type}",
            'bullets' => ['الوضوح يقلل تكلفة الإعلانات', 'يسهل بناء Buyer Persona دقيقة'],
            'action' => 'اكتب رسالة تسويقية موحدة وضعها في كل المنصات',
            'score'  => min(80, $score + 10),
        ],
    ];

    // ── Weaknesses: objects متوافقة مع JS schema ──
    $weaknesses = [
        [
            'type'   => 'bottleneck',
            'title'  => 'غياب أدوات التتبع (Pixel / Analytics)',
            'desc'   => 'بدون تتبع، الإعلانات تعمل بشكل أعمى وتُهدر الميزانية.',
            'metric' => 'تكلفة الاستحواذ مرتفعة بسبب غياب البيانات',
            'bullets' => [
                'لا يمكن بناء جمهور Lookalike بدون Pixel',
                'قرارات الميزانية تُبنى على تخمين لا أرقام',
                'خسارة بيانات كل زائر لم يشتر',
            ],
            'action' => 'ثبّت Meta Pixel وGoogle Analytics خلال 24 ساعة',
            'score'  => max(20, $score - 25),
        ],
        [
            'type'   => 'growth_blocker',
            'title'  => 'ضعف نداء الإجراء (CTA) في المحتوى والإعلانات',
            'desc'   => 'الزوار يهتمون لكن لا يعرفون الخطوة التالية — وهذا يوقف النمو.',
            'metric' => 'معدل تحويل منخفض بسبب غياب CTA واضح',
            'bullets' => [
                'كل منشور بدون CTA = فرصة بيع ضائعة',
                'الزائر المتردد يحتاج توجيهاً صريحاً لاتخاذ القرار',
                'CTA واضح يرفع التحويل 25-40% فوراً',
            ],
            'action' => 'أضف زر واتساب وعبارة CTA في أول 3 ثواني من كل محتوى',
            'score'  => max(25, $score - 20),
        ],
        [
            'type'   => 'weakness',
            'title'  => 'المحتوى التسويقي يركز على البيع المباشر أكثر من بناء الثقة',
            'desc'   => 'محتوى البيع أكثر من اللازم يُبعد الجمهور ويرفع تكلفة الإعلانات.',
            'metric' => 'نسبة محتوى البيع تتجاوز 70% المقبول',
            'action' => 'طبّق قاعدة 80/20: 80% قيمة، 20% بيع',
            'score'  => max(30, $score - 15),
        ],
        [
            'type'   => 'weakness',
            'title'  => 'غياب استراتيجية محتوى موثقة ومنتظمة',
            'desc'   => 'النشر العشوائي يُضعف الثقة ويقلل الوصول العضوي.',
            'metric' => 'تردد النشر أقل من 4 مرات أسبوعياً',
            'action' => 'ابنِ تقويماً للمحتوى شهرياً بـ 4+ منشورات أسبوعياً',
            'score'  => max(35, $score - 10),
        ],
        [
            'type'   => 'weakness',
            'title'  => 'الاستراتيجية التسويقية تحتاج توثيقاً وتحديثاً',
            'desc'   => 'بدون استراتيجية موثقة، القرارات التسويقية تتضارب.',
            'metric' => 'غياب خطة تسويقية موثقة',
            'action' => 'خصص ساعتين شهرياً لمراجعة الأداء وتحديث الخطة',
            'score'  => max(40, $score - 5),
        ],
    ];

    // ── Recommendations: متوافقة مع JS schema (priority=high/medium/low) ──
    $recommendations = [
        [
            'priority'          => 'high',
            'icon'              => '📊',
            'title'             => 'تثبيت أدوات التتبع فوراً — وقف نزيف البيانات',
            'desc'              => 'كل يوم بدون Pixel تخسر فيه بيانات زوارك وعملائك المحتملين إلى الأبد.',
            'why_now'           => 'كل زيارة بدون تتبع = فرصة Retargeting ضائعة إلى الأبد',
            'bullets'           => [
                'ادخل Business Manager → Events Manager → فعّل Meta Pixel على موقعك',
                'أنشئ حساب Google Analytics 4 وأضف الكود على جميع الصفحات',
                'تحقق خلال 7 أيام: هل يُسجّل Pixel الزيارات في Events Manager؟',
            ],
            'roi'               => 'خفض تكلفة الإعلانات 20-40% بعد 30 يوماً من التتبع',
            'time_to_implement' => '1-2 يوم',
        ],
        [
            'priority'          => 'high',
            'icon'              => '🎯',
            'title'             => 'إصلاح الـ CTA — كل منشور بدون CTA يكلفك مبيعات',
            'desc'              => 'الزائر لا يعرف ماذا يفعل بعد قراءة منشورك — هذا يوقف النمو فوراً.',
            'why_now'           => 'كل منشور نشرته حتى الآن بدون CTA = فرصة بيع ضائعة',
            'bullets'           => [
                'أضف زر واتساب مرئي في Bio وعلى الموقع',
                'اختم كل منشور بسؤال أو دعوة صريحة: "تواصل معنا"، "احجز استشارة"',
                'قس النتيجة: هل زادت رسائل الواتساب خلال أسبوع؟',
            ],
            'roi'               => 'رفع معدل التحويل 25-40% خلال أسبوعين',
            'time_to_implement' => '1-3 أيام',
        ],
        [
            'priority'          => 'medium',
            'icon'              => '✍️',
            'title'             => 'إعادة توازن المحتوى — قاعدة 80/20',
            'desc'              => 'المحتوى البيعي المباشر يُقلل الوصول العضوي ويرفع تكلفة الإعلان.',
            'why_now'           => 'الخوارزميات تعاقب الحسابات التي تبيع أكثر مما تُعلّم',
            'bullets'           => [
                'خصص 80% من منشوراتك لتقديم قيمة حقيقية (تعليم، قصص، نتائج)',
                'احتفظ بـ 20% فقط للعروض والمبيعات المباشرة',
                'قس التفاعل بعد شهر — هل ارتفع Reach؟',
            ],
            'roi'               => 'رفع الوصول العضوي 30-50% خلال شهر',
            'time_to_implement' => '1-2 أسبوع',
        ],
        [
            'priority'          => 'medium',
            'icon'              => '📅',
            'title'             => 'بناء تقويم محتوى منتظم (4+ أسبوعياً)',
            'desc'              => 'النشر المنتظم يُعلّم الخوارزمية أنك نشط ويرفع الوصول تلقائياً.',
            'why_now'           => 'الخوارزمية تكافئ الاتساق أكثر من الجودة المتقطعة',
            'bullets'           => [
                'خطط للمحتوى شهرياً: 4 Reels + 4 Carousel + 8 Stories أسبوعياً',
                'استخدم أداة جدولة مثل Buffer أو Meta Business Suite',
                'راقب أفضل أوقات نشاط جمهورك في Insights وانشر فيها',
            ],
            'roi'               => 'مضاعفة الوصول العضوي خلال 60 يوماً',
            'time_to_implement' => '2-3 أسابيع',
        ],
        [
            'priority'          => 'low',
            'icon'              => '🚀',
            'title'             => 'إطلاق أول حملة إعلانية ذكية بميزانية صغيرة',
            'desc'              => 'بعد تثبيت Pixel وتحسين CTA، الوقت المثالي لتوسيع النطاق بالإعلانات.',
            'why_now'           => 'البيانات التي ستجمعها الآن ستجعل إعلاناتك 3x أرخص لاحقاً',
            'bullets'           => [
                'ابدأ بـ 50 ريال/يوم على منشور أداؤه العضوي ممتاز',
                'استهدف Lookalike Audience بناءً على زوار موقعك',
                'قس ROAS بعد 14 يوماً واضبط الميزانية وفق النتائج',
            ],
            'roi'               => 'ROAS متوقع 2-3x خلال 30-60 يوماً من الإطلاق',
            'time_to_implement' => 'شهر أو أكثر',
        ],
    ];

    return [
        'source'           => 'fallback',
        'summary'          => $summaryMap[$tier],
        'ai_tier'          => $tier,
        'strengths'        => $strengths,
        'weaknesses'       => $weaknesses,
        'recommendations'  => $recommendations,
        'action_week'      => [
            'ثبّت Meta Pixel وGoogle Analytics خلال 24 ساعة.',
            'أضف زر واتساب واضح في Bio وعلى الموقع.',
            'حدّث الـ Bio بـ CTA صريح وخدمة واضحة.',
            'صوّر أول Reel يصف مشكلة عميلك وكيف تحلها.',
            'ادرس Insights المنصات لتعرف أفضل أوقات النشر.',
        ],
        'action_month'     => ['بناء تقويم محتوى', 'إطلاق أول حملة إعلانية', 'قياس النتائج وتحسينها'],
        'score_insight'    => "درجتك {$score}/100 تضعك في الرُبع " . ($score > 75 ? 'الأول المتقدم' : ($score > 50 ? 'الثاني' : 'الأدنى')) . " من النشاطات في قطاع {$type}.",
        'competitor_note'  => 'المنافسون الأقوى في هذا القطاع يستثمرون في المحتوى الفيديو القصير والإعلانات الممنهجة.',
        'customer_journey' => [
            'stages' => [
                'awareness'  => ['score' => min(95, $score + 10), 'analysis' => 'الوصول المبدئي مقبول لكن يعتمد على الجهود العضوية غير الموجهة.'],
                'attraction' => ['score' => min(90, $score + 5),  'analysis' => 'المحتوى يجذب بعض التفاعل لكنه لا يركز على الشريحة الأكثر ربحية.'],
                'trust'      => ['score' => max(20, $score - 10), 'analysis' => 'هناك حاجة ملحة لتعزيز الثقة عبر تجارب العملاء السابقين.'],
                'purchase'   => ['score' => max(15, $score - 20), 'analysis' => 'CTA ضعيف يُصعّب على المهتمين اتخاذ قرار الشراء فوراً.'],
                'loyalty'    => ['score' => max(10, $score - 30), 'analysis' => 'لا توجد آليات واضحة للحفاظ على العملاء بعد الشراء الأول.'],
            ],
            'bottleneck_stage'        => 'purchase',
            'psychological_diagnosis' => 'العميل يهتم بما تقدمه لكنه يتردد في لحظة الشراء بسبب غياب CTA واضح وضمانات موثوقة.',
            'bottleneck_fix'          => [
                'أضف زر واتساب في كل صفحة وكل منشور.',
                'اعرض ضمان استرجاع أو تجربة مجانية لكسر حاجز الخوف.',
                'بسّط خطوات الطلب إلى خطوة واحدة.',
            ],
        ],
        'content_strategy' => [
            'intro' => 'الاستراتيجية الحالية تحتاج إعادة توجيه للتركيز على مشاكل العميل وليس استعراض المنتج.',
            'shift' => 'قلّل محتوى البيع المباشر لـ 20% وركّز على المحتوى التثقيفي والقصصي بـ 80%.',
            'hook'  => 'ابدأ بتساؤل مستفز أو خطأ شائع يقع فيه جمهورك المستهدف.',
            'cta'   => 'ادعُ الزوار للحصول على استشارة مجانية أو رابط تواصل واضح بدلاً من البيع المباشر.',
        ],
    ];
}
