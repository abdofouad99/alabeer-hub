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
$cfg = require __DIR__ . '/config.php';
setCors();

// ============================================================
// دوال القراءة الآمنة (Null-Safe Helpers) — العملية 2
// تضمن أن أي مسار JSON مفقود لا يكسر الـ Mapping
// ============================================================

/**
 * قراءة آمنة من مصفوفة متداخلة عبر مسار نقطي
 * مثال: safeGet($arr, 'page_6_content.hook_analysis', [])
 */
function safeGet(array $data, string $path, $default = null) {
    $keys    = explode('.', $path);
    $current = $data;
    foreach ($keys as $key) {
        if (!is_array($current) || !array_key_exists($key, $current)) {
            return $default;
        }
        $current = $current[$key];
    }
    return ($current === null) ? $default : $current;
}

/** قراءة آمنة لمصفوفة — تُرجع [] إذا غير موجودة أو غير مصفوفة */
function safeGetArray(array $data, string $path): array {
    $v = safeGet($data, $path, []);
    return is_array($v) ? $v : [];
}

/** قراءة آمنة لرقم — تُرجع $default إذا غير رقمي */
function safeGetNumber(array $data, string $path, float $default = 0): float {
    $v = safeGet($data, $path, $default);
    return is_numeric($v) ? (float) $v : $default;
}

/** قراءة آمنة لنص — تُرجع placeholder إذا فارغ */
function safeGetString(array $data, string $path, string $placeholder = '—'): string {
    $v = safeGet($data, $path, $placeholder);
    return (is_string($v) && $v !== '') ? $v : $placeholder;
}

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
$token = trim((string)($_GET['token'] ?? ''));
if (!$id) jsonError('معرّف التقييم غير صالح');
if ($token === '') jsonError('لم يُعثر على التقييم', 404);

try {

$db   = getDB();
$stmt = $db->prepare("SELECT a.*, l.full_name, l.company_name, l.project_type, l.country, l.platform, l.website_url, l.facebook_url, l.instagram_url, l.tiktok_url, l.twitter_url, l.maps_url FROM assessments a LEFT JOIN leads l ON a.lead_id=l.id WHERE a.id = ? AND a.report_token = ? LIMIT 1");
$stmt->execute([$id, $token]);
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
$_aiStrengths  = safeGetArray($aiReport, 'strengths');
$_aiWeaknesses = safeGetArray($aiReport, 'weaknesses');
if (!empty($_aiStrengths))  $row['ai_report']['strengths']  = $_aiStrengths;
if (!empty($_aiWeaknesses)) $row['ai_report']['weaknesses'] = $_aiWeaknesses;

// ── إذا كانت strengths/weaknesses فارغة في ai_report، ابحث في جذر الـ row ──
$_rowStrengths  = is_array($row['strengths'] ?? null) ? $row['strengths'] : [];
$_rowWeaknesses = is_array($row['weaknesses'] ?? null) ? $row['weaknesses'] : [];
if (empty($row['ai_report']['strengths'])  && !empty($_rowStrengths))  $row['ai_report']['strengths']  = $_rowStrengths;
if (empty($row['ai_report']['weaknesses']) && !empty($_rowWeaknesses)) $row['ai_report']['weaknesses'] = $_rowWeaknesses;

// Prefer the final AI JSON for all render-facing fields.
$aiReport = is_array($row['ai_report']) ? $row['ai_report'] : [];

// ── Schema Normalization: Map new Gemini page_* keys to legacy frontend keys ──
$page_6 = safeGet($aiReport, 'page_6_content', null);
if (is_array($page_6)) {
    if (empty($aiReport['content_pillars_matrix'])) {
        $pillars = safeGetArray($page_6, 'content_pillars');
        if (!empty($pillars)) {
            $aiReport['content_pillars_matrix'] = array_map(function($p) {
                return [
                    'pillar'     => safeGetString($p, 'pillar', '—'),
                    'percentage' => safeGetNumber($p, 'percentage', 0),
                    'desc'       => safeGetString($p, 'why', ''),
                    'example'    => !empty($p['examples']) && is_array($p['examples']) ? $p['examples'][0] : '',
                ];
            }, $pillars);
        }
    }
    if (empty($aiReport['hook_bank'])) {
        $hookBank = safeGetArray($page_6, 'hook_bank');
        if (!empty($hookBank)) {
            $aiReport['hook_bank'] = array_map(function($h) {
                return [
                    'type'    => safeGetString($h, 'format', '') . ' - ' . safeGetString($h, 'platform', ''),
                    'formula' => safeGetString($h, 'psychology', ''),
                    'example' => safeGetString($h, 'hook', ''),
                ];
            }, $hookBank);
        }
    }
    if (empty($aiReport['viral_deconstruction'])) {
        $hookAnalysis = safeGet($page_6, 'hook_analysis', null);
        if (is_array($hookAnalysis)) {
            $aiReport['viral_deconstruction'] = [
                'post_type'           => 'فيديو/ريلز',
                'hook_analysis'       => safeGetString($hookAnalysis, 'current_hook', '—'),
                'sentiment_diagnosis' => [
                    'intent_to_buy' => safeGetString($hookAnalysis, 'verdict', '—'),
                    'objections'    => safeGetString($hookAnalysis, 'improvement', '—'),
                    'emotion'       => safeGetString($hookAnalysis, 'psychology_used', '—'),
                ],
                'gap_extracted' => 'بناءً على تحليل الذكاء الاصطناعي',
            ];
        }
    }
    if (empty($aiReport['omnichannel_strategy'])) {
        $viralFormula = safeGet($page_6, 'viral_formula', null);
        if (is_array($viralFormula)) {
            $formats = safeGet($viralFormula, 'trending_formats', '');
            $aiReport['omnichannel_strategy'] = [
                'core_content' => safeGetString($viralFormula, 'structure', '—'),
                'distribution' => is_array($formats) ? implode('، ', $formats) : (string) $formats,
            ];
        }
    }
}

if (empty($aiReport['customer_journey'])) {
    // page_8_journey يستخدم funnel_analysis.{awareness, interest, decision, action, loyalty}
    // لكن js/report-connect.js يتوقع customer_journey.stages.{awareness, attraction, trust, purchase, loyalty}
    // (أسماء وبنية مختلفة) — لذلك نحوّل/نطبّع هنا قبل الإرسال للواجهة.
    //
    // قبل الإصلاح: الـ funnel في report.html كان يعرض كل المراحل "—" لأن
    // cj.stages كانت غير موجودة (only cj.funnel_analysis present).
    $page8 = safeGet($aiReport, 'page_8_journey', null);
    if (is_array($page8) && !empty($page8['funnel_analysis'])) {
        $fa = $page8['funnel_analysis'];
        // مطابقة أسماء المراحل: funnel_analysis → stages
        $stageMap = [
            'awareness'  => 'awareness',
            'interest'   => 'attraction',
            'decision'   => 'trust',
            'action'     => 'purchase',
            'loyalty'    => 'loyalty',
        ];
        $stages = [];
        // ── بناء نص analysis لكل مرحلة من الحقول المتاحة في schema الوكيل ──
        // schema Agent 3 يختلف لكل مرحلة (لا يوجد حقل analysis موحّد):
        //   awareness  → current_channels, gaps, monthly_reach
        //   interest   → what_hooks_them, what_loses_them
        //   decision   → trust_signals_present, trust_signals_missing, objections
        //   action     → conversion_rate_estimate, friction_points, cta_quality
        //   loyalty    → retention_tactics_used, missing_tactics
        // كل مرحلة تبني وصفها التشخيصي من حقولها الخاصة.
        $buildStageAnalysis = static function (string $src, array $stage): string {
            // 1) لو الـ AI أرجع حقل وصفي صريح، استخدمه مباشرة
            foreach (['analysis', 'description', 'reason', 'summary', 'verdict'] as $k) {
                if (!empty($stage[$k]) && is_string($stage[$k]) && trim($stage[$k]) !== '') {
                    return trim($stage[$k]);
                }
            }
            // 2) وإلا: اصنع نصاً تشخيصياً من الحقول الخاصة بالمرحلة
            $parts = [];
            switch ($src) {
                case 'awareness':
                    $reach    = (int) ($stage['monthly_reach'] ?? 0);
                    $channels = is_array($stage['current_channels'] ?? null) ? $stage['current_channels'] : [];
                    $gaps     = is_array($stage['gaps'] ?? null)             ? $stage['gaps']             : [];
                    if ($reach > 0)               $parts[] = 'الوصول الشهري: ' . number_format($reach);
                    if (!empty($channels))        $parts[] = 'القنوات الحالية: ' . implode('، ', array_slice($channels, 0, 3));
                    if (!empty($gaps))            $parts[] = 'فجوات في: ' . implode('، ', array_slice($gaps, 0, 2));
                    break;
                case 'interest':
                    $hooks  = is_array($stage['what_hooks_them'] ?? null)  ? $stage['what_hooks_them']  : [];
                    $loses  = is_array($stage['what_loses_them'] ?? null)  ? $stage['what_loses_them']  : [];
                    if (!empty($hooks)) $parts[] = 'ما يجذبهم: ' . implode('، ', array_slice($hooks, 0, 2));
                    if (!empty($loses)) $parts[] = 'ما يفقدهم: ' . implode('، ', array_slice($loses, 0, 2));
                    break;
                case 'decision':
                    $present = is_array($stage['trust_signals_present'] ?? null) ? $stage['trust_signals_present'] : [];
                    $missing = is_array($stage['trust_signals_missing'] ?? null) ? $stage['trust_signals_missing'] : [];
                    $objs    = is_array($stage['objections'] ?? null)            ? $stage['objections']            : [];
                    if (!empty($present)) $parts[] = 'إشارات الثقة الموجودة: ' . implode('، ', array_slice($present, 0, 2));
                    if (!empty($missing)) $parts[] = 'الناقصة: ' . implode('، ', array_slice($missing, 0, 2));
                    if (!empty($objs))    $parts[] = 'اعتراضات الجمهور: ' . implode('، ', array_slice($objs, 0, 2));
                    break;
                case 'action':
                    $rate     = $stage['conversion_rate_estimate'] ?? '';
                    $friction = is_array($stage['friction_points'] ?? null) ? $stage['friction_points'] : [];
                    $ctaQ     = (int) ($stage['cta_quality'] ?? 0);
                    if (!empty($rate))             $parts[] = 'معدل التحويل المقدّر: ' . $rate;
                    if (!empty($friction))         $parts[] = 'نقاط احتكاك: ' . implode('، ', array_slice($friction, 0, 2));
                    if ($ctaQ > 0)                 $parts[] = 'جودة CTA: ' . $ctaQ . '/100';
                    break;
                case 'loyalty':
                    $used    = is_array($stage['retention_tactics_used'] ?? null) ? $stage['retention_tactics_used'] : [];
                    $missing = is_array($stage['missing_tactics'] ?? null)        ? $stage['missing_tactics']        : [];
                    if (!empty($used))    $parts[] = 'تكتيكات الاحتفاظ المستخدمة: ' . implode('، ', array_slice($used, 0, 2));
                    if (!empty($missing)) $parts[] = 'الناقصة: ' . implode('، ', array_slice($missing, 0, 2));
                    break;
            }
            // 3) أضف أول توصية كنص "الحل"
            $recs = is_array($stage['recommendations'] ?? null) ? $stage['recommendations'] : [];
            if (!empty($recs) && is_string($recs[0]) && trim($recs[0]) !== '') {
                $parts[] = 'الحل المقترح: ' . trim($recs[0]);
            }
            return $parts ? implode(' • ', $parts) : '';
        };

        foreach ($stageMap as $src => $dst) {
            $stage = $fa[$src] ?? [];
            $stages[$dst] = [
                'score'        => (int) safeGetNumber($stage, 'score', 0),
                'analysis'     => $buildStageAnalysis($src, $stage),
                'fix_steps'    => safeGetArray($stage, 'recommendations'),
                'gaps'         => safeGetArray($stage, 'gaps'),
                'channels'     => safeGetArray($stage, 'current_channels'),
                'objections'   => safeGetArray($stage, 'objections'),
                'cta_quality'  => (int) safeGetNumber($stage, 'cta_quality', 0),
            ];
        }
        // bottleneck
        $leak = $page8['biggest_funnel_leak'] ?? [];
        $bottleneckRaw = safeGetString($leak, 'stage', '');
        $bottleneckStage = $stageMap[$bottleneckRaw] ?? $bottleneckRaw;
        $aiReport['customer_journey'] = [
            'journey_score'    => (int) safeGetNumber($page8, 'journey_score', 0),
            'stages'           => $stages,
            'bottleneck_stage' => $bottleneckStage,
            'bottleneck_fix'   => safeGetString($leak, 'fix', ''),
            'fix_steps'        => safeGetArray($stages[$bottleneckStage] ?? [], 'fix_steps'),
        ];
    } else {
        // fallback أصلي (كان موجوداً قبل الإصلاح) — لو page_8_journey مفقود تماماً
        $aiReport['customer_journey'] = safeGet($aiReport, 'page_8_journey', [
            'journey_score'      => 0,
            'funnel_analysis'    => [
                'awareness' => ['score'=>0,'current_channels'=>[],'gaps'=>[],'recommendations'=>[],'monthly_reach'=>0],
                'interest'  => ['score'=>0,'what_hooks_them'=>[],'what_loses_them'=>[],'recommendations'=>[]],
                'decision'  => ['score'=>0,'trust_signals_present'=>[],'trust_signals_missing'=>[],'objections'=>[],'objection_handles'=>[]],
                'action'    => ['score'=>0,'conversion_rate_estimate'=>'—','friction_points'=>[],'cta_quality'=>0,'recommendations'=>[]],
                'loyalty'   => ['score'=>0,'retention_tactics_used'=>[],'missing_tactics'=>[],'recommendations'=>[]],
            ],
            'biggest_funnel_leak' => ['stage'=>'—','problem'=>'—','monthly_lost_revenue'=>'—','fix'=>'—'],
        ]);
    }
}

if (empty($aiReport['competitor_analysis'])) {
    $competitors = safeGetArray($aiReport, 'page_10_competitors.competitors');
    if (!empty($competitors)) {
        $aiReport['competitor_analysis'] = array_map(function($c) {
            return [
                'name'        => safeGetString($c, 'name', '—'),
                'strategy'    => safeGetString($c, 'content_strategy', '—'),
                'how_to_beat' => !empty($c['steal_this']) ? $c['steal_this'] : safeGetString($c, 'their_weakness', '—'),
            ];
        }, $competitors);
    }
}

// ── طبقة تطبيع + جسر page_10_competitors → competitor_radar / execution_arsenal / market_summary ──
//
// السياق المعماري (نفس نمط page_12_ads → ads_analysis):
//   - Single-prompt fallback (api/ai-analyze.php) ينتج: competitor_radar مباشرة.
//   - Multi-Agent (Agent 3 Market Intel — gemini-agents.php:429) ينتج: page_10_competitors
//     بـ schema جديد مختلف.
//   - الواجهة (js/report-connect.js → competitors.html block:2269-2354) تقرأ فقط:
//       data.competitor_radar (للبطاقات)
//       data.execution_arsenal (لترسانة التفوق)
//       data.market_summary (لملخص الفجوة السوقية)
//
// النتيجة قبل الإصلاح: عند Multi-Agent path، صفحة competitors.html تعرض:
//   - رسالة "لم يتمكن محرك Apify من استخراج بيانات منافسين كافية..."
//   - ترسانة فارغة تماماً (لا يوجد else للـ arsenalGrid)
//   - نص hardcoded للـ market_summary ("استراتيجية المحيط الأزرق...")
// بينما الوكيل أنتج تحليلاً كاملاً في page_10_competitors.
//
// schema المرجع (gemini-agents.php:429-449):
//   page_10_competitors.competitors[]:
//     name, followers, engagement_rate, content_strategy,
//     what_they_do_better[], their_weakness, their_winning_hook,
//     threat_level (high|medium|low), steal_this
//   page_10_competitors.market_gaps[]:
//     gap, size (high|medium|low), how_to_exploit, content_angle, time_to_capture
//   page_10_competitors.blue_ocean_opportunity (string)
//   page_10_competitors.battle_plan: { short_term, medium_term, positioning_statement }
//
// التحويل (لا اختراع — كل حقل من حقل موجود):
//   competitors[].name              → competitor_radar[].name
//   competitors[].what_they_do_better[] → competitor_radar[].strengths[]
//   competitors[].their_weakness    → competitor_radar[].weaknesses[0]
//   competitors[].their_winning_hook → competitor_radar[].weaknesses[1] (هوكهم الفائز نتعلم منه)
//   competitors[].steal_this        → competitor_radar[].attack_plan
//   market_gaps[]                   → execution_arsenal[]
//   blue_ocean_opportunity + battle_plan.positioning_statement → market_summary
//
// ما لا نلمسه:
//   - لو الحقول موجودة مسبقاً (Single-prompt path) → لا نطغى عليها.
//   - لو page_10_competitors فارغ تماماً → لا نُنشئ شيئاً (الواجهة تعرض missing-data).
//   - لا نضع weaknesses عامة كـ "خدمة عملاء بطيئة" أو "محتوى غير متجدد" (هي
//     hardcoded fallbacks في JS سنُنظفها أيضاً).
$page10 = safeGet($aiReport, 'page_10_competitors', null);
if (is_array($page10)) {
    // ── 1) Normalization: تطبيع أنواع البيانات في schema الوكيل ──
    $rawCompetitors = isset($page10['competitors']) && is_array($page10['competitors'])
        ? $page10['competitors']
        : [];
    // تنظيف العناصر الفارغة (schema الافتراضي يحوي عنصراً فارغاً واحداً)
    $rawCompetitors = array_values(array_filter($rawCompetitors, static function($c) {
        if (!is_array($c)) return false;
        return (!empty($c['name']) && is_string($c['name']) && trim($c['name']) !== '')
            || !empty($c['what_they_do_better'])
            || !empty($c['steal_this'])
            || (int) ($c['followers'] ?? 0) > 0;
    }));
    // تطبيع كل منافس
    $rawCompetitors = array_map(static function($c) {
        if (!is_array($c)) return $c;
        // followers, engagement_rate → numeric
        if (isset($c['followers']) && is_numeric($c['followers'])) {
            $c['followers'] = (int) $c['followers'];
        }
        if (isset($c['engagement_rate']) && is_numeric($c['engagement_rate'])) {
            $c['engagement_rate'] = (float) $c['engagement_rate'];
        }
        // what_they_do_better → array من نصوص فقط
        $wtdb = isset($c['what_they_do_better']) && is_array($c['what_they_do_better'])
            ? $c['what_they_do_better']
            : [];
        $c['what_they_do_better'] = array_values(array_filter(array_map(static function($t) {
            if (!is_string($t)) return null;
            $t = trim($t);
            return $t !== '' ? $t : null;
        }, $wtdb)));
        return $c;
    }, $rawCompetitors);
    $page10['competitors'] = $rawCompetitors;

    // market_gaps: تنظيف العناصر الفارغة
    $rawGaps = isset($page10['market_gaps']) && is_array($page10['market_gaps'])
        ? $page10['market_gaps']
        : [];
    $rawGaps = array_values(array_filter($rawGaps, static function($g) {
        if (!is_array($g)) return false;
        return (!empty($g['gap']) && is_string($g['gap']) && trim($g['gap']) !== '')
            || (!empty($g['how_to_exploit']) && is_string($g['how_to_exploit']) && trim($g['how_to_exploit']) !== '');
    }));
    $page10['market_gaps'] = $rawGaps;

    $aiReport['page_10_competitors'] = $page10;

    // ── 2) جسر إلى competitor_radar ──
    if (empty($aiReport['competitor_radar']) && !empty($rawCompetitors)) {
        $aiReport['competitor_radar'] = array_map(static function($c) {
            // weaknesses: نبدأ بـ their_weakness، ثم نضيف their_winning_hook كـ
            // "هوكهم الفائز" — هذا ليس "ضعفاً" حرفياً لكن الواجهة تعرضه تحت
            // عنوان "نقاط الضعف (Vulnerabilities)" والمنطق التسويقي: عندما تعرف
            // هوك المنافس الفائز، تعرف الزاوية التي عليك التفوق فيها.
            $weakness1 = is_string($c['their_weakness'] ?? null) && trim($c['their_weakness']) !== ''
                ? trim($c['their_weakness'])
                : '';
            $weakness2 = is_string($c['their_winning_hook'] ?? null) && trim($c['their_winning_hook']) !== ''
                ? 'هوكهم الفائز: ' . trim($c['their_winning_hook'])
                : '';
            $weaknesses = array_values(array_filter([$weakness1, $weakness2]));

            $strengths = is_array($c['what_they_do_better'] ?? null)
                ? $c['what_they_do_better']
                : [];

            // attack_plan: نولّد نصاً موحّداً من steal_this + their_weakness
            $stealThis = is_string($c['steal_this'] ?? null) ? trim($c['steal_this']) : '';
            $attackPlan = $stealThis !== ''
                ? $stealThis
                : ($weakness1 !== '' ? "استغل: {$weakness1}" : '');

            return [
                'name'         => is_string($c['name'] ?? null) ? trim($c['name']) : '',
                'url'          => '', // الـ schema لا يُعطي url للمنافس
                'strengths'    => $strengths,
                'weaknesses'   => $weaknesses,
                'attack_plan'  => $attackPlan,
                'followers'    => (int) ($c['followers'] ?? 0),
                'engagement_rate' => (float) ($c['engagement_rate'] ?? 0),
                'threat_level' => is_string($c['threat_level'] ?? null) ? $c['threat_level'] : '',
                '_source'      => 'page_10_competitors_bridge',
            ];
        }, $rawCompetitors);
    }

    // ── 3) جسر إلى execution_arsenal ──
    // market_gaps[].gap → arsenal title، how_to_exploit + time_to_capture → desc،
    // size (high/medium/low) → emoji.
    // ملاحظة: schema يسمي الحقل "size" (حجم الفجوة)، نُترجمها لأيقونة بديهية.
    if (empty($aiReport['execution_arsenal']) && !empty($rawGaps)) {
        $sizeIcon = static function(string $size): string {
            $s = strtolower(trim($size));
            if (in_array($s, ['high', 'large', 'كبير', 'كبيرة'], true))   return '🚀';
            if (in_array($s, ['medium', 'متوسط', 'متوسطة'], true))         return '⚡';
            if (in_array($s, ['low', 'small', 'صغير', 'صغيرة'], true))     return '💡';
            return '🎯';
        };
        $aiReport['execution_arsenal'] = array_map(static function($g) use ($sizeIcon) {
            $gap         = is_string($g['gap'] ?? null) ? trim($g['gap']) : '';
            $howToExp    = is_string($g['how_to_exploit'] ?? null) ? trim($g['how_to_exploit']) : '';
            $timeCapture = is_string($g['time_to_capture'] ?? null) ? trim($g['time_to_capture']) : '';
            $size        = is_string($g['size'] ?? null) ? trim($g['size']) : '';

            // desc: ادمج how_to_exploit + time_to_capture (لو متاحين)
            $descParts = [];
            if ($howToExp !== '')    $descParts[] = $howToExp;
            if ($timeCapture !== '') $descParts[] = "⏱️ {$timeCapture}";
            $desc = $descParts ? implode(' • ', $descParts) : '';

            return [
                'icon'  => $sizeIcon($size),
                'title' => $gap !== '' ? $gap : 'فرصة سوقية',
                'desc'  => $desc !== '' ? $desc : 'فرصة رصدها الوكيل في تحليل المنافسين.',
            ];
        }, $rawGaps);
    }

    // ── 4) جسر إلى market_summary ──
    // blue_ocean_opportunity = الفجوة الكبرى، positioning_statement = البيان
    // الاستراتيجي. ندمجهما لتشكيل ملخص واحد.
    if (empty($aiReport['market_summary'])) {
        $blueOcean = is_string($page10['blue_ocean_opportunity'] ?? null)
            ? trim($page10['blue_ocean_opportunity'])
            : '';
        $positioning = is_string(safeGet($page10, 'battle_plan.positioning_statement', null))
            ? trim(safeGet($page10, 'battle_plan.positioning_statement', ''))
            : '';
        $shortTerm = is_string(safeGet($page10, 'battle_plan.short_term', null))
            ? trim(safeGet($page10, 'battle_plan.short_term', ''))
            : '';

        $summaryParts = [];
        if ($blueOcean !== '' && $blueOcean !== '—')       $summaryParts[] = $blueOcean;
        if ($positioning !== '' && $positioning !== '—')   $summaryParts[] = "<strong>التموضع المقترح:</strong> {$positioning}";
        if ($shortTerm !== '' && $shortTerm !== '—' && empty($positioning)) {
            // لو ما عندنا positioning، نضع short_term كبديل تكتيكي
            $summaryParts[] = "<strong>أول خطوة:</strong> {$shortTerm}";
        }

        if (!empty($summaryParts)) {
            $aiReport['market_summary'] = implode(' • ', $summaryParts);
        }
    }
}

if (empty($aiReport['action_month'])) {
    $r = safeGet($aiReport, 'page_18_roadmap', null);
    if ($r) {
        $buildWeek = function(string $wk) use ($r) {
            return [
                'title' => safeGetString($r, "{$wk}.theme", '—'),
                'goals' => safeGetArray($r, "{$wk}.week_kpis"),
                'tasks' => array_map(
                    fn($d) => safeGetString($d, 'date_offset', '') . ': ' . safeGetString($d, 'morning_task.task', '') . ' | ' . safeGetString($d, 'afternoon_task.task', ''),
                    safeGetArray($r, "{$wk}.daily_tasks")
                ),
            ];
        };
        $aiReport['action_month'] = [
            'week1' => $buildWeek('week1'),
            'week2' => $buildWeek('week2'),
            'week3' => $buildWeek('week3'),
            'week4' => $buildWeek('week4'),
        ];
    }
}

// ── طبقة تطبيع page_9_conversion ─────────────────────────────────
// نقوم فقط بتطبيع نوع البيانات ووحدات القياس — بدون افتراض أي معادلة
// قد لا توجد في prompt الوكيل (مثل gap = unlock - estimated).
//
// ما نعالجه (آمن، متوافق مع schema الوكيل في gemini-agents.php:403):
//   1. revenue_analysis.gap: لو وصلت كـ "0" (string رقمي) → نحوّلها لـ float
//      لمنع Falsy-Zero Bug في JS (gap=0 قد يُعرض كـ "-" مع || ).
//   2. conversion_killers[].expected_conversion_lift: لو رقم عاري (10) →
//      نضيف % لأن schema يسميه "lift" (نسبة مئوية صريحة).
//   3. sales_funnel_recommendations[].expected_close_rate: لو رقم عاري →
//      نضيف % لأن schema يسميه "close_rate" (معدل إغلاق = نسبة).
//
// ما لا نلمسه:
//   - estimated_monthly_revenue, revenue_per_follower, industry_benchmark,
//     unlock_potential, gap (كقيمة): الـ prompt لا يُعرّف معادلات صريحة
//     لـ gap و unlock، فنحترم ما أرجعه الوكيل ولا نخترع منطقاً.
$page9 = safeGet($aiReport, 'page_9_conversion', null);
if (is_array($page9)) {
    // 1) revenue_analysis.gap: تطبيع نوع فقط (string رقمي → float)
    if (!empty($page9['revenue_analysis']) && is_array($page9['revenue_analysis'])) {
        $ra = $page9['revenue_analysis'];
        if (isset($ra['gap']) && is_string($ra['gap']) && is_numeric($ra['gap'])) {
            $ra['gap'] = (float) $ra['gap'];
        }
        $page9['revenue_analysis'] = $ra;
    }

    // 2) conversion_killers[].expected_conversion_lift: إضافة % لرقم عاري
    if (!empty($page9['conversion_killers']) && is_array($page9['conversion_killers'])) {
        foreach ($page9['conversion_killers'] as &$killer) {
            if (!is_array($killer)) continue;
            if (isset($killer['expected_conversion_lift']) && is_numeric($killer['expected_conversion_lift'])) {
                $killer['expected_conversion_lift'] = $killer['expected_conversion_lift'] . '%';
            }
        }
        unset($killer);
    }

    // 3) sales_funnel_recommendations[].expected_close_rate: نفس المعالجة
    if (!empty($page9['sales_funnel_recommendations']) && is_array($page9['sales_funnel_recommendations'])) {
        foreach ($page9['sales_funnel_recommendations'] as &$step) {
            if (!is_array($step)) continue;
            if (isset($step['expected_close_rate']) && is_numeric($step['expected_close_rate'])) {
                $step['expected_close_rate'] = $step['expected_close_rate'] . '%';
            }
        }
        unset($step);
    }

    $aiReport['page_9_conversion'] = $page9;
}

// ── طبقة تطبيع page_11_consistency ───────────────────────────────
// schema الوكيل (gemini-agents.php:450-475):
//   consistency_score: int 0-100
//   posting_analysis: { current_frequency, recommended_frequency, best_times[],
//                       gap_days, verdict }
//   growth_trajectory: { current_monthly_growth_pct: number, industry_avg_growth,
//                        projection_if_consistent: { month1_followers, month3_followers,
//                                                    month6_followers } (كلها أرقام) }
//   algorithm_health: { instagram_score, tiktok_score, facebook_score (كلها 0-100),
//                       algorithm_tips: [strings] }
//   consistency_system: { content_batching_strategy, tools_recommended: [strings],
//                         weekly_routine: [{day, task}] }
//
// ما نعالجه (آمن، يحترم schema بحرفيّته):
//   1. الـ scores: لو وصلت كـ "45" (string رقمي) → int. منع Falsy-Zero في JS
//      ويضمن أن العرض موحّد (لا "45"/"45.0").
//   2. projection_if_consistent.* (3 أرقام): لو string رقمي → int. يدعم
//      "+ 930" RTL formatting في الواجهة (formatPlusNumber).
//   3. current_monthly_growth_pct: لو string رقمي → float (للاتساق).
//   4. algorithm_tips, tools_recommended: ضمان array + إزالة العناصر الفارغة
//      أو غير النصية (تمنع <li></li> فارغة في الواجهة).
//   5. weekly_routine: ضمان array + إزالة العناصر التي ليس لها day ولا task
//      (تمنع timeline-item فارغة لو الوكيل أرجع schema الافتراضي
//       [{"day":"","task":""}] دون تعبئة).
//
// ما لا نلمسه:
//   - posting_analysis.* النصية (current_frequency, gap_days, verdict): قد
//     تكون "1 منشور/أسبوع" أو "يومي الجمعة والسبت". لا اختراع منطق هنا.
//   - منطق "هل projection معقول؟" (مثلاً month6 > month3): الـ prompt
//     يضع قاعدة "≤10% نمو شهرياً بدون إعلانات"، لكن تحققها مسؤولية الوكيل
//     لا طبقة العرض. أي تصحيح هنا يخفي مشكلة جودة الوكيل.
//   - أسماء الأدوات (Hootsuite/Buffer): محتوى الوكيل يُعرض كما هو.
$page11 = safeGet($aiReport, 'page_11_consistency', null);
if (is_array($page11)) {
    // 1) consistency_score → int
    if (isset($page11['consistency_score']) && is_numeric($page11['consistency_score'])) {
        $page11['consistency_score'] = (int) $page11['consistency_score'];
    }

    // 2) algorithm_health: scores → int، tips → array نظيفة
    if (!empty($page11['algorithm_health']) && is_array($page11['algorithm_health'])) {
        $ah = $page11['algorithm_health'];
        foreach (['instagram_score', 'tiktok_score', 'facebook_score'] as $scoreKey) {
            if (isset($ah[$scoreKey]) && is_numeric($ah[$scoreKey])) {
                $ah[$scoreKey] = (int) $ah[$scoreKey];
            }
        }
        // algorithm_tips: ضمان array من نصوص فقط
        $tips = isset($ah['algorithm_tips']) && is_array($ah['algorithm_tips'])
            ? $ah['algorithm_tips']
            : [];
        $ah['algorithm_tips'] = array_values(array_filter(array_map(static function($t) {
            if (!is_string($t)) return null;
            $t = trim($t);
            return $t !== '' ? $t : null;
        }, $tips)));
        $page11['algorithm_health'] = $ah;
    }

    // 3) growth_trajectory: المئوية → float، projections → int
    if (!empty($page11['growth_trajectory']) && is_array($page11['growth_trajectory'])) {
        $gt = $page11['growth_trajectory'];
        if (isset($gt['current_monthly_growth_pct']) && is_numeric($gt['current_monthly_growth_pct'])) {
            $gt['current_monthly_growth_pct'] = (float) $gt['current_monthly_growth_pct'];
        }
        if (!empty($gt['projection_if_consistent']) && is_array($gt['projection_if_consistent'])) {
            $proj = $gt['projection_if_consistent'];
            foreach (['month1_followers', 'month3_followers', 'month6_followers'] as $monthKey) {
                if (isset($proj[$monthKey]) && is_numeric($proj[$monthKey])) {
                    $proj[$monthKey] = (int) $proj[$monthKey];
                }
            }
            $gt['projection_if_consistent'] = $proj;
        }
        $page11['growth_trajectory'] = $gt;
    }

    // 4 + 5) consistency_system: tools نظيف، weekly_routine بدون عناصر فارغة
    if (!empty($page11['consistency_system']) && is_array($page11['consistency_system'])) {
        $cs = $page11['consistency_system'];

        // tools_recommended: array من نصوص غير فارغة فقط
        $tools = isset($cs['tools_recommended']) && is_array($cs['tools_recommended'])
            ? $cs['tools_recommended']
            : [];
        $cs['tools_recommended'] = array_values(array_filter(array_map(static function($t) {
            if (!is_string($t)) return null;
            $t = trim($t);
            return $t !== '' ? $t : null;
        }, $tools)));

        // weekly_routine: تصفية العناصر التي ليس لها day ولا task
        $routine = isset($cs['weekly_routine']) && is_array($cs['weekly_routine'])
            ? $cs['weekly_routine']
            : [];
        $cs['weekly_routine'] = array_values(array_filter(array_map(static function($r) {
            if (!is_array($r)) return null;
            $day  = isset($r['day'])  && is_string($r['day'])  ? trim($r['day'])  : '';
            $task = isset($r['task']) && is_string($r['task']) ? trim($r['task']) : '';
            if ($day === '' && $task === '') return null;
            return ['day' => $day, 'task' => $task];
        }, $routine)));

        $page11['consistency_system'] = $cs;
    }

    $aiReport['page_11_consistency'] = $page11;
}

// ── طبقة تطبيع + جسر page_12_ads → ads_analysis ─────────────────
//
// السياق المعماري:
//   - Single-prompt fallback (api/ai-analyze.php) ينتج حقل `ads_analysis`
//     مباشرة بـ schema تستهلكه الواجهة.
//   - Multi-Agent path (Agent 4 الاستراتيجي في api/gemini-agents.php:544)
//     ينتج `page_12_ads` بـ schema جديد مختلف تماماً.
//   - الواجهة (js/report-connect.js → renderAdsSection) تقرأ فقط
//     `data.ads_analysis`، ولا تعرف عن `page_12_ads`.
//
// النتيجة قبل الإصلاح: عند Multi-Agent path، صفحة ads.html تعرض
// "بيانات الإعلانات غير متوفرة" حتى لو الوكيل أنتج تحليلاً كاملاً.
//
// الحل: جسر يحوّل `page_12_ads` (schema الوكيل) إلى `ads_analysis`
// (schema الواجهة) — تماماً كما يفعل الجسر المماثل لـ page_8_journey →
// customer_journey أعلاه. لا اختراع منطق: كل حقل يأتي من حقل موجود
// في schema الوكيل.
//
// schema المرجع (gemini-agents.php:544-578):
//   page_12_ads.ads_score                                     → ads_analysis.score
//   page_12_ads.current_ads_audit.ad_quality_verdict          → ads_analysis.status
//   (مشتق من active_ads_count + inactive_ads_count + waste)   → ads_analysis.desc
//   page_12_ads.current_ads_audit.* (counts + waste)          → ads_analysis.metrics[]
//   page_12_ads.current_ads_audit.what_works[]                → creative_pointers (green)
//   page_12_ads.current_ads_audit.what_fails[]                → creative_pointers (red)
//   page_12_ads.recommended_campaigns[].{name,hook,objective} → strategy.steps[]
//
// ما لا نلمسه:
//   - لو ads_analysis موجود مسبقاً (Single-prompt path) → لا نطغى عليه.
//   - لو ads_score == 0 و active+inactive == 0 و لا verdict ولا
//     recommended_campaigns → لا نُنشئ ads_analysis (نترك JS يعرض
//     missing-data state بشكل طبيعي).
//   - أرقام recommended_campaigns (CPM, CTR, ROAS): لا تُحقن في metrics
//     الواجهة لأنها metrics تنبؤية للحملات المقترحة، لا أداء حالي.
$page12 = safeGet($aiReport, 'page_12_ads', null);
if (is_array($page12)) {
    // ── 1) Normalization: تطبيع أنواع البيانات ──
    if (isset($page12['ads_score']) && is_numeric($page12['ads_score'])) {
        $page12['ads_score'] = (int) $page12['ads_score'];
    }
    if (!empty($page12['current_ads_audit']) && is_array($page12['current_ads_audit'])) {
        $caa = $page12['current_ads_audit'];
        foreach (['active_ads_count', 'inactive_ads_count'] as $k) {
            if (isset($caa[$k]) && is_numeric($caa[$k])) {
                $caa[$k] = (int) $caa[$k];
            }
        }
        // what_works / what_fails: arrays نظيفة من النصوص فقط
        foreach (['what_works', 'what_fails'] as $listKey) {
            $list = isset($caa[$listKey]) && is_array($caa[$listKey]) ? $caa[$listKey] : [];
            $caa[$listKey] = array_values(array_filter(array_map(static function($t) {
                if (!is_string($t)) return null;
                $t = trim($t);
                return $t !== '' ? $t : null;
            }, $list)));
        }
        $page12['current_ads_audit'] = $caa;
    }
    // recommended_campaigns: تنظيف العناصر الفارغة الافتراضية من schema
    if (!empty($page12['recommended_campaigns']) && is_array($page12['recommended_campaigns'])) {
        $page12['recommended_campaigns'] = array_values(array_filter(
            $page12['recommended_campaigns'],
            static function($c) {
                if (!is_array($c)) return false;
                $hasContent =
                    (!empty($c['campaign_name']) && is_string($c['campaign_name']) && trim($c['campaign_name']) !== '') ||
                    (!empty($c['hook_for_ad']) && is_string($c['hook_for_ad']) && trim($c['hook_for_ad']) !== '') ||
                    (!empty($c['ad_copy']) && is_string($c['ad_copy']) && trim($c['ad_copy']) !== '');
                return $hasContent;
            }
        ));
    }

    $aiReport['page_12_ads'] = $page12;

    // ── 2) جسر إلى ads_analysis (schema الواجهة) ──
    $hasExistingAdsAnalysis = !empty($aiReport['ads_analysis']) && is_array($aiReport['ads_analysis']);
    $caa     = is_array($page12['current_ads_audit'] ?? null) ? $page12['current_ads_audit'] : [];
    $active  = (int) ($caa['active_ads_count'] ?? 0);
    $inactive= (int) ($caa['inactive_ads_count'] ?? 0);
    $score12 = (int) ($page12['ads_score'] ?? 0);
    $hasMeaningfulData = ($score12 > 0) || ($active + $inactive > 0)
                       || !empty($caa['ad_quality_verdict'])
                       || !empty($caa['what_works']) || !empty($caa['what_fails'])
                       || !empty($page12['recommended_campaigns']);

    if (!$hasExistingAdsAnalysis && $hasMeaningfulData) {
        $verdict   = is_string($caa['ad_quality_verdict'] ?? null) ? trim($caa['ad_quality_verdict']) : '';
        $waste     = is_string($caa['wasted_budget_estimate'] ?? null) ? trim($caa['wasted_budget_estimate']) : '';
        $totalAds  = $active + $inactive;

        // status: لو الوكيل أعطى verdict نصياً واضحاً نستخدمه، وإلا نشتقّ من score
        $status = $verdict !== ''
            ? $verdict
            : ($score12 >= 70 ? '✅ أداء جيد'
                : ($score12 >= 40 ? '⚠️ يحتاج تحسين'
                    : ($score12 > 0 ? '❌ يحتاج تدخل عاجل' : '— لا توجد بيانات إعلانية كافية')));

        // desc: نص وصفي مبني من حقول schema الوكيل (لا اختراع)
        $descParts = [];
        if ($totalAds > 0) {
            $descParts[] = "تم رصد {$totalAds} إعلان (نشط: {$active}، متوقف: {$inactive})";
        } elseif ($score12 > 0 && $verdict !== '') {
            $descParts[] = $verdict;
        }
        if ($waste !== '') {
            $descParts[] = "هدر تقديري للميزانية: {$waste}";
        }
        $desc = $descParts ? implode(' • ', $descParts) : 'تحليل من Multi-Agent استراتيجي.';

        // metrics[]: 3 خانات من حقول current_ads_audit
        $metrics = [];
        $metrics[] = [
            'title'        => 'إجمالي الإعلانات',
            'val'          => (string) $totalAds,
            'status'       => $totalAds > 0 ? '▲ تم الرصد' : '▼ لا يوجد',
            'status_class' => $totalAds > 0 ? 'status-green' : 'status-red',
            'val_class'    => $totalAds > 0 ? 'val-green'   : 'val-red',
            'desc'         => "نشط: {$active} | متوقف: {$inactive}",
        ];
        $metrics[] = [
            'title'        => 'تقييم الجودة',
            'val'          => $verdict !== '' ? mb_substr($verdict, 0, 30) : '—',
            'status'       => '▶ Multi-Agent',
            'status_class' => 'status-yellow',
            'val_class'    => 'val-yellow',
            'desc'         => $verdict !== '' ? $verdict : 'لم يصدر الوكيل حكماً نصياً.',
        ];
        $metrics[] = [
            'title'        => 'هدر الميزانية',
            'val'          => $waste !== '' ? $waste : '—',
            'status'       => $waste !== '' ? '▼ هدر مرصود' : '▶ غير محدد',
            'status_class' => $waste !== '' ? 'status-red' : 'status-yellow',
            'val_class'    => $waste !== '' ? 'val-red'    : 'val-yellow',
            'desc'         => $waste !== '' ? "تقدير الوكيل: {$waste}" : 'لم يقدّر الوكيل هدراً صريحاً.',
        ];

        // creative_pointers: what_works (أخضر) + what_fails (أحمر)
        $pointers = [];
        $works = is_array($caa['what_works'] ?? null) ? $caa['what_works'] : [];
        $fails = is_array($caa['what_fails'] ?? null) ? $caa['what_fails'] : [];
        foreach (array_slice($fails, 0, 3) as $f) {
            $pointers[] = [
                'type'  => 'red',
                'icon'  => '❌',
                'title' => 'نقطة ضعف رصدها الوكيل',
                'desc'  => $f,
            ];
        }
        foreach (array_slice($works, 0, 2) as $w) {
            $pointers[] = [
                'type'  => 'green',
                'icon'  => '✅',
                'title' => 'نقطة قوة رصدها الوكيل',
                'desc'  => $w,
            ];
        }
        if (empty($pointers)) {
            $pointers[] = [
                'type'  => 'yellow',
                'icon'  => '⚠️',
                'title' => 'تحليل تفصيلي غير متوفر',
                'desc'  => 'الوكيل لم يُرجع نقاط قوة/ضعف مفصّلة لهذه الحملة.',
            ];
        }

        // strategy.steps: من recommended_campaigns[].{campaign_name, hook_for_ad, objective}
        $steps = [];
        $recommended = is_array($page12['recommended_campaigns'] ?? null)
            ? $page12['recommended_campaigns']
            : [];
        foreach (array_slice($recommended, 0, 5) as $camp) {
            if (!is_array($camp)) continue;
            $name = is_string($camp['campaign_name'] ?? null) ? trim($camp['campaign_name']) : '';
            $hook = is_string($camp['hook_for_ad'] ?? null)   ? trim($camp['hook_for_ad'])   : '';
            $obj  = is_string($camp['objective'] ?? null)     ? trim($camp['objective'])     : '';
            $line = '';
            if ($name !== '' && $hook !== '') {
                $line = "{$name}: {$hook}";
            } elseif ($name !== '' && $obj !== '') {
                $line = "{$name} ({$obj})";
            } elseif ($name !== '') {
                $line = $name;
            } elseif ($hook !== '') {
                $line = $hook;
            }
            if ($line !== '') $steps[] = $line;
        }
        if (empty($steps)) {
            $steps = ['راجع التقرير الكامل أدناه للحصول على التوصيات التفصيلية.'];
        }

        $aiReport['ads_analysis'] = [
            'score'              => $score12,
            'status'             => $status,
            'desc'               => $desc,
            'metrics'            => $metrics,
            'creative_pointers'  => $pointers,
            'strategy'           => [
                'desc'  => 'التعديلات العاجلة المقترحة من Multi-Agent:',
                'steps' => array_slice($steps, 0, 5),
            ],
            '_source' => 'page_12_ads_bridge', // علامة تشخيصية
        ];
    }
}

$row['ai_report'] = $aiReport;
// schema الوكيل (gemini-agents.php:450-475):
//   consistency_score: int 0-100
//   posting_analysis: { current_frequency, recommended_frequency, best_times[],
//                       gap_days, verdict }
//   growth_trajectory: { current_monthly_growth_pct: number, industry_avg_growth,
//                        projection_if_consistent: { month1_followers, month3_followers,
//                                                    month6_followers } (كلها أرقام) }
//   algorithm_health: { instagram_score, tiktok_score, facebook_score (كلها 0-100),
//                       algorithm_tips: [strings] }
//   consistency_system: { content_batching_strategy, tools_recommended: [strings],
//                         weekly_routine: [{day, task}] }
//
// ما نعالجه (آمن، يحترم schema بحرفيّته):
//   1. الـ scores: لو وصلت كـ "45" (string رقمي) → int. منع Falsy-Zero في JS
//      ويضمن أن العرض موحّد (لا "45"/"45.0").
//   2. projection_if_consistent.* (3 أرقام): لو string رقمي → int. يدعم
//      "+ 930" RTL formatting في الواجهة (formatPlusNumber).
//   3. current_monthly_growth_pct: لو string رقمي → float (للاتساق).
//   4. algorithm_tips, tools_recommended: ضمان array + إزالة العناصر الفارغة
//      أو غير النصية (تمنع <li></li> فارغة في الواجهة).
//   5. weekly_routine: ضمان array + إزالة العناصر التي ليس لها day ولا task
//      (تمنع timeline-item فارغة لو الوكيل أرجع schema الافتراضي
//       [{"day":"","task":""}] دون تعبئة).
//
// ما لا نلمسه:
//   - posting_analysis.* النصية (current_frequency, gap_days, verdict): قد
//     تكون "1 منشور/أسبوع" أو "يومي الجمعة والسبت". لا اختراع منطق هنا.
//   - منطق "هل projection معقول؟" (مثلاً month6 > month3): الـ prompt
//     يضع قاعدة "≤10% نمو شهرياً بدون إعلانات"، لكن تحققها مسؤولية الوكيل
//     لا طبقة العرض. أي تصحيح هنا يخفي مشكلة جودة الوكيل.
//   - أسماء الأدوات (Hootsuite/Buffer): محتوى الوكيل يُعرض كما هو.
$page11 = safeGet($aiReport, 'page_11_consistency', null);
if (is_array($page11)) {
    // 1) consistency_score → int
    if (isset($page11['consistency_score']) && is_numeric($page11['consistency_score'])) {
        $page11['consistency_score'] = (int) $page11['consistency_score'];
    }

    // 2) algorithm_health: scores → int، tips → array نظيفة
    if (!empty($page11['algorithm_health']) && is_array($page11['algorithm_health'])) {
        $ah = $page11['algorithm_health'];
        foreach (['instagram_score', 'tiktok_score', 'facebook_score'] as $scoreKey) {
            if (isset($ah[$scoreKey]) && is_numeric($ah[$scoreKey])) {
                $ah[$scoreKey] = (int) $ah[$scoreKey];
            }
        }
        // algorithm_tips: ضمان array من نصوص فقط
        $tips = isset($ah['algorithm_tips']) && is_array($ah['algorithm_tips'])
            ? $ah['algorithm_tips']
            : [];
        $ah['algorithm_tips'] = array_values(array_filter(array_map(static function($t) {
            if (!is_string($t)) return null;
            $t = trim($t);
            return $t !== '' ? $t : null;
        }, $tips)));
        $page11['algorithm_health'] = $ah;
    }

    // 3) growth_trajectory: المئوية → float، projections → int
    if (!empty($page11['growth_trajectory']) && is_array($page11['growth_trajectory'])) {
        $gt = $page11['growth_trajectory'];
        if (isset($gt['current_monthly_growth_pct']) && is_numeric($gt['current_monthly_growth_pct'])) {
            $gt['current_monthly_growth_pct'] = (float) $gt['current_monthly_growth_pct'];
        }
        if (!empty($gt['projection_if_consistent']) && is_array($gt['projection_if_consistent'])) {
            $proj = $gt['projection_if_consistent'];
            foreach (['month1_followers', 'month3_followers', 'month6_followers'] as $monthKey) {
                if (isset($proj[$monthKey]) && is_numeric($proj[$monthKey])) {
                    $proj[$monthKey] = (int) $proj[$monthKey];
                }
            }
            $gt['projection_if_consistent'] = $proj;
        }
        $page11['growth_trajectory'] = $gt;
    }

    // 4 + 5) consistency_system: tools نظيف، weekly_routine بدون عناصر فارغة
    if (!empty($page11['consistency_system']) && is_array($page11['consistency_system'])) {
        $cs = $page11['consistency_system'];

        // tools_recommended: array من نصوص غير فارغة فقط
        $tools = isset($cs['tools_recommended']) && is_array($cs['tools_recommended'])
            ? $cs['tools_recommended']
            : [];
        $cs['tools_recommended'] = array_values(array_filter(array_map(static function($t) {
            if (!is_string($t)) return null;
            $t = trim($t);
            return $t !== '' ? $t : null;
        }, $tools)));

        // weekly_routine: تصفية العناصر التي ليس لها day ولا task
        $routine = isset($cs['weekly_routine']) && is_array($cs['weekly_routine'])
            ? $cs['weekly_routine']
            : [];
        $cs['weekly_routine'] = array_values(array_filter(array_map(static function($r) {
            if (!is_array($r)) return null;
            $day  = isset($r['day'])  && is_string($r['day'])  ? trim($r['day'])  : '';
            $task = isset($r['task']) && is_string($r['task']) ? trim($r['task']) : '';
            if ($day === '' && $task === '') return null;
            return ['day' => $day, 'task' => $task];
        }, $routine)));

        $page11['consistency_system'] = $cs;
    }

    $aiReport['page_11_consistency'] = $page11;
}

$row['ai_report'] = $aiReport;

if (!$__hasRenderableValue(safeGet($aiReport, 'summary', null)) && $__hasRenderableValue(safeGet($aiReport, 'final_report', null))) {
    $row['ai_report']['summary'] = safeGet($aiReport, 'final_report');
    $aiReport['summary'] = safeGet($aiReport, 'final_report');
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

// ── نسخ حقول صفحات الوكلاء المتعددين للـ Root ──
foreach ($aiReport as $k => $v) {
    if (is_string($k) && strpos($k, 'page_') === 0) {
        $row[$k] = $v;
    }
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

// ── شبكة أمان: بناء content_analysis على الطاير للتقارير القديمة ─
// لو الـ ai_report محفوظ في DB من قبل تطبيق fix #2 ولا يحوي content_analysis،
// نبنيه الآن من scan_result بدون إعادة تشغيل أي AI call.
if (empty($row['ai_report']['content_analysis']) && !empty($row['scan_result'])) {
    $aiAnalyzePath = __DIR__ . '/ai-analyze.php';
    if (is_file($aiAnalyzePath)) {
        require_once $aiAnalyzePath;
    }
    if (function_exists('buildContentAnalysis')) {
        try {
            $caInput = is_array($row['scan_result']) ? $row['scan_result'] : [];
            // buildContentAnalysis تتوقع $data كاملاً (id, score, scan_result...)
            // فنمرر صورة موسعة منها مع الجذر.
            $caData = array_merge($row, ['scan_result' => $caInput]);
            $row['ai_report']['content_analysis'] = buildContentAnalysis($caData);
        } catch (\Throwable $e) {
            // فشل صامت — الواجهة ستعرض القيم الافتراضية بدلاً من الكسر
        }
    }
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

// ── نقل hook_bank للجذر (E2 Mapping) ──
if (!empty($aiReport['hook_bank'])) {
    $row['hook_bank'] = !empty($row['hook_bank']) 
        ? array_merge($row['hook_bank'], $aiReport['hook_bank']) 
        : $aiReport['hook_bank'];
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

// ── DEBUG: مؤشرات التشخيص — فقط في وضع التطوير ───────────
if (($cfg['app']['debug'] ?? false) === true) {
    $row['_debug'] = [
        'ai_report_source'        => !empty($row['ai_report']) ? 'DB:ai_report' : 'EMPTY',
        'strengths_count'         => count(safeGetArray($row['ai_report'] ?? [], 'strengths')),
        'weaknesses_count'        => count(safeGetArray($row['ai_report'] ?? [], 'weaknesses')),
        'recommendations_count'   => count(is_array($row['recommendations'] ?? null) ? $row['recommendations'] : []),
        'has_high_priority_rec'   => count(array_filter(
            is_array($row['recommendations'] ?? null) ? $row['recommendations'] : [],
            fn($r) => is_array($r) && ($r['priority'] ?? '') === 'high'
        )),
        'has_content_analysis'    => !empty(safeGet($row['ai_report'] ?? [], 'content_analysis')),
        'data_quality'            => safeGet($row['scan_result'] ?? [], 'data_quality', null),
        'ads_actor_used'          => safeGet($row['scan_result'] ?? [], 'ads_library.actor_used', null),
        'ads_raw_count'           => safeGetNumber($row['scan_result'] ?? [], 'ads_library.raw_count', 0),
        'ads_mapped_count'        => count(safeGetArray($row['scan_result'] ?? [], 'ads_library.ads')),
        'has_failures'            => safeGet($row['ai_report'] ?? [], 'meta.has_failures', false),
        'failed_agents'           => safeGetArray($row['ai_report'] ?? [], 'meta.failed_agents'),
    ];
}

jsonOut($row);

} catch (\Throwable $e) {
    // Server-side guard: never leak stack traces to clients. Log details and
    // return a stable JSON envelope so the frontend can show a clean error.
    if (function_exists('error_log')) {
        error_log('[result.php] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(
        ['error' => 'server_error', 'msg' => 'حدث خطأ داخلي'],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}
