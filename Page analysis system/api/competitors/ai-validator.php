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
