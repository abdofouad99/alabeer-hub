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
    $priority = $cfg['analysis']['ai_priority'] ?? ['gemini', 'groq', 'deepseek'];

    foreach ($priority as $provider) {
        try {
            $result = callAIProvider($provider, $data, $cfg);
            if (!empty($result['summary'])) return $result;
        } catch (\Throwable $e) {
            // جرّب المزود التالي
            continue;
        }
    }

    return fallbackAnalysis($data);
}

// ============================================================
function callAIProvider(string $provider, array $data, array $cfg): array {
    $prompt = buildPrompt($data);

    return match($provider) {
        'gemini'   => callGemini($prompt, $data, $cfg),
        'groq'     => callGroq($prompt, $data, $cfg),
        'deepseek' => callDeepSeek($prompt, $data, $cfg),
        'openai'   => callOpenAI($prompt, $data, $cfg),
        default    => throw new \Exception("Unknown provider: {$provider}"),
    };
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
                'maxOutputTokens'  => 2048,
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

        if (!$response || $httpCode === 429) continue; // rate limit — جرّب المفتاح التالي
        if ($httpCode >= 400) continue;

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
        'max_tokens'  => 2048,
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

    $body = json_encode(['model' => $model, 'messages' => $messages, 'temperature' => 0.7, 'max_tokens' => 2048]);

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

    $body = json_encode(['model' => $model, 'messages' => $messages, 'temperature' => 0.7, 'max_tokens' => 2048, 'response_format' => ['type' => 'json_object']]);

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

// ── Parser موحّد لجميع مزودي AI ─────────────────────────────
function parseAIResponse(array $aiData, string $source): array {
    return [
        'source'          => $source,
        'summary'         => $aiData['summary']         ?? '',
        'strengths'       => $aiData['strengths']       ?? [],
        'weaknesses'      => $aiData['weaknesses']      ?? [],
        'recommendations' => $aiData['recommendations'] ?? [],
        'action_week'     => $aiData['action_week']     ?? [],
        'action_month'    => $aiData['action_month']    ?? [],
        'score_insight'   => $aiData['score_insight']   ?? '',
        'competitor_note' => $aiData['competitor_note'] ?? '',
        'ai_tier'         => $aiData['tier']            ?? 'yellow',
    ];
}


// ============================================================
function buildPrompt(array $data): string {
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
            $scanInfo = "نتائج الفحص التقني للموقع:\n";
            $scanInfo .= "- HTTPS: " . ($scan['hasSSL'] ?? $scan['has_ssl'] ?? false ? 'نعم' : 'لا') . "\n";
            $scanInfo .= "- Facebook Pixel: " . ($scan['hasPixel'] ?? $scan['has_fb_pixel'] ?? false ? 'نعم ✅' : 'لا ❌') . "\n";
            $scanInfo .= "- Google Analytics: " . ($scan['hasGA'] ?? $scan['has_ga'] ?? false ? 'نعم ✅' : 'لا ❌') . "\n";
            $scanInfo .= "- زر واتساب: " . ($scan['hasWhatsApp'] ?? $scan['has_whatsapp'] ?? false ? 'نعم ✅' : 'لا ❌') . "\n";
            $scanInfo .= "- سرعة التحميل: " . ($scan['speedRating'] ?? $scan['speed_rating'] ?? 'غير معروف') . "\n";
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

    return <<<PROMPT
أنت خبير تسويق رقمي متخصص في السوق العربي بخبرة 10+ سنوات.

قم بتحليل الوضع التسويقي للنشاط التالي وأعطِ توصيات دقيقة ومخصصة:

**معلومات النشاط:**
- الاسم: {$name}
- الشركة/النشاط: {$company}
- نوع المشروع: {$type}
- الدولة: {$country}
- المنصة الرئيسية: {$platform}
- الدرجة الإجمالية: {$score}/100

**تحليل المحاور:**
{$breakdown}

**إجابات التقييم:**
{$answersText}

{$scanInfo}

أعطني تحليلاً شاملاً بصيغة JSON بدون أي نص خارجه، بالتنسيق التالي:
{
  "summary": "خلاصة تنفيذية مخصصة ودقيقة بـ 2-3 جمل تصف الوضع الحالي",
  "score_insight": "تعليق على الدرجة ({$score}/100) مقارنة بالمنافسين في نفس القطاع",
  "tier": "red|yellow|green",
  "strengths": ["نقطة قوة 1", "نقطة قوة 2", "نقطة قوة 3"],
  "weaknesses": ["نقطة ضعف 1", "نقطة ضعف 2", "نقطة ضعف 3"],
  "recommendations": [
    {
      "title": "عنوان التوصية",
      "priority": "عاجل|مهم|اختياري",
      "impact": "عالي|متوسط|منخفض",
      "bullets": ["خطوة 1", "خطوة 2", "خطوة 3"],
      "roi": "نتيجة متوقعة خلال 30 يوم"
    }
  ],
  "action_week": ["مهمة اليوم 1-7 الأولى", "مهمة 2", "مهمة 3"],
  "action_month": ["هدف شهر 1", "هدف شهر 2", "هدف شهر 3"],
  "competitor_note": "ملاحظة حول المنافسين في هذا القطاع والسوق"
}

التعليمات:
- اكتب بالعربية الفصحى المبسطة
- كن دقيقاً ومخصصاً لنوع المشروع ({$type}) في ({$country})
- أعط توصيات قابلة للتنفيذ فوراً
- ركّز على ما يستطيع الشخص تنفيذه بدون ميزانية كبيرة
- لا تعطِ توصيات عامة — كل شيء يجب أن يكون مخصصاً لهذا النشاط
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
