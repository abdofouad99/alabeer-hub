<?php
/**
 * Per-Competitor AI Analysis
 * لكل منافس: 9 حقول تحليل مبنية على أرقامه الفعلية
 */

declare(strict_types=1);

require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/ai-validator.php';

/**
 * @param array $competitor المنافس بعد enrichment
 * @param array $clientData بيانات العميل للمقارنة
 * @return array منافس مُحدَّث مع حقل ai_analysis
 */
function analyzeCompetitorDeep(array $competitor, array $clientData, array $cfg): array {
    if ($competitor['_meta']['data_completeness'] ?? 0 < 20) {
        $competitor['ai_analysis'] = [
            'analyzed'  => false,
            'reason'    => 'بيانات شحيحة جداً - لا يمكن تحليلها بدقة',
            'data_gaps' => _identifyDataGaps($competitor),
        ];
        return $competitor;
    }

    // ── بناء البيانات للـ AI ──
    $aiInput = _buildAIInput($competitor, $clientData);

    // ── prompt صارم ──
    $systemPrompt = _buildSystemPrompt();
    $userPrompt   = _buildUserPrompt($aiInput);

    // ── استدعاء AI ──
    $provider = $cfg['analysis']['competitor_ai_provider'] ?? 'openai';
    $response = _callAIProvider($provider, $systemPrompt, $userPrompt, $cfg);

    if (!$response['success']) {
        $competitor['ai_analysis'] = [
            'analyzed' => false,
            'reason'   => 'فشل استدعاء AI: ' . ($response['error'] ?? ''),
        ];
        return $competitor;
    }

    // ── parsing ──
    $parsed = _parseAIResponse($response['text']);
    if ($parsed === null) {
        $competitor['ai_analysis'] = [
            'analyzed' => false,
            'reason'   => 'فشل parse الرد',
        ];
        return $competitor;
    }

    // ── Validation (Anti-Hallucination) ──
    if ($cfg['analysis']['competitor_ai_strict_mode'] ?? true) {
        $validation = validateAIResponseAgainstData($parsed, $aiInput['raw_data']);
        if (!$validation['valid']) {
            logError('AI response failed validation', [
                'competitor' => $competitor['name'],
                'violations' => $validation['violations'],
            ]);

            // محاولة ثانية مع prompt أقوى
            $retryPrompt = $userPrompt . "\n\n⚠️ المحاولة السابقة احتوت على معلومات غير دقيقة. كرر التحليل مع التزام صارم بالأرقام المرفقة فقط.";
            $retryResponse = _callAIProvider($provider, $systemPrompt, $retryPrompt, $cfg);
            if ($retryResponse['success']) {
                $retryParsed = _parseAIResponse($retryResponse['text']);
                if ($retryParsed) {
                    $retryValidation = validateAIResponseAgainstData($retryParsed, $aiInput['raw_data']);
                    if ($retryValidation['valid']) {
                        $parsed = $retryParsed;
                    } else {
                        // المحاولتان فشلتا - أعد ما لدينا مع flag
                        $parsed['_validation_warnings'] = $retryValidation['violations'];
                    }
                }
            }
        }
    }

    $competitor['ai_analysis'] = array_merge($parsed, [
        'analyzed'    => true,
        'analyzed_at' => date('c'),
        'provider'    => $provider,
    ]);

    return $competitor;
}

/**
 * بناء input للـ AI: فقط الأرقام والحقائق، لا أوصاف خام
 */
function _buildAIInput(array $competitor, array $clientData): array {
    // بيانات المنافس النظيفة
    $compData = [
        'name'           => $competitor['name'],
        'category'       => $competitor['category'] ?? null,
        'website'        => $competitor['website']  ?? null,
        'address'        => $competitor['address']  ?? null,
        'rating'         => $competitor['rating']   ?? null,
        'reviews_count'  => $competitor['reviews_count'] ?? 0,
        'platforms'      => $competitor['platforms'] ?? [],
        'reviews_summary'=> $competitor['reviews_summary'] ?? null,
        'ads_info'       => $competitor['ads_info'] ?? null,
        'quick_analysis' => $competitor['quick_analysis'] ?? null,
    ];

    // بيانات العميل (للمقارنة)
    $clientSummary = [
        'name'      => $clientData['social']['page_name'] ?? $clientData['company_name'] ?? '?',
        'platforms' => [],
    ];

    foreach (['facebook','instagram','tiktok','twitter'] as $p) {
        if (!empty($clientData[$p]['followers'])) {
            $clientSummary['platforms'][$p] = [
                'followers'       => $clientData[$p]['followers'],
                'engagement_rate' => $clientData[$p]['engagement_rate'] ?? null,
                'posts_per_week'  => $clientData[$p]['posts_per_week']  ?? null,
            ];
        }
    }

    return [
        'competitor' => $compData,
        'client'     => $clientSummary,
        'raw_data'   => $compData, // للـ validation
    ];
}

/**
 * System prompt صارم
 */
function _buildSystemPrompt(): string {
    return <<<PROMPT
أنت محلل تنافسي تسويقي محترف. مهمتك تحليل منافس وحيد بناءً على بياناته الفعلية المرفقة.

⚠️ قواعد صارمة (لا تخالفها مهما حدث):

1. ممنوع اختراع أرقام لم ترد في البيانات.
2. لو حقل = null → اكتب null في إجابتك. لا تخمّن.
3. كل ادعاء (claim) يجب أن يستند على رقم محدد من البيانات.
4. ممنوع كلمات: "غالباً، يبدو، ربما، عادة، probably, likely".
5. ممنوع قوالب: "وجود قوي في السوق، خدمة عملاء بطيئة، سمعة جيدة" بدون أرقام.
6. لو بيانات ناقصة لإكمال حقل → اتركه null واكتب السبب في "data_gaps".
7. الصياغة الإلزامية للنقاط: "بناءً على [رقم محدد] [وحدة]، هذا المنافس [استنتاج]".

📋 المخرجات: JSON صالح فقط، بدون شرح إضافي. الـ schema:

{
  "position_vs_client": "stronger" | "equal" | "weaker" | null,
  "threat_level": "high" | "medium" | "low" | null,
  "strengths": [
    "نقطة قوة #1 مع رقم محدد من البيانات",
    "نقطة قوة #2 مع رقم محدد",
    "نقطة قوة #3 مع رقم محدد"
  ],
  "weaknesses": [
    "نقطة ضعف #1 مع رقم محدد",
    "نقطة ضعف #2 مع رقم محدد",
    "نقطة ضعف #3 مع رقم محدد"
  ],
  "winning_hook": "ما الأسلوب الذي ينجح للمنافس (مع دليل رقمي) | null",
  "content_pattern": "نمط محتواه باختصار (مع أرقام: مثلاً 70% فيديو، 20% صور) | null",
  "pricing_strategy": "استراتيجية تسعيره لو ظهرت | null",
  "attack_plan": [
    "خطوة #1 محددة بأرقام للتفوق عليه",
    "خطوة #2 محددة بأرقام",
    "خطوة #3 محددة بأرقام"
  ],
  "steal_this": "ميزة واحدة قابلة للنسخ بسرعة | null",
  "avoid_this": "خطأ يرتكبه يجب على العميل تجنبه | null",
  "data_gaps": [
    "بيانات ناقصة منعت تحليلاً أعمق - مثلاً: لا توجد بيانات إعلانات"
  ]
}

اكتب باللغة العربية.
PROMPT;
}

/**
 * User prompt مع البيانات الفعلية
 */
function _buildUserPrompt(array $aiInput): string {
    $compJson   = json_encode($aiInput['competitor'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $clientJson = json_encode($aiInput['client'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    return <<<PROMPT
بيانات المنافس (فقط هذا — لا تخترع أي رقم خارجها):
```json
{$compJson}
```

بيانات العميل للمقارنة:
```json
{$clientJson}
```

حلّل المنافس بناءً على هذه البيانات فقط. أعد JSON صالح حسب الـ schema المحدد في النظام.
PROMPT;
}

/**
 * استدعاء AI provider
 */
function _callAIProvider(string $provider, string $systemPrompt, string $userPrompt, array $cfg): array {
    if ($provider === 'openai') {
        return _callOpenAI($systemPrompt, $userPrompt, $cfg);
    } elseif ($provider === 'gemini') {
        return _callGemini($systemPrompt, $userPrompt, $cfg);
    }
    return ['success' => false, 'error' => 'Unknown provider'];
}

function _callOpenAI(string $systemPrompt, string $userPrompt, array $cfg): array {
    $key = $cfg['apis']['openai_key'] ?? '';
    if (empty($key)) return ['success' => false, 'error' => 'No OpenAI key'];

    $model = $cfg['analysis']['competitor_ai_model'] ?? 'gpt-4o-mini';
    $temp  = (float)($cfg['analysis']['competitor_ai_temperature'] ?? 0.2);

    $payload = [
        'model'       => $model,
        'temperature' => $temp,
        'response_format' => ['type' => 'json_object'],
        'messages'    => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userPrompt],
        ],
        'max_tokens'  => 2000,
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
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        return ['success' => false, 'error' => "HTTP {$code}: " . substr($body, 0, 200)];
    }

    $data = json_decode($body, true);
    $text = $data['choices'][0]['message']['content'] ?? '';

    return [
        'success' => !empty($text),
        'text'    => $text,
    ];
}

function _callGemini(string $systemPrompt, string $userPrompt, array $cfg): array {
    $keys = $cfg['apis']['gemini_keys'] ?? [];
    if (empty($keys)) {
        $key = $cfg['apis']['gemini_key'] ?? '';
        if ($key) $keys = [$key];
    }
    if (empty($keys)) return ['success' => false, 'error' => 'No Gemini key'];

    $model = $cfg['apis']['gemini_model'] ?? 'gemini-1.5-flash';
    $key   = $keys[0];

    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";

    $payload = [
        'contents' => [
            ['parts' => [['text' => $systemPrompt . "\n\n" . $userPrompt]]],
        ],
        'generationConfig' => [
            'temperature'        => (float)($cfg['analysis']['competitor_ai_temperature'] ?? 0.2),
            'maxOutputTokens'    => 2000,
            'responseMimeType'   => 'application/json',
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        return ['success' => false, 'error' => "HTTP {$code}: " . substr($body, 0, 200)];
    }

    $data = json_decode($body, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

    return [
        'success' => !empty($text),
        'text'    => $text,
    ];
}

/**
 * Parse AI response (نتوقع JSON)
 */
function _parseAIResponse(string $text): ?array {
    // إزالة markdown code blocks لو وجدت
    $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
    $text = preg_replace('/```\s*$/m', '', $text);
    $text = trim($text);

    $data = json_decode($text, true);
    if (!is_array($data)) return null;

    // ضمان الـ schema الأساسي
    return [
        'position_vs_client' => $data['position_vs_client'] ?? null,
        'threat_level'       => $data['threat_level']       ?? null,
        'strengths'          => is_array($data['strengths']  ?? null) ? $data['strengths'] : [],
        'weaknesses'         => is_array($data['weaknesses'] ?? null) ? $data['weaknesses'] : [],
        'winning_hook'       => $data['winning_hook']       ?? null,
        'content_pattern'    => $data['content_pattern']    ?? null,
        'pricing_strategy'   => $data['pricing_strategy']   ?? null,
        'attack_plan'        => is_array($data['attack_plan'] ?? null) ? $data['attack_plan'] : [],
        'steal_this'         => $data['steal_this']         ?? null,
        'avoid_this'         => $data['avoid_this']         ?? null,
        'data_gaps'          => is_array($data['data_gaps']  ?? null) ? $data['data_gaps'] : [],
    ];
}

function _identifyDataGaps(array $competitor): array {
    $gaps = [];
    if (empty($competitor['platforms']))           $gaps[] = 'لا توجد بيانات سوشيال';
    if (empty($competitor['rating']))              $gaps[] = 'لا تقييم Google';
    if (empty($competitor['reviews_summary']))     $gaps[] = 'لا مراجعات';
    if (empty($competitor['quick_analysis']))      $gaps[] = 'لا تحليل موقع';
    if (empty($competitor['ads_info']))            $gaps[] = 'لا معلومات إعلانات';
    return $gaps;
}

/**
 * Wrapper: تحليل كل المنافسين
 */
function analyzeAllCompetitorsDeep(array $competitors, array $clientData, array $cfg): array {
    $results = [];
    foreach ($competitors as $comp) {
        try {
            $analyzed = analyzeCompetitorDeep($comp, $clientData, $cfg);
            $results[] = $analyzed;
        } catch (\Throwable $e) {
            logError('Per-competitor AI analysis failed', [
                'name'  => $comp['name'] ?? '?',
                'error' => $e->getMessage(),
            ]);
            $comp['ai_analysis'] = ['analyzed' => false, 'reason' => $e->getMessage()];
            $results[] = $comp;
        }
    }
    return $results;
}
