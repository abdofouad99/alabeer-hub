# 🎯 Sprint 5: Deep Ads Analysis — تحليل عميق لإعلانات منافس بضغطة زر

## الهدف
إضافة زر "تحليل عميق لإعلانات هذا المنافس" تحت كل بطاقة منافس. عند الضغط:
1. يستدعي `scrapeAdsLibrary` الموجود (المُصلَح في PR #58)
2. يسحب نصوص وصور إعلانات المنافس
3. يحلّلها بـ AI (نمط الرسائل، CTA، الجمهور المستهدف)
4. يعرض النتيجة في modal منبثق

## المخرجات النهائية
- `api/competitor-deep-ads.php` — endpoint جديد
- `js/competitor-deep-ads.js` — منطق الواجهة + modal
- إضافة زر في بطاقة المنافس (تم في Sprint 4)
- CSS للـ modal
- حماية من الإفراط (rate limit + daily cap)

## مدة العمل المتوقعة
2-3 أيام

---

# 📁 الملفات الجديدة

## 1. `api/competitor-deep-ads.php`

### الوظيفة
endpoint مستقل يستقبل URL منافس + scan_id ويُرجع تحليل عميق لإعلاناته.

### الكود الكامل

```php
<?php
/**
 * api/competitor-deep-ads.php
 *
 * Endpoint لتحليل عميق لإعلانات منافس بضغطة زر
 * يستخدم scrapeAdsLibrary (المُصلَح في PR #58) + OpenAI للتحليل
 *
 * Request:
 *   POST /api/competitor-deep-ads.php
 *   {
 *     "scan_id": 123,
 *     "competitor_idx": 0,        // index في competitor_radar
 *     "competitor_url": "https://www.facebook.com/X"
 *   }
 *
 * Response:
 *   {
 *     "success": true,
 *     "competitor_name": "...",
 *     "ads_summary": {
 *       "total_ads": 12,
 *       "active_ads": 8,
 *       "running_since": "2024-01-15",
 *       "platforms": ["facebook", "instagram"]
 *     },
 *     "ai_analysis": {
 *       "messaging_pattern": "...",
 *       "primary_offers": [...],
 *       "target_audience_signals": "...",
 *       "cta_strategy": "...",
 *       "creative_style": "...",
 *       "weaknesses_in_ads": [...],
 *       "what_to_copy": [...],
 *       "what_to_avoid": [...]
 *     },
 *     "ads_sample": [...]
 *   }
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

set_time_limit(300);
ini_set('memory_limit', '256M');

$cfg = require __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/apify-scraper.php'; // scrapeAdsLibrary المُصلَح

// ── Rate limit مدمج ──
require_once __DIR__ . '/rate_limit.php';

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// ── قراءة الـ input ──
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$scanId = (int)($body['scan_id'] ?? 0);
$compIdx = (int)($body['competitor_idx'] ?? 0);
$compUrl = trim((string)($body['competitor_url'] ?? ''));
$force = !empty($body['force']); // تخطي cache

if (!$scanId || empty($compUrl)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'scan_id و competitor_url مطلوبان',
    ]);
    exit;
}

// ── Rate limit: 20 طلب/يوم لكل IP ──
$dailyCap = (int)($cfg['analysis']['competitor_deep_ads_max_per_day'] ?? 20);
$rateKey = "deep_ads_daily_{$ip}_" . date('Ymd');
$rateFile = sys_get_temp_dir() . '/' . md5($rateKey);
$rateCount = file_exists($rateFile) ? (int)file_get_contents($rateFile) : 0;
if ($rateCount >= $dailyCap) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error'   => "تجاوزت الحد اليومي ({$dailyCap} طلبات). حاول غداً.",
    ]);
    exit;
}

try {
    // ── 1. جلب بيانات الفحص ──
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT a.id, a.scan_result, l.full_name FROM assessments a LEFT JOIN leads l ON l.id = a.lead_id WHERE a.id = ?");
    $stmt->execute([$scanId]);
    $scan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$scan) {
        echo json_encode(['success' => false, 'error' => 'لا يوجد سجل بهذا الـ ID']);
        exit;
    }

    $scanResult = json_decode($scan['scan_result'] ?? '{}', true) ?? [];
    $competitors = $scanResult['competitor_radar'] ?? $scanResult['competitors'] ?? [];

    if (!isset($competitors[$compIdx])) {
        echo json_encode(['success' => false, 'error' => 'منافس غير موجود']);
        exit;
    }

    $competitor = $competitors[$compIdx];
    $compName = $competitor['name'] ?? 'منافس';

    // ── 2. فحص الـ cache ──
    $cacheFile = sys_get_temp_dir() . '/comp_deep_ads_' . md5($compUrl) . '.json';
    if (!$force && file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            $cached['from_cache'] = true;
            echo json_encode($cached, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // ── 3. سحب الإعلانات (scrapeAdsLibrary المُصلَح في PR #58) ──
    $token = function_exists('getValidApifyToken') ? getValidApifyToken($cfg) : '';
    if (empty($token)) {
        echo json_encode(['success' => false, 'error' => 'لا يوجد Apify token صالح']);
        exit;
    }

    logInfo('Deep ads analysis start', [
        'competitor' => $compName,
        'url'        => $compUrl,
    ]);

    // استخدام page_id إن توفر (أدق)
    $pageId = $competitor['platforms']['facebook']['page_id'] ?? '';
    $searchParam = $pageId ? "ID:{$pageId}" : $compUrl;

    $adsResult = scrapeAdsLibrary(
        $searchParam,
        $token,
        $cfg,
        $cfg['apis']['ads_default_country'] ?? 'SA',
        $competitor['platforms']['facebook'] ?? []
    );

    if (!($adsResult['success'] ?? false) || empty($adsResult['ads'])) {
        echo json_encode([
            'success'         => false,
            'error'           => 'لا توجد إعلانات لتحليلها',
            'competitor_name' => $compName,
            'ads_summary'     => [
                'total_ads'  => $adsResult['total_ads'] ?? 0,
                'active_ads' => $adsResult['active_ads'] ?? 0,
            ],
        ]);
        exit;
    }

    // ── 4. تحضير Ads summary ──
    $allAds = $adsResult['ads'];
    $activeAds = array_filter($allAds, fn($a) => !empty($a['is_active']));
    $platforms = [];
    $oldestDate = null;

    foreach ($allAds as $ad) {
        if (is_array($ad['platforms'] ?? null)) {
            foreach ($ad['platforms'] as $p) {
                if (!in_array($p, $platforms, true)) $platforms[] = $p;
            }
        }
        if (!empty($ad['start_date'])) {
            $ts = strtotime($ad['start_date']);
            if ($ts && (!$oldestDate || $ts < $oldestDate)) $oldestDate = $ts;
        }
    }

    $adsSummary = [
        'total_ads'      => $adsResult['total_ads']      ?? count($allAds),
        'active_ads'     => $adsResult['active_ads']     ?? count($activeAds),
        'is_running_ads' => $adsResult['is_running_ads'] ?? !empty($activeAds),
        'platforms'      => $platforms,
        'running_since'  => $oldestDate ? date('Y-m-d', $oldestDate) : null,
    ];

    // ── 5. تحليل بـ AI ──
    $aiAnalysis = analyzeCompetitorAdsWithAI($allAds, $compName, $scanResult, $cfg);

    // ── 6. تحضير الاستجابة ──
    $response = [
        'success'         => true,
        'competitor_name' => $compName,
        'competitor_url'  => $compUrl,
        'ads_summary'     => $adsSummary,
        'ai_analysis'     => $aiAnalysis,
        'ads_sample'      => array_slice($allAds, 0, 6),
        'analyzed_at'     => date('c'),
        'from_cache'      => false,
    ];

    // ── 7. حفظ في cache + DB ──
    @file_put_contents($cacheFile, json_encode($response, JSON_UNESCAPED_UNICODE));

    // حفظ النتيجة داخل scanResult تحت competitor_radar[idx]
    $competitors[$compIdx]['deep_ads_analysis'] = [
        'ads_summary' => $adsSummary,
        'ai_analysis' => $aiAnalysis,
        'analyzed_at' => $response['analyzed_at'],
    ];
    $scanResult['competitor_radar'] = $competitors;

    try {
        $pdo->prepare("UPDATE assessments SET scan_result = ? WHERE id = ?")
            ->execute([json_encode($scanResult, JSON_UNESCAPED_UNICODE), $scanId]);
    } catch (\Throwable $e) {
        logError('Failed to save deep ads to DB', ['error' => $e->getMessage()]);
    }

    // ── 8. تحديث rate counter ──
    @file_put_contents($rateFile, $rateCount + 1);

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    logError('Deep ads endpoint exception', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'حدث خطأ غير متوقع',
        'detail'  => $e->getMessage(),
    ]);
}

/**
 * تحليل إعلانات المنافس بـ OpenAI
 */
function analyzeCompetitorAdsWithAI(array $ads, string $compName, array $scanResult, array $cfg): array {
    $key = $cfg['apis']['openai_key'] ?? '';
    if (empty($key)) {
        return [
            'analyzed' => false,
            'reason'   => 'لا يوجد OpenAI key',
        ];
    }

    // ── تحضير ملخص الإعلانات للـ AI ──
    $adsSummaries = [];
    foreach (array_slice($ads, 0, 15) as $idx => $ad) {
        $adsSummaries[] = [
            'idx'       => $idx + 1,
            'text'      => mb_substr((string)($ad['title'] ?? ''), 0, 500),
            'cta'       => $ad['cta_type'] ?? '',
            'platforms' => $ad['platforms'] ?? [],
            'is_active' => $ad['is_active'] ?? false,
            'start'     => $ad['start_date'] ?? null,
        ];
    }

    $clientName = $scanResult['social']['page_name'] ?? '';

    $systemPrompt = <<<PROMPT
أنت محلل تسويقي محترف. مهمتك تحليل عميق لاستراتيجية إعلانات منافس وحيد بناءً على نصوص إعلاناته الفعلية.

⚠️ قواعد صارمة:

1. ممنوع اختراع تفاصيل لم ترد في النصوص.
2. لو نمط غير واضح → اكتب null.
3. كل ادعاء يجب يستند على نص إعلان محدد (اذكر idx).
4. ممنوع كلمات: "غالباً، يبدو، ربما".
5. لا تخمن الميزانية أو ROI.

📋 المخرجات: JSON صالح فقط:

{
  "messaging_pattern": "نمط الرسائل الأساسي مع أمثلة من 2-3 إعلانات (اذكر idx)",
  "primary_offers": [
    "العرض/الخصم #1 المتكرر مع idx الإعلانات",
    "العرض #2",
    ...
  ],
  "target_audience_signals": "علامات الجمهور المستهدف من النصوص (لغة، عمر، فئة)",
  "cta_strategy": "استراتيجية الدعوة لاتخاذ إجراء (احجز/اطلب/...)",
  "creative_style": "أسلوب الإبداع (عاطفي/عقلاني/كوميدي/...)",
  "frequency_pattern": "هل يكرر نفس الإعلان أم يتنوع (مع أمثلة)",
  "weaknesses_in_ads": [
    "نقطة ضعف #1 مع دليل من الإعلانات",
    "نقطة ضعف #2"
  ],
  "what_to_copy": [
    "ميزة قابلة للنسخ #1",
    "ميزة #2"
  ],
  "what_to_avoid": [
    "خطأ يرتكبه #1",
    "خطأ #2"
  ],
  "winning_hook": "أقوى hook استخدمه (مع نص الإعلان)"
}
PROMPT;

    $userPrompt = "اسم المنافس: {$compName}\nاسم العميل (للسياق): {$clientName}\n\nإعلانات المنافس:\n" .
                  json_encode($adsSummaries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) .
                  "\n\nحلّل بناءً على هذه النصوص فقط.";

    $payload = [
        'model'       => $cfg['analysis']['competitor_ai_model'] ?? 'gpt-4o-mini',
        'temperature' => 0.3,
        'response_format' => ['type' => 'json_object'],
        'messages'    => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userPrompt],
        ],
        'max_tokens'  => 2500,
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
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        return [
            'analyzed' => false,
            'reason'   => "OpenAI HTTP {$code}",
        ];
    }

    $data = json_decode($body, true);
    $text = $data['choices'][0]['message']['content'] ?? '';
    if (empty($text)) {
        return ['analyzed' => false, 'reason' => 'رد AI فارغ'];
    }

    $parsed = json_decode($text, true);
    if (!is_array($parsed)) {
        return ['analyzed' => false, 'reason' => 'فشل parse JSON'];
    }

    $parsed['analyzed'] = true;
    $parsed['_meta'] = [
        'model'         => $payload['model'],
        'ads_analyzed'  => count($adsSummaries),
        'analyzed_at'   => date('c'),
    ];

    return $parsed;
}
```

---

## 2. `js/competitor-deep-ads.js`

### الوظيفة
يستمع لزر "تحليل عميق"، يستدعي endpoint، يعرض modal بالنتيجة.

### الكود الكامل

```javascript
/**
 * Competitor Deep Ads Analysis
 * Adds click handler to all .btn-deep-ads buttons in competitors.html
 */

(function() {
    'use strict';

    // ── 1. تفعيل الأزرار ──
    document.addEventListener('DOMContentLoaded', initDeepAdsButtons);

    function initDeepAdsButtons() {
        // استخدام event delegation للأزرار التي تُحقن لاحقاً
        document.body.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-deep-ads');
            if (!btn) return;

            e.preventDefault();
            handleDeepAdsClick(btn);
        });
    }

    async function handleDeepAdsClick(btn) {
        const fbUrl = btn.dataset.fbUrl;
        const compName = btn.dataset.compName || 'المنافس';
        const card = btn.closest('.competitor-card');
        const compIdx = parseInt(card?.dataset?.competitorIdx ?? '0', 10);

        // scan_id من URL
        const params = new URLSearchParams(window.location.search);
        const scanId = parseInt(params.get('id') || '0', 10);

        if (!scanId || !fbUrl) {
            showError('بيانات ناقصة لإجراء التحليل');
            return;
        }

        // ── Loading state ──
        const originalText = btn.textContent;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner"></span> جاري التحليل... قد يستغرق دقيقتين';

        try {
            const res = await fetch('api/competitor-deep-ads.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    scan_id: scanId,
                    competitor_idx: compIdx,
                    competitor_url: fbUrl,
                }),
            });

            if (res.status === 429) {
                showError('تجاوزت الحد اليومي للتحاليل العميقة. حاول غداً.');
                return;
            }

            const data = await res.json();
            if (!data.success) {
                showError(data.error || 'فشل التحليل');
                return;
            }

            showDeepAdsModal(data, compName);

        } catch (err) {
            console.error('[deep-ads]', err);
            showError('خطأ في الاتصال: ' + err.message);
        } finally {
            btn.disabled = false;
            btn.textContent = originalText;
        }
    }

    function showDeepAdsModal(data, compName) {
        // إزالة modal قديم
        document.querySelector('.deep-ads-modal-overlay')?.remove();

        const summary = data.ads_summary || {};
        const ai = data.ai_analysis || {};

        const overlay = document.createElement('div');
        overlay.className = 'deep-ads-modal-overlay';
        overlay.innerHTML = `
            <div class="deep-ads-modal">
                <div class="deep-modal-header">
                    <div>
                        <h2>🎯 تحليل عميق لإعلانات: ${escapeHtml(compName)}</h2>
                        ${data.from_cache ? '<span class="cache-badge">📦 من cache</span>' : ''}
                    </div>
                    <button class="deep-modal-close" onclick="this.closest('.deep-ads-modal-overlay').remove()">×</button>
                </div>

                <div class="deep-modal-body">
                    ${renderSummary(summary)}
                    ${ai.analyzed ? renderAIAnalysis(ai) : renderUnanalyzed(ai.reason)}
                    ${renderAdsSamples(data.ads_sample || [])}
                </div>

                <div class="deep-modal-footer">
                    <small>تم التحليل: ${formatDateTime(data.analyzed_at)}</small>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);

        // إغلاق عند النقر خارج المحتوى
        overlay.addEventListener('click', e => {
            if (e.target === overlay) overlay.remove();
        });

        // ESC للإغلاق
        document.addEventListener('keydown', function escHandler(e) {
            if (e.key === 'Escape') {
                overlay.remove();
                document.removeEventListener('keydown', escHandler);
            }
        });
    }

    function renderSummary(summary) {
        return `
            <section class="deep-section">
                <h3>📊 ملخص الإعلانات</h3>
                <div class="summary-grid">
                    <div class="summary-stat">
                        <div class="ss-num">${summary.total_ads ?? '—'}</div>
                        <div class="ss-label">إجمالي الإعلانات</div>
                    </div>
                    <div class="summary-stat">
                        <div class="ss-num">${summary.active_ads ?? '—'}</div>
                        <div class="ss-label">نشطة الآن</div>
                    </div>
                    ${summary.running_since ? `
                        <div class="summary-stat">
                            <div class="ss-num">${formatDate(summary.running_since)}</div>
                            <div class="ss-label">يعلن منذ</div>
                        </div>
                    ` : ''}
                    ${summary.platforms?.length ? `
                        <div class="summary-stat">
                            <div class="ss-num">${summary.platforms.length}</div>
                            <div class="ss-label">منصات: ${summary.platforms.join('، ')}</div>
                        </div>
                    ` : ''}
                </div>
            </section>
        `;
    }

    function renderAIAnalysis(ai) {
        let html = '<section class="deep-section"><h3>🧠 التحليل العميق</h3>';

        if (ai.messaging_pattern) {
            html += `
                <div class="deep-block">
                    <h4>💬 نمط الرسائل</h4>
                    <p>${escapeHtml(ai.messaging_pattern)}</p>
                </div>
            `;
        }

        if (Array.isArray(ai.primary_offers) && ai.primary_offers.length) {
            html += `
                <div class="deep-block">
                    <h4>🎁 العروض الأساسية</h4>
                    <ul>${ai.primary_offers.map(o => `<li>${escapeHtml(o)}</li>`).join('')}</ul>
                </div>
            `;
        }

        if (ai.target_audience_signals) {
            html += `
                <div class="deep-block">
                    <h4>🎯 الجمهور المستهدف</h4>
                    <p>${escapeHtml(ai.target_audience_signals)}</p>
                </div>
            `;
        }

        if (ai.cta_strategy || ai.creative_style) {
            html += `<div class="deep-grid-2">`;
            if (ai.cta_strategy) html += `
                <div class="deep-block">
                    <h4>📞 استراتيجية CTA</h4>
                    <p>${escapeHtml(ai.cta_strategy)}</p>
                </div>
            `;
            if (ai.creative_style) html += `
                <div class="deep-block">
                    <h4>🎨 الأسلوب الإبداعي</h4>
                    <p>${escapeHtml(ai.creative_style)}</p>
                </div>
            `;
            html += `</div>`;
        }

        if (ai.winning_hook) {
            html += `
                <div class="deep-block highlight-block">
                    <h4>🏆 أقوى Hook استخدمه</h4>
                    <p class="winning-hook-text">"${escapeHtml(ai.winning_hook)}"</p>
                </div>
            `;
        }

        if (Array.isArray(ai.weaknesses_in_ads) && ai.weaknesses_in_ads.length) {
            html += `
                <div class="deep-block warning-block">
                    <h4>⚠️ نقاط ضعف في إعلاناته</h4>
                    <ul>${ai.weaknesses_in_ads.map(w => `<li>${escapeHtml(w)}</li>`).join('')}</ul>
                </div>
            `;
        }

        if (Array.isArray(ai.what_to_copy) && ai.what_to_copy.length) {
            html += `
                <div class="deep-block success-block">
                    <h4>💎 ما يستحق النسخ</h4>
                    <ul>${ai.what_to_copy.map(c => `<li>${escapeHtml(c)}</li>`).join('')}</ul>
                </div>
            `;
        }

        if (Array.isArray(ai.what_to_avoid) && ai.what_to_avoid.length) {
            html += `
                <div class="deep-block error-block">
                    <h4>🚫 ما يجب تجنّبه</h4>
                    <ul>${ai.what_to_avoid.map(a => `<li>${escapeHtml(a)}</li>`).join('')}</ul>
                </div>
            `;
        }

        html += '</section>';
        return html;
    }

    function renderUnanalyzed(reason) {
        return `
            <section class="deep-section">
                <div class="deep-block warning-block">
                    <h4>⚠️ التحليل غير متاح</h4>
                    <p>${escapeHtml(reason || 'سبب غير معروف')}</p>
                </div>
            </section>
        `;
    }

    function renderAdsSamples(samples) {
        if (!samples.length) return '';

        let html = '<section class="deep-section"><h3>📸 عينة من الإعلانات</h3><div class="ads-samples-grid">';

        samples.forEach((ad, i) => {
            const text = (ad.title || '').substring(0, 200);
            const isActive = ad.is_active;
            html += `
                <div class="ad-sample-card ${isActive ? 'active' : 'inactive'}">
                    ${ad.image_url ? `<img src="${escapeHtml(ad.image_url)}" alt="" loading="lazy" />` : ''}
                    <div class="ad-sample-body">
                        <div class="ad-sample-status">
                            ${isActive ? '🟢 نشط' : '⚪ غير نشط'}
                            ${ad.start_date ? `<span class="ad-sample-date">${formatDate(ad.start_date)}</span>` : ''}
                        </div>
                        <p class="ad-sample-text">${escapeHtml(text)}${text.length >= 200 ? '...' : ''}</p>
                        ${ad.cta_type ? `<div class="ad-sample-cta">CTA: ${escapeHtml(ad.cta_type)}</div>` : ''}
                    </div>
                </div>
            `;
        });

        html += '</div></section>';
        return html;
    }

    function showError(message) {
        document.querySelector('.deep-ads-modal-overlay')?.remove();
        const overlay = document.createElement('div');
        overlay.className = 'deep-ads-modal-overlay';
        overlay.innerHTML = `
            <div class="deep-ads-modal error-modal">
                <div class="deep-modal-header">
                    <h2>⚠️ خطأ</h2>
                    <button class="deep-modal-close" onclick="this.closest('.deep-ads-modal-overlay').remove()">×</button>
                </div>
                <div class="deep-modal-body">
                    <p style="text-align:center; padding:40px;">${escapeHtml(message)}</p>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);
        overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
    }

    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        return String(text)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function formatDate(d) {
        if (!d) return '';
        try {
            return new Date(d).toLocaleDateString('ar', { year: 'numeric', month: 'short', day: 'numeric' });
        } catch { return d; }
    }

    function formatDateTime(d) {
        if (!d) return '';
        try {
            return new Date(d).toLocaleString('ar', { dateStyle: 'medium', timeStyle: 'short' });
        } catch { return d; }
    }

})();
```

---

## 3. CSS للـ Modal (إضافة في `competitors.html` أو ملف CSS منفصل)

```css
/* ═══════════════════════════════════════════════════════
   Deep Ads Modal
═══════════════════════════════════════════════════════ */

.btn-deep-ads {
    width: 100%;
    margin-top: 20px;
    padding: 14px 20px;
    background: linear-gradient(135deg, var(--primary), #ff6b1a);
    color: white;
    border: none;
    border-radius: 12px;
    font-family: inherit;
    font-weight: 700;
    font-size: 15px;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.btn-deep-ads:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 10px 30px var(--primary-glow);
}

.btn-deep-ads:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}

.spinner {
    display: inline-block;
    width: 16px;
    height: 16px;
    border: 2px solid rgba(255,255,255,0.3);
    border-top-color: white;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }

/* Overlay */
.deep-ads-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.85);
    backdrop-filter: blur(8px);
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

/* Modal */
.deep-ads-modal {
    background: var(--bg-card);
    backdrop-filter: var(--glass-blur);
    border: 1px solid var(--glass-border);
    border-radius: 24px;
    width: 100%;
    max-width: 1100px;
    max-height: 90vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 0 30px 80px rgba(0, 0, 0, 0.7);
    animation: slideUp 0.4s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes slideUp { from { transform: translateY(40px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

.deep-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 24px 32px;
    border-bottom: 1px solid var(--glass-border);
    background: linear-gradient(135deg, rgba(245, 142, 26, 0.1), transparent);
}

.deep-modal-header h2 {
    font-size: 22px;
    font-weight: 800;
    margin: 0;
}

.cache-badge {
    display: inline-block;
    margin-top: 6px;
    padding: 4px 10px;
    background: rgba(139, 92, 246, 0.2);
    color: var(--purple);
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}

.deep-modal-close {
    width: 40px;
    height: 40px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid var(--glass-border);
    color: white;
    font-size: 24px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: 0.2s;
}

.deep-modal-close:hover { background: rgba(239, 68, 68, 0.2); }

.deep-modal-body {
    flex: 1;
    overflow-y: auto;
    padding: 32px;
}

.deep-section { margin-bottom: 36px; }

.deep-section h3 {
    font-size: 18px;
    font-weight: 800;
    margin: 0 0 16px;
    color: var(--primary);
}

/* Summary grid */
.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
}

.summary-stat {
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--glass-border);
    border-radius: 16px;
    padding: 20px;
    text-align: center;
}

.summary-stat .ss-num {
    font-size: 32px;
    font-weight: 900;
    color: var(--primary);
    line-height: 1;
    margin-bottom: 8px;
}

.summary-stat .ss-label {
    font-size: 13px;
    color: var(--text-gray);
    font-weight: 600;
}

/* Deep blocks */
.deep-block {
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--glass-border);
    border-radius: 14px;
    padding: 20px;
    margin-bottom: 16px;
}

.deep-block h4 {
    font-size: 15px;
    font-weight: 700;
    margin: 0 0 12px;
    color: var(--text-dark);
}

.deep-block p { line-height: 1.7; color: var(--text-gray); margin: 0; }

.deep-block ul { margin: 0; padding-right: 20px; }
.deep-block ul li { line-height: 1.8; color: var(--text-gray); margin-bottom: 6px; }

.deep-grid-2 {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 16px;
}

.highlight-block {
    background: linear-gradient(135deg, rgba(245, 142, 26, 0.1), transparent);
    border-color: var(--primary-glow);
}

.warning-block {
    background: linear-gradient(135deg, rgba(245, 158, 11, 0.08), transparent);
    border-color: rgba(245, 158, 11, 0.3);
}

.success-block {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.08), transparent);
    border-color: rgba(16, 185, 129, 0.3);
}

.error-block {
    background: linear-gradient(135deg, rgba(239, 68, 68, 0.08), transparent);
    border-color: rgba(239, 68, 68, 0.3);
}

.winning-hook-text {
    font-size: 18px;
    font-weight: 700;
    color: var(--primary);
    font-style: italic;
}

/* Ads samples */
.ads-samples-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 20px;
}

.ad-sample-card {
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--glass-border);
    border-radius: 14px;
    overflow: hidden;
    transition: 0.3s;
}

.ad-sample-card.active { border-color: rgba(16, 185, 129, 0.4); }
.ad-sample-card.inactive { opacity: 0.7; }

.ad-sample-card img {
    width: 100%;
    height: 180px;
    object-fit: cover;
    display: block;
}

.ad-sample-body { padding: 14px; }

.ad-sample-status {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-gray);
    margin-bottom: 8px;
}

.ad-sample-date { color: var(--text-dim); }

.ad-sample-text {
    font-size: 13px;
    line-height: 1.6;
    color: var(--text-gray);
    margin: 0 0 8px;
}

.ad-sample-cta {
    font-size: 12px;
    font-weight: 700;
    color: var(--primary);
    padding-top: 8px;
    border-top: 1px solid var(--glass-border);
}

.deep-modal-footer {
    padding: 16px 32px;
    border-top: 1px solid var(--glass-border);
    text-align: center;
    color: var(--text-dim);
    font-size: 13px;
}

/* Error modal */
.error-modal { max-width: 500px; }

/* Responsive */
@media (max-width: 768px) {
    .deep-ads-modal { max-height: 95vh; border-radius: 16px; }
    .deep-modal-header { padding: 18px; }
    .deep-modal-header h2 { font-size: 18px; }
    .deep-modal-body { padding: 20px; }
    .deep-grid-2 { grid-template-columns: 1fr; }
}
```

---

## 4. ربط `competitor-deep-ads.js` في `competitors.html`

أضف قبل `</body>`:

```html
<script src="js/competitor-deep-ads.js"></script>
```

---

# ⚙️ الإعدادات الجديدة

## في `.env.example`

```env
# ── Sprint 5: Deep Ads Analysis ──
COMPETITOR_DEEP_ADS_ENABLED=true
COMPETITOR_DEEP_ADS_MAX_PER_DAY=20
COMPETITOR_DEEP_ADS_CACHE_HOURS=24
```

## في `api/config.example.php` داخل `'analysis' => [...]`

```php
'competitor_deep_ads_enabled'      => filter_var($get('COMPETITOR_DEEP_ADS_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN),
'competitor_deep_ads_max_per_day'  => (int)$get('COMPETITOR_DEEP_ADS_MAX_PER_DAY', '20'),
'competitor_deep_ads_cache_hours'  => (int)$get('COMPETITOR_DEEP_ADS_CACHE_HOURS', '24'),
```

---

# 🛡️ ضمانات الأمان والحماية

## 1. Rate Limiting (مدمج في endpoint)
- 20 طلب/يوم لكل IP
- Cache 24 ساعة لكل منافس
- منع تعطيل التطبيق بطلبات مفرطة

## 2. Validation
- scan_id يجب أن يكون موجود في DB
- compIdx يجب أن يكون داخل النطاق
- compUrl يجب أن يكون رابط Facebook صحيح

## 3. الحماية من إساءة الاستخدام
```php
// أضف فحص أن الـ scan ينتمي للمستخدم (لو نظام مستخدمين موجود)
$userOwnsThis = checkUserOwnership($scanId, $_SESSION['user_id'] ?? null);
if (!$userOwnsThis) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'غير مصرح']);
    exit;
}
```

---

# 🧪 خطة الاختبار

## اختبار 1: الزر يظهر فقط لو في Facebook URL
```javascript
// منافس بدون social.facebook
// النتيجة: زر التحليل العميق غير موجود في البطاقة
```

## اختبار 2: التحليل الكامل
```bash
# اضغط الزر → modal يظهر بـ loading
# ثم: ads_summary + ai_analysis + ads_sample
# الوقت المتوقع: 60-120 ثانية
```

## اختبار 3: Cache
```bash
# اضغط الزر مرتين متتاليتين
# الأولى: 60-120s
# الثانية: <2s (with cache_badge)
```

## اختبار 4: Rate limit
```bash
# اضغط 21 مرة في يوم واحد
# الـ 21 يُرجع 429 برسالة "تجاوزت الحد اليومي"
```

## اختبار 5: لا توجد إعلانات
```bash
# منافس بدون إعلانات
# النتيجة: success=false, error="لا توجد إعلانات لتحليلها"
```

---

# ✅ Checklist للـ Coder Agent

- [ ] إنشاء `api/competitor-deep-ads.php`
- [ ] إنشاء `js/competitor-deep-ads.js`
- [ ] إضافة CSS الـ modal في `competitors.html`
- [ ] ربط `<script src="js/competitor-deep-ads.js">` في `competitors.html`
- [ ] إضافة env vars في `.env.example`
- [ ] إضافة config vars في `api/config.example.php`
- [ ] `php -l` على endpoint
- [ ] اختبار manual: ضغط زر → modal
- [ ] اختبار rate limit (21 طلب)
- [ ] اختبار cache (طلبين متتاليين)
- [ ] commit: `feat(competitors): Sprint 5 - per-competitor deep ads analysis`
- [ ] PR: "Sprint 5: Deep Ads Analysis Per-Competitor"

---

# 🎉 الانتهاء من النظام كاملاً

بعد إكمال Sprints 2+3+4+5، النظام النهائي:

✅ يكتشف 5 منافسين حقيقيين 100% من 3 مصادر  
✅ يسحب بيانات كل منافس من 4 منصات + Google Reviews  
✅ يحلّل كل منافس بـ AI (9 حقول) مع منع hallucinations  
✅ يولد market summary مع ترتيب العميل  
✅ يحدد Blue Ocean opportunities  
✅ زر "تحليل عميق لإعلانات منافس" بضغطة واحدة  
✅ صفر بيانات وهمية (كل قيمة لها مصدر)  
✅ تكلفة Apify معقولة ($0.50/فحص + $0.10 لكل deep ads)  

---

# 🚀 الترتيب الموصى به للتنفيذ

1. **اختبر النظام الحالي بعد PR #58** للتأكد أن الإصلاحات الأساسية شغّالة
2. **Sprint 2 (Discovery)** — أساس كل ما يأتي بعد
3. **Sprint 3 (Enrichment)** — البيانات الكمية
4. **Sprint 4 (AI + UI)** — التحليل والعرض
5. **Sprint 5 (Deep Ads)** — الميزة Premium

كل sprint = PR منفصل = اختبار منفصل = أمان أكبر.

نجاح موفّق 🎯
