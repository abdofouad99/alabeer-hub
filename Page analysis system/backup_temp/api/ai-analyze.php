<?php
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

    return array_merge($assessment, ['answers' => $answers]);
}

// ============================================================
function runGeminiAnalysis(array $data, array $cfg): array {
    $priority = $cfg['analysis']['ai_priority'] ?? ['pekpik', 'gemini', 'groq', 'deepseek'];

    // ── Cache: لا تستدعي AI مرتين لنفس البيانات ─────────────
    $cacheKey = 'ai_' . md5(
        ($data['score'] ?? 0) . '_' .
        json_encode($data['breakdown'] ?? []) . '_' .
        ($data['full_name'] ?? $data['company_name'] ?? '')
    );
    $cached = cacheGet($cacheKey);
    if ($cached && !empty($cached['summary'])) {
        $cached['_from_cache'] = true;
        return $cached;
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
        CURLOPT_TIMEOUT        => 20,
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
    $model = $cfg['apis']['gemini_model'] ?? 'gemini-2.0-flash';

    // حاول كل مفتاح
    foreach ($keys as $key) {
        if (!$key || str_contains($key, 'YOUR')) continue;

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
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$response || $httpCode === 429) {
            logError("Gemini Rate Limit/No Response", ['httpCode' => $httpCode, 'response' => $response]);
            continue; // rate limit — جرّب المفتاح التالي
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

        return parseAIResponse($aiData, 'gemini');
    }

    throw new \Exception('All Gemini keys failed');
}

// ── Groq AI (سريع جداً) ─────────────────────────────────────
function callGroq(string $prompt, array $data, array $cfg): array {
    $key   = $cfg['apis']['groq_key']   ?? '';
    $model = $cfg['apis']['groq_model'] ?? 'llama-3.3-70b-versatile';
    if (!$key) throw new \Exception('No Groq key');

    $messages = [
        ['role' => 'system', 'content' => 'أنت خبير تسويق رقمي. أجب دائماً بـ JSON صحيح فقط بدون أي نص إضافي.'],
        ['role' => 'user',   'content' => $prompt],
    ];

    $body = json_encode([
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => 0.7,
        'max_tokens'  => 8192,
        'response_format' => ['type' => 'json_object'],
    ]);

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Bearer {$key}",
        ],
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$response || $httpCode >= 400) throw new \Exception("Groq failed: {$httpCode}");

    $decoded = json_decode($response, true);
    $text    = $decoded['choices'][0]['message']['content'] ?? '';
    $aiData  = json_decode($text, true);
    if (!is_array($aiData)) throw new \Exception('Groq: invalid JSON response');

    return parseAIResponse($aiData, 'groq');
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
        CURLOPT_TIMEOUT        => 30,
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

    return parseAIResponse($aiData, 'deepseek');
}

// ── OpenAI (Fallback) ─────────────────────────────────────────
function callOpenAI(string $prompt, array $data, array $cfg): array {
    $key   = $cfg['apis']['openai_key']   ?? '';
    $model = $cfg['apis']['openai_model'] ?? 'gpt-4o-mini';
    if (!$key) throw new \Exception('No OpenAI key');

    $messages = [
        ['role' => 'system', 'content' => 'You are an expert Arabic digital marketing analyst. Always respond with valid JSON only.'],
        ['role' => 'user', 'content'   => $prompt],
    ];

    $body = json_encode(['model' => $model, 'messages' => $messages, 'temperature' => 0.7, 'max_tokens' => 8192, 'response_format' => ['type' => 'json_object']]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', "Authorization: Bearer {$key}"],
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$response || $httpCode >= 400) throw new \Exception("OpenAI failed: {$httpCode}");

    $decoded = json_decode($response, true);
    $text    = $decoded['choices'][0]['message']['content'] ?? '';
    $aiData  = json_decode($text, true);
    if (!is_array($aiData)) throw new \Exception('OpenAI: invalid JSON');

    return parseAIResponse($aiData, 'openai');
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
        CURLOPT_TIMEOUT        => 35,
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

    return parseAIResponse($aiData, 'nvidia');
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

    return parseAIResponse($aiData, 'qwen');
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

    return parseAIResponse($aiData, 'gptoss');
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
    return parseAIResponse($aiData, 'nemotron_253b');
}

// ── DeepSeek R1 671B عبر NVIDIA — نموذج Reasoning عملاق ──────
function callDeepSeekR1Nvidia(string $prompt, array $data, array $cfg): array {
    $key = $cfg['apis']['nvidia_keys']['deepseek_r1']
        ?? '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';
    $aiData = _nvidiaCall($key, 'deepseek-ai/deepseek-r1', $prompt, 90);
    return parseAIResponse($aiData, 'deepseek_r1_671b');
}

// ── Qwen3 235B عبر NVIDIA — أحدث وأكبر موديل Qwen ───────────
function callQwen3_235B(string $prompt, array $data, array $cfg): array {
    $key = $cfg['apis']['nvidia_keys']['qwen3_235b']
        ?? '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';
    $aiData = _nvidiaCall($key, 'qwen/qwen3-235b-a22b', $prompt, 70, true);
    return parseAIResponse($aiData, 'qwen3_235b');
}

// ── Llama 3.1 405B عبر NVIDIA — أضخم Llama متاح ─────────────
function callLlama405B(string $prompt, array $data, array $cfg): array {
    $key = $cfg['apis']['nvidia_keys']['llama_405b']
        ?? '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';
    $aiData = _nvidiaCall($key, 'meta/llama-3.1-405b-instruct', $prompt, 60);
    return parseAIResponse($aiData, 'llama_405b');
}

// ── Parser موحّد لجميع مزودي AI ─────────────────────────────
function parseAIResponse(array $aiData, string $source): array {
    return [
        'source'               => $source,
        // ── تصنيف الصفحة ──────────────────────────────────────
        'page_type'            => $aiData['page_type']            ?? 'Unknown',
        'page_type_confidence' => $aiData['confidence']           ?? 0,
        'page_type_signals'    => $aiData['signals_used']         ?? [],
        'page_type_reasoning'  => $aiData['reasoning']            ?? '',
        // ── الحقول الأساسية ────────────────────────────────────
        'summary'              => $aiData['summary']              ?? ($aiData['final_report'] ?? ''),
        'strengths'            => $aiData['strengths']            ?? [],
        'weaknesses'           => $aiData['weaknesses']           ?? [],
        'recommendations'      => $aiData['recommendations']      ?? [],
        'action_week'          => $aiData['action_week']          ?? [],
        'action_month'         => $aiData['action_month']         ?? [],
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
        // ── حقل رحلة العميل ──────────────────────────────────────────
        'customer_journey'     => $aiData['customer_journey']     ?? null,
        // ── حقل صفحة المحتوى الجديد (لا يؤثر على الصفحات الأخرى) ──
        'content_analysis'     => $aiData['content_analysis']     ?? null,
    ];
}

// ============================================================
// ── محرك تصنيف الصفحة (إشارات متعددة) ─────────────────────
// ============================================================
function detectPageType(array $data): array {
    $signals  = [];
    $scores   = [
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

    // 1) إشارات الهيكل — cart / checkout → ecommerce قوي
    $hasCart     = !empty($scan['has_cart'])     || !empty($scan['hasCart']);
    $hasCheckout = !empty($scan['has_checkout']) || !empty($scan['hasCheckout']);
    $hasProducts = !empty($scan['has_products']) || !empty($scan['hasProducts']);
    if ($hasCart)     { $scores['ecommerce'] += 35; $signals['structure'][] = 'cart_detected'; }
    if ($hasCheckout) { $scores['ecommerce'] += 30; $signals['structure'][] = 'checkout_detected'; }
    if ($hasProducts) { $scores['ecommerce'] += 20; $signals['structure'][] = 'product_pages_detected'; }

    // 2) إشارات CTA
    $cta = strtolower($scan['primary_cta'] ?? $data['cta'] ?? '');
    if (preg_match('/buy|shop|order|اشتر|اطلب|تسوق/', $cta)) {
        $scores['ecommerce'] += 25; $signals['cta'] = 'buy_cta';
    } elseif (preg_match('/book|contact|اتصل|احجز|تواصل/', $cta)) {
        $scores['business'] += 25; $signals['cta'] = 'service_cta';
    } elseif (preg_match('/follow|subscribe|تابع|اشترك/', $cta)) {
        $scores['influencer'] += 25; $signals['cta'] = 'follow_cta';
    }

    // 3) إشارات الهوية
    $bio  = strtolower($scan['bio'] ?? $data['description'] ?? '');
    $type = strtolower($data['project_type'] ?? '');
    if (preg_match('/influencer|creator|مؤثر|منشئ/', $bio . $type)) {
        $scores['influencer'] += 30; $signals['identity'] = 'personal_creator';
    } elseif (preg_match('/store|متجر|shop|ecommerce/', $bio . $type)) {
        $scores['ecommerce'] += 30; $signals['identity'] = 'store_brand';
    } elseif (preg_match('/company|شركة|agency|وكالة|service|خدمة/', $bio . $type)) {
        $scores['business'] += 30; $signals['identity'] = 'company_service';
    }

    // 4) إشارة المحتوى — reviews/personal → influencer، product listings → ecommerce
    $contentType = strtolower($scan['content_type'] ?? '');
    if (str_contains($contentType, 'review') || str_contains($contentType, 'personal')) {
        $scores['influencer'] += 15; $signals['content'] = 'reviews_personal';
    } elseif (str_contains($contentType, 'product') || str_contains($contentType, 'listing')) {
        $scores['ecommerce'] += 15; $signals['content'] = 'product_listing';
    } elseif (str_contains($contentType, 'blog') || str_contains($contentType, 'article')) {
        $scores['blog'] += 20; $signals['content'] = 'blog_content';
    }

    // 5) قاعدة Disambiguation: تحدث عن منتجات بدون cart/checkout → influencer
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
        foreach ($bd as $k => $v) {
            $breakdown .= "- {$k}: {$v}\n";
        }
    }

    $scanInfo = '';
    if (!empty($data['scan_result'])) {
        $scan = is_string($data['scan_result']) ? json_decode($data['scan_result'], true) : $data['scan_result'];
        if (is_array($scan)) {
            $scanInfo = "**نتائج الفحص التقني التلقائي:**\n";
            $scanInfo .= "- HTTPS: " . ($scan['hasSSL'] ?? $scan['has_ssl'] ?? false ? 'نعم' : 'لا') . "\n";
            $scanInfo .= "- Facebook Pixel: " . ($scan['hasPixel'] ?? $scan['has_fb_pixel'] ?? false ? 'نعم ✅' : 'لا ❌') . "\n";
            $scanInfo .= "- Google Analytics: " . ($scan['hasGA'] ?? $scan['has_ga'] ?? false ? 'نعم ✅' : 'لا ❌') . "\n";
            $scanInfo .= "- زر واتساب: " . ($scan['hasWhatsApp'] ?? $scan['has_whatsapp'] ?? false ? 'نعم ✅' : 'لا ❌') . "\n";
            $scanInfo .= "- سرعة التحميل: " . ($scan['speedRating'] ?? $scan['speed_rating'] ?? 'غير معروف') . "\n";

            $scanInfo .= "\n**الهدف التسويقي والميزانية:**\n";
            $scanInfo .= "- الهدف التسويقي: " . ($scan['lead_objective'] ?? 'غير محدد') . "\n";
            $scanInfo .= "- الجمهور المستهدف: " . ($scan['lead_audience'] ?? 'غير محدد') . "\n";
            $scanInfo .= "- الميزانية الإعلانية: " . ($scan['lead_budget'] ?? 'غير محدد') . "\n";

            if (!empty($scan['competitor_radar'])) {
                $scanInfo .= "\n**رادار المنافسين (مستخرج من بحث جوجل):**\n";
                foreach ($scan['competitor_radar'] as $comp) {
                    $scanInfo .= "- {$comp['name']} ({$comp['url']}): {$comp['description']}\n";
                }
            }

            // TikTok & Twitter
            if (!empty($scan['tiktok']) && ($scan['tiktok']['success'] ?? false)) {
                $tk = $scan['tiktok'];
                $scanInfo .= "\n**بيانات TikTok:**\n";
                $scanInfo .= "- المتابعون: " . ($tk['followers'] ?? '0') . "\n";
                $scanInfo .= "- الإعجابات: " . ($tk['likes'] ?? '0') . "\n";
                $scanInfo .= "- الفيديوهات: " . ($tk['video_count'] ?? '0') . "\n";
                if (!empty($tk['bio'])) $scanInfo .= "- الوصف: {$tk['bio']}\n";
            }
            if (!empty($scan['twitter']) && ($scan['twitter']['success'] ?? false)) {
                $tw = $scan['twitter'];
                $scanInfo .= "\n**بيانات Twitter (X):**\n";
                $scanInfo .= "- المتابعون: " . ($tw['followers'] ?? '0') . "\n";
                $scanInfo .= "- التغريدات: " . ($tw['posts_count'] ?? '0') . "\n";
                $scanInfo .= "- الموقع: " . ($tw['location'] ?? 'غير محدد') . "\n";
                if (!empty($tw['bio'])) $scanInfo .= "- الوصف: {$tw['bio']}\n";
            }
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
    if (strpos($detectedType, 'Influencer') !== false || strpos($detectedType, 'Creator') !== false) {
        $reportFocus = "ركّز على: استراتيجية المحتوى، نمو الجمهور، معدلات التفاعل، والبراند الشخصي. لا تفترض وجود متجر أو منتجات للبيع المباشر.";
    } elseif (strpos($detectedType, 'E-commerce') !== false) {
        $reportFocus = "ركّز على: معدل التحويل، تقديم المنتجات، تحسين القمع التسويقي، وتجربة الشراء.";
    } elseif (strpos($detectedType, 'Business') !== false || strpos($detectedType, 'Service') !== false) {
        $reportFocus = "ركّز على: جذب العملاء المحتملين، وضوح عرض الخدمة، وبناء الثقة والمصداقية.";
    }

    return <<<PROMPT
## SYSTEM: Advanced Page Classification & Context-Aware Analysis Engine

أنت محلل تسويق رقمي خبير. قبل أي شيء، حدد نوع الصفحة بدقة ثم أنشئ التقرير.

### تصنيف الصفحة (مكتمل مسبقاً بإشارات متعددة):
- النوع المكتشف: {$detectedType}
- درجة الثقة: {$typeConfidence}%
- الإشارات المستخدمة: {$typeSignals}

⚠️ قاعدة التمييز: إذا كانت الصفحة تتحدث عن منتجات لكن لا يوجد cart/checkout → فهي Influencer وليست E-commerce.

### توجيه التقرير:
{$reportFocus}

---
## بيانات العميل:
- الاسم: {$name} | الشركة: {$company} | النوع: {$type}
- الدولة: {$country} | المنصة: {$platform} | الدرجة: {$score}/100

### محاور الأداء:
{$breakdown}

### نتائج الفحص التقني:
{$scanInfo}

### إجابات الاستبيان:
{$answersText}

---
## المطلوب: JSON فقط بالهيكل التالي:

```json
{
  "page_type": "{$detectedType}",
  "niche": "المجال الدقيق للصفحة (مثلاً: شركة تسويق، عقارات، متجر إلكتروني، إلخ)",
  "confidence": {$typeConfidence},
  "signals_used": {"identity": "...", "intent": "...", "structure": "...", "content": "...", "cta": "..."},
  "reasoning": "تفسير موجز للتصنيف",
  "summary": "خلاصة تنفيذية 3-4 جمل مبنية على البيانات الفعلية",
  "tier": "red|yellow|green",
  "score_insight": "تحليل الدرجة {$score}/100",
  "market_opportunity": "الفرصة المتاحة في {$country}",
  "pain_points": ["ألم 1", "ألم 2", "ألم 3"],
  "strengths": ["قوة 1", "قوة 2"],
  "weaknesses": ["ضعف 1", "ضعف 2"],
  "recommendations": [
    {"title": "...", "priority": "عاجل|مهم", "impact": "عالي", "why_now": "...", "bullets": ["..."], "roi": "..."}
  ],
  "competitor_analysis": [
    {"name": "...", "strength": "...", "weakness": "...", "how_to_beat": "..."}
  ],
  "executive_plan": {
    "phase1": {"title": "...", "description": "...", "tasks": ["..."]},
    "phase2": {"title": "...", "description": "...", "tasks": ["..."]},
    "phase3": {"title": "...", "description": "...", "tasks": ["..."]},
    "roi_projection": {"cr": "...", "roas": "...", "cac": "..."}
  },
    "action_month": {
      "week1": {"theme": "...", "tasks": ["..."], "kpi": "..."},
      "week2": {"theme": "...", "tasks": ["..."], "kpi": "..."},
      "week3": {"theme": "...", "tasks": ["..."], "kpi": "..."},
      "week4": {"theme": "...", "tasks": ["..."], "kpi": "..."},
      "expected_results": "..."
    },
    "customer_journey": {
      "stages": {
        "awareness": {"score": 80, "analysis": "هل الحساب يعرف الناس بوجوده ويصل إليهم؟"},
        "attraction": {"score": 60, "analysis": "هل المحتوى يجذب الفئة الصحيحة للاهتمام؟"},
        "trust": {"score": 40, "analysis": "هل الحساب يقنع الزوار بموثوقيته (تجارب، وضوح)؟"},
        "purchase": {"score": 20, "analysis": "هل الشراء سهل، CTA واضح؟"},
        "loyalty": {"score": 10, "analysis": "هل يبني علاقة مستمرة؟"}
      },
      "bottleneck_stage": "trust",
      "psychological_diagnosis": "التشخيص النفسي الخفي: الحساب يجذب الانتباه لكنه يفشل في بناء الثقة مما يمنع التحويل."
    },
    "content_analysis": {
    "q": [
      {"id": 1, "status": "good|warn|bad", "answer": "تحليل موجز..."},
      {"id": 2, "status": "good|warn|bad", "answer": "..."},
      // استمر بإرجاع الإجابات من id 1 حتى 43 بناءً على القائمة التالية:
      // من 1 إلى 9 (الهوية)، من 10 إلى 15 (تفاعل)، من 16 إلى 23 (جودة)، من 24 إلى 28 (قيمة)،
      // من 29 إلى 31 (CTA)، من 32 إلى 35 (سيو/مشاركة)، من 36 إلى 39 (تناسق)، من 40 إلى 43 (الانتشار).
      {"id": 43, "status": "good|warn|bad", "answer": "..."}
    ],
    "bar_cta": 80,
    "bar_contact": 70,
    "bar_value": 60,
    "bar_market_fit": 85,
    "bar_visual": 90,
    "bar_brand": 75,
    "bar_consistency": 80,
    "bar_regularity": 65,
    "bar_calendar": 50
  }
}
```

❌ ممنوع: نصوص عامة أو خارج JSON.
✅ كل شيء مبني على البيانات الفعلية أعلاه.
PROMPT;
}

// ============================================================
function fallbackAnalysis(array $data): array {
    $score = (int)($data['score'] ?? 50);
    $type  = $data['project_type'] ?? 'نشاط تجاري';

    $tier = $score < 40 ? 'red' : ($score < 70 ? 'yellow' : 'green');

    $summaryMap = [
        'red'    => "نشاطك التجاري يملك إمكانات حقيقية، لكن يحتاج إلى تأسيس سليم في عدة محاور قبل البدء بالإعلانات أو التوسع.",
        'yellow' => "لديك أساس جيد ومحاور تسويقية تعمل، لكن 3-4 نقاط تحتاج معالجة سريعة حتى تصل للمستوى الاحترافي.",
        'green'  => "ممتاز! نشاطك مؤهل للنمو السريع. التركيز الآن على تحسين الكفاءة وتوسيع الوصول.",
    ];

    return [
        'source'     => 'fallback',
        'summary'    => $summaryMap[$tier],
        'ai_tier'    => $tier,
        'strengths'  => [
            'وجود رغبة واضحة في التطور والتحسين',
            'اتخاذ خطوة للتقييم يُظهر وعياً تسويقياً',
        ],
        'weaknesses' => [
            'بعض المحاور الأساسية تحتاج معالجة',
            'الحاجة لتوثيق الاستراتيجية التسويقية',
        ],
        'recommendations' => [
            [
                'title'   => 'ابدأ بالأساسيات التقنية',
                'priority'=> 'عاجل',
                'impact'  => 'عالي',
                'bullets' => ['تركيب Meta Pixel', 'إعداد Google Analytics 4', 'تأمين HTTPS'],
                'roi'     => 'تتبع أفضل وقرارات أذكى خلال أسبوعين',
            ],
        ],
        'action_week'  => ['مراجعة ملفات التواصل', 'تركيب أدوات التتبع', 'تحديث Bio بـ CTA واضح'],
        'action_month' => ['إطلاق خطة محتوى', 'أول حملة إعلانية', 'قياس النتائج وتحسينها'],
        'score_insight'  => "درجتك {$score}/100 تضعك في الرُبع " . ($score > 75 ? 'الأول المتقدم' : ($score > 50 ? 'الثاني' : 'الأدنى')) . " من النشاطات في قطاع {$type}.",
        'competitor_note'=> 'المنافسون الأقوى في هذا القطاع يستثمرون في المحتوى الفيديو القصير والإعلانات الممنهجة.',
    ];
}
