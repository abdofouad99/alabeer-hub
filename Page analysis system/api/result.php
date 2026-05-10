<?php
// ============================================================
// api/result.php — جلب نتيجة تقييم معين (v4.1)
// GET /api/result.php?id=123
//
// v4.1 (PR #9): طبقة تطبيع دفاعية للبيانات المخزّنة في قاعدة البيانات.
// تُكمل التطبيع الذي يحدث في api/ai-analyze.php (PR #8) لكن تنطبق على
// السجلات القديمة المخزّنة قبل #8 (strengths/weaknesses كـ kalimat).
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/page-scan.php';
setCors();

// ── طبقة تطبيع دفاعية: مكافِئ للـ normalizeStrengthWeakness في ai-analyze.php ──
// تضمن وجود مفتاح 'title' في كل عنصر strengths/weaknesses قبل إرساله للواجهة.
// لا تضع 'score' في الـ object المُحوَّل من نص؛ الـ frontend يختار الـ default
// المناسب لكل صفحة (95-i*5 للقوة، 30+i*5 للضعف).
$__normalizeItemsForRender = static function(array $items): array {
    $altKeys = ['title', 'name', 'point', 'text', 'heading', 'label', 'item', 'desc', 'description'];
    return array_values(array_filter(array_map(static function($item) use ($altKeys) {
        if (is_string($item)) {
            $trimmed = trim($item);
            return $trimmed !== '' ? ['title' => $trimmed, 'desc' => ''] : null;
        }
        if (is_array($item)) {
            if (empty($item['title']) || !is_string($item['title'])) {
                foreach ($altKeys as $k) {
                    if (!empty($item[$k]) && is_string($item[$k])) {
                        $item['title'] = $item[$k];
                        break;
                    }
                }
            }
            if (empty($item['title']) || !is_string($item['title'])) {
                return null;
            }
            return $item;
        }
        return null;
    }, $items)));
};

// ── مطَبِّع لـ action_week: قد يُرجع الـ AI strings أو objects بحقل 'task' ──
$__normalizeActionItemsForRender = static function(array $items): array {
    return array_values(array_filter(array_map(static function($item) {
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
};

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) jsonError('معرّف التقييم غير صالح');

$db   = getDB();
$stmt = $db->prepare("SELECT a.*, l.full_name, l.company_name, l.project_type, l.country, l.platform, l.website_url, l.facebook_url, l.instagram_url, l.tiktok_url, l.twitter_url FROM assessments a LEFT JOIN leads l ON a.lead_id=l.id WHERE a.id = ? LIMIT 1");
$stmt->execute([$id]);
$row  = $stmt->fetch();

if (!$row) jsonError('لم يُعثر على التقييم', 404);

// إذا كان التحليل لم ينتهِ بعد — أرجع حالة pending
if ($row['status'] === 'submitted' || $row['status'] === 'running') {
    jsonOut([
        'status'  => 'pending',
        'id'      => (int)$id,
        'message' => 'التحليل جارٍ... يرجى الانتظار أو إعادة المحاولة بعد لحظات.',
    ], 202);
}

// ── Decode JSON fields ──────────────────────────────────────
$jsonFields = ['breakdown','strengths','weaknesses','recommendations','scan_result','next_steps','ai_report'];
foreach ($jsonFields as $f) {
    if (!empty($row[$f]) && is_string($row[$f])) {
        $row[$f] = json_decode($row[$f], true);
    }
}

if (!empty($row['scan_result']) && is_array($row['scan_result']) && function_exists('normalizeScanResult')) {
    $row['scan_result'] = normalizeScanResult($row['scan_result']);
}

// ── استخراج بيانات الذكاء الاصطناعي من ai_report ──────────
// ai_report يحتوي على كامل مخرجات الذكاء الاصطناعي
$aiReport = is_array($row['ai_report']) ? $row['ai_report'] : [];
if (!is_array($row['ai_report'])) {
    $row['ai_report'] = [];
}

$__hasRenderableValue = static function($value): bool {
    if (is_array($value)) return count($value) > 0;
    if (is_string($value)) return trim($value) !== '';
    return $value !== null;
};

$__copyAiFieldToRoot = static function(array &$row, array $aiReport, string $field, ?string $target = null) use ($__hasRenderableValue): void {
    $target = $target ?: $field;
    if (array_key_exists($field, $aiReport) && $__hasRenderableValue($aiReport[$field])) {
        $row[$target] = $aiReport[$field];
    }
};

// أولوية: strengths/weaknesses من ai_report (الذكاء الاصطناعي الحقيقي)
// وإلا من الحقول المستقلة (التي حفظها analyze.php)
if (!empty($aiReport['strengths'])) {
    $row['ai_report']['strengths']  = $aiReport['strengths'];
}
if (!empty($aiReport['weaknesses'])) {
    $row['ai_report']['weaknesses'] = $aiReport['weaknesses'];
}

// ── إذا كانت strengths/weaknesses فارغة في ai_report، ابحث في جذر الـ row ──
if (empty($row['ai_report']['strengths']) && !empty($row['strengths'])) {
    $row['ai_report']['strengths']  = is_array($row['strengths']) ? $row['strengths'] : json_decode($row['strengths'], true) ?? [];
}
if (empty($row['ai_report']['weaknesses']) && !empty($row['weaknesses'])) {
    $row['ai_report']['weaknesses'] = is_array($row['weaknesses']) ? $row['weaknesses'] : json_decode($row['weaknesses'], true) ?? [];
}

// Prefer the final AI JSON for all render-facing fields.
// Root columns may contain older local/fallback data, while ai_report carries
// the final report shape consumed by the frontend.
$aiReport = is_array($row['ai_report']) ? $row['ai_report'] : [];
if (!($__hasRenderableValue($aiReport['summary'] ?? null)) && $__hasRenderableValue($aiReport['final_report'] ?? null)) {
    $row['ai_report']['summary'] = $aiReport['final_report'];
    $aiReport['summary'] = $aiReport['final_report'];
}
foreach ([
    'summary',
    'strengths',
    'weaknesses',
    'recommendations',
    'action_week',
    'action_month',
    'competitor_analysis',
    'score_insight',
    'competitor_note',
    'ads_analysis',
    'competitor_radar',
    'content_strategy',
    'customer_journey',
    'market_opportunity',
    'platform_strategy',
    'quick_wins',
    'kpis_to_track',
    'executive_plan',
] as $__aiField) {
    $__copyAiFieldToRoot($row, $aiReport, $__aiField);
}
if ($__hasRenderableValue($row['action_week'] ?? null)) {
    $row['next_steps'] = $row['action_week'];
}

// ── مزامنة عكسية: انسخ strengths/weaknesses من ai_report إلى جذر الـ row ──
// الـ inline script في report.html يقرأ data.strengths (الجذر) مباشرة
// لذلك لا بد من ضمان وجود البيانات في الموقعين
if (!empty($row['ai_report']['strengths']) && empty($row['strengths'])) {
    $row['strengths'] = $row['ai_report']['strengths'];
}
if (!empty($row['ai_report']['weaknesses']) && empty($row['weaknesses'])) {
    $row['weaknesses'] = $row['ai_report']['weaknesses'];
}
// في كل الأحوال، تأكد أن الجذر يحتوي على أحدث البيانات (ai_report أقوى)
if (!empty($row['ai_report']['strengths'])) {
    $row['strengths'] = $row['ai_report']['strengths'];
}
if (!empty($row['ai_report']['weaknesses'])) {
    $row['weaknesses'] = $row['ai_report']['weaknesses'];
}

// ── content_analysis ─────────────────────────────────────────
if (empty($row['ai_report']['content_analysis']) && !empty($aiReport['content_analysis'])) {
    $row['ai_report']['content_analysis'] = $aiReport['content_analysis'];
}

// ── action_week من next_steps ────────────────────────────────
if (!empty($row['next_steps']) && is_array($row['next_steps'])) {
    $row['action_week'] = $row['next_steps'];
}

// إضافة action_week من scan كبديل إذا لم يرجع الذكاء الاصطناعي شيئاً
if (!empty($row['scan_result']) && is_array($row['scan_result']) && empty($row['action_week'])) {
    $row['action_week'] = [];
    $scan = $row['scan_result'];
    if (!($scan['hasPixel'] ?? false)) $row['action_week'][] = 'تركيب Meta Pixel على الموقع.';
    if (!($scan['hasGA'] ?? false))    $row['action_week'][] = 'إعداد Google Analytics 4.';
    if (!($scan['hasSSL'] ?? true))    $row['action_week'][] = 'تفعيل HTTPS من لوحة الاستضافة.';
    $hasWA = ($scan['hasWhatsApp'] ?? false)
           || ($scan['has_whatsapp'] ?? false)
           || ($scan['facebook']['has_whatsapp'] ?? false)
           || (!empty($scan['facebook']['whatsapp']))
           || ($scan['social']['has_whatsapp'] ?? false)
           || ($scan['website_scan']['has_whatsapp'] ?? false);
    if (!$hasWA) $row['action_week'][] = 'إضافة زر واتساب للموقع.';
    $row['action_week'][] = 'مراجعة Bio على جميع المنصات وإضافة CTA.';
}

// ── نقل recommendations من ai_report للجذر إذا لم تكن موجودة ──
// JS يقرأ data.recommendations (جذر الـ row)
if (empty($row['recommendations']) && !empty($row['ai_report']['recommendations'])) {
    $row['recommendations'] = $row['ai_report']['recommendations'];
}
// وإذا كانت في الجذر فقط — انقلها للـ ai_report أيضاً للاتساق
if (!empty($row['recommendations']) && empty($row['ai_report']['recommendations'])) {
    $row['ai_report']['recommendations'] = $row['recommendations'];
}

// ── حقول موحدة للـ Frontend ──────────────────────────────────
$row['url']       = $row['website_url'] ?: $row['facebook_url'] ?: $row['instagram_url'] ?: $row['tiktok_url'] ?: $row['twitter_url'] ?: '';
$row['full_name'] = $row['full_name']   ?: $row['company_name'] ?: '';

// ── طبقة تطبيع دفاعية (PR #9): تنطبق على البيانات بعد جمعها وقبل الإرسال ──
// تحمي من سجلات DB قديمة كانت محفوظة قبل تطبيع PR #8 في api/ai-analyze.php.
// آمنة على البيانات المُطبَّعة فعلاً (فهي idempotent).
if (!empty($row['ai_report']['strengths']) && is_array($row['ai_report']['strengths'])) {
    $row['ai_report']['strengths']  = $__normalizeItemsForRender($row['ai_report']['strengths']);
}
if (!empty($row['ai_report']['weaknesses']) && is_array($row['ai_report']['weaknesses'])) {
    $row['ai_report']['weaknesses'] = $__normalizeItemsForRender($row['ai_report']['weaknesses']);
}
// تكرار للحقول في الجذر (يستهلكها الـ inline script في report.html بعد إصلاح PR #9)
if (!empty($row['strengths']) && is_array($row['strengths'])) {
    $row['strengths']  = $__normalizeItemsForRender($row['strengths']);
}
if (!empty($row['weaknesses']) && is_array($row['weaknesses'])) {
    $row['weaknesses'] = $__normalizeItemsForRender($row['weaknesses']);
}
// action_week: نتأكد من أنها strings (مش objects)
if (!empty($row['action_week']) && is_array($row['action_week'])) {
    $row['action_week'] = $__normalizeActionItemsForRender($row['action_week']);
}
if (!empty($row['ai_report']['action_week']) && is_array($row['ai_report']['action_week'])) {
    $row['ai_report']['action_week'] = $__normalizeActionItemsForRender($row['ai_report']['action_week']);
}

// ── package_tier: مجاني (3 توصيات) أم مدفوع (القائمة الكاملة) ──
// المصدر: assessments.is_unlocked (TINYINT(1)) — يُضبط على 1 من قِبل الإدارة
// عند فتح التقرير للعميل بعد دفع باقة. يُستهلك في الواجهة (report-connect.js)
// لتطبيق slice(0, 3) على قسم التوصيات في الباقة المجانية.
$row['package_tier'] = !empty($row['is_unlocked']) ? 'paid' : 'free';

// ── DEBUG: أضف مؤشر المصدر لتسهيل التشخيص ──────────────────
$row['_debug'] = [
    'ai_report_source'        => !empty($row['ai_report']) ? 'DB:ai_report' : 'EMPTY',
    'strengths_count'         => count($row['ai_report']['strengths']       ?? []),
    'weaknesses_count'        => count($row['ai_report']['weaknesses']      ?? []),
    'recommendations_count'   => count($row['recommendations']              ?? []),
    'has_high_priority_rec'   => count(array_filter($row['recommendations'] ?? [], fn($r) => ($r['priority'] ?? '') === 'high')),
    'has_content_analysis'    => !empty($row['ai_report']['content_analysis']),
    'data_quality'            => $row['scan_result']['data_quality'] ?? null,
    'ads_actor_used'          => $row['scan_result']['ads_library']['actor_used'] ?? null,
    'ads_raw_count'           => $row['scan_result']['ads_library']['raw_count'] ?? 0,
    'ads_mapped_count'        => count($row['scan_result']['ads_library']['ads'] ?? []),
];

jsonOut($row);
