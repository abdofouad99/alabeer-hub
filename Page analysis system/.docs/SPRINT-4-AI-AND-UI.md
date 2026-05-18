# 🤖 Sprint 4: AI Analysis & UI — التحليل الذكي والواجهة

## الهدف
تحويل البيانات الخام لكل منافس إلى تحليل احترافي عبر AI (مع منع hallucinations) + تحديث `competitors.html` لعرض بطاقات احترافية.

## المخرجات النهائية
- `api/competitors/ai-analysis.php` — تحليل لكل منافس بـ 9 حقول
- `api/competitors/ai-validator.php` — منع hallucinations
- `api/competitors/market-summary.php` — ملخص سوقي
- تحديث `competitors.html` كاملاً
- تحديث `js/report-connect.js` لعرض البيانات الجديدة

## مدة العمل المتوقعة
4-5 أيام

---

# 📁 الملفات الجديدة

## 1. `api/competitors/ai-validator.php`

### الوظيفة
يفحص رد AI ويتأكد أن كل رقم/ادعاء مذكور موجود فعلاً في البيانات الأصلية.

### الكود الكامل

```php
<?php
/**
 * AI Response Validator — Anti-Hallucination
 * يفحص رد AI ضد البيانات الفعلية. يرفض الردود التي تخترع أرقام.
 */

declare(strict_types=1);

require_once __DIR__ . '/../logger.php';

/**
 * @return array {
 *   valid: bool,
 *   violations: string[],
 *   sanitized?: array (الرد بعد إزالة الأجزاء المخالفة)
 * }
 */
function validateAIResponseAgainstData(array $aiResponse, array $rawData): array {
    $violations = [];

    // ── 1. فحص الأرقام المذكورة ──
    // أي رقم في الـ AI response يجب أن يكون موجود تقريباً في rawData
    $aiNumbers   = _extractNumbersFromResponse($aiResponse);
    $dataNumbers = _extractNumbersFromData($rawData);

    foreach ($aiNumbers as $context => $numbers) {
        foreach ($numbers as $num) {
            if (!_isNumberPresentInData($num, $dataNumbers)) {
                $violations[] = "رقم مخترع في {$context}: {$num} (غير موجود في البيانات الفعلية)";
            }
        }
    }

    // ── 2. فحص الكلمات المحظورة ──
    $bannedWords = [
        'غالباً', 'يبدو أن', 'من المحتمل', 'ربما', 'عادةً',
        'وجود قوي في السوق', 'خدمة عملاء بطيئة', 'سمعة جيدة',
        'probably', 'likely', 'usually', 'seems',
    ];

    $allText = json_encode($aiResponse, JSON_UNESCAPED_UNICODE);
    foreach ($bannedWords as $word) {
        if (mb_stripos($allText, $word) !== false) {
            $violations[] = "كلمة محظورة: '{$word}'";
        }
    }

    // ── 3. فحص أن البيانات الناقصة null وليست placeholder ──
    foreach (['strengths', 'weaknesses', 'attack_plan'] as $field) {
        if (isset($aiResponse[$field]) && is_array($aiResponse[$field])) {
            foreach ($aiResponse[$field] as $item) {
                if (is_string($item) && _looksLikePlaceholder($item)) {
                    $violations[] = "placeholder مكتشف في {$field}: '{$item}'";
                }
            }
        }
    }

    return [
        'valid'      => empty($violations),
        'violations' => $violations,
    ];
}

/**
 * استخراج كل الأرقام من رد AI مع context
 */
function _extractNumbersFromResponse(array $response): array {
    $numbers = [];

    $traverse = function ($data, $path = '') use (&$traverse, &$numbers) {
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                $newPath = $path ? "$path.$k" : (string)$k;
                $traverse($v, $newPath);
            }
        } elseif (is_string($data)) {
            // استخراج الأرقام من النص (1234, 1,234, 1.5K, 100%)
            preg_match_all('/\b(\d+(?:[\.,]\d+)?)\b/', $data, $matches);
            if (!empty($matches[1])) {
                $numbers[$path] = array_map('floatval', $matches[1]);
            }
        } elseif (is_numeric($data)) {
            $numbers[$path] = [(float)$data];
        }
    };

    $traverse($response);
    return $numbers;
}

/**
 * استخراج كل الأرقام الموجودة في البيانات الفعلية
 */
function _extractNumbersFromData(array $data): array {
    $numbers = [];

    $traverse = function ($d) use (&$traverse, &$numbers) {
        if (is_array($d)) {
            foreach ($d as $v) $traverse($v);
        } elseif (is_numeric($d)) {
            $numbers[] = (float)$d;
        }
    };

    $traverse($data);

    // إضافة الـ percentages الشائعة (من حقل engagement_rate إلخ)
    return array_unique($numbers);
}

/**
 * هل الرقم موجود في البيانات (مع tolerance ±5%)
 */
function _isNumberPresentInData(float $num, array $dataNumbers): bool {
    if ($num === 0.0 || $num === 1.0 || $num === 100.0) return true; // أرقام عامة
    if ($num < 5) return true; // أرقام صغيرة (1, 2, 3 = ranks/counts)

    $tolerance = max(1, abs($num) * 0.05); // ±5%
    foreach ($dataNumbers as $dn) {
        if (abs($dn - $num) <= $tolerance) return true;
    }
    return false;
}

/**
 * هل النص يبدو placeholder (جملة قالبية بدون أرقام)
 */
function _looksLikePlaceholder(string $text): bool {
    $placeholderPatterns = [
        '/^(منافس|قوة منافس|ضعف منافس)\d/iu',
        '/^[\.\-—\s]+$/u',
        '/^(تحليل|نقطة|ميزة|عيب)\s*\d+$/iu',
        '/^\W+$/u', // فقط رموز
    ];

    foreach ($placeholderPatterns as $p) {
        if (preg_match($p, trim($text))) return true;
    }

    // النص قصير جداً
    if (mb_strlen(trim($text)) < 10) return true;

    return false;
}
```

---

## 2. `api/competitors/ai-analysis.php`

### الوظيفة
يحلل كل منافس بمفرده باستخدام OpenAI (أو Gemini) مع prompt صارم.

### الكود الكامل

```php
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
```

---

## 3. `api/competitors/market-summary.php`

### الوظيفة
ملخص شامل للسوق: ترتيب العميل، متوسطات السوق، فجوات Blue Ocean.

### الكود الكامل

```php
<?php
/**
 * Market Summary — STEP 6
 * تحليل شامل لكل السوق (العميل + 5 منافسين)
 */

declare(strict_types=1);

require_once __DIR__ . '/../logger.php';

/**
 * @param array $competitors المنافسون بعد analyzeCompetitorDeep
 * @param array $clientData بيانات العميل
 * @return array {
 *   client_rank, client_position,
 *   market_averages, market_leader,
 *   blue_ocean_opportunities,
 *   biggest_threat, top_3_actions
 * }
 */
function buildMarketSummary(array $competitors, array $clientData, array $cfg): array {
    // ── 1. حساب متوسطات السوق ──
    $marketAverages = _calculateMarketAverages($competitors, $clientData);

    // ── 2. ترتيب العميل ──
    $ranking = _rankClientInMarket($competitors, $clientData);

    // ── 3. اكتشاف Blue Ocean (فرص لم يستغلها أحد) ──
    $blueOcean = _identifyBlueOceanOpportunities($competitors, $clientData);

    // ── 4. أكبر تهديد ──
    $biggestThreat = _identifyBiggestThreat($competitors);

    // ── 5. أهم 3 إجراءات (AI-generated) ──
    $top3Actions = _generateTop3Actions($competitors, $clientData, $marketAverages, $cfg);

    return [
        'client_rank'              => $ranking['rank'],
        'client_position'          => $ranking['position'], // "1st" | "2nd" | etc
        'total_competitors'        => count($competitors) + 1, // +1 للعميل
        'client_score_breakdown'   => $ranking['score_breakdown'],
        'market_averages'          => $marketAverages,
        'market_leader'            => $ranking['leader'],
        'blue_ocean_opportunities' => $blueOcean,
        'biggest_threat'           => $biggestThreat,
        'top_3_actions'            => $top3Actions,
        'generated_at'             => date('c'),
    ];
}

function _calculateMarketAverages(array $competitors, array $clientData): array {
    $allFollowers = [];
    $allEngagement = [];
    $allPostsPerWeek = [];
    $allRatings = [];

    // العميل
    foreach (['facebook','instagram','tiktok','twitter'] as $p) {
        if (!empty($clientData[$p]['followers']))       $allFollowers[]      = (int)$clientData[$p]['followers'];
        if (!empty($clientData[$p]['engagement_rate'])) $allEngagement[]     = (float)$clientData[$p]['engagement_rate'];
        if (!empty($clientData[$p]['posts_per_week']))  $allPostsPerWeek[]   = (float)$clientData[$p]['posts_per_week'];
    }

    // المنافسون
    foreach ($competitors as $comp) {
        if (!empty($comp['rating'])) $allRatings[] = (float)$comp['rating'];

        foreach (['facebook','instagram','tiktok','twitter'] as $p) {
            if (!empty($comp['platforms'][$p]['followers']))       $allFollowers[]      = (int)$comp['platforms'][$p]['followers'];
            if (!empty($comp['platforms'][$p]['engagement_rate'])) $allEngagement[]     = (float)$comp['platforms'][$p]['engagement_rate'];
            if (!empty($comp['platforms'][$p]['posts_per_week']))  $allPostsPerWeek[]   = (float)$comp['platforms'][$p]['posts_per_week'];
        }
    }

    return [
        'avg_followers'      => !empty($allFollowers) ? (int)round(array_sum($allFollowers) / count($allFollowers)) : null,
        'median_followers'   => !empty($allFollowers) ? _median($allFollowers) : null,
        'avg_engagement'     => !empty($allEngagement) ? round(array_sum($allEngagement) / count($allEngagement), 2) : null,
        'avg_posts_per_week' => !empty($allPostsPerWeek) ? round(array_sum($allPostsPerWeek) / count($allPostsPerWeek), 1) : null,
        'avg_rating'         => !empty($allRatings) ? round(array_sum($allRatings) / count($allRatings), 2) : null,
    ];
}

function _median(array $arr): float {
    sort($arr);
    $n = count($arr);
    if ($n === 0) return 0;
    if ($n % 2 === 1) return $arr[(int)($n / 2)];
    return ($arr[$n / 2 - 1] + $arr[$n / 2]) / 2;
}

function _rankClientInMarket(array $competitors, array $clientData): array {
    // حساب score للعميل
    $clientScore = _calculateAccountScore($clientData, true);

    // حساب scores للمنافسين
    $allScores = [['name' => 'CLIENT', 'score' => $clientScore['total'], 'is_client' => true]];
    foreach ($competitors as $idx => $comp) {
        $score = _calculateAccountScore($comp, false);
        $allScores[] = [
            'name'      => $comp['name'],
            'score'     => $score['total'],
            'is_client' => false,
            'idx'       => $idx,
        ];
    }

    // ترتيب
    usort($allScores, fn($a, $b) => $b['score'] <=> $a['score']);

    $rank = 1;
    foreach ($allScores as $item) {
        if (!empty($item['is_client'])) break;
        $rank++;
    }

    $positions = ['1st' => 'الأول', '2nd' => 'الثاني', '3rd' => 'الثالث', '4th' => 'الرابع', '5th' => 'الخامس', '6th' => 'الأخير'];
    $positionKey = match($rank) {
        1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', 5 => '5th', default => '6th'
    };

    return [
        'rank'            => $rank,
        'position'        => $positions[$positionKey] ?? "#{$rank}",
        'total'           => count($allScores),
        'leader'          => $allScores[0]['name'],
        'leader_score'    => $allScores[0]['score'],
        'client_score'    => $clientScore['total'],
        'score_breakdown' => $clientScore['breakdown'],
    ];
}

/**
 * scoring مبسط للحساب (للترتيب فقط)
 */
function _calculateAccountScore($entity, bool $isClient): array {
    $breakdown = [
        'followers' => 0,
        'engagement' => 0,
        'activity' => 0,
        'rating' => 0,
        'website' => 0,
        'ads' => 0,
    ];

    $platforms = $isClient ? $entity : ($entity['platforms'] ?? []);

    // followers (أعلى منصة)
    $maxFollowers = 0;
    foreach (['facebook','instagram','tiktok','twitter'] as $p) {
        $f = (int)($platforms[$p]['followers'] ?? 0);
        if ($f > $maxFollowers) $maxFollowers = $f;
    }
    if ($maxFollowers >= 100000)     $breakdown['followers'] = 25;
    elseif ($maxFollowers >= 50000)  $breakdown['followers'] = 20;
    elseif ($maxFollowers >= 10000)  $breakdown['followers'] = 15;
    elseif ($maxFollowers >= 1000)   $breakdown['followers'] = 10;
    elseif ($maxFollowers > 0)       $breakdown['followers'] = 5;

    // engagement (متوسط)
    $engagements = [];
    foreach (['facebook','instagram','tiktok','twitter'] as $p) {
        if (!empty($platforms[$p]['engagement_rate'])) {
            $engagements[] = (float)$platforms[$p]['engagement_rate'];
        }
    }
    $avgEng = !empty($engagements) ? array_sum($engagements) / count($engagements) : 0;
    if ($avgEng >= 5)        $breakdown['engagement'] = 25;
    elseif ($avgEng >= 3)    $breakdown['engagement'] = 20;
    elseif ($avgEng >= 1)    $breakdown['engagement'] = 15;
    elseif ($avgEng > 0)     $breakdown['engagement'] = 10;

    // posting activity
    $maxPostsPerWeek = 0;
    foreach (['facebook','instagram','tiktok','twitter'] as $p) {
        $ppw = (float)($platforms[$p]['posts_per_week'] ?? 0);
        if ($ppw > $maxPostsPerWeek) $maxPostsPerWeek = $ppw;
    }
    if ($maxPostsPerWeek >= 7)     $breakdown['activity'] = 15;
    elseif ($maxPostsPerWeek >= 3) $breakdown['activity'] = 10;
    elseif ($maxPostsPerWeek > 0)  $breakdown['activity'] = 5;

    // rating
    $rating = $isClient ? null : ($entity['rating'] ?? null);
    if ($rating >= 4.5)       $breakdown['rating'] = 15;
    elseif ($rating >= 4.0)   $breakdown['rating'] = 10;
    elseif ($rating >= 3.5)   $breakdown['rating'] = 5;

    // website quality
    $qa = $isClient ? ($entity['website_scan'] ?? null) : ($entity['quick_analysis'] ?? null);
    if (!empty($qa['has_ssl']))    $breakdown['website'] += 3;
    if (!empty($qa['has_pixel']))  $breakdown['website'] += 4;
    if (!empty($qa['has_ga']))     $breakdown['website'] += 3;
    if (!empty($qa['has_cta']))    $breakdown['website'] += 5;

    // ads
    $adsInfo = $isClient ? ($entity['ads_library'] ?? null) : ($entity['ads_info'] ?? null);
    if (!empty($adsInfo['is_running_ads'])) $breakdown['ads'] = 5;

    return [
        'total'     => array_sum($breakdown),
        'breakdown' => $breakdown,
    ];
}

function _identifyBlueOceanOpportunities(array $competitors, array $clientData): array {
    $opportunities = [];

    // 1. منصة لا أحد عليها
    $platformsUsed = ['facebook' => 0, 'instagram' => 0, 'tiktok' => 0, 'twitter' => 0];
    foreach ($competitors as $comp) {
        foreach ($platformsUsed as $p => &$count) {
            if (!empty($comp['platforms'][$p]['followers'])) $count++;
        }
    }

    foreach ($platformsUsed as $p => $count) {
        if ($count <= 1 && empty($clientData[$p]['followers'])) {
            $opportunities[] = [
                'type'        => 'platform_gap',
                'description' => "{$count} منافسين فقط على {$p} — فرصة للسيطرة المبكرة",
                'platform'    => $p,
            ];
        }
    }

    // 2. لا أحد يطلق إعلانات
    $adsRunningCount = 0;
    foreach ($competitors as $comp) {
        if (!empty($comp['ads_info']['is_running_ads'])) $adsRunningCount++;
    }
    if ($adsRunningCount === 0) {
        $opportunities[] = [
            'type'        => 'ads_gap',
            'description' => 'لا أحد من المنافسين يطلق إعلانات حالياً — فرصة لاحتكار المساحة الإعلانية',
        ];
    }

    // 3. متوسط التفاعل ضعيف
    $allEngagements = [];
    foreach ($competitors as $comp) {
        foreach ($comp['platforms'] ?? [] as $platformData) {
            if (!empty($platformData['engagement_rate'])) {
                $allEngagements[] = (float)$platformData['engagement_rate'];
            }
        }
    }
    if (!empty($allEngagements) && (array_sum($allEngagements) / count($allEngagements)) < 1.5) {
        $opportunities[] = [
            'type'        => 'engagement_gap',
            'description' => 'متوسط تفاعل المنافسين ضعيف (< 1.5%) — محتوى تفاعلي يمكن التميز به',
        ];
    }

    return $opportunities;
}

function _identifyBiggestThreat(array $competitors): ?array {
    $maxThreat = null;
    $maxScore  = 0;

    foreach ($competitors as $comp) {
        if (($comp['ai_analysis']['threat_level'] ?? '') === 'high') {
            // نختار الأعلى في data_completeness لو فيه أكثر من واحد
            $score = $comp['_meta']['data_completeness'] ?? 0;
            if ($score > $maxScore) {
                $maxScore = $score;
                $maxThreat = [
                    'name'         => $comp['name'],
                    'reason'       => 'تهديد عالي حسب AI',
                    'attack_plan'  => $comp['ai_analysis']['attack_plan'] ?? [],
                ];
            }
        }
    }

    return $maxThreat;
}

function _generateTop3Actions(array $competitors, array $clientData, array $marketAvg, array $cfg): array {
    // اعتمد على بيانات بسيطة — يمكن استخدام AI لاحقاً
    $actions = [];

    // 1. لو متوسط متابعي السوق أعلى من العميل
    $clientMaxFollowers = 0;
    foreach (['facebook','instagram','tiktok','twitter'] as $p) {
        $f = (int)($clientData[$p]['followers'] ?? 0);
        if ($f > $clientMaxFollowers) $clientMaxFollowers = $f;
    }
    $avgFollowers = $marketAvg['avg_followers'] ?? 0;
    if ($avgFollowers > 0 && $clientMaxFollowers < $avgFollowers * 0.7) {
        $actions[] = [
            'priority'    => 'high',
            'action'      => 'زيادة المتابعين لمستوى السوق',
            'description' => "متابعوك ({$clientMaxFollowers}) أقل من متوسط السوق ({$avgFollowers}). الهدف: زيادة 30% خلال 60 يوم.",
            'kpi'         => "متوسط السوق: {$avgFollowers}",
        ];
    }

    // 2. تفاعل
    $clientEng = 0;
    foreach (['facebook','instagram','tiktok','twitter'] as $p) {
        $e = (float)($clientData[$p]['engagement_rate'] ?? 0);
        if ($e > $clientEng) $clientEng = $e;
    }
    $avgEng = $marketAvg['avg_engagement'] ?? 0;
    if ($avgEng > 0 && $clientEng < $avgEng) {
        $actions[] = [
            'priority'    => 'medium',
            'action'      => 'رفع معدل التفاعل',
            'description' => "تفاعلك ({$clientEng}%) أقل من متوسط السوق ({$avgEng}%). جرب: محتوى تفاعلي، أسئلة، استطلاعات.",
            'kpi'         => "الهدف: {$avgEng}% خلال 30 يوم",
        ];
    }

    // 3. لو لا أحد يطلق إعلانات → أنت ابدأ
    $adsCount = 0;
    foreach ($competitors as $comp) {
        if (!empty($comp['ads_info']['is_running_ads'])) $adsCount++;
    }
    if ($adsCount === 0) {
        $actions[] = [
            'priority'    => 'high',
            'action'      => 'احتكار المساحة الإعلانية',
            'description' => 'لا منافس يطلق إعلانات حالياً. ابدأ حملة Awareness بـ 50 ريال/يوم لمدة 14 يوم لاحتكار الـ ad placements.',
            'kpi'         => 'هدف: 100K Reach خلال أسبوعين',
        ];
    }

    return array_slice($actions, 0, 3);
}
```

---

# 🎨 تحديث الواجهة UI

## 4. تحديث `competitors.html`

### الأقسام المضافة:

#### أ. Market Position Banner (في أعلى الصفحة بعد vs-header)
```html
<section class="market-position-banner animate-up delay-1">
  <div class="position-card">
    <div class="rank-badge" id="clientRankBadge">—</div>
    <div class="rank-text">
      <h3>ترتيبك في السوق</h3>
      <p id="clientPositionText">جاري التحليل...</p>
    </div>
  </div>
  <div class="market-stats">
    <div class="stat-item">
      <div class="stat-value" id="marketAvgFollowers">—</div>
      <div class="stat-label">متوسط متابعي السوق</div>
    </div>
    <div class="stat-item">
      <div class="stat-value" id="marketAvgEngagement">—</div>
      <div class="stat-label">متوسط التفاعل</div>
    </div>
    <div class="stat-item">
      <div class="stat-value" id="marketAvgRating">—</div>
      <div class="stat-label">متوسط التقييم</div>
    </div>
  </div>
</section>
```

#### ب. بطاقة منافس مُحدَّثة (داخل competitorsGrid)
```html
<!-- Template يُملأ من JS -->
<div class="competitor-card" data-competitor-idx="{IDX}">
  <!-- Header -->
  <div class="comp-header">
    <div class="comp-rank">{RANK}</div>
    <div class="comp-info">
      <h3 class="comp-name">{NAME}</h3>
      <p class="comp-category">{CATEGORY}</p>
      <div class="comp-meta-row">
        <span class="rating-badge" data-show="{HAS_RATING}">⭐ {RATING}</span>
        <span class="reviews-badge" data-show="{HAS_REVIEWS}">{REVIEWS_COUNT} مراجعة</span>
        <span class="threat-badge threat-{THREAT_LEVEL}">{THREAT_LABEL}</span>
      </div>
    </div>
  </div>

  <!-- Platforms grid -->
  <div class="comp-platforms">
    <!-- لكل منصة لها بيانات -->
    <div class="platform-stat" data-platform="facebook" data-show="{HAS_FB}">
      <div class="platform-icon">📘</div>
      <div class="platform-data">
        <div class="ps-num">{FB_FOLLOWERS}</div>
        <div class="ps-label">متابعين Facebook</div>
        <div class="ps-meta">تفاعل {FB_ENGAGEMENT}% • {FB_POSTS_WEEK}/أسبوع</div>
      </div>
    </div>
    <!-- مكرر لكل منصة -->
  </div>

  <!-- Quick analysis pills -->
  <div class="comp-quick-pills">
    <span class="qp" data-show="{HAS_SSL}">🔒 SSL</span>
    <span class="qp" data-show="{HAS_PIXEL}">📊 Pixel</span>
    <span class="qp" data-show="{HAS_GA}">📈 GA</span>
    <span class="qp" data-show="{HAS_ADS}">📢 يطلق {ADS_COUNT} إعلان</span>
  </div>

  <!-- AI Analysis sections -->
  <div class="comp-ai-section">
    <h4>نقاط القوة</h4>
    <ul class="strengths-list">
      <!-- يُملأ من ai_analysis.strengths -->
    </ul>
  </div>

  <div class="comp-ai-section">
    <h4>نقاط الضعف</h4>
    <ul class="weaknesses-list"></ul>
  </div>

  <div class="comp-ai-section">
    <h4>خطة الهجوم</h4>
    <ul class="attack-plan-list"></ul>
  </div>

  <!-- Steal/Avoid -->
  <div class="comp-ai-row" data-show="{HAS_STEAL}">
    <div class="steal-this">
      <div class="ai-row-icon">💎</div>
      <div>
        <div class="ai-row-label">انسخ هذا</div>
        <div class="ai-row-value">{STEAL_THIS}</div>
      </div>
    </div>
    <div class="avoid-this">
      <div class="ai-row-icon">⚠️</div>
      <div>
        <div class="ai-row-label">تجنّب هذا</div>
        <div class="ai-row-value">{AVOID_THIS}</div>
      </div>
    </div>
  </div>

  <!-- Deep ads button (Sprint 5) -->
  <button class="btn-deep-ads" onclick="deepAnalyzeAds({IDX}, '{COMP_URL}')">
    🔍 تحليل عميق لإعلانات هذا المنافس
  </button>
</div>
```

#### ج. Blue Ocean Opportunities (في الأسفل)
```html
<section class="blue-ocean-section animate-up delay-3">
  <h2>🌊 فرص Blue Ocean في السوق</h2>
  <div class="opportunities-grid" id="blueOceanGrid">
    <!-- يُملأ من market_summary.blue_ocean_opportunities -->
  </div>
</section>

<section class="top-actions-section animate-up delay-4">
  <h2>🎯 أهم 3 إجراءات للتفوّق</h2>
  <div class="actions-list" id="topActionsList">
    <!-- يُملأ من market_summary.top_3_actions -->
  </div>
</section>
```

---

## 5. تحديث `js/report-connect.js`

### السطور 2580-2620 (قسم competitors.html)

استبدل المنطق الحالي بـ:

```javascript
if (path.includes('competitors.html')) {
    const compName = document.getElementById('compClientName');
    const vsName = document.getElementById('vsClientName');
    if (compName) compName.textContent = clientName;
    if (vsName) vsName.textContent = clientName;

    // ── Market Summary ──
    const summary = data.market_summary || {};
    const rankBadge = document.getElementById('clientRankBadge');
    const positionText = document.getElementById('clientPositionText');
    if (rankBadge) rankBadge.textContent = summary.client_position || '—';
    if (positionText && summary.market_leader) {
        positionText.textContent = `الأقوى في السوق: ${summary.market_leader}`;
    }

    // Market averages (إخفاء لو null)
    setIfNotNull('marketAvgFollowers', summary.market_averages?.avg_followers, formatNumber);
    setIfNotNull('marketAvgEngagement', summary.market_averages?.avg_engagement, v => `${v}%`);
    setIfNotNull('marketAvgRating', summary.market_averages?.avg_rating, v => `⭐ ${v}`);

    // ── Competitors Grid ──
    const grid = document.getElementById('competitorsGrid');
    if (grid && Array.isArray(data.competitor_radar) && data.competitor_radar.length > 0) {
        grid.innerHTML = '';
        data.competitor_radar.forEach((comp, idx) => {
            const card = renderCompetitorCard(comp, idx + 1);
            grid.appendChild(card);
        });
    }

    // ── Blue Ocean ──
    const blueOceanGrid = document.getElementById('blueOceanGrid');
    if (blueOceanGrid && Array.isArray(summary.blue_ocean_opportunities)) {
        blueOceanGrid.innerHTML = summary.blue_ocean_opportunities.map(opp => `
            <div class="opportunity-card">
                <div class="opp-icon">${opp.type === 'platform_gap' ? '🚀' : opp.type === 'ads_gap' ? '📢' : '💡'}</div>
                <div class="opp-content">${sanitize(opp.description)}</div>
            </div>
        `).join('');
    }

    // ── Top Actions ──
    const actionsList = document.getElementById('topActionsList');
    if (actionsList && Array.isArray(summary.top_3_actions)) {
        actionsList.innerHTML = summary.top_3_actions.map((act, i) => `
            <div class="action-item priority-${act.priority || 'medium'}">
                <div class="action-num">${i + 1}</div>
                <div class="action-body">
                    <h4>${sanitize(act.action)}</h4>
                    <p>${sanitize(act.description)}</p>
                    ${act.kpi ? `<div class="action-kpi">📊 ${sanitize(act.kpi)}</div>` : ''}
                </div>
            </div>
        `).join('');
    }
}
```

### دوال مساعدة جديدة (أضفها في أعلى الملف)

```javascript
/**
 * تعيين قيمة لعنصر فقط لو الرقم ليس null/undefined
 * هذي القاعدة الذهبية: لا "0" مزيف، لا "غير متوفر"
 */
function setIfNotNull(elementId, value, formatter = (v) => v) {
    if (value === null || value === undefined || value === '') return;
    const el = document.getElementById(elementId);
    if (el) el.textContent = formatter(value);
}

function formatNumber(n) {
    if (n === null || n === undefined) return '';
    if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
    if (n >= 1000)    return (n / 1000).toFixed(1) + 'K';
    return n.toString();
}

function sanitize(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/**
 * بناء بطاقة منافس واحد
 */
function renderCompetitorCard(comp, rank) {
    const card = document.createElement('div');
    card.className = 'competitor-card';
    card.dataset.competitorIdx = String(rank - 1);

    const ai = comp.ai_analysis || {};
    const platforms = comp.platforms || {};
    const qa = comp.quick_analysis || {};
    const ads = comp.ads_info || {};

    // Header
    let html = `
        <div class="comp-header">
            <div class="comp-rank">${rank}</div>
            <div class="comp-info">
                <h3 class="comp-name">${sanitize(comp.name)}</h3>
                ${comp.category ? `<p class="comp-category">${sanitize(comp.category)}</p>` : ''}
                <div class="comp-meta-row">
                    ${comp.rating ? `<span class="rating-badge">⭐ ${comp.rating}</span>` : ''}
                    ${comp.reviews_count > 0 ? `<span class="reviews-badge">${comp.reviews_count} مراجعة</span>` : ''}
                    ${ai.threat_level ? `<span class="threat-badge threat-${ai.threat_level}">${threatLabel(ai.threat_level)}</span>` : ''}
                </div>
            </div>
        </div>
    `;

    // Platforms
    const platformIcons = { facebook: '📘', instagram: '📷', tiktok: '🎵', twitter: '🐦' };
    const platformLabels = { facebook: 'Facebook', instagram: 'Instagram', tiktok: 'TikTok', twitter: 'Twitter/X' };
    let platformsHtml = '<div class="comp-platforms">';
    let hasAnyPlatform = false;

    for (const [pKey, pData] of Object.entries(platforms)) {
        if (!pData || !pData.followers) continue;
        hasAnyPlatform = true;
        const meta = [];
        if (pData.engagement_rate) meta.push(`تفاعل ${pData.engagement_rate}%`);
        if (pData.posts_per_week) meta.push(`${pData.posts_per_week}/أسبوع`);

        platformsHtml += `
            <div class="platform-stat" data-platform="${pKey}">
                <div class="platform-icon">${platformIcons[pKey] || '🔗'}</div>
                <div class="platform-data">
                    <div class="ps-num">${formatNumber(pData.followers)}</div>
                    <div class="ps-label">متابعين ${platformLabels[pKey]}</div>
                    ${meta.length ? `<div class="ps-meta">${meta.join(' • ')}</div>` : ''}
                </div>
            </div>
        `;
    }
    platformsHtml += '</div>';
    if (hasAnyPlatform) html += platformsHtml;

    // Quick pills
    const pills = [];
    if (qa.has_ssl)   pills.push('🔒 SSL');
    if (qa.has_fb_pixel) pills.push('📊 Pixel');
    if (qa.has_ga)    pills.push('📈 GA');
    if (qa.has_cta)   pills.push('🎯 CTA واضح');
    if (ads.is_running_ads) {
        pills.push(`📢 ${ads.ads_count > 0 ? ads.ads_count + ' إعلانات نشطة' : 'يطلق إعلانات'}`);
    }
    if (Array.isArray(qa.tech_stack) && qa.tech_stack.length > 0) {
        qa.tech_stack.slice(0, 3).forEach(t => pills.push(`⚙️ ${sanitize(t)}`));
    }
    if (pills.length > 0) {
        html += `<div class="comp-quick-pills">${pills.map(p => `<span class="qp">${p}</span>`).join('')}</div>`;
    }

    // AI Analysis (فقط لو ai.analyzed = true)
    if (ai.analyzed) {
        // Strengths
        const strengths = (ai.strengths || []).filter(s => typeof s === 'string' && s.trim());
        if (strengths.length > 0) {
            html += `<div class="comp-ai-section"><h4>نقاط القوة</h4><ul class="strengths-list">`;
            strengths.forEach(s => html += `<li>${sanitize(s)}</li>`);
            html += `</ul></div>`;
        }

        const weaknesses = (ai.weaknesses || []).filter(w => typeof w === 'string' && w.trim());
        if (weaknesses.length > 0) {
            html += `<div class="comp-ai-section"><h4>نقاط الضعف</h4><ul class="weaknesses-list">`;
            weaknesses.forEach(w => html += `<li>${sanitize(w)}</li>`);
            html += `</ul></div>`;
        }

        const attack = (ai.attack_plan || []).filter(a => typeof a === 'string' && a.trim());
        if (attack.length > 0) {
            html += `<div class="comp-ai-section"><h4>خطة الهجوم</h4><ul class="attack-plan-list">`;
            attack.forEach(a => html += `<li>${sanitize(a)}</li>`);
            html += `</ul></div>`;
        }

        // Steal / Avoid
        if (ai.steal_this || ai.avoid_this) {
            html += `<div class="comp-ai-row">`;
            if (ai.steal_this) {
                html += `
                    <div class="steal-this">
                        <div class="ai-row-icon">💎</div>
                        <div>
                            <div class="ai-row-label">انسخ هذا</div>
                            <div class="ai-row-value">${sanitize(ai.steal_this)}</div>
                        </div>
                    </div>
                `;
            }
            if (ai.avoid_this) {
                html += `
                    <div class="avoid-this">
                        <div class="ai-row-icon">⚠️</div>
                        <div>
                            <div class="ai-row-label">تجنّب هذا</div>
                            <div class="ai-row-value">${sanitize(ai.avoid_this)}</div>
                        </div>
                    </div>
                `;
            }
            html += `</div>`;
        }
    }

    // Deep ads button (Sprint 5)
    const fbUrl = (comp.social || {}).facebook || '';
    if (fbUrl) {
        html += `
            <button class="btn-deep-ads" data-fb-url="${sanitize(fbUrl)}" data-comp-name="${sanitize(comp.name)}">
                🔍 تحليل عميق لإعلانات هذا المنافس
            </button>
        `;
    }

    // Data warnings
    if (comp._warning) {
        html += `<div class="comp-warning">⚠️ ${sanitize(comp._warning)}</div>`;
    }

    card.innerHTML = html;
    return card;
}

function threatLabel(level) {
    const labels = { high: '🔴 تهديد عالي', medium: '🟡 تهديد متوسط', low: '🟢 تهديد منخفض' };
    return labels[level] || level;
}
```

---

# 🔌 ربط Sprint 4 بالنظام

## تعديل `orchestrator.php`

أضف بعد STEP 4:

```php
// ── STEP 5: AI Analysis لكل منافس ──
require_once __DIR__ . '/ai-analysis.php';
$enrichedCompetitors = analyzeAllCompetitorsDeep(
    $merged['top_competitors'],
    $clientData,
    $cfg
);
$merged['top_competitors'] = $enrichedCompetitors;

// ── STEP 6: Market Summary ──
require_once __DIR__ . '/market-summary.php';
$marketSummary = buildMarketSummary($enrichedCompetitors, $clientData, $cfg);
```

ثم في return النهائي:

```php
return [
    'success'         => true,
    'profile'         => $profile,
    'top_competitors' => $merged['top_competitors'],
    'market_summary'  => $marketSummary,
    'metadata'        => [...],
];
```

## تعديل `analyze.php`

في السطر 1048، استبدل:

```php
$scanResult['competitor_radar'] = $compRadar;
```

بـ:

```php
if (!empty($compRadar)) {
    $scanResult['competitor_radar'] = $compRadar;
}
if (!empty($compResult['market_summary'])) {
    $scanResult['market_summary'] = $compResult['market_summary'];
}
```

---

# 🧪 خطة الاختبار

## اختبار 1: Anti-Hallucination
```php
// أرسل بيانات بسيطة (followers=1000) واطلب تحليل
// AI لو ذكر "10K متابعين" → validator يرفض ويعيد
// النتيجة المتوقعة: تحليل مبني على 1000 فقط
```

## اختبار 2: Data Gaps
```php
// منافس بدون social (فقط Google Places)
// النتيجة: ai_analysis.data_gaps يحتوي ['لا توجد بيانات سوشيال']
// strengths/weaknesses تحتوي فقط نقاط مبنية على rating + reviews
```

## اختبار 3: UI - Null Handling
```javascript
// منافس بدون followers
// النتيجة: السطر "متابعون" مخفي تماماً (لا "0" مكتوب)
```

## اختبار 4: Market Summary
```bash
# 5 منافسين + عميل
# تحقق من:
# - client_rank بين 1 و 6
# - market_averages.avg_followers > 0
# - blue_ocean_opportunities قائمة (قد تكون فارغة)
```

---

# ✅ Checklist للـ Coder Agent

- [ ] إنشاء `competitors/ai-validator.php`
- [ ] إنشاء `competitors/ai-analysis.php`
- [ ] إنشاء `competitors/market-summary.php`
- [ ] تعديل `competitors/orchestrator.php` لاستدعاء AI + Market summary
- [ ] تعديل `competitors.html` (أقسام جديدة)
- [ ] تعديل `js/report-connect.js` (renderCompetitorCard + market summary)
- [ ] تعديل `analyze.php` لحفظ market_summary في scanResult
- [ ] CSS جديد لـ `.competitor-card`, `.market-position-banner`, `.blue-ocean-section`
- [ ] `php -l` لكل ملف
- [ ] commit: `feat(competitors): Sprint 4 - AI analysis + Market summary + Updated UI`
- [ ] PR: "Sprint 4: Per-Competitor AI Analysis & Market Intelligence UI"

---

# ⏭️ بعد Sprint 4

اقرأ `SPRINT-5-ADS-DEEP-DIVE.md` لإضافة زر التحليل العميق لإعلانات منافس.
