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
