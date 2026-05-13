// ============================================================
// js/report-connect.js v2.1 — ربط كامل لجميع الصفحات الفرعية
// P2-1: إصلاح الاسم (brand_name → full_name)
// P2-2: ربط ads, competitors, journey, content ببيانات حقيقية
// P2-3: ربط packages بدرجة العميل الحقيقية
// Phase 4 (Part 2): Strict mode + XSS hardening + Error Boundaries
// ============================================================
'use strict';

// ============================================================
// طبقة الـ JS Fallback — العملية 5
// قراءة آمنة من كائنات JSON متداخلة — لا TypeError حتى لو مفتاح مفقود
// ============================================================

/**
 * قراءة آمنة من كائن متداخل عبر مسار نقطي
 * safeRead(data, 'page_1_report.overall_score') → 0 if missing
 */
function safeRead(obj, path, fallback = '—') {
    if (obj === null || obj === undefined || typeof obj !== 'object') return fallback;
    const keys = path.split('.');
    let current = obj;
    for (const key of keys) {
        if (current === null || current === undefined || typeof current !== 'object' || !(key in current)) {
            return fallback;
        }
        current = current[key];
    }
    return (current === null || current === undefined) ? fallback : current;
}

/**
 * قراءة آمنة لرقم — يُنظّف النسب والفواصل
 * safeNum(data, 'page_1_report.overall_score') → 0 if missing/NaN
 */
function safeNum(obj, path, fallback = 0) {
    const val = safeRead(obj, path, null);
    if (val === null || val === undefined) return fallback;
    const cleaned = typeof val === 'string' ? val.replace(/[,%\u0631\u064a\u0627\u0644\s]/g, '') : String(val);
    const num = parseFloat(cleaned);
    return isNaN(num) ? fallback : num;
}

/**
 * قراءة آمنة لمصفوفة
 * safeArr(data, 'page_6_content.hook_bank') → [] if missing/not array
 */
function safeArr(obj, path) {
    const val = safeRead(obj, path, null);
    return Array.isArray(val) ? val : [];
}

/**
 * ملء عنصر DOM بأمان — يُستخدم '—' إذا القيمة فارغة
 */
function safeFill(elementId, value) {
    const el = document.getElementById(elementId);
    if (el) {
        el.textContent = (value !== null && value !== undefined && value !== '' && value !== '—') ? value : '—';
    }
}

/**
 * ملء شريط تقدم بأمان
 */
function safeBar(elementId, value, max = 100) {
    const el = document.getElementById(elementId);
    if (el) {
        const pct = Math.min(100, Math.max(0, (Number(value) / max) * 100));
        el.style.width = pct + '%';
    }
}

function sanitize(str) {
    if (typeof str !== 'string') return str;
    const temp = document.createElement('div');
    temp.textContent = str;
    return temp.innerHTML;
}

// ── Phase 4.5: XSS hardening helpers ───────────────────────────
// escapeHtml — يهرب < > & " ' لمنع HTML injection حتى داخل سمات
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
// safeUrl — يقبل http/https/mailto/tel فقط، يرفض javascript: و data: و vbscript:
function safeUrl(url) {
    if (!url || typeof url !== 'string') return '';
    const trimmed = url.trim();
    if (!trimmed) return '';
    // Block dangerous schemes (javascript:, data:, vbscript:, file:)
    if (/^\s*(javascript|data|vbscript|file)\s*:/i.test(trimmed)) return '';
    // Allow relative URLs, http(s), mailto, tel
    return escapeHtml(trimmed);
}
// safeRunBlock — يلف بلوك صفحة بـ try/catch لمنع cascading failure
function safeRunBlock(name, fn) {
    try {
        fn();
    } catch (e) {
        console.error('[RC] فشل عرض قسم ' + name + ':', e);
    }
}

// ── extractText: استخراج نص من قيمة قد تكون string أو object ──
// يحل مشكلة [object Object] في بطاقات strengths/weaknesses عندما يُرجع
// الـ AI كائنات بمفاتيح متباينة (title, name, point, text, label, ...).
function extractText(item, fallback = '—') {
    if (item == null) return fallback;
    if (typeof item === 'string') return item;
    if (typeof item === 'number' || typeof item === 'boolean') return String(item);
    if (typeof item === 'object') {
        const keys = [
            'title',
            'name',
            'point',
            'text',
            'heading',
            'label',
            'item',
            'desc',
            'description',
            'task',
        ];
        for (const k of keys) {
            const v = item[k];
            if (typeof v === 'string' && v.trim()) return v;
        }
        return fallback;
    }
    return String(item);
}

function sanitizeRelaxed(str) {
    if (typeof str !== 'string') return String(str || '');
    const div = document.createElement('div');
    div.innerHTML = str;
    div.querySelectorAll('script,iframe,object,embed,form,link,meta,style').forEach(el =>
        el.remove()
    );
    return div.innerHTML;
}

function missingDataHtml(
    title = 'البيانات غير متوفرة من الفحص',
    body = 'لم يرجع هذا المحور بيانات كافية لهذا التقرير. أعد تشغيل التحليل أو اربط مصدر البيانات المطلوب لعرض نتيجة مؤكدة.'
) {
    return `
    <div style="grid-column:1/-1;padding:22px;border:1px solid rgba(148,163,184,0.22);border-radius:14px;background:rgba(148,163,184,0.08);direction:rtl;">
      <strong style="display:block;color:#f59e0b;margin-bottom:8px;">${sanitize(title)}</strong>
      <p style="margin:0;color:#94a3b8;line-height:1.8;font-weight:700;">${sanitize(body)}</p>
    </div>
  `;
}

// نسخة createElement — آمنة مع CSP (بدون innerHTML)
function appendMissingData(parent, title, body) {
    title = title || 'البيانات غير متوفرة من الفحص';
    body =
        body ||
        'لم يرجع هذا المحور بيانات كافية لهذا التقرير. أعد تشغيل التحليل أو اربط مصدر البيانات المطلوب لعرض نتيجة مؤكدة.';
    const wrap = document.createElement('div');
    wrap.style.cssText =
        'grid-column:1/-1;padding:22px;border:1px solid rgba(148,163,184,0.22);border-radius:14px;background:rgba(148,163,184,0.08);direction:rtl;';
    const strong = document.createElement('strong');
    strong.style.cssText = 'display:block;color:#f59e0b;margin-bottom:8px;';
    strong.textContent = title;
    const p = document.createElement('p');
    p.style.cssText = 'margin:0;color:#94a3b8;line-height:1.8;font-weight:700;';
    p.textContent = body;
    wrap.appendChild(strong);
    wrap.appendChild(p);
    while (parent.firstChild) parent.removeChild(parent.firstChild);
    parent.appendChild(wrap);
}

function setTextIf(id, text) {
    const el = document.getElementById(id);
    if (el) el.textContent = text;
}

function buildPublicAdsOverview(sr) {
    const adsLib = (sr && (sr.ads_library || sr.ads)) || {};
    const ads = Array.isArray(adsLib.ads) ? adsLib.ads : [];
    const rawItems = Array.isArray(adsLib.raw_items) ? adsLib.raw_items : [];
    const totalAds =
        Number(
            adsLib.total_ads ??
                adsLib.total_count ??
                adsLib.raw_count ??
                ads.length ??
                rawItems.length ??
                0
        ) || 0;
    const activeAds = Number(adsLib.active_ads ?? adsLib.active_count ?? 0) || 0;
    const stoppedAds = Math.max(totalAds - activeAds, 0);
    const hasPixel = !!(sr && (sr.hasPixel || sr.has_fb_pixel || sr.pixel_found || sr.meta_pixel));
    const realMetrics =
        adsLib.real_metrics && typeof adsLib.real_metrics === 'object' ? adsLib.real_metrics : null;
    const hasRealMetrics = !!(realMetrics && Object.keys(realMetrics).length > 0);
    const spendVal = realMetrics && (realMetrics.spend ?? realMetrics.amount_spent);
    const roasVal = realMetrics && (realMetrics.roas ?? realMetrics.purchase_roas);
    const ctrVal = realMetrics && realMetrics.ctr;

    const exactMetrics = hasRealMetrics
        ? [
              {
                  title: 'الإنفاق الحقيقي',
                  val: spendVal != null ? String(spendVal) : 'غير متوفر',
                  status: 'من Meta Ads Manager',
                  status_class: 'status-green',
                  val_class: spendVal != null ? 'val-green' : 'val-yellow',
                  desc: 'هذه القيمة تأتي من ربط Meta Ads Manager وليست من مكتبة الإعلانات العامة.',
              },
              {
                  title: 'ROAS الحقيقي',
                  val: roasVal != null ? String(roasVal) : 'غير متوفر',
                  status: 'من الحساب الإعلاني',
                  status_class: 'status-green',
                  val_class: roasVal != null ? 'val-green' : 'val-yellow',
                  desc: 'لا يتم تقدير العائد هنا. يظهر فقط إذا أرجعه الحساب الإعلاني المربوط.',
              },
              {
                  title: 'CTR الحقيقي',
                  val: ctrVal != null ? String(ctrVal) : 'غير متوفر',
                  status: 'من الحساب الإعلاني',
                  status_class: 'status-green',
                  val_class: ctrVal != null ? 'val-green' : 'val-yellow',
                  desc: 'مؤشر النقر يظهر من بيانات Meta المربوطة عند توفره.',
              },
          ]
        : [
              {
                  title: 'الميزانية والعائد',
                  val: 'غير متوفر',
                  status: 'يتطلب ربط Meta Ads Manager',
                  status_class: 'status-yellow',
                  val_class: 'val-yellow',
                  desc: 'مكتبة الإعلانات العامة تعرض وجود الإعلان ومحتواه، لكنها لا تعطي الإنفاق أو ROAS أو تكلفة النتيجة.',
              },
          ];

    return {
        score: totalAds > 0 ? (hasPixel ? 55 : 35) : 10,
        status:
            totalAds > 0 ? 'بيانات إعلانات عامة متاحة' : 'لا توجد إعلانات مؤكدة من البيانات العامة',
        desc:
            totalAds > 0
                ? `تم العثور على ${totalAds} إعلان في البيانات العامة. الأرقام المالية لا تظهر إلا عند ربط Meta Ads Manager.`
                : 'لم ترجع مكتبة الإعلانات العامة أي إعلان مؤكد لهذا التقرير.',
        metrics: [
            {
                title: 'إجمالي الإعلانات المرصودة',
                val: String(totalAds),
                status: totalAds > 0 ? 'من مكتبة Meta العامة' : 'لا يوجد رصد',
                status_class: totalAds > 0 ? 'status-green' : 'status-red',
                val_class: totalAds > 0 ? 'val-green' : 'val-red',
                desc: 'هذا العدد مبني على نتيجة السحب الفعلية من مكتبة الإعلانات أو بيانات Apify المحفوظة.',
            },
            {
                title: 'الإعلانات النشطة',
                val: activeAds ? String(activeAds) : totalAds ? 'غير محدد' : '0',
                status: activeAds ? 'نشطة وقت السحب' : 'غير مؤكد',
                status_class: activeAds ? 'status-green' : 'status-yellow',
                val_class: activeAds ? 'val-green' : 'val-yellow',
                desc:
                    stoppedAds > 0
                        ? `يوجد ${stoppedAds} إعلان غير نشط أو غير مصنف ضمن البيانات المتاحة.`
                        : 'حالة النشاط تعرض فقط عندما ترجعها أداة السحب.',
            },
            ...exactMetrics,
        ],
        creative_pointers: [
            {
                type: totalAds > 0 ? 'green' : 'yellow',
                icon: totalAds > 0 ? '✓' : '!',
                title: totalAds > 0 ? 'محتوى إعلاني مرصود' : 'لا توجد نسخة إعلان مؤكدة',
                desc:
                    totalAds > 0
                        ? 'يمكن تحليل الرسائل والكرياتيف من نصوص الإعلانات والصور المتاحة.'
                        : 'لا يمكن تحليل الرسائل الإعلانية بدون إعلانات مرصودة.',
            },
            {
                type: hasPixel ? 'green' : 'yellow',
                icon: hasPixel ? '✓' : '!',
                title: hasPixel ? 'Meta Pixel مؤكد من الفحص' : 'حالة Meta Pixel غير مؤكدة',
                desc: hasPixel
                    ? 'وجود البيكسل يساعد على قراءة مسار التحويل عند توفر بيانات الحساب.'
                    : 'غياب تأكيد البيكسل لا يعني بالضرورة أنه غير موجود، لكنه غير مثبت في بيانات الفحص الحالية.',
            },
            {
                type: hasRealMetrics ? 'green' : 'yellow',
                icon: hasRealMetrics ? '✓' : '!',
                title: hasRealMetrics ? 'أرقام Ads Manager متاحة' : 'الأرقام المالية غير متاحة',
                desc: hasRealMetrics
                    ? 'الصفحة ستعرض أرقام الحساب الإعلاني المربوط فقط.'
                    : 'اربط Meta Ads Manager لعرض الإنفاق، تكلفة النتيجة، ROAS، والتحويلات.',
            },
        ],
        strategy: {
            desc: 'هذا عرض مبني على البيانات المؤكدة فقط:',
            steps: hasRealMetrics
                ? [
                      'راجع الإنفاق والعائد من الحساب المربوط قبل أي قرار ميزانية.',
                      'حلل آخر 30 إعلان من الرسائل والكرياتيف لتحديد الأنماط الرابحة.',
                      'اربط نتائج الإعلانات بصفحة الهبوط للتحقق من جاهزية التحويل.',
                  ]
                : [
                      'استخدم مكتبة الإعلانات لتحليل الرسائل والكرياتيف فقط.',
                      'اربط Meta Ads Manager للحصول على الإنفاق، ROAS، CPA، والتحويلات.',
                      'لا تعتمد على أي تقدير مالي قبل توفر بيانات الحساب الإعلاني.',
                  ],
        },
    };
}

// ── Animation Helpers (Moved to top) ──
function animateCounters() {
    document
        .querySelectorAll('.score-num[data-val], .d-score-num[data-val], .mini-val[data-val]')
        .forEach(el => {
            const target = parseInt(el.getAttribute('data-val'));
            if (isNaN(target)) return;
            let current = 0;
            const step = () => {
                current += (target - current) * 0.12;
                el.textContent = Math.floor(current);
                if (Math.abs(target - current) > 0.5) requestAnimationFrame(step);
                else el.textContent = target;
            };
            requestAnimationFrame(step);
        });
}

function animateRings() {
    document
        .querySelectorAll('.score-circle[data-percent], .mini-ring[data-percent]')
        .forEach(ring => {
            const pct = parseInt(ring.getAttribute('data-percent'));
            const color = ring.getAttribute('data-color') || 'var(--primary)';
            let cur = 0;
            const step = () => {
                cur += (pct - cur) * 0.08;
                ring.style.background = `conic-gradient(${color} ${cur}%, rgba(255,255,255,0.1) 0)`;
                if (Math.abs(pct - cur) > 0.5) requestAnimationFrame(step);
                else
                    ring.style.background = `conic-gradient(${color} ${pct}%, rgba(255,255,255,0.1) 0)`;
            };
            requestAnimationFrame(step);
        });
}

document.addEventListener('DOMContentLoaded', () => {
    // ── Wire print button (CSP-safe replacement for onclick="window.print()") ──
    const printBtn = document.getElementById('btnPrint');
    if (printBtn) printBtn.addEventListener('click', () => window.print());

    const urlParams = new URLSearchParams(window.location.search);
    const path = window.location.pathname;
    let id = urlParams.get('id');

    // ── بدون id حقيقي → رسالة خطأ واضحة ──
    if (!id) {
        // لا يوجد معرّف تقرير — عرض رسالة واضحة بدلاً من بيانات وهمية
        const _mc =
            document.querySelector('.main-content') ||
            document.querySelector('main') ||
            document.body;
        if (_mc)
            _mc.innerHTML = `
      <div style="text-align:center;padding:80px 40px;direction:rtl;font-family:Cairo,sans-serif;">
        <div style="font-size:64px;margin-bottom:20px;">🔒</div>
        <h2 style="color:#f58e1a;font-size:26px;font-weight:900;margin-bottom:12px;">لا يوجد تقرير لعرضه</h2>
        <p style="color:#94a3b8;font-size:16px;font-weight:600;margin-bottom:32px;line-height:1.7;">
          هذه الصفحة تعرض نتائج تحليل حقيقي فقط.<br>يجب الوصول إليها عبر رابط التقرير المُرسَل إليك.
        </p>
        <a href="index.html" style="display:inline-block;background:#f58e1a;color:#fff;padding:14px 36px;border-radius:14px;font-weight:900;font-size:16px;text-decoration:none;">🚀 ابدأ تحليلك الآن</a>
      </div>`;
        return;
    }

    // ── 1. تحديث روابط التنقل والباكجات لتحتفظ بـ id ──────────
    if (path.includes('content.html')) {
        document.querySelectorAll('.q-answer').forEach(el => {
            el.textContent = 'جاري تحميل بيانات التقرير...';
        });
        document.querySelectorAll('.q-status').forEach(el => {
            el.className = el.className.replace(/\b(good|warn|bad|neu)\b/g, '').trim() + ' neu';
            el.textContent = '--';
        });
    }

    document
        .querySelectorAll('.nav-menu a, .btn-upgrade, .btn-primary, .btn-pkg, .back-btn')
        .forEach(link => {
            const href = link.getAttribute('href');
            if (href && !href.startsWith('#') && !href.startsWith('http')) {
                if (href.includes('id=' + id)) return;
                const separator = href.includes('?') ? '&' : '?';
                link.setAttribute('href', href + separator + 'id=' + id);
            }
        });

    // ── 2. جلب البيانات الحقيقية فقط ─────────────────────────
    const token = urlParams.get('token') || sessionStorage.getItem('last_assessment_token');

    fetch(`api/result.php?id=${id}&token=${token || ''}`)
        .then(res => {
            if (!res.ok) throw new Error('Server error: ' + res.status);
            return res.json();
        })
        .then(data => {
            if (data.error) {
                const _mc =
                    document.querySelector('.main-content') ||
                    document.querySelector('main') ||
                    document.body;
                if (_mc) {
                    // CSP-safe: use createElement instead of innerHTML with onclick
                    while (_mc.firstChild) _mc.removeChild(_mc.firstChild);
                    const wrap = document.createElement('div');
                    wrap.style.cssText =
                        'text-align:center;padding:60px;direction:rtl;font-family:Cairo,sans-serif;';
                    const iconDiv = document.createElement('div');
                    iconDiv.style.cssText = 'font-size:48px;margin-bottom:16px;';
                    iconDiv.textContent = '⚠️';
                    const h2 = document.createElement('h2');
                    h2.style.cssText = 'color:#ef4444;font-size:22px;font-weight:900;';
                    h2.textContent = data.error;
                    const p = document.createElement('p');
                    p.style.cssText = 'color:#94a3b8;margin-top:10px;';
                    p.textContent = 'تحقق من رابط التقرير وأعد المحاولة';
                    const a = document.createElement('a');
                    a.href = 'index.html';
                    a.style.cssText =
                        'display:inline-block;margin-top:24px;background:#f58e1a;color:#fff;padding:12px 28px;border-radius:12px;font-weight:900;text-decoration:none;';
                    a.textContent = 'ابدأ من جديد';
                    wrap.appendChild(iconDiv);
                    wrap.appendChild(h2);
                    wrap.appendChild(p);
                    wrap.appendChild(a);
                    _mc.appendChild(wrap);
                }
                return;
            }
            if (data.status === 'pending') {
                const _mc =
                    document.querySelector('.main-content') ||
                    document.querySelector('main') ||
                    document.body;
                if (_mc) {
                    // CSP-safe: use createElement instead of innerHTML with onclick
                    while (_mc.firstChild) _mc.removeChild(_mc.firstChild);
                    const wrap = document.createElement('div');
                    wrap.style.cssText =
                        'text-align:center;padding:60px;direction:rtl;font-family:Cairo,sans-serif;';
                    const h2 = document.createElement('h2');
                    h2.style.cssText = 'color:#f58e1a;';
                    h2.textContent = '⏳ جاري تجهيز تقريرك...';
                    const p = document.createElement('p');
                    p.style.cssText = 'color:#666;margin-top:12px;';
                    p.textContent = 'يرجى الانتظار قليلاً ثم تحديث الصفحة';
                    const btn = document.createElement('button');
                    btn.style.cssText =
                        'margin-top:20px;padding:12px 28px;background:#f58e1a;color:#fff;border:none;border-radius:12px;cursor:pointer;';
                    btn.textContent = '🔄 تحديث';
                    btn.addEventListener('click', function () {
                        location.reload();
                    });
                    wrap.appendChild(h2);
                    wrap.appendChild(p);
                    wrap.appendChild(btn);
                    _mc.appendChild(wrap);
                }
                return;
            }
            renderData(data);
        })
        .catch(e => {
            console.error('[RC] Fetch error:', e);
            const _mc =
                document.querySelector('.main-content') ||
                document.querySelector('main') ||
                document.body;
            if (_mc) {
                // CSP-safe: use createElement instead of innerHTML with onclick
                while (_mc.firstChild) _mc.removeChild(_mc.firstChild);
                const wrap = document.createElement('div');
                wrap.style.cssText =
                    'text-align:center;padding:60px;direction:rtl;font-family:Cairo,sans-serif;';
                const iconDiv = document.createElement('div');
                iconDiv.style.cssText = 'font-size:48px;margin-bottom:16px;';
                iconDiv.textContent = '🔌';
                const h2 = document.createElement('h2');
                h2.style.cssText = 'color:#ef4444;font-size:20px;font-weight:900;';
                h2.textContent = 'تعذّر الاتصال بالخادم';
                const p = document.createElement('p');
                p.style.cssText = 'color:#94a3b8;margin-top:10px;';
                p.textContent = 'تحقق من اتصالك بالإنترنت ثم أعد تحديث الصفحة';
                const btn = document.createElement('button');
                btn.style.cssText =
                    'margin-top:20px;padding:12px 28px;background:#f58e1a;color:#fff;border:none;border-radius:12px;cursor:pointer;';
                btn.textContent = '🔄 تحديث';
                btn.addEventListener('click', function () {
                    location.reload();
                });
                wrap.appendChild(iconDiv);
                wrap.appendChild(h2);
                wrap.appendChild(p);
                wrap.appendChild(btn);
                _mc.appendChild(wrap);
            }
        });

    const renderData = function(data) {
        // ── Error Boundary — العملية 5 ──────────────────────────
        try {
            if (!data || typeof data !== 'object') {
                console.error('[RC] Al-Abeer: بيانات غير صالحة وصلت إلى renderData');
                document.querySelectorAll('[id]').forEach(el => {
                    if (el.textContent.trim() === '' || el.textContent.includes('{{')) {
                        el.textContent = '—';
                    }
                });
                return;
            }
        // ── نهاية validation guard — الكود يستمر داخل try ──────

        // Share data with report-page.js to render platform cards
        window.__reportData = data;
        document.dispatchEvent(new CustomEvent('reportDataReady', { detail: data }));

        // ── كشف فشل الوكلاء — العملية 3+5 ─────────────────────────
        if (data.meta && data.meta.has_failures) {
            const warningDiv = document.createElement('div');
            warningDiv.style.cssText = [
                'background:#fffbeb',
                'border:1px solid #fbbf24',
                'color:#92400e',
                'padding:16px 20px',
                'border-radius:12px',
                'margin:16px 20px',
                'display:flex',
                'align-items:flex-start',
                'gap:12px',
                'direction:rtl',
                'font-family:Cairo,sans-serif',
                'z-index:9999',
            ].join(';');
            const icon = document.createElement('span');
            icon.style.cssText = 'font-size:24px;flex-shrink:0;';
            icon.textContent = '⚠️';
            const msg = document.createElement('div');
            const strong = document.createElement('strong');
            strong.textContent = 'تحذير: ';
            msg.appendChild(strong);
            msg.appendChild(document.createTextNode(data.meta.failure_message || 'فشل بعض أقسام التحليل'));
            warningDiv.appendChild(icon);
            warningDiv.appendChild(msg);
            const mainEl = document.querySelector('main') || document.querySelector('.main-content') || document.querySelector('.container') || document.body;
            mainEl.prepend(warningDiv);
        }

        // كشف فشل صفحات فردية — العملية 3
        ['page_1_report','page_2_scan','page_3_detailed','page_4_core_problem',
         'page_5_identity','page_6_content','page_7_engagement','page_8_journey',
         'page_9_conversion','page_10_competitors','page_11_consistency',
         'page_12_ads','page_13_missed_opportunities','page_14_strengths',
         'page_15_weaknesses','page_16_recommendations','page_17_ads_plan','page_18_roadmap',
        ].forEach(pageKey => {
            const pageData = safeRead(data, pageKey, null);
            if (pageData && typeof pageData === 'object' && pageData.meta && pageData.meta._agent_failed) {
                const container = document.querySelector(`[data-page="${pageKey}"]`) ||
                                  document.querySelector('.page-content') ||
                                  document.querySelector('main');
                if (container) {
                    const alertDiv = document.createElement('div');
                    alertDiv.style.cssText = 'background:rgba(254,242,242,0.15);border:1px solid rgba(252,165,165,0.4);color:#fca5a5;padding:16px;border-radius:12px;margin:16px;text-align:center;direction:rtl;font-family:Cairo,sans-serif;';
                    const h3 = document.createElement('h3');
                    h3.style.marginBottom = '8px';
                    h3.textContent = '⚠️ فشل تحليل هذا القسم';
                    const p = document.createElement('p');
                    p.textContent = pageData.meta._message || 'حاول إعادة التحليل';
                    alertDiv.appendChild(h3);
                    alertDiv.appendChild(p);
                    container.prepend(alertDiv);
                }
            }
        });
        // ─────────────────────────────────────────────────────────

        const sr = data.scan_result || {};
        const srObj = sr; // alias — available to all page blocks inside renderData
        // ✅ إصلاح: عند إدخال رابط فيسبوك/إنستجرام مباشرة، البيانات قد تُخزَّن في sr مباشرة
        // وليس ضمن sr.facebook أو sr.instagram — نتحقق من sr.platform لتحديد المصدر الصحيح
        const fb = sr.facebook || (sr.platform === 'facebook' ? sr : sr.social || {});
        const ig = sr.instagram || (sr.platform === 'instagram' ? sr : sr.social || {});
        const ws = sr.website_scan || {};


        // ── P2-1: الاسم الصحيح (مصدر واحد — DB فقط) ──────────
        const clientName = data.full_name || data.company_name || 'العميل';
        const clientUrl = data.url || '';

        // تحديث Profile Header
        const nameEl = document.querySelector('.profile-info h2');
        const handleEl = document.querySelector('.profile-info p');
        if (nameEl && !path.includes('detailed-analysis.html')) nameEl.textContent = clientName;
        if (handleEl && clientUrl && !path.includes('detailed-analysis.html')) {
            try {
                const u = new URL(clientUrl);
                let handle = u.pathname.replace(/\//g, '') || u.hostname;
                handleEl.textContent = '@' + handle;
            } catch (e) {
                handleEl.textContent = clientUrl;
            }
        }

        // ── تحديث نوع الحساب والمجال (Dynamic Binding) ────────
        const typeEl = document.getElementById('profileAccountType');
        const nicheEl = document.getElementById('profileNiche');

        // ── دمج المصادر: ai_report (التحليل الكامل) + الجذر (الحقول القديمة) ──
        // التقارير القديمة قد تحفظ strengths/weaknesses/recommendations في جذر الـ row
        // بدلاً من ai_report. هنا ندمج الاثنين لضمان عمل جميع التقارير.
        const ai = Object.assign({}, data.ai_report || {}, {
            strengths:
                data.ai_report && data.ai_report.strengths && data.ai_report.strengths.length > 0
                    ? data.ai_report.strengths
                    : data.strengths || [],
            weaknesses:
                data.ai_report && data.ai_report.weaknesses && data.ai_report.weaknesses.length > 0
                    ? data.ai_report.weaknesses
                    : data.weaknesses || [],
            recommendations:
                data.ai_report &&
                data.ai_report.recommendations &&
                data.ai_report.recommendations.length > 0
                    ? data.ai_report.recommendations
                    : data.recommendations || [],
        });
        // ── DEBUG (يمكن حذفه لاحقاً) ──
        console.debug('[RC] ai merged:', {
            str_count: ai.strengths.length,
            weak_count: ai.weaknesses.length,
            rec_count: ai.recommendations.length,
            source_ai_report: !!(
                data.ai_report &&
                data.ai_report.strengths &&
                data.ai_report.strengths.length > 0
            ),
            source_root: !!(data.strengths && data.strengths.length > 0),
            _debug: data._debug,
        });
        if (typeEl) {
            let typeStr = ai.page_type || data.project_type || 'غير محدد من البيانات';
            // ترجمة سريعة للأنواع الشائعة
            const translations = {
                'E-commerce Store': 'متجر إلكتروني 🛒',
                'Business / Service Provider': 'شركة / مزود خدمة 💼',
                'Personal Influencer / Content Creator': 'صانع محتوى / مؤثر ✨',
                'Brand Awareness Page': 'صفحة علامة تجارية 🏷️',
                'Blog / Media Content': 'مدونة / محتوى إعلامي 📝',
            };
            typeEl.textContent = translations[typeStr] || typeStr;
        }
        if (nicheEl) {
            nicheEl.textContent = ai.niche || 'غير محدد من البيانات';
        }

        // ── تحديث الدرجة في جميع الصفحات ─────────────────────
        if (data.score != null) {
            const scoreNum = document.querySelector('.score-num[data-val]');
            if (scoreNum) {
                scoreNum.setAttribute('data-val', data.score);
                scoreNum.textContent = data.score;
            }
            const ring = document.querySelector('.score-circle[data-percent]');
            if (ring) {
                const color =
                    data.score >= 70
                        ? 'var(--green)'
                        : data.score >= 40
                        ? 'var(--yellow)'
                        : 'var(--red)';
                ring.setAttribute('data-percent', data.score);
                ring.setAttribute('data-color', color);
            }
            // تحريك العداد
            animateCounters();
            animateRings();
        }

        // [Old strengths block removed - consolidated below]

        // ==========================================
        // PAGE: result.html (MAIN DASHBOARD)
        // ==========================================
        if (path.includes('result.html') || path.endsWith('/') || path.endsWith('report.html')) { try {
            const score = Number.isFinite(Number(data.score)) ? Number(data.score) : 0;
            // استخدام `ai` الموحد من الـ outer scope (يشمل strengths/weaknesses من الجذر)
            const cj = ai.customer_journey || null;

            // ── 1. Score Status & Competitor Pin ──
            const scoreStatus = document.getElementById('resultScoreStatus');
            const scoreDesc = document.getElementById('resultScoreDesc');
            const compPin = document.getElementById('compPin');

            if (scoreStatus) {
                if (score >= 70) {
                    scoreStatus.textContent = '✅ التقييم: ممتاز';
                    scoreStatus.style.color = 'var(--green)';
                } else if (score >= 50) {
                    scoreStatus.textContent = '😊 التقييم: جيد';
                    scoreStatus.style.color = 'var(--yellow)';
                } else if (score >= 30) {
                    scoreStatus.textContent = '⚠️ التقييم: يحتاج عمل';
                    scoreStatus.style.color = 'var(--yellow)';
                } else {
                    scoreStatus.textContent = '❌ التقييم: ضعيف';
                    scoreStatus.style.color = 'var(--red)';
                }
            }
            if (scoreDesc) {
                if (score >= 70)
                    scoreDesc.innerHTML =
                        'لديك أساس ممتاز ومتقدم. البيانات تُظهر أداءً قوياً في معظم المحاور، والهدف الآن هو <strong>التوسع والمضاعفة</strong>.';
                else if (score >= 40)
                    scoreDesc.innerHTML =
                        'لديك أساس جيد للعمل عليه، لكننا اكتشفنا <strong>عوائق حقيقية</strong> تمنعك من مضاعفة أرباحك.';
                else
                    scoreDesc.innerHTML =
                        'هناك <strong>ثغرات حرجة</strong> في منظومتك التسويقية تستنزف فرصك يومياً. نحتاج تدخلاً فورياً.';
            }
            if (compPin) {
                setTimeout(() => {
                    compPin.style.left = 100 - score + '%';
                }, 600);
            }

            // ── 1.5 Update Axes Grid from breakdown ──
            if (data.breakdown && data.breakdown.length > 0) {
                const axisCards = document.querySelectorAll('.axes-grid .axis-card');
                axisCards.forEach(card => {
                    const axisNameEl = card.querySelector('.axis-name');
                    if (!axisNameEl) return;
                    const name = axisNameEl.textContent.trim();
                    const item = data.breakdown.find(
                        b => b.axis && (b.axis.includes(name) || name.includes(b.axis))
                    );
                    if (item) {
                        const s = item.score || 0;
                        const ring = card.querySelector('.mini-ring');
                        const valSpan = card.querySelector('.mini-val');
                        const statusSpan = card.querySelector('.axis-status');

                        if (ring) {
                            ring.setAttribute('data-percent', s);
                            ring.setAttribute(
                                'data-color',
                                s >= 75 ? 'var(--green)' : s >= 50 ? 'var(--yellow)' : 'var(--red)'
                            );
                        }
                        if (valSpan) valSpan.setAttribute('data-val', s);
                        if (statusSpan) {
                            statusSpan.textContent =
                                s >= 75 ? 'جيد جداً' : s >= 50 ? 'متوسط' : 'ضعيف';
                            statusSpan.className =
                                'axis-status ' +
                                (s >= 75
                                    ? 'status-green'
                                    : s >= 50
                                    ? 'status-yellow'
                                    : 'status-red');
                        }
                    }
                });
            }

            // ── 2. Problem Card من AI أو من الـ breakdown ──
            const problemMain = document.getElementById('resultProblemMain');
            const problemDesc = document.getElementById('resultProblemDesc');
            const priorityBadge = document.getElementById('resultPriorityBadge');

            // استخراج أضعف محور من breakdown
            const breakdown = data.breakdown || [];
            if (breakdown.length > 0 && problemMain) {
                const weakest = breakdown.reduce((a, b) => (a.score < b.score ? a : b));
                problemMain.textContent = 'ضعف في: ' + (weakest.axis || 'التحويل');
                if (problemDesc) {
                    const shortReason =
                        (weakest.reason || '').substring(0, 120) +
                        (weakest.reason && weakest.reason.length > 120 ? '...' : '');
                    problemDesc.textContent =
                        shortReason ||
                        'هذا المحور يحتاج تدخلاً عاجلاً لرفع معدل التحويل وتحقيق النمو.';
                }
                if (priorityBadge) {
                    priorityBadge.textContent =
                        weakest.score < 30
                            ? 'الأولوية: قصوى وعاجلة'
                            : weakest.score < 50
                            ? 'الأولوية: عالية'
                            : 'الأولوية: متوسطة';
                }
            } else if (ai.main_problem && problemMain) {
                problemMain.textContent = ai.main_problem;
                if (problemDesc && ai.main_problem_desc)
                    problemDesc.textContent = ai.main_problem_desc;
            }

            // ── 3. قمع التحويل (Funnel) من customer_journey أو fallback ──
            const fStageData =
                cj && cj.stages
                    ? [
                          { id: 'resultFVal1', stage: 'awareness', stageId: 'resultFStage1' },
                          { id: 'resultFVal2', stage: 'attraction', stageId: 'resultFStage2' },
                          { id: 'resultFVal3', stage: 'trust', stageId: 'resultFStage3' },
                          { id: 'resultFVal4', stage: 'purchase', stageId: 'resultFStage4' },
                          { id: 'resultFVal5', stage: 'loyalty', stageId: 'resultFStage5' },
                      ]
                    : null;

            if (fStageData && cj.stages) {
                // بيانات حقيقية من الذكاء الاصطناعي
                let minScore = 100;
                let bottleneckId = 'resultFVal3';
                fStageData.forEach(f => {
                    const stageData = cj.stages[f.stage];
                    if (!stageData) return;
                    const val = stageData.score;
                    const el = document.getElementById(f.id);
                    const stageEl = document.getElementById(f.stageId);
                    if (el) el.textContent = val + '%';
                    if (stageEl) {
                        stageEl.style.width = Math.max(30, val) + '%';
                    }
                    if (val < minScore) {
                        minScore = val;
                        bottleneckId = f.id;
                    }
                });
                // أظهر نقطة الاختناق
                const bottleneckBadge = document.getElementById('resultBottleneckBadge');
                if (bottleneckBadge) bottleneckBadge.style.display = 'inline';
                // تحديث الـ funnel desc من AI
                const fDesc = document.getElementById('resultFunnelDesc');
                if (fDesc && cj.bottleneck_stage) {
                    const stageNames = {
                        awareness: 'الوعي',
                        attraction: 'الجذب',
                        trust: 'الثقة',
                        purchase: 'الشراء',
                        loyalty: 'الولاء',
                    };
                    fDesc.innerHTML = `يتوقف معظم العملاء في <strong>مرحلة ${escapeHtml(
                        stageNames[cj.bottleneck_stage] || cj.bottleneck_stage || ''
                    )}</strong> — ${escapeHtml(
                        cj.bottleneck_fix || 'تحتاج لمعالجة هذه المرحلة بشكل عاجل.'
                    )}<div class="fix-box" id="resultFixBox"><div class="fix-box-title">كيف نحل هذه العقدة؟</div><ul id="resultFixList">${
                        (cj.fix_steps || [])
                            .map(s => `<li><span style="color:var(--green);">✔</span> ${escapeHtml(s)}</li>`)
                            .join('') ||
                        '<li><span style="color:var(--green);">✔</span> راجع تقرير رحلة العميل للتفاصيل الكاملة</li>'
                    }</ul></div>`;
                }
            } else {
                // No AI funnel data → reset all stages to 0%/— and show honest message.
                // Do NOT fabricate values from the overall score (would mislead the client).
                ['resultFVal1', 'resultFVal2', 'resultFVal3', 'resultFVal4', 'resultFVal5'].forEach(id => {
                    const el = document.getElementById(id);
                    if (el) el.textContent = '—';
                });
                ['resultFStage1', 'resultFStage2', 'resultFStage3', 'resultFStage4', 'resultFStage5'].forEach(id => {
                    const stageEl = document.getElementById(id);
                    if (stageEl) stageEl.style.width = '0%';
                });

                const badge = document.getElementById('resultBottleneckBadge');
                if (badge) badge.style.display = 'none';

                const fDesc = document.getElementById('resultFunnelDesc');
                if (fDesc) {
                    fDesc.textContent = 'لم يرجع AI تحليل قمع التحويل لهذا الحساب.';
                }
            }

            // ── 4. Mini Strengths & Weaknesses (عناوين فقط من نفس البيانات) ──
            // بناء بـ createElement — آمن مع CSP بدون innerHTML
            const resultStrList = document.getElementById('resultStrengthsList');
            const resultWksList = document.getElementById('resultWeaknessesList');

            if (resultStrList && ai.strengths && ai.strengths.length > 0) {
                while (resultStrList.firstChild)
                    resultStrList.removeChild(resultStrList.firstChild);
                ai.strengths.forEach(s => {
                    const isObj = s && typeof s === 'object';
                    const title = isObj
                        ? s.title || s.name || s.point || extractText(s, 'نقطة قوة')
                        : extractText(s, 'نقطة قوة');
                    const li = document.createElement('li');
                    const checkSpan = document.createElement('span');
                    checkSpan.style.color = 'var(--green)';
                    checkSpan.textContent = '✔';
                    li.appendChild(checkSpan);
                    li.appendChild(document.createTextNode(' ' + title));
                    resultStrList.appendChild(li);
                });
            }

            if (resultWksList && ai.weaknesses && ai.weaknesses.length > 0) {
                while (resultWksList.firstChild)
                    resultWksList.removeChild(resultWksList.firstChild);
                ai.weaknesses.forEach(w => {
                    const isObj = w && typeof w === 'object';
                    const title = isObj
                        ? w.title || w.name || w.point || extractText(w, 'نقطة ضعف')
                        : extractText(w, 'نقطة ضعف');
                    const li = document.createElement('li');
                    const xSpan = document.createElement('span');
                    xSpan.style.color = 'var(--red)';
                    xSpan.textContent = '✖';
                    li.appendChild(xSpan);
                    li.appendChild(document.createTextNode(' ' + title));
                    resultWksList.appendChild(li);
                });
            }

            // ── 5. Quick Checks الأربعة ──────────────────────────────────
            const ca = ai.content_analysis || {};
            const brandVal = ca.bar_brand || ca.bar_visual || 0;
            const visualVal = ca.bar_visual || ca.bar_brand || 0;

            // — الحساب مرتب —
            const orgEl = document.getElementById('strCheckOrg');
            const orgValEl = document.getElementById('strCheckOrgVal');
            if (orgEl && orgValEl) {
                const orgScore = brandVal || score;
                if (orgScore >= 70) {
                    orgEl.textContent = '✅';
                    orgValEl.textContent = 'نعم، مرتب بشكل احترافي';
                    orgValEl.style.color = 'var(--green)';
                } else if (orgScore >= 45) {
                    orgEl.textContent = '⚠️';
                    orgValEl.textContent = 'مقبول — يحتاج تنظيماً';
                    orgValEl.style.color = 'var(--yellow)';
                } else {
                    orgEl.textContent = '❌';
                    orgValEl.textContent = 'يحتاج إعادة ترتيب كاملة';
                    orgValEl.style.color = 'var(--red)';
                }
            }

            // — الهوية جيدة —
            const identEl = document.getElementById('strCheckIdent');
            const identValEl = document.getElementById('strCheckIdentVal');
            if (identEl && identValEl) {
                const identScore = visualVal || score;
                if (identScore >= 70) {
                    identEl.textContent = '✅';
                    identValEl.textContent = 'جيدة ومتماسكة';
                    identValEl.style.color = 'var(--green)';
                } else if (identScore >= 45) {
                    identEl.textContent = '⚠️';
                    identValEl.textContent = 'مقبولة — تحتاج تطوير';
                    identValEl.style.color = 'var(--yellow)';
                } else {
                    identEl.textContent = '❌';
                    identValEl.textContent = 'هوية غير واضحة';
                    identValEl.style.color = 'var(--red)';
                }
            }

            // — الجمهور مناسب —
            const audEl = document.getElementById('strCheckAud');
            const audValEl = document.getElementById('strCheckAudVal');
            if (audEl && audValEl) {
                const niche = ai.niche || '';
                if (niche) {
                    audEl.textContent = '✅';
                    audValEl.textContent = `مناسب — يستهدف: ${sanitize(niche)}`;
                    audValEl.style.color = 'var(--green)';
                } else if (score >= 50) {
                    audEl.textContent = '⚠️';
                    audValEl.textContent = 'يحتاج تدقيقاً في الاستهداف';
                    audValEl.style.color = 'var(--yellow)';
                } else {
                    audEl.textContent = '❌';
                    audValEl.textContent = 'الاستهداف يحتاج مراجعة كاملة';
                    audValEl.style.color = 'var(--red)';
                }
            }

            // — الجملة التحفيزية —
            const motivEl = document.getElementById('strMotivation');
            const motivTextEl = document.getElementById('strMotivationText');
            if (motivEl && motivTextEl) {
                const tier = (ai.tier || ai.ai_tier || '').toLowerCase();
                let motivMsg = '';
                let motivColor = 'var(--primary)';
                let motivBg = 'rgba(245,142,26,0.08)';
                let motivBorder = 'rgba(245,142,26,0.2)';
                if (score >= 70 || tier === 'green') {
                    motivMsg = 'لديك فرصة نمو ممتازة — البيانات تؤكدها 🚀';
                    motivColor = 'var(--green)';
                    motivBg = 'rgba(16,185,129,0.08)';
                    motivBorder = 'rgba(16,185,129,0.2)';
                } else if (score >= 45 || tier === 'yellow') {
                    motivMsg = 'فرصة نمو حقيقية تنتظر التفعيل — لا تدعها تفوتك 💡';
                    motivColor = 'var(--yellow)';
                    motivBg = 'rgba(234,179,8,0.08)';
                    motivBorder = 'rgba(234,179,8,0.2)';
                } else {
                    motivMsg = 'كل تحدٍّ هو فرصة — نقطة البداية الصحيحة تصنع الفارق ⚡';
                    motivColor = 'var(--primary)';
                    motivBg = 'rgba(245,142,26,0.08)';
                    motivBorder = 'rgba(245,142,26,0.2)';
                }
                motivTextEl.textContent = motivMsg;
                motivEl.style.color = motivColor;
                motivEl.style.background = motivBg;
                motivEl.style.borderColor = motivBorder;
                motivEl.style.display = 'block';
            }

            // ── 6. Weakness Quick Checks (5 مؤشرات ديناميكية) ──
            const set = (iconId, valId, icon, text, color) => {
                const ic = document.getElementById(iconId);
                const vl = document.getElementById(valId);
                if (ic) ic.textContent = icon;
                if (vl) {
                    vl.textContent = text;
                    vl.style.color = color;
                }
            };

            const sr2 = data.scan_result || srObj || {};
            const ca2 = ai.content_analysis || {};

            // — CTA —
            const hasCTA = sr2.hasCTA || sr2.has_cta || (ca2.bar_cta && ca2.bar_cta >= 50);
            if (hasCTA) set('wkCheckCTA', 'wkCheckCTAVal', '✅', 'واضح ومحدد', 'var(--green)');
            else
                set(
                    'wkCheckCTA',
                    'wkCheckCTAVal',
                    '❌',
                    'غير واضح — يخسرك عملاء في مرحلة الشراء',
                    'var(--red)'
                );

            // — المحتوى (مبيعاتي مقابل تثقيفي) —
            const salesHeavy = (ca2.bar_brand && ca2.bar_brand < 50) || score < 45;
            if (!salesHeavy)
                set(
                    'wkCheckContent',
                    'wkCheckContentVal',
                    '✅',
                    'متوازن بين المحتوى والمبيعات',
                    'var(--green)'
                );
            else
                set(
                    'wkCheckContent',
                    'wkCheckContentVal',
                    '⚠️',
                    'محتوى البيع أكثر من اللازم — يبيع قبل أن يبني ثقة',
                    'var(--yellow)'
                );

            // — الإعلانات —
            const hasAds =
                sr2.hasAds ||
                sr2.has_ads ||
                sr2.activeAds ||
                (ai.ads_analysis && ai.ads_analysis.has_active_ads);
            const adsNeedFix = hasAds && score < 55;
            if (!hasAds)
                set(
                    'wkCheckAds',
                    'wkCheckAdsVal',
                    '⚠️',
                    'لا توجد إعلانات نشطة مكتشفة',
                    'var(--yellow)'
                );
            else if (adsNeedFix)
                set(
                    'wkCheckAds',
                    'wkCheckAdsVal',
                    '⚠️',
                    'الإعلان موجود لكن يحتاج تعديل في الاستهداف',
                    'var(--yellow)'
                );
            else
                set('wkCheckAds', 'wkCheckAdsVal', '✅', 'الإعلانات تعمل بشكل جيد', 'var(--green)');

            // — التتبع التقني (Pixel / Analytics) —
            const hasPixel = sr2.hasPixel || sr2.has_pixel || sr2.hasFbPixel;
            const hasGA = sr2.hasGA || sr2.has_ga || sr2.hasAnalytics;
            if (hasPixel && hasGA)
                set(
                    'wkCheckPixel',
                    'wkCheckPixelVal',
                    '✅',
                    'Pixel + Analytics مفعّلان',
                    'var(--green)'
                );
            else if (hasPixel || hasGA)
                set(
                    'wkCheckPixel',
                    'wkCheckPixelVal',
                    '⚠️',
                    `${hasPixel ? 'Pixel' : 'Analytics'} مفعّل — الآخر مفقود`,
                    'var(--yellow)'
                );
            else
                set(
                    'wkCheckPixel',
                    'wkCheckPixelVal',
                    '❌',
                    'Pixel و Analytics غير مربوطَين — بيانات الحملات تضيع',
                    'var(--red)'
                );

            // — التفاعل —
            const engRate = sr2.engagementRate || sr2.engagement_rate || sr2.avg_engagement || 0;
            const igFol = sr2.instagramFollowers || sr2.ig_followers || 0;
            if (engRate >= 3)
                set(
                    'wkCheckEng',
                    'wkCheckEngVal',
                    '✅',
                    `معدل تفاعل ${engRate}% — ممتاز`,
                    'var(--green)'
                );
            else if (engRate >= 1)
                set(
                    'wkCheckEng',
                    'wkCheckEngVal',
                    '⚠️',
                    `معدل تفاعل ${engRate}% — أقل من المتوسط (3%)`,
                    'var(--yellow)'
                );
            else if (igFol > 0)
                set(
                    'wkCheckEng',
                    'wkCheckEngVal',
                    '❌',
                    'تفاعل منخفض — يضر بالظهور العضوي',
                    'var(--red)'
                );
            else
                set(
                    'wkCheckEng',
                    'wkCheckEngVal',
                    '⚠️',
                    'لا توجد بيانات تفاعل كافية للتحليل',
                    'var(--yellow)'
                );

            // ── 7. Top Recommendations Preview (الباقة المجانية: 3 توصيات بالضبط) ──
            const resultRecsList = document.getElementById('resultRecsList');
            const recs = data.recommendations || [];
            const recViewAllEl = document.getElementById('recViewAll');
            const curId = new URLSearchParams(window.location.search).get('id') || '';
            if (recViewAllEl) recViewAllEl.href = 'recommendations.html?id=' + curId;

            if (resultRecsList && recs.length > 0) {
                const highRecs = recs.filter(
                    r => r.priority === 'critical' || r.priority === 'high'
                );
                const preview = (highRecs.length >= 3 ? highRecs : recs).slice(0, 3);
                const priColor = p =>
                    p === 'critical' || p === 'high'
                        ? 'var(--red)'
                        : p === 'low'
                        ? 'var(--green)'
                        : 'var(--yellow)';
                const priRgb = p =>
                    p === 'critical' || p === 'high'
                        ? '239,68,68'
                        : p === 'low'
                        ? '16,185,129'
                        : '234,179,8';
                const priLabel = p =>
                    p === 'critical'
                        ? 'حرجة'
                        : p === 'high'
                        ? 'عاجل'
                        : p === 'low'
                        ? 'مستقبلي'
                        : 'متوسط';
                resultRecsList.innerHTML =
                    preview
                        .map(rec => {
                            const p = rec.priority || 'medium';
                            const ac = priColor(p);
                            const ar = priRgb(p);
                            const icon =
                                rec.icon ||
                                (p === 'critical'
                                    ? '🛑'
                                    : p === 'high'
                                    ? '🛡️'
                                    : p === 'low'
                                    ? '🤝'
                                    : '✍️');
                            const step1 =
                                rec.bullets && rec.bullets[0]
                                    ? `<div style="margin-top:8px;font-size:12px;color:var(--text-gray);font-weight:700;">▶ ${sanitize(
                                          rec.bullets[0]
                                      )}</div>`
                                    : '';
                            const whyNow = rec.why_now
                                ? `<div style="font-size:11px;font-weight:800;color:${ac};margin-top:6px;">⚡ ${sanitize(
                                      rec.why_now
                                  )}</div>`
                                : '';
                            const roiBox = rec.roi
                                ? `<div style="margin-top:10px;padding:6px 12px;background:rgba(${ar},0.08);border:1px solid rgba(${ar},0.2);border-radius:8px;font-size:12px;font-weight:800;color:${ac};">💰 ${sanitize(
                                      rec.roi
                                  )}</div>`
                                : '';
                            return `<div style="background:rgba(${ar},0.04);border:1px solid rgba(${ar},0.2);border-radius:16px;padding:20px;position:relative;overflow:hidden;">
              <div style="position:absolute;top:0;right:0;background:${ac};color:${
                                p === 'medium' ? '#000' : '#fff'
                            };font-size:10px;font-weight:900;padding:3px 12px;border-radius:0 16px 0 10px;">${priLabel(
                                p
                            )}${p === 'critical' ? ' ⚠️' : ''}</div>
              <div style="display:flex;align-items:flex-start;gap:14px;margin-top:8px;">
                <div style="font-size:22px;flex-shrink:0;">${icon}</div>
                <div style="flex:1;">
                  <h4 style="font-size:15px;font-weight:900;color:${ac};margin-bottom:4px;">${sanitize(
                                rec.title || 'توصية'
                            )}</h4>
                  <p style="font-size:13px;color:var(--text-gray);font-weight:600;line-height:1.6;">${sanitize(
                      (rec.desc || '').substring(0, 100)
                  )}${(rec.desc || '').length > 100 ? '...' : ''}</p>
                  ${whyNow}${step1}${roiBox}
                </div>
              </div>
            </div>`;
                        })
                        .join('') +
                    `<div style="text-align:center;margin-top:4px;"><a href="recommendations.html?id=${curId}" style="font-size:13px;font-weight:800;color:var(--primary);text-decoration:none;">← عرض كل التوصيات التفصيلية (${recs.length} إجراء)</a></div>`;
            }
        } catch (__err_result_html) {
            console.error("[RC] Phase 4.4: تعطل قسم result.html:", __err_result_html);
        }
        }

        // ==========================================
        // PAGE: report.html — Viral Growth Engine Teaser Card
        // ==========================================
        if (path.includes('report.html')) { try {
            const viralCard = document.getElementById('viralTeaserCard');
            const teaserLink = document.getElementById('viralTeaserLink');

            // Update link to preserve ?id= parameter
            if (teaserLink) teaserLink.href = 'content.html' + (curId ? '?id=' + curId : '');

            // Hook teaser — show first hook formula
            const hooks = Array.isArray(ai.hook_bank) ? ai.hook_bank : [];
            const teaserHook = document.getElementById('viralTeaserHook');
            if (hooks.length > 0 && teaserHook) {
                const firstHook = hooks[0];
                teaserHook.textContent = firstHook.example || firstHook.formula || '—';
            }

            // Gap teaser — from viral_deconstruction
            const vd = ai.viral_deconstruction || null;
            const teaserGap = document.getElementById('viralTeaserGap');
            if (vd && teaserGap) {
                const gapText = (vd.gap_extracted || '').substring(0, 120);
                teaserGap.textContent = gapText + (vd.gap_extracted && vd.gap_extracted.length > 120 ? '...' : '');
            }

            // Pillars teaser — show pillar names with percentages
            const pillars = Array.isArray(ai.content_pillars_matrix) ? ai.content_pillars_matrix : [];
            const teaserPillars = document.getElementById('viralTeaserPillars');
            const pillarBarColors = ['#10b981', '#8b5cf6', '#f58e1a'];
            if (pillars.length > 0 && teaserPillars) {
                teaserPillars.innerHTML = pillars.map((p, i) => `
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
                      <div style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:800;color:var(--text-dark);">
                        <div style="width:8px;height:8px;border-radius:50%;background:${pillarBarColors[i % 3]};flex-shrink:0;"></div>
                        ${sanitize(p.pillar || '')}
                      </div>
                      <span style="font-size:13px;font-weight:900;color:${pillarBarColors[i % 3]};">${p.percentage || 0}%</span>
                    </div>`
                ).join('');
            }

            // Show card only if we have viral data
            if (viralCard && (hooks.length > 0 || vd || pillars.length > 0)) {
                viralCard.style.display = '';
            }
        } catch (__err_report_html) {
            console.error("[RC] Phase 4.4: تعطل قسم report.html:", __err_report_html);
        }
        }

        // ==========================================

        // ==========================================
        // PAGE: detailed-analysis.html (ULTIMATE AUDIT)
        // ==========================================
        if (path.includes('detailed-analysis.html')) { try {
            const daName = document.getElementById('auditClientName');
            if (daName) daName.textContent = clientName;

            // Extract URLs safely from both sources (DB leads or Scan discovery)
            // srObj already defined at top of renderData
            const websiteUrl =
                data.website_url ||
                (srObj.website_scan ? srObj.website_scan.final_url : '') ||
                srObj.website ||
                '';
            const instagramUrl =
                data.instagram_url || (srObj.instagram ? srObj.instagram.url : '') || '';
            const facebookUrl =
                data.facebook_url || (srObj.facebook ? srObj.facebook.url : '') || '';

            // --- 1. Website Audit ---
            const urlW = document.getElementById('auditWebUrl');
            if (urlW)
                urlW.innerHTML = websiteUrl
                    ? `<a href="${safeUrl(websiteUrl)}" target="_blank" rel="noopener noreferrer" style="color:var(--primary);text-decoration:none;">${escapeHtml(websiteUrl)}</a>`
                    : 'لم يتم العثور على موقع إلكتروني';
            const _gw = document.getElementById('gridWebsite');
            if (!websiteUrl && _gw) _gw.classList.add('card-disabled');

            const awSSL = document.getElementById('awSSL');
            if (awSSL) {
                awSSL.textContent = srObj.hasSSL ? 'مفعل' : 'مفقود';
                awSSL.className = srObj.hasSSL ? 'si-badge badge-ok' : 'si-badge badge-warn';
            }
            const awPixel = document.getElementById('awPixel');
            if (awPixel) {
                awPixel.textContent = srObj.hasPixel ? 'مفعل' : 'غير مركب';
                awPixel.className = srObj.hasPixel ? 'si-badge badge-ok' : 'si-badge badge-warn';
            }
            const awGA = document.getElementById('awGA');
            if (awGA) {
                awGA.textContent = srObj.hasGA ? 'مفعل' : 'مفقود';
                awGA.className = srObj.hasGA ? 'si-badge badge-ok' : 'si-badge badge-warn';
            }
            const awWA = document.getElementById('awWA');
            if (awWA) {
                awWA.textContent = srObj.hasWhatsApp ? 'مفعل' : 'مفقود';
                awWA.className = srObj.hasWhatsApp ? 'si-badge badge-ok' : 'si-badge badge-warn';
            }

            // --- 2. Instagram Audit ---
            const urlI = document.getElementById('auditIgUrl');
            if (urlI)
                urlI.innerHTML = instagramUrl
                    ? `<a href="${safeUrl(instagramUrl)}" target="_blank" rel="noopener noreferrer" style="color:var(--primary);text-decoration:none;">${escapeHtml(instagramUrl)}</a>`
                    : 'لم يتم العثور على حساب';
            const _gi = document.getElementById('gridInstagram');
            if (!instagramUrl && _gi) _gi.classList.add('card-disabled');

            const igData = srObj.instagram || {};
            // Instagram Followers - المزيد من التفقد للحالات المختلفة
            if (document.getElementById('igFollowers')) {
                const el = document.getElementById('igFollowers');
                if (
                    igData.followers !== undefined &&
                    igData.followers !== null &&
                    igData.followers !== ''
                ) {
                    el.textContent = parseInt(igData.followers).toLocaleString();
                } else {
                    el.textContent = '--';
                }
            }
            if (document.getElementById('badge-ig-er')) {
                const er =
                    igData.engagement_rate !== undefined && igData.engagement_rate !== null
                        ? parseFloat(igData.engagement_rate)
                        : null;
                const el = document.getElementById('badge-ig-er');
                if (er !== null && !isNaN(er)) {
                    el.textContent = er.toFixed(2) + '%';
                    el.className = er >= 2 ? 'si-badge badge-ok' : 'si-badge badge-warn';
                } else {
                    el.textContent = '--';
                    el.className = 'si-badge badge-warn';
                }
            }

            // Dynamically update other badges from scan_result (srObj)
            const updateDetailedBadges = sr => {
                const updateBadge = (id, condition, okText, warnText) => {
                    const badge = document.getElementById(id);
                    if (!badge) return;
                    if (condition === undefined || condition === null || condition === '') {
                        badge.className = 'si-badge badge-warn data-missing';
                        // Keep original textContent as fallback, no need to reassign
                    } else {
                        badge.classList.remove('data-missing');
                        badge.textContent = condition ? okText : warnText;
                        badge.className = condition ? 'si-badge badge-ok' : 'si-badge badge-warn';
                    }
                };

                // TikTok Pixel
                const hasTTPixel =
                    sr.hasTikTokPixel !== undefined ? sr.hasTikTokPixel : sr.tiktok_pixel;
                updateBadge('badge-tiktok-pixel', hasTTPixel, 'مفعل', 'غير مركب');

                // Pagespeed
                const speedBadge = document.getElementById('badge-pagespeed');
                if (speedBadge) {
                    const speed = sr.load_time || sr.pagespeed || sr.performance_score;
                    if (speed === undefined || speed === null || speed === '') {
                        speedBadge.classList.add('data-missing');
                        speedBadge.className = 'si-badge badge-warn data-missing';
                    } else {
                        speedBadge.classList.remove('data-missing');
                        const sNum = parseFloat(speed);
                        speedBadge.textContent = !isNaN(sNum)
                            ? sNum + (sNum > 10 ? ' / 100' : ' ثانية')
                            : speed;
                        speedBadge.className =
                            !isNaN(sNum) && (sNum < 3 || sNum > 80)
                                ? 'si-badge badge-ok'
                                : 'si-badge badge-warn';
                    }
                }

                // IG Badges
                const ig = sr.instagram || {};
                updateBadge(
                    'badge-ig-avatar',
                    ig.profile_pic_url || ig.has_avatar !== false,
                    'احترافية',
                    'مفقود'
                );
                const hasBio = ig.biography
                    ? ig.biography.length > 10
                    : ig.has_bio !== undefined
                    ? ig.has_bio
                    : undefined;
                updateBadge('badge-ig-value', hasBio, 'واضح', 'غير واضح/قصير');
                const hasCta = ig.has_cta_button !== undefined ? ig.has_cta_button : undefined;
                updateBadge('badge-ig-cta', hasCta, 'موجود', 'مفقود');
                updateBadge('badge-ig-link', ig.external_url || ig.website, 'موجود', 'مفقود');
            };
            updateDetailedBadges(srObj);
            // Instagram Likes
            if (document.getElementById('igLikes')) {
                const el = document.getElementById('igLikes');
                if (
                    igData.avg_likes !== undefined &&
                    igData.avg_likes !== null &&
                    igData.avg_likes !== ''
                ) {
                    el.textContent = parseInt(igData.avg_likes).toLocaleString();
                } else {
                    el.textContent = '--';
                }
            }

            // --- 3. Facebook Audit ---
            const urlF = document.getElementById('auditFbUrl');
            if (urlF)
                urlF.innerHTML = facebookUrl
                    ? `<a href="${safeUrl(facebookUrl)}" target="_blank" rel="noopener noreferrer" style="color:var(--primary);text-decoration:none;">${escapeHtml(facebookUrl)}</a>`
                    : 'لم يتم العثور على حساب';
            const _gf = document.getElementById('gridFacebook');
            if (!facebookUrl && _gf) _gf.classList.add('card-disabled');

            const adsObj = srObj.ads_library || {};
            if (document.getElementById('fbAdsCount')) {
                document.getElementById('fbAdsCount').textContent =
                    adsObj.total_ads !== undefined ? adsObj.total_ads : '0';
            }

            // --- 3.5 TikTok Audit ---
            const tiktokUrl = data.tiktok_url || (srObj.tiktok ? srObj.tiktok.url : '') || '';
            const urlTT = document.getElementById('auditTikTokUrl');
            if (urlTT)
                urlTT.innerHTML = tiktokUrl
                    ? `<a href="${safeUrl(tiktokUrl)}" target="_blank" rel="noopener noreferrer" style="color:var(--primary);text-decoration:none;">${escapeHtml(tiktokUrl)}</a>`
                    : 'لم يتم العثور على حساب';
            const _gtt = document.getElementById('gridTikTok');
            if (!tiktokUrl && _gtt) _gtt.classList.add('card-disabled');

            const ttData = srObj.tiktok || {};
            // Followers
            if (document.getElementById('ttFollowers')) {
                const el = document.getElementById('ttFollowers');
                el.textContent = ttData.followers
                    ? parseInt(ttData.followers).toLocaleString()
                    : '--';
            }
            // Likes (tt-likes في HTML)
            if (document.getElementById('tt-likes')) {
                const el = document.getElementById('tt-likes');
                el.textContent = ttData.likes ? parseInt(ttData.likes).toLocaleString() : '--';
            }
            // Following (tt-following في HTML)
            if (document.getElementById('tt-following')) {
                const el = document.getElementById('tt-following');
                el.textContent = ttData.following
                    ? parseInt(ttData.following).toLocaleString()
                    : '--';
            }
            // Total Likes
            if (document.getElementById('tt-likes')) {
                const el = document.getElementById('tt-likes');
                el.textContent = ttData.likes ? parseInt(ttData.likes).toLocaleString() : '--';
            }
            // Videos
            if (document.getElementById('ttVideos')) {
                const el = document.getElementById('ttVideos');
                if (ttData.video_count) {
                    el.textContent = ttData.video_count + ' فيديو';
                    el.className =
                        parseInt(ttData.video_count) > 20
                            ? 'si-badge badge-ok'
                            : 'si-badge badge-warn';
                } else {
                    el.textContent = '--';
                }
            }
            // Average Likes
            if (document.getElementById('ttAvgLikes')) {
                const el = document.getElementById('ttAvgLikes');
                if (ttData.avg_likes) {
                    el.textContent = parseFloat(ttData.avg_likes).toLocaleString();
                    el.className = 'si-badge badge-ok';
                } else {
                    el.textContent = '--';
                }
            }
            // Average Comments
            if (document.getElementById('ttAvgComments')) {
                const el = document.getElementById('ttAvgComments');
                if (ttData.avg_comments) {
                    el.textContent = parseFloat(ttData.avg_comments).toFixed(1);
                    el.className = 'si-badge badge-ok';
                } else {
                    el.textContent = '--';
                }
            }
            // Engagement Rate
            if (document.getElementById('ttEngagement')) {
                const el = document.getElementById('ttEngagement');
                if (ttData.engagement_rate) {
                    el.textContent = parseFloat(ttData.engagement_rate).toFixed(2) + '%';
                    el.className =
                        parseFloat(ttData.engagement_rate) >= 3
                            ? 'si-badge badge-ok'
                            : 'si-badge badge-warn';
                } else {
                    el.textContent = '--';
                }
            }
            // Bio
            if (document.getElementById('ttBio')) {
                const el = document.getElementById('ttBio');
                const hasBio = ttData.bio && ttData.bio.length > 10;
                el.textContent = hasBio ? 'مكتمل (' + ttData.bio.length + ' حرف)' : 'قصير/فارغ';
                el.className = hasBio ? 'si-badge badge-ok' : 'si-badge badge-warn';
            }
            // Verified
            if (document.getElementById('ttVerified')) {
                const el = document.getElementById('ttVerified');
                el.textContent = ttData.is_verified ? 'موثق ✅' : 'غير موثق';
                el.className = ttData.is_verified ? 'si-badge badge-ok' : 'si-badge badge-warn';
            }
            // Website
            if (document.getElementById('ttWebsite')) {
                const el = document.getElementById('ttWebsite');
                el.textContent = ttData.website ? 'موجود' : 'مفقود';
                el.className = ttData.website ? 'si-badge badge-ok' : 'si-badge badge-warn';
            }
            // Avatar
            if (document.getElementById('ttAvatar')) {
                const el = document.getElementById('ttAvatar');
                el.textContent = ttData.avatar ? 'موجودة' : 'مفقودة';
                el.className = ttData.avatar ? 'si-badge badge-ok' : 'si-badge badge-warn';
            }
            // TikTok Score
            if (document.getElementById('tt-score')) {
                const ttFollowers = ttData.followers || 0;
                const ttER = ttData.engagement_rate || 0;
                const ttVideos = ttData.video_count || 0;
                let ttScore = Math.min(
                    100,
                    Math.round(
                        (ttFollowers > 10000
                            ? 30
                            : ttFollowers > 1000
                            ? 20
                            : ttFollowers > 100
                            ? 10
                            : 5) +
                            (ttER >= 3 ? 30 : ttER >= 1 ? 20 : ttER >= 0.5 ? 10 : 5) +
                            (ttVideos >= 30 ? 20 : ttVideos >= 10 ? 15 : ttVideos >= 5 ? 10 : 5) +
                            (ttData.is_verified ? 10 : 0) +
                            (ttData.website ? 10 : 0)
                    )
                );
                document.getElementById('tt-score').textContent = ttScore;
                const progTT = document.getElementById('prog-tt');
                if (progTT) {
                    progTT.style.width = ttScore + '%';
                    progTT.className =
                        'progress-fill ' +
                        (ttScore >= 70 ? 'green' : ttScore >= 40 ? 'yellow' : 'red');
                }
            }

            // --- 3.6 Twitter/X Audit ---
            const twitterUrl = data.twitter_url || (srObj.twitter ? srObj.twitter.url : '') || '';
            const urlTW = document.getElementById('auditTwitterUrl');
            if (urlTW)
                urlTW.innerHTML = twitterUrl
                    ? `<a href="${safeUrl(twitterUrl)}" target="_blank" rel="noopener noreferrer" style="color:var(--primary);text-decoration:none;">${escapeHtml(twitterUrl)}</a>`
                    : 'لم يتم العثور على حساب';
            const _gtw = document.getElementById('gridTwitter');
            if (!twitterUrl && _gtw) _gtw.classList.add('card-disabled');

            const twData = srObj.twitter || {};
            // Followers
            if (document.getElementById('twFollowers')) {
                const el = document.getElementById('twFollowers');
                el.textContent = twData.followers
                    ? parseInt(twData.followers).toLocaleString()
                    : '--';
            }
            // Following (tw-following في HTML)
            if (document.getElementById('tw-following')) {
                const el = document.getElementById('tw-following');
                el.textContent = twData.following
                    ? parseInt(twData.following).toLocaleString()
                    : '--';
            }
            // Ratio (tw-ratio في HTML)
            if (document.getElementById('tw-ratio')) {
                const el = document.getElementById('tw-ratio');
                const f = twData.followers || 0;
                const fg = twData.following || 1;
                const ratio = fg > 0 ? Math.round(f / fg) : 0;
                el.textContent = ratio > 1 ? ratio + ':1' : '--';
                el.style.color =
                    ratio >= 10 ? 'var(--green)' : ratio >= 3 ? 'var(--yellow)' : 'var(--red)';
            }
            // Tweets count
            if (document.getElementById('twTweets')) {
                const el = document.getElementById('twTweets');
                if (twData.posts_count) {
                    el.textContent = twData.posts_count + ' تغريدة';
                    el.className =
                        parseInt(twData.posts_count) > 100
                            ? 'si-badge badge-ok'
                            : 'si-badge badge-warn';
                } else {
                    el.textContent = '--';
                }
            }
            // Bio
            if (document.getElementById('twBio')) {
                const el = document.getElementById('twBio');
                const bioText = twData.description || twData.bio || '';
                const hasBio = bioText.length > 5;
                el.textContent = hasBio ? 'مكتمل' : 'قصير/فارغ';
                el.className = hasBio ? 'si-badge badge-ok' : 'si-badge badge-warn';
            }
            // Location
            if (document.getElementById('twLocation')) {
                const el = document.getElementById('twLocation');
                el.textContent = twData.location ? twData.location : 'غير محدد';
                el.className = twData.location ? 'si-badge badge-ok' : 'si-badge badge-warn';
            }
            // Created date
            if (document.getElementById('twCreated')) {
                const el = document.getElementById('twCreated');
                el.textContent = twData.created_at ? twData.created_at : 'غير متوفر';
                el.className = 'si-badge badge-ok';
            }
            // Verified
            if (document.getElementById('twVerified')) {
                const el = document.getElementById('twVerified');
                el.textContent = twData.is_verified ? 'موثق ✅' : 'غير موثق';
                el.className = twData.is_verified ? 'si-badge badge-ok' : 'si-badge badge-warn';
            }
            // Media & Link Pct
            if (document.getElementById('twMediaPct')) {
                const mp = twData.media_percent || 0;
                document.getElementById('twMediaPct').textContent = mp > 0 ? mp + '%' : '--';
            }
            if (document.getElementById('twLinkPct')) {
                const lp = twData.link_percent || 0;
                document.getElementById('twLinkPct').textContent = lp > 0 ? lp + '%' : '--';
            }
            // Twitter Score
            if (document.getElementById('tw-score')) {
                const twFollowers = twData.followers || 0;
                const twPosts = twData.posts_count || 0;
                const bioText = twData.description || twData.bio || '';
                let twScore = Math.min(
                    100,
                    Math.round(
                        (twFollowers > 5000
                            ? 30
                            : twFollowers > 500
                            ? 20
                            : twFollowers > 50
                            ? 10
                            : 5) +
                            (twPosts >= 500 ? 25 : twPosts >= 100 ? 15 : twPosts >= 20 ? 10 : 5) +
                            (twData.is_verified ? 15 : 0) +
                            (bioText.length > 10 ? 15 : 5) +
                            (twData.location ? 5 : 0) +
                            (twData.website ? 10 : 0)
                    )
                );
                document.getElementById('tw-score').textContent = twScore;
                const progTW = document.getElementById('prog-tw');
                if (progTW) {
                    progTW.style.width = twScore + '%';
                    progTW.className =
                        'progress-fill ' +
                        (twScore >= 70 ? 'green' : twScore >= 40 ? 'yellow' : 'red');
                }
            }

            // ═════════════════════════════════════════════════════════
            // بيانات تفصيلية إضافية للموقع
            // ═════════════════════════════════════════════════════════
            const wsData = srObj.website_scan || {};

            // SEO fields
            const updateSeoBadge = (id, condition, okText, warnText) => {
                const el = document.getElementById(id);
                if (!el) return;
                el.textContent = condition ? okText : warnText;
                el.className = condition ? 'si-badge badge-ok' : 'si-badge badge-warn';
            };

            updateSeoBadge(
                'badge-meta-desc',
                wsData.description && wsData.description.length > 50,
                'مكتمل',
                'ناقص'
            );
            updateSeoBadge('badge-og', wsData.has_og_tags, 'موجود', 'مفقود');
            updateSeoBadge('badge-schema', wsData.has_schema, 'مثبت', 'غير مثبت');
            updateSeoBadge('badge-h1', wsData.h1, 'موجود', 'مفقود');
            updateSeoBadge('badge-contact-form', wsData.has_contact_form, 'موجود', 'مفقود');
            updateSeoBadge('badge-phone', wsData.has_phone, 'ظاهر', 'مخفي');
            updateSeoBadge('badge-cta', wsData.has_cta, 'واضح', 'غائب');
            updateSeoBadge(
                'badge-services',
                wsData.services_list && wsData.services_list.length > 0,
                wsData.services_list?.length + ' خدمة',
                'لا توجد'
            );

            // Word count
            if (document.getElementById('badge-wordcount')) {
                const wc = wsData.word_count || 0;
                const wcEl = document.getElementById('badge-wordcount');
                wcEl.textContent = wc > 0 ? wc + ' كلمة' : 'غير محسوب';
                wcEl.className = wc > 500 ? 'si-badge badge-ok' : 'si-badge badge-warn';
            }

            // Metrics
            if (document.getElementById('metric-pages')) {
                document.getElementById('metric-pages').textContent = wsData.pages_count || '--';
            }
            if (document.getElementById('metric-words')) {
                document.getElementById('metric-words').textContent = wsData.word_count || '--';
            }

            // Website score
            if (document.getElementById('website-score')) {
                const webScore = data.score || 0;
                document.getElementById('website-score').textContent = webScore;
                const progWeb = document.getElementById('prog-website');
                if (progWeb) {
                    progWeb.style.width = webScore + '%';
                    progWeb.className =
                        'progress-fill ' +
                        (webScore >= 70 ? 'green' : webScore >= 40 ? 'yellow' : 'red');
                }
            }

            // Issues/warnings
            if (document.getElementById('metric-issues')) {
                const issues =
                    (!srObj.hasSSL ? 1 : 0) +
                    (!srObj.hasPixel ? 1 : 0) +
                    (!srObj.hasGA ? 1 : 0) +
                    (!srObj.hasWhatsApp ? 1 : 0);
                document.getElementById('metric-issues').textContent = issues;
            }
            if (document.getElementById('metric-warnings')) {
                const warnings =
                    (!wsData.has_og_tags ? 1 : 0) +
                    (!wsData.has_schema ? 1 : 0) +
                    (!wsData.h1 ? 1 : 0);
                document.getElementById('metric-warnings').textContent = warnings;
            }

            // ═════════════════════════════════════════════════════════
            // بيانات تفصيلية إضافية لإنستقرام
            // ═════════════════════════════════════════════════════════
            if (document.getElementById('ig-following')) {
                document.getElementById('ig-following').textContent = igData.following
                    ? parseInt(igData.following).toLocaleString()
                    : '--';
            }
            if (document.getElementById('ig-ratio')) {
                const f = igData.followers || 0;
                const fg = igData.following || 1;
                const ratio = fg > 0 ? Math.round(f / fg) : '--';
                const ratioEl = document.getElementById('ig-ratio');
                ratioEl.textContent = ratio > 1 ? ratio + ':1' : '--';
                ratioEl.style.color =
                    ratio >= 10 ? 'var(--green)' : ratio >= 3 ? 'var(--yellow)' : 'var(--red)';
            }
            if (document.getElementById('igComments')) {
                const c = igData.avg_comments || 0;
                const cEl = document.getElementById('igComments');
                cEl.textContent = c > 0 ? parseFloat(c).toFixed(1) : '--';
                cEl.className = c >= 5 ? 'si-badge badge-ok' : 'si-badge badge-warn';
            }
            if (document.getElementById('igSaves')) {
                const s = igData.avg_saves || igData.saves_rate || 0;
                document.getElementById('igSaves').textContent =
                    s > 0 ? parseFloat(s).toFixed(1) + '%' : '--';
            }
            if (document.getElementById('igPosts')) {
                const pc = igData.posts_count || 0;
                const pcEl = document.getElementById('igPosts');
                pcEl.textContent = pc > 0 ? pc + ' منشور' : '--';
                pcEl.className = pc >= 50 ? 'si-badge badge-ok' : 'si-badge badge-warn';
            }
            if (document.getElementById('igPostsWeek')) {
                const pw = igData.posts_per_week || 0;
                const pwEl = document.getElementById('igPostsWeek');
                pwEl.textContent = pw > 0 ? pw + '/أسبوع' : '--';
                pwEl.className = pw >= 3 ? 'si-badge badge-ok' : 'si-badge badge-warn';
            }
            if (document.getElementById('igLastPost')) {
                const lp = igData.last_post_days || 0;
                const lpEl = document.getElementById('igLastPost');
                lpEl.textContent = lp > 0 ? lp + ' يوم' : '--';
                lpEl.className =
                    lp <= 7
                        ? 'si-badge badge-ok'
                        : lp <= 21
                        ? 'si-badge badge-warn'
                        : 'si-badge badge-warn';
            }
            if (document.getElementById('igVideoPct')) {
                const vp = igData.deep_analysis?.types_percent?.video || igData.video_percent || 0;
                const vpEl = document.getElementById('igVideoPct');
                vpEl.textContent = vp > 0 ? vp + '%' : '--';
                vpEl.className = vp >= 30 ? 'si-badge badge-ok' : 'si-badge badge-warn';
            }
            if (document.getElementById('igHighlights')) {
                const hl = igData.highlights_count || 0;
                document.getElementById('igHighlights').textContent = hl > 0 ? hl : '--';
            }

            // IG Business/Verified
            if (document.getElementById('igBusiness')) {
                const ibEl = document.getElementById('igBusiness');
                ibEl.textContent = igData.is_business ? 'نعم ✅' : 'لا (شخصي)';
                ibEl.className = igData.is_business ? 'si-badge badge-ok' : 'si-badge badge-warn';
            }
            if (document.getElementById('igVerified')) {
                const ivEl = document.getElementById('igVerified');
                ivEl.textContent = igData.is_verified ? 'موثق ✅' : 'غير موثق';
                ivEl.className = igData.is_verified ? 'si-badge badge-ok' : 'si-badge badge-warn';
            }

            // IG Score
            if (document.getElementById('ig-score')) {
                const igFollowers = igData.followers || 0;
                const igER = igData.engagement_rate || 0;
                const igPosts = igData.posts_count || 0;
                let igScore = Math.min(
                    100,
                    Math.round(
                        (igFollowers > 10000
                            ? 30
                            : igFollowers > 1000
                            ? 20
                            : igFollowers > 100
                            ? 10
                            : 5) +
                            (igER >= 3 ? 30 : igER >= 1 ? 20 : igER >= 0.5 ? 10 : 5) +
                            (igPosts >= 50 ? 20 : igPosts >= 20 ? 15 : igPosts >= 10 ? 10 : 5) +
                            (igData.is_business ? 10 : 5) +
                            (igData.is_verified ? 10 : 0)
                    )
                );
                document.getElementById('ig-score').textContent = igScore;
                const progIg = document.getElementById('prog-ig');
                if (progIg) {
                    progIg.style.width = igScore + '%';
                    progIg.className =
                        'progress-fill ' +
                        (igScore >= 70 ? 'green' : igScore >= 40 ? 'yellow' : 'red');
                }
            }

            // ═════════════════════════════════════════════════════════
            // بيانات تفصيلية إضافية لفيسبوك
            // ═════════════════════════════════════════════════════════
            // ✅ إصلاح: البيانات قد تكون في srObj.facebook أو srObj.social أو في جذر srObj مباشرة (عند سحب رابط فيسبوك مباشرة)
            const fbData =
                srObj.facebook || srObj.social || (srObj.platform === 'facebook' ? srObj : {});

            if (document.getElementById('fbFollowers')) {
                document.getElementById('fbFollowers').textContent = fbData.followers
                    ? parseInt(fbData.followers).toLocaleString()
                    : '--';
            }
            if (document.getElementById('fb-likes')) {
                document.getElementById('fb-likes').textContent = fbData.likes
                    ? parseInt(fbData.likes).toLocaleString()
                    : '--';
            }
            if (document.getElementById('fb-checkins')) {
                document.getElementById('fb-checkins').textContent =
                    fbData.checkins || fbData.were_here_count || '--';
            }
            if (document.getElementById('fbVerified')) {
                const fbvEl = document.getElementById('fbVerified');
                fbvEl.textContent = fbData.is_verified ? 'موثق ✅' : 'غير موثق';
                fbvEl.className = fbData.is_verified ? 'si-badge badge-ok' : 'si-badge badge-warn';
            }
            if (document.getElementById('fbRating')) {
                const rating = fbData.rating || 0;
                const frEl = document.getElementById('fbRating');
                if (rating > 0) {
                    frEl.textContent = rating + '/5 ⭐';
                    frEl.className = rating >= 4 ? 'si-badge badge-ok' : 'si-badge badge-warn';
                } else {
                    frEl.textContent = 'لا توجد';
                    frEl.className = 'si-badge badge-warn';
                }
            }
            if (document.getElementById('fbCompleteness')) {
                let comp = 0;
                if (fbData.about) comp += 25;
                if (fbData.phone) comp += 25;
                if (fbData.email) comp += 25;
                if (fbData.website) comp += 25;
                const fcEl = document.getElementById('fbCompleteness');
                fcEl.textContent = comp + '%';
                fcEl.className =
                    comp >= 75
                        ? 'si-badge badge-ok'
                        : comp >= 50
                        ? 'si-badge badge-warn'
                        : 'si-badge badge-warn';
            }
            if (document.getElementById('fbEngagement')) {
                const eng = fbData.avg_engagement || 0;
                const feEl = document.getElementById('fbEngagement');
                feEl.textContent = eng > 0 ? parseFloat(eng).toFixed(1) : '--';
                feEl.className = eng >= 50 ? 'si-badge badge-ok' : 'si-badge badge-warn';
            }
            if (document.getElementById('fbPosts')) {
                const fbp = fbData.posts_count || 0;
                const fbpEl = document.getElementById('fbPosts');
                fbpEl.textContent = fbp > 0 ? fbp + ' منشور' : '--';
                fbpEl.className = fbp >= 50 ? 'si-badge badge-ok' : 'si-badge badge-warn';
            }
            if (document.getElementById('fbPostsWeek')) {
                const fbpw = fbData.posts_per_week || 0;
                const fbpwEl = document.getElementById('fbPostsWeek');
                fbpwEl.textContent = fbpw > 0 ? fbpw + '/أسبوع' : '--';
                fbpwEl.className = fbpw >= 3 ? 'si-badge badge-ok' : 'si-badge badge-warn';
            }
            if (document.getElementById('fbLastPost')) {
                const fblp = fbData.last_post_days || 0;
                const fblpEl = document.getElementById('fbLastPost');
                fblpEl.textContent = fblp > 0 ? fblp + ' يوم' : '--';
                fblpEl.className = fblp <= 7 ? 'si-badge badge-ok' : 'si-badge badge-warn';
            }
            if (document.getElementById('fbHashtags')) {
                const fht = fbData.deep_analysis?.top_hashtags?.length || 0;
                const fhtEl = document.getElementById('fbHashtags');
                fhtEl.textContent = fht > 0 ? fht + ' هاشتاق' : '--';
                fhtEl.className = fht >= 5 ? 'si-badge badge-ok' : 'si-badge badge-warn';
            }

            // FB Communication
            if (document.getElementById('fbWhatsapp')) {
                const fwa = fbData.whatsapp || srObj.hasWhatsApp;
                const fwaEl = document.getElementById('fbWhatsapp');
                fwaEl.textContent = fwa ? 'مرتبط ✅' : 'غير مرتبط';
                fwaEl.className = fwa ? 'si-badge badge-ok' : 'si-badge badge-warn';
            }
            if (document.getElementById('fbPhone')) {
                const fphEl = document.getElementById('fbPhone');
                fphEl.textContent = fbData.phone ? 'ظاهر' : 'مخفي';
                fphEl.className = fbData.phone ? 'si-badge badge-ok' : 'si-badge badge-warn';
            }
            if (document.getElementById('fbEmail')) {
                const femEl = document.getElementById('fbEmail');
                femEl.textContent = fbData.email ? 'متاح' : 'غير متاح';
                femEl.className = fbData.email ? 'si-badge badge-ok' : 'si-badge badge-warn';
            }
            if (document.getElementById('fbWebsite')) {
                const fweEl = document.getElementById('fbWebsite');
                fweEl.textContent = fbData.website ? 'موجود' : 'مفقود';
                fweEl.className = fbData.website ? 'si-badge badge-ok' : 'si-badge badge-warn';
            }

            // FB Ads details
            if (document.getElementById('fbAdsVideo')) {
                const vidAds =
                    adsObj.video_ads || adsObj.ads?.filter(a => a.type === 'video')?.length || 0;
                const favEl = document.getElementById('fbAdsVideo');
                favEl.textContent = vidAds > 0 ? vidAds : '0';
                favEl.className = vidAds > 0 ? 'si-badge badge-ok' : 'si-badge badge-warn';
            }
            if (document.getElementById('fbAdsImage')) {
                const imgAds =
                    adsObj.image_ads || (adsObj.total_ads || 0) - (adsObj.video_ads || 0);
                const faiEl = document.getElementById('fbAdsImage');
                faiEl.textContent = imgAds > 0 ? imgAds : '0';
                faiEl.className = imgAds > 0 ? 'si-badge badge-ok' : 'si-badge badge-warn';
            }
            if (document.getElementById('fbAdsObjective')) {
                const obj = adsObj.ads?.[0]?.objective || adsObj.primary_objective || 'متنوع';
                document.getElementById('fbAdsObjective').textContent = obj;
            }

            // FB Score
            if (document.getElementById('fb-score')) {
                const fbFollowers = fbData.followers || 0;
                const fbRating = fbData.rating || 0;
                const fbPosts = fbData.posts_count || 0;
                let fbScore = Math.min(
                    100,
                    Math.round(
                        (fbFollowers > 5000
                            ? 30
                            : fbFollowers > 1000
                            ? 20
                            : fbFollowers > 100
                            ? 10
                            : 5) +
                            (fbRating >= 4 ? 20 : fbRating > 0 ? 15 : 5) +
                            (fbPosts >= 50 ? 20 : fbPosts >= 20 ? 15 : fbPosts >= 10 ? 10 : 5) +
                            (fbData.is_verified ? 15 : 5) +
                            (adsObj.total_ads > 0 ? 15 : 5)
                    )
                );
                document.getElementById('fb-score').textContent = fbScore;
                const progFb = document.getElementById('prog-fb');
                if (progFb) {
                    progFb.style.width = fbScore + '%';
                    progFb.className =
                        'progress-fill ' +
                        (fbScore >= 70 ? 'green' : fbScore >= 40 ? 'yellow' : 'red');
                }
            }

            // --- 4. Deep Diagnosis (AI Paragraphs) ---
            const diagContainer = document.getElementById('auditDeepDiagnosis');
            if (diagContainer && data.breakdown && data.breakdown.length > 0) {
                let diagHtml = '';
                data.breakdown.forEach(item => {
                    const score = item.score || 0;
                    // Phase 4.5: escape first, THEN apply allowed highlight markup on already-safe HTML
                    const escapedReason = escapeHtml(item.reason || '');
                    const richText = escapedReason.replace(
                        /([A-Za-z]+)/g,
                        '<span class="highlight">$1</span>'
                    );

                    diagHtml += `
              <div class="diagnosis-block">
                <div class="diag-score">
                  <div class="num" style="color:${
                      score >= 80 ? 'var(--green)' : score >= 50 ? 'var(--yellow)' : 'var(--red)'
                  }">${Number(score)}</div>
                  <div class="label">تقييم المحور</div>
                </div>
                <div class="diag-content">
                  <h3><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> تشخيص محور: ${escapeHtml(item.axis || '')}</h3>
                  <p>${richText}</p>
                </div>
              </div>
            `;
                });
                diagContainer.innerHTML = diagHtml;
            }
        } catch (__err_detailed_analysis_html) {
            console.error("[RC] Phase 4.4: تعطل قسم detailed-analysis.html:", __err_detailed_analysis_html);
        }
        }

        // ==========================================
        // PAGE: competitors.html (MARKET RADAR)
        // ==========================================
        if (path.includes('competitors.html')) { try {
            const compName = document.getElementById('compClientName');
            const vsName = document.getElementById('vsClientName');
            if (compName) compName.textContent = clientName;
            if (vsName) vsName.textContent = clientName;

            const grid = document.getElementById('competitorsGrid');
            if (grid && data.competitor_radar && data.competitor_radar.length > 0) {
                let html = '';
                const ranks = ['1st', '2nd', '3rd', '4th', '5th'];

                data.competitor_radar.slice(0, 5).forEach((comp, i) => {
                    const st1 =
                        comp.strengths && comp.strengths[0]
                            ? comp.strengths[0]
                            : 'وجود قوي في السوق';
                    const st2 =
                        comp.strengths && comp.strengths[1]
                            ? comp.strengths[1]
                            : 'قاعدة عملاء مستقرة';
                    const wk1 =
                        comp.weaknesses && comp.weaknesses[0]
                            ? comp.weaknesses[0]
                            : 'خدمة عملاء بطيئة';
                    const wk2 =
                        comp.weaknesses && comp.weaknesses[1]
                            ? comp.weaknesses[1]
                            : 'محتوى غير متجدد';

                    html += `
              <div class="comp-card">
                <div class="cc-header">
                  <div class="cc-info">
                    <h3>${escapeHtml(comp.name || 'منافس')}</h3>
                    <p><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg> ${escapeHtml(comp.url || 'غير متوفر')}</p>
                  </div>
                  <div class="cc-rank">${escapeHtml(ranks[i] || '#')}</div>
                </div>

                <div class="traits-list">
                  <div class="trait-group">
                    <span class="trait-title">نقاط تفوقه (Strengths)</span>
                    <div class="trait-item trait-strength">${escapeHtml(extractText(st1))}</div>
                    <div class="trait-item trait-strength">${escapeHtml(extractText(st2))}</div>
                  </div>
                  <div class="trait-group" style="margin-top:8px;">
                    <span class="trait-title">نقاط ضعفه (Vulnerabilities)</span>
                    <div class="trait-item trait-weakness">${escapeHtml(extractText(wk1))}</div>
                    <div class="trait-item trait-weakness">${escapeHtml(extractText(wk2))}</div>
                  </div>
                </div>

                <div class="attack-plan">
                  <div class="ap-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg> خطة الهجوم</div>
                  <div class="ap-desc">${escapeHtml(comp.attack_plan || 'استغل نقاط ضعفه أعلاه للسيطرة على عملائه.')}</div>
                </div>
              </div>
            `;
                });
                grid.innerHTML = html;
            } else if (grid) {
                grid.innerHTML = `<div style="grid-column: 1 / -1; padding: 40px; text-align: center; color: var(--text-gray); font-size:18px;">لم يتمكن محرك Apify من استخراج بيانات منافسين كافية لهذا المجال، أو أن البيانات قيد المعالجة.</div>`;
            }

            const arsenalGrid = document.getElementById('arsenalGrid');
            if (arsenalGrid && data.execution_arsenal) {
                let arsenalHtml = '';
                data.execution_arsenal.forEach(item => {
                    arsenalHtml += `
              <div class="arsenal-item">
                <div class="arsenal-icon">${escapeHtml(item.icon || '🔥')}</div>
                <div class="arsenal-title">${escapeHtml(item.title || '')}</div>
                <div class="arsenal-desc">${escapeHtml(item.desc || '')}</div>
              </div>
            `;
                });
                arsenalGrid.innerHTML = arsenalHtml;
            }

            const summaryText = document.getElementById('marketSummaryText');
            if (summaryText && data.market_summary) {
                // Highlight text between span highlight
                summaryText.innerHTML = sanitize(data.market_summary);
            } else if (summaryText) {
                summaryText.innerHTML =
                    'استراتيجية (المحيط الأزرق) تكمن في استغلال الثغرات في خدمة عملاء المنافسين. ركز على تجربة شراء لا تُنسى وسيبدأ ولاء العملاء بالتحول إليك تدريجياً.';
            }
        } catch (__err_competitors_html) {
            console.error("[RC] Phase 4.4: تعطل قسم competitors.html:", __err_competitors_html);
        }
        }

        // ==========================================
        // PAGE: ads.html (ADS WAR ROOM)
        // ==========================================
        if (path.includes('ads.html')) { try {
            const adName = document.getElementById('adClientName');
            const adHandle = document.getElementById('adHandle');
            if (adName) adName.textContent = clientName;

            // srObj already defined at top of renderData
            if (adHandle)
                adHandle.textContent = data.instagram_url
                    ? data.instagram_url
                          .replace(/https?:\/\/(www\.)?instagram\.com\//, '@')
                          .replace(/\//, '')
                    : '@' + clientName.replace(/\s+/g, '').toLowerCase();

            // ── جلب الإعلانات الحية من ads-fetch.php إن لم تكن محملة ──
            const hasLiveAds =
                srObj.ads_library && srObj.ads_library.ads && srObj.ads_library.ads.length > 0;
            if (!hasLiveAds && id && id !== 'mock') {
                // عرض حالة التحميل
                const actualAdsGrid = document.getElementById('actualAdsGrid');
                const metricsGrid = document.getElementById('adMetricsGrid');
                const loadingHtml = `<div style="grid-column:1/-1;padding:40px;text-align:center;color:var(--primary);">
            <div style="font-size:32px;margin-bottom:12px;">🔍</div>
            <div style="font-size:16px;font-weight:800;">جاري استخراج الإعلانات من مكتبة Meta...</div>
            <div style="font-size:13px;color:var(--text-gray);margin-top:8px;">قد يستغرق ذلك دقيقة أو اثنتين</div>
          </div>`;
                if (actualAdsGrid) actualAdsGrid.innerHTML = loadingHtml;
                if (metricsGrid) metricsGrid.innerHTML = loadingHtml;

                fetch(`api/ads-fetch.php?id=${id}`)
                    .then(r => r.json())
                    .then(resp => {
                        if (resp.success && resp.data) {
                            // دمج البيانات الحية في srObj
                            srObj.ads_library = {
                                ads: resp.data.ads || [],
                                total_ads: resp.data.total_ads || 0,
                                active_ads: resp.data.active_ads || 0,
                                ai_analysis: resp.data.ai || {},
                            };
                            // إعادة رسم قسم الإعلانات بالبيانات الحية
                            renderAdsSection(data, srObj, clientName, resp.data.ai || null);
                            // إظهار التقرير الكامل من OpenAI
                            if (resp.data.full_report) {
                                const box = document.getElementById('deepReportSection');
                                const cnt = document.getElementById('fullReportContent');
                                if (box && cnt) {
                                    // Phase 4.5: escape user content first, then apply allowed Markdown→HTML
                                    const escaped = escapeHtml(resp.data.full_report);
                                    const html = escaped
                                        .replace(/^## (.+)$/gm, '<h3>$1</h3>')
                                        .replace(/^### (.+)$/gm, '<h4>$1</h4>')
                                        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                                        .replace(/\*(.+?)\*/g, '<em>$1</em>')
                                        .replace(/\n/g, '<br>');
                                    cnt.innerHTML = html;
                                    box.style.display = 'block';
                                }
                            }
                        }
                    })
                    .catch(err => console.warn('[ads-fetch] Error:', err));
            } else {
                // البيانات موجودة — ارسم مباشرة
                renderAdsSection(
                    data,
                    srObj,
                    clientName,
                    srObj.ads_library && srObj.ads_library.ai_analysis
                        ? srObj.ads_library.ai_analysis
                        : null
                );
            }
        } catch (__err_ads_html) {
            console.error("[RC] Phase 4.4: تعطل قسم ads.html:", __err_ads_html);
        }
        } // ← closes `if (path.includes('ads.html'))` opened above (was missing — caused parse failure for the entire file)

        // (الكتلة المكررة لـ recommendations تم دمجها مع الأولى وإزالتها من هنا)

        // ==========================================
        // PAGE: journey.html
        // ──────────────────────────────────────────
        // Funnel rendering is AI-driven only. The single source of truth
        // is the block farther down that consumes data.ai_report.customer_journey.
        // No score-based fake-data fallback (no Math.random()) — see audit #2.
        // ==========================================

        // ==========================================
        // PAGE: content.html
        // ==========================================
        if (path.includes('content.html')) { try {
            const score = Number.isFinite(Number(data.score)) ? Number(data.score) : 0;
            // ✅ إصلاح: استخدام srObj المتاح فعلياً (sr قد لا يكون معرَّفاً في هذا السياق)
            const fb =
                srObj.facebook || srObj.social || (srObj.platform === 'facebook' ? srObj : {});
            const ig =
                srObj.instagram || srObj.social || (srObj.platform === 'instagram' ? srObj : {});

            // محاولة جلب التحليل العميق (نفضل إنستجرام لأنه أدق في توزيع الأنواع عادةً)
            const da = ig.deep_analysis || fb.deep_analysis || {};
            // لا نخترع types_percent — إذا لم يرجع API توزيعاً، تظل البطاقات فارغة.
            const types = da.types_percent || null;

            // ═══════════════════════════════════════════════════
            // Bento metrics (Visual/Msg/Eng/Var):
            // المصدر الموثوق الوحيد هو ai_report.content_analysis.bar_*
            // (يبنيها buildContentAnalysis() في PHP من بيانات حقيقية).
            // عند غيابها نعرض null → الواجهة ستعرض "—" بدلاً من رقم مخترع.
            // ═══════════════════════════════════════════════════
            let cVisual = null;
            let cMsg = null;
            let cEng = null;
            let cVar = null;

            // ═══════════════════════════════════════════════════
            // ربط بيانات الذكاء الاصطناعي (content_analysis)
            // لا يؤثر على أي صفحة أخرى — يعمل فقط داخل content.html
            // ═══════════════════════════════════════════════════
            const ca =
                data.ai_report && data.ai_report.content_analysis
                    ? data.ai_report.content_analysis
                    : null;

            if (ca && Array.isArray(ca.q)) {
                const statusEmoji = { good: '✅', warn: '⚠️', bad: '❌' };
                const statusClass = { good: 'good', warn: 'warn', bad: 'bad' };

                ca.q.forEach(item => {
                    const statusEl = document.getElementById(`q${item.id}_status`);
                    const answerEl = document.getElementById(`q${item.id}_answer`);
                    const s = item.status || 'neu';
                    if (statusEl) {
                        statusEl.className = statusEl.className.replace(
                            /\b(good|warn|bad|neu)\b/g,
                            ''
                        );
                        statusEl.classList.add(statusClass[s] || 'neu');
                        statusEl.textContent = statusEmoji[s] || '—';
                    }
                    if (answerEl && item.answer) {
                        answerEl.textContent = item.answer;
                        answerEl.setAttribute('data-ai', 'true');
                    }
                });

                // تحديث أشرطة الأداء (Score Bars) من بيانات الذكاء الاصطناعي
                const barMap = {
                    bar_cta: '[data-width]', // Section 1
                    bar_contact: null,
                    bar_value: null,
                    bar_market_fit: null,
                    bar_visual: null,
                    bar_brand: null,
                    bar_consistency: null,
                    bar_regularity: null,
                    bar_calendar: null,
                };

                // تحديث الأشرطة بالترتيب (كل قسم يحتوي عدة أشرطة)
                const allBars = document.querySelectorAll('.bar-fill[data-width]');
                const barKeys = [
                    'bar_cta',
                    'bar_contact',
                    'bar_value',
                    'bar_market_fit',
                    'bar_visual',
                    'bar_brand',
                    'bar_consistency',
                    'bar_regularity',
                    'bar_calendar',
                ];
                allBars.forEach((bar, idx) => {
                    const key = barKeys[idx];
                    if (key && ca[key] !== undefined && ca[key] > 0) {
                        const val = Math.min(100, Math.max(0, ca[key]));
                        bar.setAttribute('data-width', val);
                        bar.style.width = val + '%';
                        // تحديث اللون بناءً على القيمة
                        bar.className = bar.className.replace(/\b(green|yellow|red|blue)\b/g, '');
                        bar.classList.add(val > 70 ? 'green' : val > 40 ? 'yellow' : 'red');
                        // تحديث النص المجاور
                        const valEl = bar.closest('.bar-row')?.querySelector('.bar-val');
                        if (valEl) {
                            valEl.textContent = val + '%';
                            valEl.className = valEl.className.replace(
                                /\b(green|yellow|red|blue)\b/g,
                                ''
                            );
                            valEl.classList.add(val > 70 ? 'green' : val > 40 ? 'yellow' : 'red');
                        }
                    }
                });
            } // END IF AI

            // =====================================
            // ملاحظة: تم حذف بلوك "Global Sanitization" نهائياً (Phase 2).
            // كان البلوك يحوي ~140 سطر من txt.replace() لتنظيف نصوص قديمة عن
            // عميل آخر (سيروم/تجميل/أمهات بعد الولادة/...) كانت مدمجة في HTML
            // كـ fallback. بعد تنظيف HTML نفسه (Tasks #1-#3) لم تعد هذه النصوص
            // موجودة، فأصبح بلوك "التنظيف" بدوره عرضة لتشويه النصوص الحقيقية.
            //
            // أيضاً تم حذف بلوك fallback bar formula (idx % 3 ? -15 : ...)
            // الذي كان يولّد قيم 9 شرائط تخيلياً من score عند فشل AI.
            // عند غياب ca.bar_*، تبقى الشرائط 0%/— كما هي في HTML.
            // =====================================

            // Update balance wheel and journey descriptions from customer_journey stages if available (real AI data)
            const cj = data.ai_report && data.ai_report.customer_journey;

            // Update Journey Text in journey.html
            const journeyDescs = document.querySelectorAll('.j-card-desc[data-field]');
            if (journeyDescs.length > 0) {
                journeyDescs.forEach(descEl => {
                    const field = descEl.getAttribute('data-field');
                    const stageData = cj && cj.stages ? cj.stages[field] : null;
                    const textContent = stageData
                        ? stageData.description ||
                          stageData.reason ||
                          stageData.analysis ||
                          stageData.text
                        : null;

                    if (textContent) {
                        descEl.textContent = textContent;
                        descEl.classList.remove('is-placeholder');
                    } else {
                        descEl.classList.add('is-placeholder');
                    }
                });
            }

            if (cj && cj.stages) {
                // Update Balance Wheel in report.html
                const bwMap = {
                    awareness: ['bc_awareness_fill', 'bc_awareness_pct'],
                    attraction: ['bc_attraction_fill', 'bc_attraction_pct'],
                    trust: ['bc_trust_fill', 'bc_trust_pct'],
                    purchase: ['bc_purchase_fill', 'bc_purchase_pct'],
                    loyalty: ['bc_loyalty_fill', 'bc_loyalty_pct'],
                };
                Object.keys(bwMap).forEach(stage => {
                    const stageData = cj.stages[stage];
                    if (!stageData) return;
                    const val = stageData.score || stageData.value || 0;
                    const [fillId, pctId] = bwMap[stage];
                    const fillEl = document.getElementById(fillId);
                    const pctEl = document.getElementById(pctId);
                    if (fillEl) fillEl.style.width = val + '%';
                    if (pctEl) pctEl.textContent = val + '%';
                });

                // Update Journey Scores in journey.html
                const jScoreMap = {
                    awareness: 'stage1Score',
                    attraction: 'stage2Score',
                    trust: 'stage3Score',
                    purchase: 'stage4Score',
                    loyalty: 'stage5Score',
                };
                Object.keys(jScoreMap).forEach(stage => {
                    const stageData = cj.stages[stage];
                    if (!stageData) return;
                    const val = stageData.score || stageData.value || 0;
                    const scoreEl = document.getElementById(jScoreMap[stage]);
                    if (scoreEl) {
                        scoreEl.setAttribute('data-val', val);
                        scoreEl.textContent = val + '%';
                        // Trigger ring animation if the ring is there
                        const ringEl = scoreEl
                            .closest('.j-card-stats')
                            ?.querySelector('.score-circle');
                        if (ringEl) ringEl.setAttribute('data-percent', val);
                    }
                });

                // Update the main journey circle score
                const journeyCircle = document.getElementById('journeyCircle');
                const journeyScoreNum = document.getElementById('journeyScore');
                let totalVal = 0;
                let stagesCount = 0;
                Object.values(cj.stages).forEach(s => {
                    if (s && (s.score || s.value)) {
                        totalVal += s.score || s.value;
                        stagesCount++;
                    }
                });
                if (stagesCount > 0) {
                    const avgScore = Math.round(totalVal / stagesCount);
                    if (journeyCircle) journeyCircle.setAttribute('data-percent', avgScore);
                    if (journeyScoreNum) {
                        journeyScoreNum.setAttribute('data-val', avgScore);
                        journeyScoreNum.textContent = avgScore;
                    }
                }
            } else {
                // No AI customer_journey → reset balance wheel to 0%/—
                // (do NOT fabricate from overall score).
                const bcFills = document.querySelectorAll('.bc-fill');
                bcFills.forEach(fill => {
                    fill.style.width = '0%';
                    const pctEl = fill.closest('.balance-card')?.querySelector('.bc-pct');
                    if (pctEl) pctEl.textContent = '—';
                });
            }

            // Populate Content Type Pills from real scan data
            const pillsContainer = document.getElementById('contentTypePills');
            if (pillsContainer) {
                const scanR = data.scan_result || {};
                const igData = scanR.instagram || {};
                const fbData = scanR.facebook || {};
                const pills = [];
                // Check from real data
                const hasVideo =
                    fbData.deep_analysis?.types_percent?.video > 0 ||
                    igData.deep_analysis?.types_percent?.video > 0;
                const hasStories = igData.highlights_count > 0 || scanR.hasStories;
                const hasTestimonials = scanR.hasTestimonials || false;
                const hasBTS = scanR.hasBTS || false;
                const hasUGC = scanR.hasUGC || false;
                const hasEducational =
                    fbData.deep_analysis?.types_percent?.educational > 0 ||
                    igData.deep_analysis?.types_percent?.educational > 0;
                const hasProducts = fbData.posts_count > 0 || igData.posts_count > 0;

                if (hasProducts) pills.push({ cls: 'green', txt: '✅ منشورات المنتجات/الخدمات' });
                if (hasVideo) pills.push({ cls: 'green', txt: '✅ محتوى فيديو (Reels/Video)' });
                if (hasStories) pills.push({ cls: 'green', txt: '✅ قصص (Stories/Highlights)' });
                if (hasEducational) pills.push({ cls: 'yellow', txt: '⚠️ محتوى تعليمي (نادر)' });
                else pills.push({ cls: 'red', txt: '❌ محتوى تعليمي (غائب)' });
                if (hasTestimonials) pills.push({ cls: 'green', txt: '✅ شهادات العملاء' });
                else pills.push({ cls: 'red', txt: '❌ شهادات العملاء (غائبة)' });
                if (hasBTS) pills.push({ cls: 'green', txt: '✅ كواليس (BTS)' });
                else pills.push({ cls: 'red', txt: '❌ ما وراء الكواليس (BTS)' });
                if (hasUGC) pills.push({ cls: 'green', txt: '✅ محتوى UGC' });
                else pills.push({ cls: 'red', txt: '❌ محتوى UGC' });
                if (!hasVideo) pills.push({ cls: 'red', txt: '❌ مقارنات / قبل وبعد' });

                pillsContainer.innerHTML = pills
                    .map(p => `<div class="pill ${p.cls}">${p.txt}</div>`)
                    .join('');
            }

            // Fallback: Dynamic overrides based on actual technical scan
            const scan = data.scan_result || {};
            const hasContact =
                scan.hasWhatsApp ||
                scan.hasPhoneNumber ||
                scan.hasContactForm ||
                (scan.social &&
                    (scan.social.has_whatsapp ||
                        scan.social.whatsapp ||
                        scan.social.has_phone ||
                        scan.social.has_contact));
            const hasCTA = scan.hasCTA || (scan.social && scan.social.has_cta_button);

            if (hasContact) {
                const q3Answer = document.getElementById('q3_answer');
                const q3Status = document.getElementById('q3_status');
                if (q3Answer)
                    q3Answer.textContent =
                        'نعم — وسائل التواصل (مثل رابط واتساب أو اتصال) موجودة وواضحة، مما يسهل على العميل الوصول إليك بسرعة.';
                if (q3Status) {
                    q3Status.textContent = '✅';
                    q3Status.className = 'q-status good';
                }

                const q14Answer = document.getElementById('q14_answer');
                const q14Status = document.getElementById('q14_status');
                if (q14Answer)
                    q14Answer.textContent =
                        'قنوات التواصل مريحة ومتاحة بوضوح، مما يقلل من تردد العميل ويزيد احتمالية التحويل بنجاح.';
                if (q14Status) {
                    q14Status.textContent = '✅';
                    q14Status.className = 'q-status good';
                }
            }

            if (hasCTA) {
                const q2Answer = document.getElementById('q2_answer');
                const q2Status = document.getElementById('q2_status');
                if (q2Answer)
                    q2Answer.textContent =
                        'نعم — توجد دعوة واضحة لاتخاذ إجراء (CTA) توجه العميل بشكل صحيح للخطوة التالية.';
                if (q2Status) {
                    q2Status.textContent = '✅';
                    q2Status.className = 'q-status good';
                }
            }

            // Fallback: Dynamic Engagement Cards based on score and followers
            const ecVals = document.querySelectorAll('.ec-val');
            if (ecVals.length === 6) {
                const baseER = realER > 0 ? realER.toFixed(1) : null;
                if (baseER == null) {
                    ecVals.forEach(el => {
                        el.textContent = '--';
                    });
                    document.querySelectorAll('.ec-status').forEach(el => {
                        el.className = 'ec-status';
                        el.textContent = 'غير متوفر';
                    });
                } else {
                    ecVals[0].textContent = baseER + '%';
                    ecVals[1].textContent = (baseER * 0.15).toFixed(1) + '%'; // Comments
                    ecVals[2].textContent = (baseER * 0.25).toFixed(1) + '%'; // Shares
                    ecVals[3].textContent = (baseER * 0.35).toFixed(1) + '%'; // Saves

                    const followers =
                        scan.social?.followers ||
                        scan.followers ||
                        data.scan_result?.og?.followers ||
                        0;
                    const reach = followers ? Math.floor(followers * (Number(baseER) / 100)) : null;
                    ecVals[4].textContent =
                        reach == null
                            ? '--'
                            : reach > 1000
                            ? (reach / 1000).toFixed(1) + 'K'
                            : reach; // Reach

                    ecVals[5].textContent = (baseER * 0.08).toFixed(1) + '%'; // DMs

                    // update status colors to match the dynamic numbers
                    const ecStatuses = document.querySelectorAll('.ec-status');
                    if (ecStatuses.length === 6) {
                        const setSt = (el, st, txt, mo) => {
                            el.className = 'ec-status ' + st;
                            el.textContent = mo + ' ' + txt;
                        };
                        setSt(
                            ecStatuses[0],
                            score > 70 ? 'good' : score > 40 ? 'warn' : 'bad',
                            score > 70 ? 'مرتفع' : score > 40 ? 'متوسط' : 'منخفض',
                            score > 70 ? '✅' : score > 40 ? '⚠️' : '❌'
                        );
                        setSt(
                            ecStatuses[1],
                            score > 75 ? 'good' : 'warn',
                            score > 75 ? 'جيد' : 'يحتاج تفاعل',
                            score > 75 ? '✅' : '⚠️'
                        );
                        setSt(
                            ecStatuses[2],
                            score > 65 ? 'good' : 'warn',
                            score > 65 ? 'جيد' : 'يحتاج تحسين',
                            score > 65 ? '✅' : '⚠️'
                        );
                        setSt(
                            ecStatuses[3],
                            score > 60 ? 'good' : 'bad',
                            score > 60 ? 'جيد' : 'منخفض',
                            score > 60 ? '✅' : '❌'
                        );
                        setSt(
                            ecStatuses[4],
                            score > 50 ? 'good' : 'warn',
                            score > 50 ? 'جيد' : 'محدود',
                            score > 50 ? '✅' : '⚠️'
                        );
                        setSt(
                            ecStatuses[5],
                            score > 80 ? 'good' : 'bad',
                            score > 80 ? 'جيد' : 'منخفض جداً',
                            score > 80 ? '✅' : '❌'
                        );
                    }
                }
            }
            // END GLOBAL OVERRIDES

            // تحديث الدرجة الإجمالية في رأس الصفحة
            const mainScoreEl = document.getElementById('mainScore');
            if (mainScoreEl) {
                mainScoreEl.textContent = score;
                mainScoreEl.setAttribute('data-val', score);
            }

            const contentScore = document.getElementById('contentScore');
            const contentCircle = document.getElementById('contentCircle');
            const contentStatusTitle = document.getElementById('contentStatusTitle');
            const contentStatusDesc = document.getElementById('contentStatusDesc');

            // Content Index = average of available metrics only (skip nulls).
            const validMetrics = [cVisual, cMsg, cEng, cVar].filter(v => v !== null && !isNaN(v));
            const avgContent = validMetrics.length > 0
                ? Math.floor(validMetrics.reduce((a, b) => a + b, 0) / validMetrics.length)
                : null;

            if (contentScore) {
                if (avgContent !== null) {
                    contentScore.setAttribute('data-val', avgContent);
                    contentScore.textContent = avgContent;
                } else {
                    contentScore.setAttribute('data-val', 0);
                    contentScore.textContent = '—';
                }
            }

            if (contentCircle) {
                contentCircle.setAttribute('data-percent', avgContent !== null ? avgContent : 0);
                if (avgContent === null) contentCircle.setAttribute('data-color', 'var(--text-gray)');
                else if (avgContent > 70) contentCircle.setAttribute('data-color', 'var(--green)');
                else if (avgContent > 40) contentCircle.setAttribute('data-color', 'var(--yellow)');
                else contentCircle.setAttribute('data-color', 'var(--red)');
            }

            if (contentStatusTitle) {
                if (avgContent === null) {
                    contentStatusTitle.textContent = '—';
                    contentStatusTitle.style.color = 'var(--text-gray)';
                    if (contentStatusDesc)
                        contentStatusDesc.textContent = 'بانتظار تحليل المحتوى من AI...';
                } else if (avgContent > 70) {
                    contentStatusTitle.innerHTML = '✅ محتوى ذهبي';
                    contentStatusTitle.style.color = 'var(--green)';
                    if (contentStatusDesc)
                        contentStatusDesc.innerHTML =
                            'المحتوى يجلب تفاعلاً عالياً ويترجم بسلاسة إلى مبيعات حقيقية.';
                } else if (avgContent > 40) {
                    contentStatusTitle.innerHTML = '⚠️ محتوى لا يبيع';
                    contentStatusTitle.style.color = 'var(--yellow)';
                    if (contentStatusDesc)
                        contentStatusDesc.innerHTML =
                            'المحتوى يجلب لايكات ومشاهدات، لكنه <strong>لا يترجم إلى مبيعات</strong>.';
                } else {
                    contentStatusTitle.innerHTML = '❌ محتوى عشوائي';
                    contentStatusTitle.style.color = 'var(--red)';
                    if (contentStatusDesc)
                        contentStatusDesc.innerHTML =
                            'المحتوى لا يعكس قيمة علامتك التجارية ولا يتفاعل معه أحد.';
                }
            }

            const updateBento = (prefix, val) => {
                const scoreEl = document.getElementById(`bento${prefix}Score`);
                const boxEl = document.getElementById(`bento${prefix}Box`);
                if (scoreEl) {
                    if (val === null || val === undefined || isNaN(val)) {
                        scoreEl.setAttribute('data-val', 0);
                        scoreEl.textContent = '—';
                    } else {
                        scoreEl.setAttribute('data-val', val);
                        scoreEl.textContent = val;
                    }
                }
                if (boxEl) {
                    boxEl.classList.remove('b-green', 'b-yellow', 'b-red');
                    if (val === null || val === undefined || isNaN(val)) return;
                    if (val > 70) boxEl.classList.add('b-green');
                    else if (val > 40) boxEl.classList.add('b-yellow');
                    else boxEl.classList.add('b-red');
                }
            };

            updateBento('Visual', cVisual);
            updateBento('Msg', cMsg);
            updateBento('Eng', cEng);
            updateBento('Var', cVar);

            const aiStrategy = document.getElementById('contentAiStrategy');
            if (aiStrategy) {
                const strategyData = data.ai_report && data.ai_report.content_strategy;
                if (strategyData) {
                    aiStrategy.innerHTML = `
              <h4>التوجيه الذكي للمحتوى (Content Strategy)</h4>
              <p>${escapeHtml(strategyData.intro || '')}</p>
              <ul class="ai-list">
                <li><span style="color:var(--primary)">✔</span> <strong>التحول:</strong> ${escapeHtml(strategyData.shift || '')}</li>
                <li><span style="color:var(--primary)">✔</span> <strong>الخطاف (Hook):</strong> ${escapeHtml(strategyData.hook || '')}</li>
                <li><span style="color:var(--primary)">✔</span> <strong>الإجراء المباشر (CTA):</strong> ${escapeHtml(strategyData.cta || '')}</li>
              </ul>
            `;
                } else {
                    if (avgContent > 70) {
                        aiStrategy.innerHTML = `
                <h4>التوجيه الذكي للمحتوى (Content Strategy)</h4>
                <p>استراتيجيتك الحالية تعمل بامتياز! حان الوقت لتوسيع نطاق النجاح (Scaling).</p>
                <ul class="ai-list">
                  <li><span style="color:var(--primary)">✔</span> <strong>التحول:</strong> زيادة ميزانية الإعلانات المروجة للمحتوى الأفضل أداءً (UGC).</li>
                  <li><span style="color:var(--primary)">✔</span> <strong>الخطاف (Hook):</strong> استمر في استخدام تجارب العملاء كبداية لفيديوهاتك.</li>
                  <li><span style="color:var(--primary)">✔</span> <strong>الإجراء المباشر (CTA):</strong> ابدأ بتقديم عروض اشتراكات (Subscriptions) للعملاء الحاليين.</li>
                </ul>
              `;
                    } else if (avgContent > 40) {
                        aiStrategy.innerHTML = `
                <h4>التوجيه الذكي للمحتوى (Content Strategy)</h4>
                <p>أنت لا تحتاج إلى تغيير المصمم، أنت تحتاج إلى تغيير الكاتب! توقف عن نشر "صور الكتالوج" وابدأ بنشر محتوى يحل مشاكل العميل.</p>
                <ul class="ai-list">
                  <li><span style="color:var(--primary)">✔</span> <strong>التحول:</strong> بنسبة 70% محتوى تعليمي وقصصي (Storytelling) و30% محتوى بيعي مباشر.</li>
                  <li><span style="color:var(--primary)">✔</span> <strong>الخطاف (Hook):</strong> ابدأ كل فيديو بسؤال يلامس ألم العميل المباشر.</li>
                  <li><span style="color:var(--primary)">✔</span> <strong>الإجراء المباشر (CTA):</strong> وجه العميل برسالة واضحة في نهاية كل منشور بيعي.</li>
                </ul>
              `;
                    } else {
                        aiStrategy.innerHTML = `
                <h4>التوجيه الذكي للمحتوى (Content Strategy)</h4>
                <p>المحتوى الحالي يدمر علامتك التجارية بدلاً من بنائها. تحتاج لإعادة هيكلة كاملة فورا.</p>
                <ul class="ai-list">
                  <li><span style="color:var(--primary)">✔</span> <strong>التحول:</strong> توقف عن النشر العشوائي. ركز على إنتاج 3 فيديوهات ريلز عالية الجودة أسبوعيا.</li>
                  <li><span style="color:var(--primary)">✔</span> <strong>الخطاف (Hook):</strong> اعتمد على الفيديوهات القصيرة الصادمة (Pattern Interruption).</li>
                  <li><span style="color:var(--primary)">✔</span> <strong>الإجراء المباشر (CTA):</strong> قدم منتجاً مجانياً (Lead Magnet) لجمع إيميلات أو أرقام الزوار بدل محاولة البيع المباشر.</li>
                </ul>
              `;
                    }
                }
            }

            if (!ca) {
                const unavailable =
                    'غير متوفر من تحليل المحتوى لهذا التقرير. لا يتم عرض إجابة تقديرية بدون بيانات OpenAI.';
                document.querySelectorAll('.q-status').forEach(el => {
                    el.className =
                        el.className.replace(/\b(good|warn|bad|neu)\b/g, '').trim() + ' neu';
                    el.textContent = '--';
                });
                document.querySelectorAll('.q-answer').forEach(el => {
                    el.textContent = unavailable;
                });
                document.querySelectorAll('.bar-fill[data-width]').forEach(el => {
                    el.setAttribute('data-width', '0');
                    el.style.width = '0%';
                    el.className = el.className.replace(/\b(green|yellow|red|blue)\b/g, '').trim();
                });
                document.querySelectorAll('.bar-val').forEach(el => {
                    el.textContent = '--';
                    el.className = el.className.replace(/\b(green|yellow|red|blue)\b/g, '').trim();
                });
                document.querySelectorAll('.bc-fill').forEach(el => {
                    el.style.width = '0%';
                });
                document.querySelectorAll('.bc-pct').forEach(el => {
                    el.textContent = '--';
                });
                document.querySelectorAll('.ec-val').forEach(el => {
                    el.textContent = '--';
                });
                document.querySelectorAll('.ec-status').forEach(el => {
                    el.className = el.className.replace(/\b(good|warn|bad)\b/g, '').trim();
                    el.textContent = 'غير متوفر';
                });
                [
                    'contentScore',
                    'bentoVisualScore',
                    'bentoMsgScore',
                    'bentoEngScore',
                    'bentoVarScore',
                ].forEach(id => {
                    const el = document.getElementById(id);
                    if (el) {
                        el.removeAttribute('data-val');
                        el.textContent = '--';
                    }
                });
                const contentCircle = document.getElementById('contentCircle');
                if (contentCircle) {
                    contentCircle.setAttribute('data-percent', '0');
                    contentCircle.setAttribute('data-color', 'var(--yellow)');
                }
                const contentStatusTitle = document.getElementById('contentStatusTitle');
                const contentStatusDesc = document.getElementById('contentStatusDesc');
                if (contentStatusTitle) {
                    contentStatusTitle.textContent = 'تحليل المحتوى غير متوفر';
                    contentStatusTitle.style.color = 'var(--yellow)';
                }
                if (contentStatusDesc) {
                    contentStatusDesc.textContent =
                        'هذه الصفحة لا تعرض تقييماً تقديرياً للمحتوى بدون بيانات OpenAI الخاصة بهذا التقرير.';
                }
                const aiStrategy = document.getElementById('contentAiStrategy');
                if (aiStrategy) {
                    aiStrategy.innerHTML = missingDataHtml(
                        'استراتيجية المحتوى غير متوفرة',
                        'لم يرجع OpenAI استراتيجية محتوى لهذا التقرير، لذلك تم إخفاء أي خطة عامة أو ثابتة.'
                    );
                }
            }
        } catch (__err_content_html) {
            console.error("[RC] Phase 4.4: تعطل قسم content.html:", __err_content_html);
        }
        }

        // ==========================================
        // PAGE: strengths.html & weaknesses.html
        // ==========================================
        if (path.includes('strengths.html') || path.includes('weaknesses.html')) { try {
            const score = Number.isFinite(Number(data.score)) ? Number(data.score) : 0;
            const typeStr = (ai.page_type || data.project_type || 'تجاري').toLowerCase();
            const isService =
                typeStr.includes('service') ||
                typeStr.includes('business') ||
                typeStr.includes('تسويق') ||
                typeStr.includes('شركة') ||
                typeStr.includes('عقارات') ||
                typeStr.includes('influencer') ||
                /marketing|agency|وكالة|b2b/i.test(typeStr);

            // Mini score update for strengths
            if (path.includes('strengths.html')) {
                const strScore = document.getElementById('strScore');
                const strCircle = document.getElementById('strCircle');
                const strTitle = document.getElementById('strTitle');
                const strDesc = document.getElementById('strDesc');

                if (strScore) {
                    strScore.setAttribute('data-val', score);
                    strScore.textContent = score;
                }
                if (strCircle) {
                    strCircle.setAttribute('data-percent', score);
                    if (score > 70) strCircle.setAttribute('data-color', 'var(--green)');
                    else if (score > 40) strCircle.setAttribute('data-color', 'var(--yellow)');
                    else strCircle.setAttribute('data-color', 'var(--red)');
                }
                if (strTitle) {
                    if (score > 70) {
                        strTitle.textContent = '😊 ممتاز';
                        strTitle.style.color = 'var(--green)';
                        if (strDesc)
                            strDesc.textContent =
                                'حسابك مبني على أساس قوي جداً، وهذه النقاط تمثل أصولك التسويقية الرابحة.';
                    } else if (score > 40) {
                        strTitle.textContent = '🤔 جيد';
                        strTitle.style.color = 'var(--yellow)';
                        if (strDesc)
                            strDesc.textContent =
                                'لديك بعض النقاط الجيدة، ولكن تحتاج لتعزيزها لتصبح أصولاً مربحة.';
                    } else {
                        strTitle.textContent = '❌ ضعيف';
                        strTitle.style.color = 'var(--red)';
                        if (strDesc)
                            strDesc.textContent =
                                'نقاط القوة نادرة جداً حالياً، يجب العمل على بناء ميزة تنافسية واضحة.';
                    }
                }

                // Populate Strengths List
                const strList = document.getElementById('strengthsList');
                if (strList) {
                    let strengths = [];

                    if (ai.strengths && Array.isArray(ai.strengths) && ai.strengths.length > 0) {
                        ai.strengths.forEach((str, index) => {
                            // ── حفظ شكل الـ object الكامل لو موجود + اشتقاق نص للبحث/الـ split ──
                            const isObj = str && typeof str === 'object';
                            const text = extractText(str, '');
                            let parts = text.split(':');
                            let rawTitle =
                                isObj && typeof str.title === 'string' && str.title.trim()
                                    ? str.title
                                    : parts.length > 1
                                    ? parts[0]
                                    : text.split(' ').slice(0, 4).join(' ');
                            let title = rawTitle.replace(/[\*\-\#]/g, '').trim();
                            let desc =
                                isObj && typeof str.desc === 'string' && str.desc.trim()
                                    ? str.desc
                                    : parts.length > 1
                                    ? parts.slice(1).join(':').trim()
                                    : text;
                            desc = desc.replace(/[\*\-\#]/g, '').trim();

                            let icon = '✨';
                            if (
                                text.includes('محتوى') ||
                                text.includes('صور') ||
                                text.includes('هوية') ||
                                text.includes('بصري')
                            )
                                icon = '🎨';
                            else if (
                                text.includes('تفاعل') ||
                                text.includes('جمهور') ||
                                text.includes('عملاء') ||
                                text.includes('متابع')
                            )
                                icon = '💬';
                            else if (
                                text.includes('منتج') ||
                                text.includes('خدمة') ||
                                text.includes('عرض') ||
                                text.includes('جودة')
                            )
                                icon = '🛍️';
                            else if (
                                text.includes('إعلان') ||
                                text.includes('تسويق') ||
                                text.includes('مبيعات')
                            )
                                icon = '🚀';

                            // لو الـ object يحمل score خاص نستعمله، وإلا نشتق من الترتيب
                            const score =
                                isObj && typeof str.score === 'number' ? str.score : 95 - index * 5;
                            // ── حفظ جميع الحقول الأصلية (bullets, metric, action, evidence, ...) ──
                            strengths.push(
                                Object.assign({}, isObj ? str : {}, {
                                    title: title,
                                    desc: desc,
                                    score: score,
                                    icon: icon,
                                })
                            );
                        });
                    } else {
                        // No fallback fabrication: when AI returns no strengths,
                        // we display "missing data" placeholder rather than synthesize
                        // strengths locally with arithmetic scores (score+12, +10, etc).
                        // Real strengths must come from Agent 5 (page_14_strengths).
                    }

                    // Sort by highest score (when AI provides scores)
                    strengths.sort((a, b) => (b.score || 0) - (a.score || 0));

                    if (strengths.length === 0) {
                        appendMissingData(
                            strList,
                            'لا توجد نقاط قوة مؤكدة',
                            'لم ترجع بيانات الفحص أو OpenAI نقاط قوة قابلة للتحقق لهذا التقرير.'
                        );
                    }

                    // ═══════════════════════════════════════════════════════════
                    // دالة بناء بطاقة التحليل العميق (7 عناصر) - نقاط القوة
                    // ═══════════════════════════════════════════════════════════
                    // دالة بناء بطاقة التحليل العميق - نقاط القوة (بـ createElement — آمن مع CSP)
                    // ═══════════════════════════════════════════════════════════
                    const buildDeepStrengthCard = item => {
                        const s = item;
                        const hasScore = typeof s.score === 'number' && !isNaN(s.score) && s.score > 0;
                        const val = hasScore ? Math.min(Math.round(s.score), 99) : null;
                        const priority =
                            s.priority || (hasScore && val >= 85 ? 'high' : hasScore && val >= 70 ? 'medium' : 'low');
                        const priorityText =
                            priority === 'high'
                                ? 'عالية'
                                : priority === 'medium'
                                ? 'متوسطة'
                                : 'منخفضة';

                        // الأيقونة بناءً على المحتوى
                        let icon = s.icon || '✨';
                        const text = (s.title || '') + ' ' + (s.desc || s.analysis || '');
                        if (text.includes('توثيق') || text.includes('موثق')) icon = '✅';
                        else if (text.includes('جمهور') || text.includes('متابع')) icon = '👥';
                        else if (text.includes('تفاعل')) icon = '💬';
                        else if (text.includes('Pixel') || text.includes('تتبع')) icon = '📊';
                        else if (text.includes('واتساب') || text.includes('تواصل')) icon = '💬';
                        else if (text.includes('إعلان')) icon = '🚀';
                        else if (text.includes('تقييم') || text.includes('مراجع')) icon = '⭐';
                        else if (text.includes('محتوى') || text.includes('فيديو')) icon = '🎬';
                        else if (text.includes('هوية') || text.includes('Bio')) icon = '🎨';

                        // ── بناء البطاقة بالكامل بـ createElement ──
                        const card = document.createElement('div');
                        card.className = 'deep-card';

                        // header
                        const header = document.createElement('div');
                        header.className = 'deep-header';

                        const iconDiv = document.createElement('div');
                        iconDiv.className = 'deep-icon';
                        iconDiv.textContent = icon;

                        const titleGroup = document.createElement('div');
                        titleGroup.className = 'deep-title-group';

                        const titleDiv = document.createElement('div');
                        titleDiv.className = 'deep-title';
                        // دعم النوع (type) كشارة إضافية
                        if (s.type) {
                            const typeSpan = document.createElement('span');
                            typeSpan.style.cssText =
                                'display:inline-flex;align-items:center;gap:4px;background:rgba(16,185,129,0.15);border:1px solid rgba(16,185,129,0.25);padding:3px 10px;border-radius:20px;font-size:11px;font-weight:800;color:var(--green);margin-right:8px;';
                            typeSpan.textContent = s.type;
                            titleDiv.appendChild(typeSpan);
                        }
                        titleDiv.appendChild(
                            document.createTextNode(s.title || s.name || 'نقطة قوة')
                        );

                        const prioritySpan = document.createElement('span');
                        prioritySpan.className = 'deep-priority ' + priority;
                        prioritySpan.textContent = '🔺 أولوية ' + priorityText;

                        titleGroup.appendChild(titleDiv);
                        titleGroup.appendChild(prioritySpan);

                        const scoreBadge = document.createElement('div');
                        scoreBadge.className = 'deep-score-badge';
                        if (val === null) {
                            scoreBadge.style.display = 'none';
                        } else {
                            const scoreNum = document.createElement('div');
                            scoreNum.className = 'deep-score-num';
                            scoreNum.textContent = val;
                            const scoreLabel = document.createElement('div');
                            scoreLabel.className = 'deep-score-label';
                            scoreLabel.textContent = 'درجة';
                            scoreBadge.appendChild(scoreNum);
                            scoreBadge.appendChild(scoreLabel);
                        }

                        header.appendChild(iconDiv);
                        header.appendChild(titleGroup);
                        header.appendChild(scoreBadge);

                        // body
                        const body = document.createElement('div');
                        body.className = 'deep-body';

                        // دالة مساعدة لإنشاء صف (row)
                        const makeRow = (labelClass, labelText, valueText) => {
                            const row = document.createElement('div');
                            row.className = 'deep-row';
                            const label = document.createElement('div');
                            label.className = 'deep-label ' + labelClass;
                            label.textContent = labelText;
                            const value = document.createElement('div');
                            value.className = 'deep-value';
                            value.textContent = valueText;
                            row.appendChild(label);
                            row.appendChild(value);
                            return row;
                        };

                        // صف الوصف + bullets
                        const descRow = document.createElement('div');
                        descRow.className = 'deep-row';
                        const descLabel = document.createElement('div');
                        descLabel.className = 'deep-label analysis';
                        descLabel.textContent = '📋 الوصف';
                        const descValue = document.createElement('div');
                        descValue.className = 'deep-value';
                        descValue.textContent =
                            s.desc || s.analysis || s.description || 'تحليل هذه النقطة...';
                        // إضافة النقاط الفرعية (bullets) إن وجدت
                        if (s.bullets && s.bullets.length) {
                            const bulletsUl = document.createElement('ul');
                            bulletsUl.style.cssText =
                                'margin:8px 0 0 0;padding:0;list-style:none;display:flex;flex-direction:column;gap:4px;';
                            s.bullets.forEach(b => {
                                const bLi = document.createElement('li');
                                bLi.style.cssText =
                                    'font-size:13px;color:var(--text-gray);font-weight:600;display:flex;align-items:flex-start;gap:7px;';
                                const bMark = document.createElement('span');
                                bMark.style.cssText =
                                    'color:var(--green);flex-shrink:0;margin-top:2px;';
                                bMark.textContent = '◈';
                                bLi.appendChild(bMark);
                                bLi.appendChild(document.createTextNode(b));
                                bulletsUl.appendChild(bLi);
                            });
                            descValue.appendChild(bulletsUl);
                        }
                        descRow.appendChild(descLabel);
                        descRow.appendChild(descValue);

                        body.appendChild(descRow);
                        body.appendChild(
                            makeRow(
                                'evidence',
                                '📊 الدليل',
                                s.evidence || s.metric || 'بناءً على البيانات الفعلية'
                            )
                        );
                        body.appendChild(
                            makeRow(
                                'impact',
                                '💥 التأثير',
                                s.impact || 'تأثير إيجابي على الأداء العام'
                            )
                        );
                        body.appendChild(
                            makeRow(
                                'cause',
                                '🔍 السبب',
                                s.root_cause || s.cause || 'نتيجة الجهود التسويقية الحالية'
                            )
                        );

                        // صندوق الإجراء
                        const actionBox = document.createElement('div');
                        actionBox.className = 'deep-action-box';
                        const actionIcon = document.createElement('div');
                        actionIcon.className = 'action-icon';
                        actionIcon.textContent = '✅';
                        const actionText = document.createElement('div');
                        actionText.className = 'action-text';
                        actionText.textContent = s.action || 'استمر في تعزيز هذه النقطة واستثمارها';
                        actionBox.appendChild(actionIcon);
                        actionBox.appendChild(actionText);
                        body.appendChild(actionBox);

                        card.appendChild(header);
                        card.appendChild(body);
                        return card;
                    };

                    // عرض جميع نقاط القوة بدون حد أقصى — كل النقاط مهما كانت الدرجة
                    while (strList.firstChild) strList.removeChild(strList.firstChild);
                    strengths.forEach(s => {
                        strList.appendChild(buildDeepStrengthCard(s));
                    });
                    // تشغيل العداد والحلقات على البطاقات المولودة ديناميكياً
                    setTimeout(() => {
                        animateCounters();
                        animateRings();
                    }, 100);
                }
            }

            // Mini score update for weaknesses
            if (path.includes('weaknesses.html')) {
                const weakScore = document.getElementById('weakScore');
                const weakCircle = document.getElementById('weakCircle');
                const weakTitle = document.getElementById('weakTitle');
                const weakDesc = document.getElementById('weakDesc');

                const riskIndex = 100 - score;

                if (weakScore) {
                    weakScore.setAttribute('data-val', riskIndex);
                    weakScore.textContent = riskIndex;
                }
                if (weakCircle) {
                    weakCircle.setAttribute('data-percent', riskIndex);
                    if (riskIndex > 60) weakCircle.setAttribute('data-color', 'var(--red)');
                    else if (riskIndex > 30) weakCircle.setAttribute('data-color', 'var(--yellow)');
                    else weakCircle.setAttribute('data-color', 'var(--green)');
                }
                if (weakTitle) {
                    if (riskIndex > 60) {
                        weakTitle.textContent = '⚠ نزيف خطير';
                        weakTitle.style.color = 'var(--red)';
                        if (weakDesc)
                            weakDesc.textContent =
                                'يوجد نقاط اختناق حرجة تسبب في هدر المبيعات اليومية.';
                    } else if (riskIndex > 30) {
                        weakTitle.textContent = '⚠ خطر متوسط';
                        weakTitle.style.color = 'var(--yellow)';
                        if (weakDesc)
                            weakDesc.textContent =
                                'الوضع مستقر لكن يوجد تسريبات مالية تمنعك من مضاعفة أرباحك.';
                    } else {
                        weakTitle.textContent = '✅ وضع آمن';
                        weakTitle.style.color = 'var(--green)';
                        if (weakDesc)
                            weakDesc.textContent =
                                'النقاط السلبية طفيفة ولا تؤثر بشكل كارثي على المبيعات.';
                    }
                }

                // Populate Weaknesses List
                const weakList = document.getElementById('weaknessesList');
                if (weakList) {
                    let weaknesses = [];

                    if (ai.weaknesses && Array.isArray(ai.weaknesses) && ai.weaknesses.length > 0) {
                        ai.weaknesses.forEach((str, index) => {
                            // ── حفظ شكل الـ object الكامل لو موجود + اشتقاق نص للبحث/الـ split ──
                            const isObj = str && typeof str === 'object';
                            const text = extractText(str, '');
                            let parts = text.split(':');
                            let rawTitle =
                                isObj && typeof str.title === 'string' && str.title.trim()
                                    ? str.title
                                    : parts.length > 1
                                    ? parts[0]
                                    : text.split(' ').slice(0, 4).join(' ');
                            let title = rawTitle.replace(/[\*\-\#]/g, '').trim();
                            let desc =
                                isObj && typeof str.desc === 'string' && str.desc.trim()
                                    ? str.desc
                                    : parts.length > 1
                                    ? parts.slice(1).join(':').trim()
                                    : text;
                            desc = desc.replace(/[\*\-\#]/g, '').trim();

                            let icon = '⚠️';
                            if (text.includes('سرعة') || text.includes('بطء')) icon = '🐢';
                            else if (text.includes('أمان') || text.includes('حماية')) icon = '🔓';
                            else if (
                                text.includes('تتبع') ||
                                text.includes('بيكسل') ||
                                text.includes('بيانات')
                            )
                                icon = '👁️‍🗨️';
                            else if (
                                text.includes('دفع') ||
                                text.includes('طلب') ||
                                text.includes('سلة')
                            )
                                icon = '🛒';
                            else if (text.includes('تفاعل') || text.includes('متابعين'))
                                icon = '👻';
                            else if (text.includes('إعلان') || text.includes('حملة')) icon = '📉';
                            else if (text.includes('محتوى') || text.includes('هوية')) icon = '🛑';

                            // لو الـ object يحمل score خاص نستعمله، وإلا نشتق من الترتيب
                            const wScore =
                                isObj && typeof str.score === 'number' ? str.score : 30 + index * 5;
                            // ── حفظ جميع الحقول الأصلية (bullets, metric, action, evidence, ...) ──
                            weaknesses.push(
                                Object.assign({}, isObj ? str : {}, {
                                    title: title,
                                    desc: desc,
                                    score: wScore,
                                    icon: icon,
                                })
                            );
                        });
                    } else {
                        // No fallback fabrication: when AI returns no weaknesses,
                        // we display "missing data" placeholder rather than synthesize
                        // weaknesses locally with arithmetic scores (score-20, -15, etc).
                        // Real weaknesses must come from Agent 5 (page_15_weaknesses).
                    }

                    // Sort by lowest score (worst weaknesses first)
                    weaknesses.sort((a, b) => (a.score || 100) - (b.score || 100));

                    if (weaknesses.length === 0) {
                        appendMissingData(
                            weakList,
                            'لا توجد نقاط ضعف مؤكدة',
                            'لم ترجع بيانات الفحص أو OpenAI نقاط ضعف قابلة للتحقق لهذا التقرير.'
                        );
                    }

                    // ═══════════════════════════════════════════════════════════
                    // دالة بناء بطاقة التحليل العميق (7 عناصر) - نقاط الضعف
                    // ═══════════════════════════════════════════════════════════
                    // دالة بناء بطاقة التحليل العميق - نقاط الضعف (بـ createElement — آمن مع CSP)
                    // ═══════════════════════════════════════════════════════════
                    const buildDeepWeaknessCard = item => {
                        const w = item;
                        const hasScore = typeof w.score === 'number' && !isNaN(w.score) && w.score > 0;
                        const val = hasScore ? Math.max(Math.round(w.score), 5) : null;
                        const priority =
                            w.priority ||
                            (hasScore && val <= 25
                                ? 'critical'
                                : hasScore && val <= 40
                                ? 'high'
                                : val <= 60
                                ? 'medium'
                                : 'low');
                        const priorityText =
                            priority === 'critical'
                                ? 'حرجة'
                                : priority === 'high'
                                ? 'عالية'
                                : priority === 'medium'
                                ? 'متوسطة'
                                : 'منخفضة';

                        // الأيقونة بناءً على المحتوى
                        let icon = w.icon || '⚠️';
                        const text = (w.title || '') + ' ' + (w.desc || w.analysis || '');
                        if (text.includes('Pixel') || text.includes('تتبع')) icon = '📊';
                        else if (text.includes('واتساب') || text.includes('تواصل')) icon = '💬';
                        else if (text.includes('SSL') || text.includes('أمان')) icon = '🔓';
                        else if (text.includes('تفاعل')) icon = '📉';
                        else if (text.includes('توثيق') || text.includes('موثق')) icon = '❌';
                        else if (text.includes('جمهور') || text.includes('متابع')) icon = '👥';
                        else if (text.includes('تقييم') || text.includes('مراجع')) icon = '⭐';
                        else if (text.includes('محتوى')) icon = '📝';
                        else if (text.includes('CTA') || text.includes('تحويل')) icon = '🎯';

                        // ── بناء البطاقة بالكامل بـ createElement ──
                        const card = document.createElement('div');
                        card.className = 'deep-card';

                        // header
                        const header = document.createElement('div');
                        header.className = 'deep-header';

                        const iconDiv = document.createElement('div');
                        iconDiv.className = 'deep-icon';
                        iconDiv.textContent = icon;

                        const titleGroup = document.createElement('div');
                        titleGroup.className = 'deep-title-group';

                        const titleDiv = document.createElement('div');
                        titleDiv.className = 'deep-title';
                        // دعم النوع (type) كشارة إضافية
                        if (w.type) {
                            const typeSpan = document.createElement('span');
                            typeSpan.style.cssText =
                                'display:inline-flex;align-items:center;gap:4px;background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.25);padding:3px 10px;border-radius:20px;font-size:11px;font-weight:800;color:var(--red);margin-right:8px;';
                            typeSpan.textContent = w.type;
                            titleDiv.appendChild(typeSpan);
                        }
                        titleDiv.appendChild(
                            document.createTextNode(w.title || w.name || 'نقطة ضعف')
                        );

                        const prioritySpan = document.createElement('span');
                        prioritySpan.className = 'deep-priority ' + priority;
                        prioritySpan.textContent = '🚨 أولوية ' + priorityText;

                        titleGroup.appendChild(titleDiv);
                        titleGroup.appendChild(prioritySpan);

                        const scoreBadge = document.createElement('div');
                        scoreBadge.className = 'deep-score-badge';
                        if (val === null) {
                            scoreBadge.style.display = 'none';
                        } else {
                            const scoreNum = document.createElement('div');
                            scoreNum.className = 'deep-score-num';
                            scoreNum.textContent = val;
                            const scoreLabel = document.createElement('div');
                            scoreLabel.className = 'deep-score-label';
                            scoreLabel.textContent = 'خطورة';
                            scoreBadge.appendChild(scoreNum);
                            scoreBadge.appendChild(scoreLabel);
                        }

                        header.appendChild(iconDiv);
                        header.appendChild(titleGroup);
                        header.appendChild(scoreBadge);

                        // body
                        const body = document.createElement('div');
                        body.className = 'deep-body';

                        // دالة مساعدة لإنشاء صف (row)
                        const makeRow = (labelClass, labelText, valueText) => {
                            const row = document.createElement('div');
                            row.className = 'deep-row';
                            const label = document.createElement('div');
                            label.className = 'deep-label ' + labelClass;
                            label.textContent = labelText;
                            const value = document.createElement('div');
                            value.className = 'deep-value';
                            value.textContent = valueText;
                            row.appendChild(label);
                            row.appendChild(value);
                            return row;
                        };

                        // صف الوصف + bullets
                        const descRow = document.createElement('div');
                        descRow.className = 'deep-row';
                        const descLabel = document.createElement('div');
                        descLabel.className = 'deep-label analysis';
                        descLabel.textContent = '📋 الوصف';
                        const descValue = document.createElement('div');
                        descValue.className = 'deep-value';
                        descValue.textContent =
                            w.desc || w.analysis || w.description || 'تحليل هذه النقطة...';
                        // إضافة النقاط الفرعية (bullets) إن وجدت
                        if (w.bullets && w.bullets.length) {
                            const bulletsUl = document.createElement('ul');
                            bulletsUl.style.cssText =
                                'margin:8px 0 0 0;padding:0;list-style:none;display:flex;flex-direction:column;gap:4px;';
                            w.bullets.forEach(b => {
                                const bLi = document.createElement('li');
                                bLi.style.cssText =
                                    'font-size:13px;color:var(--text-gray);font-weight:600;display:flex;align-items:flex-start;gap:7px;';
                                const bMark = document.createElement('span');
                                bMark.style.cssText =
                                    'color:var(--red);flex-shrink:0;margin-top:2px;';
                                bMark.textContent = '◈';
                                bLi.appendChild(bMark);
                                bLi.appendChild(document.createTextNode(b));
                                bulletsUl.appendChild(bLi);
                            });
                            descValue.appendChild(bulletsUl);
                        }
                        descRow.appendChild(descLabel);
                        descRow.appendChild(descValue);

                        body.appendChild(descRow);
                        body.appendChild(
                            makeRow(
                                'evidence',
                                '📊 الدليل',
                                w.evidence || w.metric || 'بناءً على البيانات الفعلية'
                            )
                        );
                        body.appendChild(
                            makeRow(
                                'impact',
                                '💥 التأثير',
                                w.impact || 'تأثير سلبي على الأداء العام'
                            )
                        );
                        body.appendChild(
                            makeRow(
                                'cause',
                                '🔍 السبب',
                                w.root_cause || w.cause || 'يحتاج تحديد السبب الجذري'
                            )
                        );

                        // صندوق الإجراء
                        const actionBox = document.createElement('div');
                        actionBox.className = 'deep-action-box';
                        const actionIcon = document.createElement('div');
                        actionIcon.className = 'action-icon';
                        actionIcon.textContent = '🔧';
                        const actionText = document.createElement('div');
                        actionText.className = 'action-text';
                        actionText.textContent =
                            w.action || 'اتخذ إجراءً فورياً لمعالجة هذه النقطة';
                        actionBox.appendChild(actionIcon);
                        actionBox.appendChild(actionText);
                        body.appendChild(actionBox);

                        card.appendChild(header);
                        card.appendChild(body);
                        return card;
                    };

                    // عرض جميع نقاط الضعف بدون حد أقصى — كل النقاط مهما كانت الدرجة
                    while (weakList.firstChild) weakList.removeChild(weakList.firstChild);
                    weaknesses.forEach(w => {
                        weakList.appendChild(buildDeepWeaknessCard(w));
                    });
                    // تشغيل العداد والحلقات على البطاقات المولودة ديناميكياً
                    setTimeout(() => {
                        animateCounters();
                        animateRings();
                    }, 100);
                }
            }
        } catch (__err_strengths_html) {
            console.error("[RC] Phase 4.4: تعطل قسم strengths.html:", __err_strengths_html);
        }
        }

        // ==========================================
        // PAGE: journey.html — رحلة العميل (AI-driven only, single source of truth)
        // ==========================================
        if (path.includes('journey.html')) { try {
            const journeyData = (data.ai_report && data.ai_report.customer_journey) || null;

            // ── Stage name → DOM index mapping (matches journey.html: stage1..5) ──
            const stageMap = {
                awareness: 1,
                attraction: 2,
                trust: 3,
                purchase: 4,
                loyalty: 5,
            };

            const jScoreEl = document.getElementById('journeyScore');
            const jCircleEl = document.getElementById('journeyCircle');
            const jTitleEl = document.getElementById('journeyStatusTitle');
            const jDescEl = document.getElementById('journeyStatusDesc');

            if (!journeyData) {
                // No fake/random fallback. Surface the missing-data state clearly,
                // but DO NOT return — let the P2-2 journey block below still run for
                // technical signals (SSL/Pixel/CTA) which come from scan_result.
                if (jTitleEl) {
                    jTitleEl.innerHTML = '⚠️ تحليل رحلة العميل غير مكتمل';
                    jTitleEl.style.color = 'var(--yellow)';
                }
                if (jDescEl) {
                    jDescEl.innerHTML =
                        'لم يكتمل تحليل الذكاء الاصطناعي لمراحل قمع التحويل لهذا التقرير. يرجى إعادة تشغيل التحليل من لوحة الإدارة.';
                }
                Object.values(stageMap).forEach(num => {
                    const w = document.getElementById('stage' + num + 'Warning');
                    if (w) w.style.display = 'none';
                    const f = document.getElementById('stage' + num + 'FixBox');
                    if (f) f.style.display = 'none';
                });
            } else {
                // ── 1. Overall bottleneck score + status colors/text ──
                const bottleneckKey = journeyData.bottleneck_stage || 'trust';
                const bottleneckScore =
                    journeyData.stages && journeyData.stages[bottleneckKey]
                        ? journeyData.stages[bottleneckKey].score
                        : 45;

                if (jScoreEl) {
                    jScoreEl.setAttribute('data-val', bottleneckScore);
                    jScoreEl.textContent = bottleneckScore;
                }
                if (jCircleEl) {
                    const color =
                        bottleneckScore > 70
                            ? 'var(--green)'
                            : bottleneckScore > 40
                            ? 'var(--yellow)'
                            : 'var(--red)';
                    jCircleEl.setAttribute('data-percent', bottleneckScore);
                    jCircleEl.setAttribute('data-color', color);
                }
                if (jTitleEl) {
                    if (bottleneckScore > 70) {
                        jTitleEl.innerHTML = '✅ مسار سليم';
                        jTitleEl.style.color = 'var(--green)';
                    } else if (bottleneckScore > 40) {
                        jTitleEl.innerHTML = '⚠️ يوجد انسداد';
                        jTitleEl.style.color = 'var(--yellow)';
                    } else {
                        jTitleEl.innerHTML = '❌ نقطة اختناق حرجة';
                        jTitleEl.style.color = 'var(--red)';
                    }
                }
                if (jDescEl && journeyData.psychological_diagnosis) {
                    jDescEl.innerHTML =
                        '<strong>التشخيص النفسي:</strong> ' +
                        sanitize(journeyData.psychological_diagnosis);
                }

                // ── 2. Per-stage rendering — value + analysis from AI; bottleneck in RED ──
                Object.keys(stageMap).forEach(key => {
                    const num = stageMap[key];
                    const stageInfo = (journeyData.stages && journeyData.stages[key]) || null;
                    if (!stageInfo) return;

                    const scoreEl = document.getElementById('stage' + num + 'Score');
                    const descEl = document.getElementById('stage' + num + 'Desc');
                    const boxEl = document.getElementById('stage' + num + 'Box');
                    const warningEl = document.getElementById('stage' + num + 'Warning');
                    const fixBoxEl = document.getElementById('stage' + num + 'FixBox');

                    if (scoreEl) {
                        scoreEl.setAttribute('data-val', stageInfo.score);
                        scoreEl.textContent = stageInfo.score + '%';
                    }
                    if (descEl && stageInfo.analysis) {
                        descEl.textContent = stageInfo.analysis;
                    }

                    const isBottleneck = key === bottleneckKey;
                    if (boxEl) {
                        boxEl.classList.remove('stage-green', 'stage-yellow', 'stage-red');
                        if (isBottleneck) boxEl.classList.add('stage-red');
                        else if (stageInfo.score >= 70) boxEl.classList.add('stage-green');
                        else boxEl.classList.add('stage-yellow');
                    }
                    if (warningEl) warningEl.style.display = isBottleneck ? 'inline-block' : 'none';
                    if (fixBoxEl) {
                        fixBoxEl.style.display = isBottleneck ? 'block' : 'none';
                        if (isBottleneck && Array.isArray(journeyData.bottleneck_fix)) {
                            const fixHtml = journeyData.bottleneck_fix
                                .map((fix, i) => i + 1 + '. ' + sanitize(fix))
                                .join('<br>');
                            const pEl = fixBoxEl.querySelector('p');
                            if (pEl) pEl.innerHTML = fixHtml;
                        }
                    }
                });

                setTimeout(() => {
                    animateCounters();
                    animateRings();
                }, 100);
            } // end else (journeyData present)
        } catch (__err_journey_html) {
            console.error("[RC] Phase 4.4: تعطل قسم journey.html:", __err_journey_html);
        }
        }

        // ==========================================
        // PAGE: recommendations.html
        // ==========================================
        if (path.includes('recommendations.html')) { try {
            const recClientName = document.getElementById('recClientName');
            const recHandle = document.getElementById('recHandle');
            const recTotalCount = document.getElementById('recTotalCount');
            const recProfileImg = document.getElementById('recProfileImg');

            if (recClientName) recClientName.textContent = clientName;
            if (recHandle)
                recHandle.textContent = clientUrl
                    ? '@' + clientUrl.replace('https://', '').replace('www.', '').split('/')[0]
                    : '@' + clientName.replace(/\s+/g, '');
            if (recProfileImg && clientName)
                recProfileImg.textContent = Array.from(clientName)[0].toUpperCase();

            const recHighList = document.getElementById('recHighList');
            const recMedList = document.getElementById('recMedList');
            const recLowList = document.getElementById('recLowList');

            if (
                ai.recommendations &&
                Array.isArray(ai.recommendations) &&
                ai.recommendations.length > 0
            ) {
                let highHtml = '';
                let medHtml = '';
                let lowHtml = '';
                let totalRecs = 0;

                // ── Free tier: عرض 3 توصيات بالضبط (slice(0, 3)). Paid: القائمة كاملة. ──
                const isPaidTier = true; // data.package_tier === 'paid'; // معطل مؤقتاً لغرض التصميم
                const visibleRecs = isPaidTier
                    ? ai.recommendations
                    : ai.recommendations.slice(0, 3);
                const totalCount = ai.recommendations.length;

                // ═══════════════════════════════════════════════════════════════════
                // دالة بناء بطاقة التوصية التفصيلية
                // ═══════════════════════════════════════════════════════════════════
                const buildRecCard = (rec, index) => {
                    totalRecs++;
                    const priority = rec.priority
                        ? rec.priority.toLowerCase()
                        : totalRecs <= 2
                        ? 'high'
                        : totalRecs <= 4
                        ? 'medium'
                        : 'low';
                    // تحديد الفئة: critical أولاً، ثم high، ثم med، ثم low
                    const iconClass = priority.includes('critical')
                        ? 'critical'
                        : priority.includes('high')
                        ? 'high'
                        : priority.includes('med')
                        ? 'med'
                        : 'low';
                    const iconEmoji =
                        rec.icon ||
                        (priority.includes('critical')
                            ? '🛑'
                            : priority.includes('high')
                            ? '🔴'
                            : priority.includes('med')
                            ? '🟡'
                            : '🟢');
                    const priorityText = priority.includes('critical')
                        ? 'حرجة'
                        : priority.includes('high')
                        ? 'قصوى'
                        : priority.includes('med')
                        ? 'متوسطة'
                        : 'مستقبلية';

                    const title = sanitize(rec.title || 'توصية هامة');
                    const desc = sanitize(rec.desc || rec.description || '');
                    const whyNow = sanitize(rec.why_now || '');
                    const evidence = sanitize(rec.evidence || '');
                    const roi = sanitize(rec.roi || '');
                    const time = sanitize(rec.time_to_implement || '');
                    const difficulty = sanitize(rec.difficulty || '');
                    const scoreImpact = sanitize(rec.score_impact || '');
                    const strategicContext = sanitize(rec.strategic_context || '');

                    // الخطوات (bullets)
                    let bulletsHtml = '';
                    if (rec.bullets && Array.isArray(rec.bullets) && rec.bullets.length > 0) {
                        bulletsHtml = `<div class="rec-steps">
                <div class="rec-steps-title">📋 خطوات التنفيذ:</div>
                ${rec.bullets.map(b => `<div class="rec-step">${sanitize(b)}</div>`).join('')}
              </div>`;
                    }

                    return `
              <div class="rec-card-detailed ${iconClass}">
                <div class="rec-header">
                  <div class="rec-icon-large ${iconClass}">${iconEmoji}</div>
                  <div class="rec-header-content">
                    <div class="rec-priority-badge ${iconClass}">🚨 أولوية ${priorityText}</div>
                    <h4 class="rec-title">${title}</h4>
                  </div>
                </div>
                <div class="rec-body">
                  <div class="rec-desc">${desc}</div>

                  ${
                      strategicContext
                          ? `<div class="rec-meta strategic"><span class="meta-label">🎯 السياق الاستراتيجي:</span> ${strategicContext}</div>`
                          : ''
                  }
                  ${
                      evidence
                          ? `<div class="rec-meta"><span class="meta-label">📊 الدليل:</span> ${evidence}</div>`
                          : ''
                  }
                  ${
                      whyNow
                          ? `<div class="rec-meta highlight"><span class="meta-label">⚡ لماذا الآن:</span> ${whyNow}</div>`
                          : ''
                  }

                  ${bulletsHtml}

                  <div class="rec-metrics">
                    ${
                        roi
                            ? `<div class="rec-metric"><span class="metric-icon">💰</span><span class="metric-label">العائد:</span> ${roi}</div>`
                            : ''
                    }
                    ${
                        time
                            ? `<div class="rec-metric"><span class="metric-icon">⏱️</span><span class="metric-label">الوقت:</span> ${time}</div>`
                            : ''
                    }
                    ${
                        difficulty
                            ? `<div class="rec-metric"><span class="metric-icon">🔧</span><span class="metric-label">الصعوبة:</span> ${difficulty}</div>`
                            : ''
                    }
                    ${
                        scoreImpact
                            ? `<div class="rec-metric"><span class="metric-icon">📈</span><span class="metric-label">التأثير:</span> ${scoreImpact}</div>`
                            : ''
                    }
                  </div>
                </div>
              </div>
            `;
                };

                visibleRecs.forEach((rec, i) => {
                    const priority = rec.priority ? rec.priority.toLowerCase() : 'medium';
                    const cardHtml = buildRecCard(rec, i);
                    // critical توضع في high (القصوى)، وكذلك high
                    if (priority.includes('critical') || priority.includes('high'))
                        highHtml += cardHtml;
                    else if (priority.includes('med')) medHtml += cardHtml;
                    else lowHtml += cardHtml;
                });

                if (recHighList)
                    recHighList.innerHTML =
                        highHtml ||
                        '<div style="padding: 20px; color: var(--text-gray);">✅ لا توجد توصيات قصوى حالياً — وضع ممتاز!</div>';
                if (recMedList)
                    recMedList.innerHTML =
                        medHtml ||
                        '<div style="padding: 20px; color: var(--text-gray);">لا توجد توصيات متوسطة.</div>';
                if (recLowList)
                    recLowList.innerHTML =
                        lowHtml ||
                        '<div style="padding: 20px; color: var(--text-gray);">لا توجد توصيات مستقبلية حالياً.</div>';

                if (recTotalCount) {
                    recTotalCount.textContent = isPaidTier
                        ? totalCount + ' إجراء'
                        : visibleRecs.length + ' من ' + totalCount + ' إجراء (باقة مجانية)';
                }
            } else {
                // Fallback if no recommendations array
                if (recHighList)
                    recHighList.innerHTML =
                        '<div style="padding: 20px; color: var(--text-gray);">الرجاء مراجعة نقاط الضعف لمزيد من التفاصيل.</div>';
                if (recMedList)
                    recMedList.innerHTML =
                        '<div style="padding: 20px; color: var(--text-gray);">الرجاء مراجعة خطة العمل لمزيد من التفاصيل.</div>';
                if (recLowList)
                    recLowList.innerHTML =
                        '<div style="padding: 20px; color: var(--text-gray);">الرجاء مراجعة نقاط القوة لمزيد من التفاصيل.</div>';
                if (recTotalCount) recTotalCount.textContent = '... إجراء';
            }
        } catch (__err_recommendations_html) {
            console.error("[RC] Phase 4.4: تعطل قسم recommendations.html:", __err_recommendations_html);
        }
        }

        // ==========================================
        // GLOBAL: INNER PAGES PROFILE TAGS
        // ==========================================
        const profileAccTypes = document.querySelectorAll('#profileAccountType');
        const isBusinessAcc = srObj.instagram ? srObj.instagram.is_business : false;
        profileAccTypes.forEach(
            el => (el.textContent = isBusinessAcc ? 'حساب أعمال' : 'حساب تجاري')
        );

        const profileNiches = document.querySelectorAll('#profileNiche');
        const pt = ai.page_type || data.project_type || 'غير محدد';
        profileNiches.forEach(el => (el.textContent = sanitize(pt)));

        // ==========================================
        // PAGE: roadmap-30d.html
        // ==========================================
        if (path.includes('roadmap-30d.html')) { try {
            const roadmap =
                (data.ai_report &&
                    (data.ai_report.action_month ||
                        data.ai_report.roadmap_30d ||
                        data.ai_report.plan_30d)) ||
                data.action_month ||
                null;
            const getWeek = num => {
                if (!roadmap) return null;
                return (
                    roadmap['week' + num] ||
                    roadmap['w' + num] ||
                    (Array.isArray(roadmap.weeks) ? roadmap.weeks[num - 1] : null) ||
                    null
                );
            };
            const asArray = value =>
                Array.isArray(value)
                    ? value
                    : typeof value === 'string' && value.trim()
                    ? [value]
                    : [];

            if (!roadmap) {
                for (let i = 1; i <= 4; i++) {
                    setTextIf('titleW' + i, 'غير متوفر من بيانات التقرير');
                    const goals = document.getElementById('goalsW' + i);
                    if (goals) goals.innerHTML = '<span class="goal-tag">غير متوفر</span>';
                    const tasks = document.getElementById('tasksW' + i);
                    if (tasks)
                        tasks.innerHTML = missingDataHtml(
                            'خطة الأسبوع غير متوفرة',
                            'لم يرجع OpenAI خطة 30 يوم لهذا التقرير، لذلك لا يتم عرض مهام عامة أو ثابتة.'
                        );
                    setTextIf('kpiW' + i, 'غير متوفر بدون خطة شهرية صادرة من بيانات التقرير.');
                }
                setTextIf(
                    'expectedResultText',
                    'النتيجة المتوقعة غير متوفرة لأن خطة 30 يوم لم ترجع ضمن بيانات هذا التقرير.'
                );
            } else {
                for (let i = 1; i <= 4; i++) {
                    const week = getWeek(i) || {};
                    setTextIf('titleW' + i, week.title || week.name || `الأسبوع ${i}`);
                    const goals = document.getElementById('goalsW' + i);
                    const weekGoals = asArray(week.goals || week.objectives || week.tags);
                    if (goals) {
                        goals.innerHTML = weekGoals.length
                            ? weekGoals
                                  .map(goal => `<span class="goal-tag">${sanitize(goal)}</span>`)
                                  .join('')
                            : '<span class="goal-tag">غير محدد من البيانات</span>';
                    }
                    const tasks = document.getElementById('tasksW' + i);
                    const weekTasks = asArray(week.tasks || week.actions || week.steps);
                    if (tasks) {
                        tasks.innerHTML = weekTasks.length
                            ? weekTasks
                                  .map(
                                      task =>
                                          `<div class="task-item"><div class="task-check">✓</div><div class="task-text">${sanitize(
                                              task
                                          )}</div></div>`
                                  )
                                  .join('')
                            : missingDataHtml(
                                  'مهام الأسبوع غير متوفرة',
                                  'هذا الأسبوع موجود في الخطة لكن لم ترجع له مهام واضحة.'
                              );
                    }
                    setTextIf(
                        'kpiW' + i,
                        week.kpi || week.success_metric || week.metric || 'غير محدد من البيانات'
                    );
                }
                setTextIf(
                    'expectedResultText',
                    roadmap.expected_result ||
                        roadmap.expected_outcome ||
                        data.expected_result ||
                        'غير محدد من البيانات'
                );
            }
        } catch (__err_roadmap_30d_html) {
            console.error("[RC] Phase 4.4: تعطل قسم roadmap-30d.html:", __err_roadmap_30d_html);
        }
        }

        // ==========================================
        // PAGE: plan.html
        // ==========================================
        if (path.includes('plan.html')) { try {
            // Update client name
            const planName = document.getElementById('planClientName');
            if (planName) planName.textContent = clientName;

            // Phase 1: Quick Wins (from action_week)
            const phase1 = document.getElementById('tasksPhase1');
            if (phase1 && data.action_week && data.action_week.length > 0) {
                phase1.innerHTML = data.action_week
                    .map(
                        action =>
                            `<div class="rm-task"><i style="color:var(--green);">✓</i> ${escapeHtml(extractText(action))}</div>`
                    )
                    .join('');
            }

            // Phase 2: Core Optimization (from High/Med recommendations)
            const phase2 = document.getElementById('tasksPhase2');
            if (phase2 && data.recommendations && data.recommendations.length > 0) {
                const coreTasks = data.recommendations
                    .filter(
                        r =>
                            r.priority === 'critical' ||
                            r.priority === 'high' ||
                            r.priority === 'medium'
                    )
                    .map(r => r.title);
                if (coreTasks.length > 0) {
                    phase2.innerHTML = coreTasks
                        .slice(0, 6)
                        .map(
                            title =>
                                `<div class="rm-task"><i style="color:var(--yellow);">⚡</i> ${escapeHtml(title || '')}</div>`
                        )
                        .join('');
                } else {
                    phase2.innerHTML = `<div class="rm-task"><i>!</i> لا توجد توصيات مؤكدة من بيانات التقرير لهذه المرحلة.</div>`;
                }
            }

            // Phase 3: Scaling (Dynamic from data)
            const phase3 = document.getElementById('tasksPhase3');
            if (phase3) {
                let scaleTasks = [];

                // بناءً على نقاط القوة
                if (data.strengths && data.strengths.length > 0) {
                    const topStrength =
                        data.strengths[0].title || data.strengths[0].name || data.strengths[0];
                    scaleTasks.push('استغلال نقطة القوة: ' + topStrength);
                }

                // بناءً على حالة الإعلانات
                if (sr.ads_library && sr.ads_library.total_ads > 0) {
                    scaleTasks.push(
                        'مضاعفة ميزانية الحملات الرابحة من ' +
                            sr.ads_library.total_ads +
                            ' إعلان حالي'
                    );
                } else if (sr.hasPixel || sr.has_fb_pixel) {
                    scaleTasks.push('إطلاق أول حملة إعلانية — البنية التحتية جاهزة');
                }

                // بناءً على عدد المتابعين
                const totalF =
                    (sr.facebook?.followers || 0) +
                    (sr.instagram?.followers || 0) +
                    (sr.tiktok?.followers || 0);
                if (totalF >= 1000) {
                    scaleTasks.push(
                        'إطلاق Retargeting لـ ' + totalF.toLocaleString() + ' متابع حالي'
                    );
                }

                // بناءً على التقييمات
                if (sr.facebook && sr.facebook.rating > 0) {
                    scaleTasks.push(
                        'بناء برنامج ولاء — تقييمك ' + sr.facebook.rating + '/5 يعطي أساساً'
                    );
                } else if (sr.facebook && sr.facebook.rating === 0) {
                    scaleTasks.push('بناء برنامج ولاء بعد جمع 10+ تقييمات');
                }

                // بناءً على المنصات
                const activePlats = [];
                if (sr.facebook?.followers > 0) activePlats.push('Facebook');
                if (sr.instagram?.followers > 0) activePlats.push('Instagram');
                if (sr.tiktok?.followers > 0) activePlats.push('TikTok');
                if (activePlats.length >= 2) {
                    scaleTasks.push('التوسع في استهداف شرائح جديدة على ' + activePlats.join(' و'));
                }

                phase3.innerHTML = scaleTasks.length
                    ? scaleTasks
                          .slice(0, 4)
                          .map(
                              task =>
                                  `<div class="rm-task"><i style="color:var(--primary);">🚀</i> ${escapeHtml(task)}</div>`
                          )
                          .join('')
                    : `<div class="rm-task"><i>!</i> لا توجد بيانات توسع مؤكدة لهذا التقرير.</div>`;
            }

            // ROI from real Meta Ads Manager metrics only.
            const roiVals = document.querySelectorAll('.roi-card .val');
            if (roiVals.length >= 3) {
                const metrics =
                    sr.ads_library && sr.ads_library.real_metrics
                        ? sr.ads_library.real_metrics
                        : null;
                if (metrics) {
                    roiVals[0].textContent =
                        metrics.conversion_rate != null
                            ? String(metrics.conversion_rate)
                            : 'غير متوفر';
                    roiVals[1].textContent =
                        metrics.roas != null ? String(metrics.roas) : 'غير متوفر';
                    roiVals[2].textContent =
                        metrics.cpa != null ? String(metrics.cpa) : 'غير متوفر';
                } else {
                    roiVals[0].textContent = 'غير متوفر';
                    roiVals[1].textContent = 'غير متوفر';
                    roiVals[2].textContent = 'غير متوفر';
                }
            }
        } catch (__err_plan_html) {
            console.error("[RC] Phase 4.4: تعطل قسم plan.html:", __err_plan_html);
        }
        }

        // ==========================================
        // P2-3 PAGE: packages.html — شخصنة بالدرجة الحقيقية
        // ==========================================
        if (path.includes('packages.html')) { try {
            const score = data.score || 0;

            // تحديث العنوان الرئيسي بالاسم
            const heroTitle = document.querySelector(
                '.packages-hero h1, .hero-title, .page-title, h1'
            );
            if (heroTitle && clientName !== 'العميل') {
                heroTitle.innerHTML = heroTitle.innerHTML.replace(
                    /العميل|الباقة المثالية/,
                    clientName + '، الباقة المثالية لك'
                );
            }

            // تحديث النص التمهيدي بالدرجة
            const heroSub = document.querySelector(
                '.packages-hero p, .hero-subtitle, .page-subtitle'
            );
            if (heroSub) {
                const tier =
                    score >= 70
                        ? 'جيد (يحتاج تسريع النمو)'
                        : score >= 40
                        ? 'متوسط (يحتاج تأسيس قوي)'
                        : 'يحتاج تدخل عاجل وشامل';
                heroSub.innerHTML = `بناءً على تحليلنا لحسابك، حصلت على درجة <strong style="color:var(--primary)">${score}/100</strong> — مستوى: ${tier}`;
            }

            // إبراز الباقة الموصى بها تلقائياً
            const pkgs = document.querySelectorAll('.package-card, .pkg-card, .pricing-card');
            let recommended = 0; // starter
            if (score >= 40 && score < 70) recommended = 1; // growth
            if (score >= 70) recommended = 2; // pro

            pkgs.forEach((pkg, i) => {
                pkg.style.transition = 'all 0.4s ease';
                if (i === recommended) {
                    pkg.style.borderColor = 'var(--primary)';
                    pkg.style.transform = 'translateY(-12px) scale(1.02)';
                    pkg.style.boxShadow = '0 20px 40px rgba(245,142,26,0.25)';
                    // إضافة شارة "موصى بها"
                    if (!pkg.querySelector('.recommended-badge')) {
                        pkg.insertAdjacentHTML(
                            'afterbegin',
                            `
                <div class="recommended-badge" style="
                  position:absolute; top:0; right:0;
                  background:var(--primary); color:#fff;
                  font-size:12px; font-weight:900;
                  padding:6px 16px; border-bottom-left-radius:16px;
                ">⭐ موصى بها لك</div>`
                        );
                        if (getComputedStyle(pkg).position === 'static')
                            pkg.style.position = 'relative';
                    }
                }
            });

            // تحديث CTA بالاسم الحقيقي
            document.querySelectorAll('.pkg-cta, .cta-btn, .contact-btn').forEach(btn => {
                if (btn.textContent.includes('ابدأ') || btn.textContent.includes('تواصل')) {
                    btn.setAttribute('data-name', clientName);
                }
            });
        } catch (__err_packages_html) {
            console.error("[RC] Phase 4.4: تعطل قسم packages.html:", __err_packages_html);
        }
        }

        // ── Re-trigger animations ──────────────────────────────
        if (typeof animateCounters === 'function') animateCounters();
        if (typeof animateRings === 'function') animateRings();

        } catch (err) {
            // ── Error Boundary catch — Phase 4.4 ───────────────────
            console.error('[RC] Al-Abeer: خطأ في عرض البيانات:', err);
            try {
                const main = document.querySelector('main') || document.querySelector('.main-content') || document.body;
                if (main) {
                    const errorDiv = document.createElement('div');
                    errorDiv.style.cssText = 'background:rgba(254,242,242,0.1);border:1px solid rgba(252,165,165,0.4);color:#fca5a5;padding:20px;border-radius:12px;margin:20px;text-align:center;direction:rtl;font-family:Cairo,sans-serif;';
                    const h3 = document.createElement('h3');
                    h3.textContent = '⚠️ خطأ في عرض بيانات التقرير';
                    const p1 = document.createElement('p');
                    p1.style.marginTop = '8px';
                    p1.textContent = 'حدث خطأ أثناء عرض التقرير. يرجى تحديث الصفحة أو إعادة تشغيل التحليل.';
                    const small = document.createElement('small');
                    small.style.cssText = 'display:block;margin-top:8px;opacity:0.6;direction:ltr;';
                    small.textContent = (err && err.message) ? err.message : String(err);
                    errorDiv.appendChild(h3);
                    errorDiv.appendChild(p1);
                    errorDiv.appendChild(small);
                    main.prepend(errorDiv);
                }
            } catch (innerErr) {
                console.error('[RC] فشل أيضاً عرض رسالة الخطأ:', innerErr);
            }
        }
    }; // end renderData

    // (Animation Helpers have been moved to the top of the file)

    // =========================================================================
    // 6. محرك تصدير الـ PDF (Global PDF Engine)
    // =========================================================================
    const pdfButtons = document.querySelectorAll('.btn-outline, .btn-pdf');

    pdfButtons.forEach(btn => {
        if (
            btn.textContent.toLowerCase().includes('pdf') ||
            btn.textContent.includes('تصدير') ||
            btn.textContent.includes('تحميل')
        ) {
            btn.addEventListener('click', e => {
                e.preventDefault();
                const originalText = btn.innerHTML;
                btn.innerHTML = '<span>جاري التصدير...</span> ⏳';
                btn.style.pointerEvents = 'none';

                // 1. Load html2pdf dynamically if not exists
                if (typeof html2pdf === 'undefined') {
                    const script = document.createElement('script');
                    script.src =
                        'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js';
                    script.onload = () => generatePDF(btn, originalText);
                    document.head.appendChild(script);
                } else {
                    generatePDF(btn, originalText);
                }
            });
        }
    });

    function generatePDF(btn, originalText) {
        // 2. Select target content (Main Content without Sidebar)
        let targetElement = document.querySelector('.main-content') || document.body;

        // We clone the element to modify it for print without affecting the UI
        const clone = targetElement.cloneNode(true);
        const tempContainer = document.createElement('div');
        tempContainer.appendChild(clone);

        // Clean up UI specific elements from the clone
        const topbar = clone.querySelector('.topbar');
        if (topbar) topbar.remove(); // Remove buttons from PDF

        // Override specific CSS for better PDF rendering
        clone.style.padding = '20px';
        clone.style.background = '#09090b';
        clone.style.color = '#fff';

        // Remove 3D animations from clone cards
        clone.querySelectorAll('.card, .rec-card, .rm-phase, .roi-card').forEach(el => {
            el.style.transform = 'none';
            el.style.boxShadow = 'none';
            el.style.border = '1px solid rgba(255,255,255,0.1)';
        });

        // 3. Configure html2pdf options
        const pageTitle = document.title.split('—')[0].trim() || 'التقرير';
        // `clientName` is set inside renderData() and lives in the outer DOMContentLoaded
        // closure, but generatePDF is hoisted at module scope. typeof guards the
        // ReferenceError so we fall back to a generic label when the page bypassed renderData.
        // eslint-disable-next-line no-undef
        const clientNameStr = typeof clientName !== 'undefined' ? clientName : 'العميل';
        const fileName = `العبير_${pageTitle}_${clientNameStr}.pdf`;

        const opt = {
            margin: 10,
            filename: fileName,
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2, useCORS: true, logging: false, backgroundColor: '#09090b' },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
        };

        // 4. Generate and Download
        html2pdf()
            .set(opt)
            .from(clone)
            .save()
            .then(() => {
                // Restore button state
                btn.innerHTML = originalText;
                btn.style.pointerEvents = 'auto';
            })
            .catch(err => {
                console.error('PDF Generation Error:', err);
                btn.innerHTML = '<span>حدث خطأ</span> ❌';
                setTimeout(() => {
                    btn.innerHTML = originalText;
                    btn.style.pointerEvents = 'auto';
                }, 3000);
            });
    }

    // ============================================================
    // renderAdsSection — رسم قسم الإعلانات بالبيانات الحقيقية أو الاحتياطية
    // ============================================================
    function buildDetailedAdsAnalysis(data, srObj, clientName) {
        const sr = srObj || {};
        const adsLib = sr.ads_library || {};
        const mappedAds = Array.isArray(adsLib.ads) ? adsLib.ads : [];
        const rawAds = Array.isArray(adsLib.raw_items) ? adsLib.raw_items : [];
        const sourceAds = (rawAds.length ? rawAds : mappedAds).slice(0, 30);
        const allAds = sourceAds.map((item, index) => {
            const text =
                item.body ||
                item.text ||
                item.primary_text ||
                item.title ||
                item.headline ||
                item.linkTitle ||
                '';
            const headline = item.linkTitle || item.headline || item.title || '';
            const landing = item.linkUrl || item.ctaUrl || item.landing_url || item.url || '';
            const cta = item.ctaText || item.cta_type || item.cta || '';
            const platforms = Array.isArray(item.platforms)
                ? item.platforms
                : item.platform
                ? [item.platform]
                : [];
            const active = item.active !== undefined ? !!item.active : item.is_active !== false;
            return {
                index,
                text: String(text || '').trim(),
                headline: String(headline || '').trim(),
                landing: String(landing || '').trim(),
                cta: String(cta || '').trim(),
                platforms,
                active,
                start: item.startDate || item.start_date || '',
                end: item.endDate || item.end_date || '',
                impressions: item.total_impressions || item.impressions || '',
                spend: item.spend || item.spend_range || '',
            };
        });

        const activeAds = allAds.filter(ad => ad.active);
        const inactiveAds = allAds.filter(ad => !ad.active);
        const joinedText = allAds
            .map(ad => `${ad.text} ${ad.headline} ${ad.cta} ${ad.landing}`)
            .join(' ')
            .toLowerCase();
        const platforms = [
            ...new Set(
                allAds
                    .flatMap(ad => ad.platforms)
                    .filter(Boolean)
                    .map(String)
            ),
        ];
        const withLanding = allAds.filter(ad => ad.landing).length;
        const hasPixel = !!(
            sr.hasPixel ||
            sr.pixel ||
            sr.meta_pixel ||
            (sr.website_scan && (sr.website_scan.has_pixel || sr.website_scan.hasPixel))
        );
        const hasSsl = !!(
            sr.hasSSL ||
            sr.ssl ||
            (sr.website_scan && (sr.website_scan.has_ssl || sr.website_scan.ssl))
        );
        const hasContact = !!(
            sr.hasWhatsApp ||
            sr.whatsapp ||
            sr.contact ||
            (sr.website_scan && (sr.website_scan.has_whatsapp || sr.website_scan.has_contact))
        );
        const hasSiteCta = !!(
            sr.hasCTA ||
            sr.cta ||
            (sr.website_scan && (sr.website_scan.has_cta || sr.website_scan.cta_count > 0))
        );
        const duplicateCount = (() => {
            const seen = new Set();
            let dupes = 0;
            allAds.forEach(ad => {
                const key = (ad.text || ad.headline)
                    .replace(/\s+/g, ' ')
                    .trim()
                    .slice(0, 120)
                    .toLowerCase();
                if (!key) return;
                if (seen.has(key)) dupes++;
                else seen.add(key);
            });
            return dupes;
        })();
        const directSale = /(shop|buy|order|purchase|sale|خصم|اطلب|اشتري|تسوق|احجز|عرض|سعر)/i.test(
            joinedText
        );
        const leadGen =
            /(whatsapp|message|contact|lead|form|call|استشارة|تواصل|واتساب|احجز|سجل|اتصل)/i.test(
                joinedText
            );
        const trustTerms =
            /(review|testimonial|case|before|after|guarantee|تقييم|آراء|ضمان|نتائج|قبل|بعد|قصة|ثقة)/i.test(
                joinedText
            );
        const awarenessTerms = /(learn|discover|brand|story|تعرف|اكتشف|قصة|وعي|معلومة)/i.test(
            joinedText
        );
        const campaignType =
            directSale && leadGen
                ? 'حملة مختلطة بين البيع وتوليد العملاء'
                : directSale
                ? 'حملة بيع مباشر'
                : leadGen
                ? 'حملة توليد عملاء محتملين'
                : awarenessTerms
                ? 'حملة وعي وتثقيف'
                : 'حملة غير واضحة الهدف من النصوص المتاحة';
        const objective = directSale
            ? 'تحويلات ومبيعات'
            : leadGen
            ? 'رسائل / عملاء محتملون'
            : awarenessTerms
            ? 'وعي بالعلامة وبناء ثقة'
            : 'يحتاج تأكيد من إعدادات Ads Manager';
        const conversionReadyScore = [
            withLanding > 0,
            hasPixel,
            hasSsl,
            hasContact || hasSiteCta,
        ].filter(Boolean).length;
        const conversionReady =
            conversionReadyScore >= 3
                ? 'نعم، الصفحة تبدو قابلة للتحويل مع تحسينات بسيطة'
                : conversionReadyScore === 2
                ? 'جزئياً، توجد عناصر تحويل لكن التتبع أو الثقة يحتاجان تقوية'
                : 'لا، الإعلان يرسل حركة بدون بنية تحويل كافية';
        const hasRealMetrics = !!(
            adsLib.real_metrics &&
            (adsLib.real_metrics.spend || adsLib.real_metrics.roas)
        );
        const budgetSignal = hasRealMetrics
            ? `الأرقام دقيقة من الحساب المربوط: الإنفاق ${
                  adsLib.real_metrics.spend || 'غير متاح'
              }، ROAS ${adsLib.real_metrics.roas || 'غير متاح'}، CPC ${
                  adsLib.real_metrics.cpc || 'غير متاح'
              }.`
            : 'لا يمكن الحكم رقمياً من مكتبة Meta العامة؛ يلزم ربط Ads Manager لمعرفة الإنفاق وROAS';
        const wasteItems = [];
        if (duplicateCount > 0) wasteItems.push(`تكرار واضح في ${duplicateCount} رسالة/إعلان`);
        if (withLanding < Math.max(1, Math.ceil(allAds.length * 0.6)))
            wasteItems.push('عدد كبير من الإعلانات بلا رابط هبوط واضح');
        if (!hasPixel) wasteItems.push('لا يوجد تتبع Pixel مؤكد');
        if (directSale && !trustTerms) wasteItems.push('بيع مباشر قبل بناء الثقة');
        if (!platforms.length) wasteItems.push('المنصات غير ظاهرة في البيانات المسحوبة');
        const messages = [
            ...new Map(
                allAds
                    .map(ad => {
                        const msg = [ad.headline, ad.text, ad.cta ? `CTA: ${ad.cta}` : '']
                            .filter(Boolean)
                            .join(' — ')
                            .trim();
                        return [msg.replace(/\s+/g, ' ').slice(0, 220), msg];
                    })
                    .filter(([key]) => key)
                    .slice(0, 8)
            ).values(),
        ];
        const businessGoal =
            data.business_goal || data.goal || data.objective || sr.business_goal || '';
        const goalFit = businessGoal
            ? `الهدف التجاري المسجل: ${businessGoal}. الحملة الحالية تميل إلى ${objective}، ويجب مطابقة KPI معها.`
            : `لا يوجد هدف تجاري صريح في البيانات؛ الحملة الظاهرة تميل إلى ${objective}.`;
        const platformFit = platforms.length
            ? `المنصات المرصودة: ${platforms.join(
                  '، '
              )}. مناسبة غالباً للوعي والرسائل، ويجب فصل البيع المباشر عن إعادة الاستهداف.`
            : 'لا توجد منصات كافية للحكم؛ نحتاج بيانات المنصة من كل إعلان أو من الحساب الإعلاني.';
        const rawCount = Number(adsLib.raw_count || rawAds.length || 0);
        const mappedCount = mappedAds.length;

        return {
            summary: `تم تحليل ${allAds.length} إعلان من آخر البيانات المتاحة: ${activeAds.length} نشط و${inactiveAds.length} متوقف. ${campaignType}، والهدف المرجح: ${objective}.`,
            meta: [
                hasRealMetrics
                    ? 'المصدر: Meta Ads Manager المربوط'
                    : 'المصدر: مكتبة الإعلانات العامة',
                `آخر ${allAds.length || 0} إعلان`,
                `Raw: ${rawCount}`,
                `Mapped: ${mappedCount}`,
                adsLib.actor_used ? `Actor: ${adsLib.actor_used}` : '',
            ].filter(Boolean),
            cards: [
                {
                    title: 'نوع الحملة الحالية',
                    body: campaignType,
                    evidence: `بناء على CTA، النصوص، وروابط الهبوط في آخر ${allAds.length} إعلان.`,
                },
                {
                    title: 'الهدف الإعلاني',
                    body: objective,
                    evidence:
                        'هذا استنتاج من مكتبة الإعلانات، وليس بديلاً عن Objective داخل Ads Manager.',
                },
                {
                    title: 'توافق الحملة مع الهدف التجاري',
                    body: goalFit,
                    evidence: businessGoal
                        ? 'تمت المقارنة مع الهدف المخزن في التقرير.'
                        : 'ينصح بإضافة هدف تجاري صريح للفحص.',
                },
                {
                    title: 'توافق الإعلان مع الصفحة',
                    body: withLanding
                        ? `يوجد رابط هبوط في ${withLanding} من ${allAds.length} إعلان.`
                        : 'لا توجد روابط هبوط كافية، وهذا يضعف الحكم والتحويل.',
                    evidence: hasSsl ? 'SSL ظاهر.' : 'SSL غير مؤكد من بيانات الفحص.',
                },
                {
                    title: 'جاهزية الصفحة للتحويل',
                    body: conversionReady,
                    evidence: `Pixel: ${hasPixel ? 'موجود' : 'غير مؤكد'}، CTA/تواصل: ${
                        hasContact || hasSiteCta ? 'موجود' : 'ضعيف'
                    }، SSL: ${hasSsl ? 'موجود' : 'غير مؤكد'}.`,
                },
                {
                    title: 'هل يبيع قبل بناء الثقة؟',
                    body:
                        directSale && !trustTerms
                            ? 'نعم، الرسائل تميل للبيع المباشر بدون دلائل ثقة كافية.'
                            : 'لا يظهر خطر كبير، أو توجد مؤشرات ثقة في الرسائل.',
                    evidence: trustTerms
                        ? 'تم رصد كلمات ثقة/نتائج.'
                        : 'لم ترصد كلمات تقييمات أو ضمان أو نتائج بوضوح.',
                },
                {
                    title: 'الهدر المحتمل',
                    body: wasteItems.length
                        ? wasteItems.join('، ')
                        : 'لا يظهر هدر كبير من البيانات العامة، لكن يلزم ربط الحساب للحسم.',
                    evidence: 'تم فحص التكرار، الروابط، التتبع، وطبيعة الرسالة.',
                },
                {
                    title: 'هل الميزانية مناسبة؟',
                    body: budgetSignal,
                    evidence: 'مكتبة الإعلانات لا تعطي إنفاقاً دقيقاً دائماً.',
                },
                {
                    title: 'هل المنصة مناسبة؟',
                    body: platformFit,
                    evidence: platforms.length
                        ? 'مستخرج من حقل platforms في الإعلانات.'
                        : 'البيانات تحتاج إكمال من المصدر.',
                },
                {
                    title: 'التعديل المقترح',
                    body: directSale
                        ? 'افصل حملة الثقة/التثقيف عن حملة التحويل، واجعل البيع المباشر لإعادة الاستهداف أو جمهور دافئ.'
                        : 'ابدأ باختبار رسائل بيع واضحة مع صفحة هبوط جاهزة وتتبع كامل.',
                    evidence: 'التوصية مبنية على طبيعة الرسائل وجاهزية الصفحة.',
                },
                {
                    title: 'هل الجمهور مناسب؟',
                    body:
                        leadGen || directSale
                            ? 'الجمهور قد يكون مناسباً إذا كان مستهدفاً حسب نية الشراء، لكن البيانات العامة لا تكشف الاستهداف بدقة.'
                            : 'الجمهور يبدو واسعاً أو غير محدد من الرسائل الحالية.',
                    evidence:
                        'يلزم فحص Ad Set داخل Ads Manager لتأكيد العمر، المنطقة، والاهتمامات.',
                },
            ],
            messages,
        };
    }

    function renderAdsSection(data, srObj, clientName, liveAiAnalysis) {
        const sr = srObj || {};

        // ── تحديد تحليل الإعلانات (حي → احتياطي ذكي) ────────────
        let adsAnalysis = data.ads_analysis || liveAiAnalysis;

        if (!adsAnalysis) {
            const hasAds =
                (sr.ads_library && sr.ads_library.total_ads > 0) ||
                (sr.facebook && sr.facebook.ads_count > 0);
            const hasPixel = sr.hasPixel || false;
            const adCount = sr.ads_library
                ? sr.ads_library.total_ads
                : sr.facebook
                ? sr.facebook.ads_count
                : 0;

            if (hasAds) {
                if (!hasPixel) {
                    adsAnalysis = {
                        score: 30,
                        status: '🚨 هدر مالي خطير (بدون تتبع)',
                        desc: `تم اكتشاف ${adCount} إعلانات نشطة، لكنك لا تمتلك بيكسل تتبع! أنت تحرق أموالك في الهواء ولا تجمع أي بيانات.`,
                        metrics: [
                            {
                                title: 'العائد على الإنفاق (ROAS)',
                                val: 'مجهول',
                                status: '▼ نزيف مستمر',
                                status_class: 'status-red',
                                val_class: 'val-red',
                                desc: 'بسبب غياب التتبع (Pixel)، لا يمكن لفيسبوك تحسين العائد. أنت تدفع للظهور فقط.',
                            },
                            {
                                title: 'تكلفة النقرة (CPC)',
                                val: 'مرتفعة',
                                status: '▶ غير محسنة',
                                status_class: 'status-yellow',
                                val_class: 'val-yellow',
                                desc: 'الخوارزمية لا تعرف من يشتري، لذلك تجلب لك زيارات عشوائية بتكلفة عالية.',
                            },
                            {
                                title: 'البيانات المجمعة',
                                val: '0%',
                                status: '▼ ضياع الأصول',
                                status_class: 'status-red',
                                val_class: 'val-red',
                                desc: 'كل زائر لم يشتري ضاع للأبد، لا يمكنك إعادة استهدافه.',
                            },
                        ],
                        creative_pointers: [
                            {
                                type: 'red',
                                icon: '❌',
                                title: 'كارثة التتبع (Pixel)',
                                desc: 'تشغيل إعلانات بدون بيكسل مثل القيادة معصوب العينين. توقف فوراً.',
                            },
                            {
                                type: 'yellow',
                                icon: '⚠️',
                                title: 'غياب الداتا (Data Loss)',
                                desc: 'المنافسون يبنون قواعد بيانات لعملائهم، وأنت تدفع لفيسبوك دون أن تحتفظ بشيء.',
                            },
                            {
                                type: 'red',
                                icon: '❌',
                                title: 'محتوى غير مخصص',
                                desc: 'بسبب غياب التتبع، إعلاناتك تظهر للجميع (المهتم وغير المهتم).',
                            },
                        ],
                        strategy: {
                            desc: 'تدخل جراحي عاجل مطلوب:',
                            steps: [
                                '<strong>إيقاف الإعلانات:</strong> أوقف جميع حملاتك الممولة هذه اللحظة.',
                                '<strong>زرع بيكسل التتبع:</strong> تركيب Meta Pixel وإعداد أحداث الشراء (Purchase Events).',
                                '<strong>إطلاق حملات ذكية:</strong> إعادة إطلاق الإعلانات بهدف (التحويل Conversion) وليس (النقرات Traffic).',
                            ],
                        },
                    };
                } else {
                    adsAnalysis = {
                        score: 55,
                        status: '⚠️ أداء متوسط (يحتاج تحسين)',
                        desc: `تم رصد ${adCount} إعلانات نشطة. التتبع موجود، لكن المادة الإعلانية تحتاج لتحسين لرفع العائد.`,
                        metrics: [
                            {
                                title: 'معدل التحويل',
                                val: 'غير متوفر',
                                status: 'يتطلب Ads Manager',
                                status_class: 'status-yellow',
                                val_class: 'val-yellow',
                                desc: 'لا يتم عرض معدل تحويل تقديري بدون بيانات الحساب الإعلاني المربوط.',
                            },
                            {
                                title: 'حالة الحملة',
                                val: 'نشطة',
                                status: '▲ مستقرة',
                                status_class: 'status-green',
                                val_class: 'val-green',
                                desc: 'الحملات تعمل والبيانات تُجمع بشكل صحيح.',
                            },
                            {
                                title: 'الاستحواذ (CPA)',
                                val: 'مكلف',
                                status: '▶ يحتاج تقليل',
                                status_class: 'status-yellow',
                                val_class: 'val-yellow',
                                desc: 'تكلفة شراء العميل أعلى من الطبيعي بسبب ضعف الـ CTA.',
                            },
                        ],
                        creative_pointers: [
                            {
                                type: 'yellow',
                                icon: '⚠️',
                                title: 'تكرار المحتوى (Ad Fatigue)',
                                desc: 'نفس الإعلانات تظهر للجمهور، مما يسبب ملل وارتفاع في التكلفة.',
                            },
                            {
                                type: 'red',
                                icon: '❌',
                                title: 'العرض (Offer) غير كافي',
                                desc: 'العميل يحتاج سبباً مقنعاً للشراء (الآن). أضف عنصر الاستعجال (Scarcity).',
                            },
                            {
                                type: 'green',
                                icon: '✅',
                                title: 'التتبع مفعل',
                                desc: 'ميزة ممتازة تتيح لنا إعادة استهداف من زار الموقع ولم يشتري.',
                            },
                        ],
                        strategy: {
                            desc: 'لا توجد أرقام مالية مؤكدة في هذا المسار:',
                            steps: [
                                '<strong>تحليل الرسائل:</strong> استخدم نصوص الإعلانات المرصودة لتحديد الزوايا المتكررة.',
                                '<strong>ربط الحساب:</strong> اربط Meta Ads Manager قبل اعتماد أي قرار ميزانية أو خصومات.',
                                '<strong>صفحة الهبوط:</strong> تحقق من جاهزية الصفحة من بيانات الفحص قبل توسيع الحملة.',
                            ],
                        },
                    };
                }
            } else {
                adsAnalysis = {
                    score: 10,
                    status: '💤 سبات عميق (لا يوجد إعلانات)',
                    desc: 'لم نتمكن من رصد أي نشاط إعلاني حالي لعلامتك التجارية. أنت تترك الساحة فارغة للمنافسين.',
                    metrics: [
                        {
                            title: 'التواجد الإعلاني',
                            val: 'معدوم',
                            status: '▼ خطورة',
                            status_class: 'status-red',
                            val_class: 'val-red',
                            desc: 'الاعتماد الكلي على الزيارات العضوية (Organic) يحد من نموك بشكل كارثي.',
                        },
                        {
                            title: 'النمو الشهري',
                            val: 'بطيء',
                            status: '▼ تحت المعدل',
                            status_class: 'status-red',
                            val_class: 'val-red',
                            desc: 'بدون وقود (الإعلانات)، لن تتمكن من مضاعفة مبيعاتك في وقت قصير.',
                        },
                        {
                            title: 'تكلفة الفرصة الضائعة',
                            val: 'عالية جداً',
                            status: '▼ خسارة غير مرئية',
                            status_class: 'status-red',
                            val_class: 'val-red',
                            desc: 'منافسوك يستحوذون على عملائك المحتملين يومياً عبر حملاتهم.',
                        },
                    ],
                    creative_pointers: [
                        {
                            type: 'red',
                            icon: '❌',
                            title: 'غياب الظهور',
                            desc: 'عميلك يبحث عن منتجك، ويرى إعلانات منافسك ويشتري منه.',
                        },
                        {
                            type: 'yellow',
                            icon: '⚠️',
                            title: 'بطء مقلق',
                            desc: 'النمو العضوي ممتاز ولكنه لا يبني إمبراطورية تجارية بسرعة.',
                        },
                        {
                            type: 'red',
                            icon: '❌',
                            title: 'لا يوجد جمع بيانات',
                            desc: 'لأنك لا تشغل إعلانات، بيكسلات التتبع الخاصة بك لا تتعلم من هو عميلك المثالي.',
                        },
                    ],
                    strategy: {
                        desc: 'لا توجد بيانات إعلانات مؤكدة لهذا التقرير:',
                        steps: [
                            '<strong>التحقق أولاً:</strong> اسحب مكتبة الإعلانات أو اربط الحساب الإعلاني قبل اقتراح ميزانية.',
                            '<strong>بناء الجمهور:</strong> لا يتم اقتراح حملة محددة بدون هدف تجاري وبيانات صفحة هبوط.',
                            '<strong>العرض:</strong> لا يتم اقتراح خصم أو عرض ثابت بدون بيانات المنتج والهامش.',
                        ],
                    },
                };
            }
        }

        if (!data.ads_analysis && !liveAiAnalysis) {
            adsAnalysis = buildPublicAdsOverview(sr);
        }

        if (adsAnalysis) {
            // ── Score Mini Card ──
            const scoreRing = document.getElementById('adScoreRing');
            const scoreNum = document.getElementById('adScoreNum');
            const scoreTitle = document.getElementById('adScoreTitle');
            const scoreDesc = document.getElementById('adScoreDesc');
            const scoreColor =
                adsAnalysis.score >= 70
                    ? 'var(--green)'
                    : adsAnalysis.score >= 40
                    ? 'var(--yellow)'
                    : 'var(--red)';

            if (scoreRing) {
                scoreRing.setAttribute('data-percent', adsAnalysis.score);
                scoreRing.setAttribute('data-color', scoreColor);
            }
            if (scoreNum) {
                scoreNum.setAttribute('data-val', adsAnalysis.score);
                scoreNum.textContent = adsAnalysis.score;
            }
            if (scoreTitle) scoreTitle.innerHTML = sanitize(adsAnalysis.status);
            if (scoreDesc) scoreDesc.innerHTML = sanitize(adsAnalysis.desc);

            // ── Metrics Grid ──
            const metricsGrid = document.getElementById('adMetricsGrid');
            if (metricsGrid && adsAnalysis.metrics) {
                metricsGrid.innerHTML = adsAnalysis.metrics
                    .map(
                        m => `
          <div class="metric-box">
            <div class="m-title">${sanitize(m.title)}</div>
            <div class="m-val ${m.val_class}">${sanitize(m.val)}</div>
            <div class="m-status ${m.status_class}">${sanitize(m.status)}</div>
            <p style="font-size:12px;color:var(--text-gray);margin-top:8px;font-weight:600;">${sanitize(
                m.desc
            )}</p>
          </div>`
                    )
                    .join('');
            }

            // ── Creative Pointers ──
            const pointersGrid = document.getElementById('adPointersGrid');
            if (pointersGrid && adsAnalysis.creative_pointers) {
                pointersGrid.innerHTML = adsAnalysis.creative_pointers
                    .map(p => {
                        const pClass =
                            p.type === 'yellow'
                                ? 'pointer-yellow'
                                : p.type === 'green'
                                ? 'pointer-green'
                                : '';
                        return `<div class="pointer-item ${pClass}" style="margin-bottom:16px;">
            <h5>${p.icon} ${sanitize(p.title)}</h5>
            <p>${sanitize(p.desc)}</p>
          </div>`;
                    })
                    .join('');
            }

            // ── AI Strategy ──
            const strategyDesc = document.getElementById('adStrategyDesc');
            const strategyList = document.getElementById('adStrategyList');
            if (strategyDesc && adsAnalysis.strategy) {
                strategyDesc.innerHTML = sanitize(adsAnalysis.strategy.desc);
                if (strategyList) {
                    strategyList.innerHTML = (adsAnalysis.strategy.steps || [])
                        .map(
                            (step, i) =>
                                `<li style="display:flex;gap:10px;font-size:14px;font-weight:700;color:#fff;"><span style="color:var(--primary)">${
                                    i + 1
                                }.</span> ${step}</li>`
                        )
                        .join('');
                }
            }
        }

        // ── Ad Gallery (مكتبة الإعلانات الحقيقية) ────────────────
        const detailedAds = buildDetailedAdsAnalysis(data || {}, sr, clientName);
        const adsCampaignSummary = document.getElementById('adsCampaignSummary');
        const adsDeepMeta = document.getElementById('adsDeepMeta');
        const adsDeepAnalysisGrid = document.getElementById('adsDeepAnalysisGrid');
        const adsMessagesList = document.getElementById('adsMessagesList');

        if (adsCampaignSummary) adsCampaignSummary.textContent = detailedAds.summary;
        if (adsDeepMeta) {
            adsDeepMeta.innerHTML = detailedAds.meta
                .map(item => `<span class="ads-chip">${sanitize(item)}</span>`)
                .join('');
        }
        if (adsDeepAnalysisGrid) {
            adsDeepAnalysisGrid.innerHTML = detailedAds.cards
                .map(
                    card => `
        <div class="ads-deep-card">
          <strong>${sanitize(card.title)}</strong>
          <p>${sanitize(card.body)}</p>
          <span class="evidence">${sanitize(card.evidence)}</span>
        </div>
      `
                )
                .join('');
        }
        if (adsMessagesList) {
            adsMessagesList.innerHTML = detailedAds.messages.length
                ? detailedAds.messages
                      .map(
                          (msg, i) =>
                              `<div class="ads-message-item"><strong style="color:var(--primary);display:block;margin-bottom:5px;">رسالة ${
                                  i + 1
                              }</strong>${sanitize(msg)}</div>`
                      )
                      .join('')
                : '<div class="ads-message-item">لا توجد رسائل إعلانية كافية في البيانات المسحوبة.</div>';
        }

        const actualAdsGrid = document.getElementById('actualAdsGrid');
        if (actualAdsGrid) {
            let realAds = [];
            if (sr.ads_library && sr.ads_library.ads && sr.ads_library.ads.length > 0) {
                realAds = sr.ads_library.ads;
            } else if (
                sr.ads_library &&
                sr.ads_library.raw_items &&
                sr.ads_library.raw_items.length > 0
            ) {
                realAds = sr.ads_library.raw_items.map(item => ({
                    page_name: item.brand,
                    is_active: item.active,
                    start_date: item.startDate,
                    end_date: item.endDate,
                    title: item.linkTitle || item.body,
                    text: item.body || item.linkDescription,
                    headline: item.linkTitle,
                    description: item.linkDescription,
                    image_url:
                        item.images && item.images[0] ? item.images[0].url || item.images[0] : '',
                    video_url:
                        item.videos && item.videos[0] ? item.videos[0].url || item.videos[0] : '',
                    landing_url: item.linkUrl || item.ctaUrl,
                    cta_type: item.ctaText,
                    platforms: item.platforms,
                    impressions: item.total_impressions || item.impressions,
                }));
            } else if (sr.facebook && sr.facebook.ads) {
                realAds = sr.facebook.ads;
            }

            if (realAds && realAds.length > 0) {
                const adsToShow = realAds.slice(0, 12);
                actualAdsGrid.innerHTML = adsToShow
                    .map(ad => {
                        const isActive = ad.is_active !== false;
                        const statusClass = isActive ? 'ad-status-active' : 'ad-status-inactive';
                        const statusText = isActive ? '🟢 نشط' : '⚪ غير نشط';
                        // Phase 4.5: validate image URL — only http(s), strip quotes/parens to prevent CSS escape
                        const rawImg = String(ad.image_url || '').trim();
                        const safeImg = /^https?:\/\//i.test(rawImg)
                            ? rawImg.replace(/['"()<>\\]/g, '').replace(/javascript:/gi, '')
                            : '';
                        const imgBg = safeImg
                            ? `style="background-image:url('${escapeHtml(safeImg)}');background-size:cover;background-position:center;"`
                            : '';
                        const shortCopy =
                            (ad.title || ad.text || 'لا يوجد نص').substring(0, 80) +
                            ((ad.title || ad.text || '').length > 80 ? '...' : '');
                        return `
            <div class="ad-card-real">
              <div class="ad-header">
                <div class="ad-avatar"></div>
                <div style="font-size:13px;font-weight:700;color:#fff;">${sanitize(
                    ad.page_name || clientName
                )}</div>
              </div>
              <div class="ad-img-wrap" ${imgBg}>
                ${
                    !safeImg
                        ? '<span style="color:#555;z-index:2;position:relative;">لا تتوفر صورة</span>'
                        : ''
                }
              </div>
              <div class="ad-footer">
                <div class="ad-status ${statusClass}">${statusText}</div>
                <div style="margin-bottom:6px;"><strong>تاريخ الإطلاق:</strong> ${escapeHtml(
                    ad.start_date ? String(ad.start_date).substring(0, 10) : 'غير معروف'
                )}</div>
                <div style="color:#fff;font-weight:600;">${sanitize(shortCopy)}</div>
              </div>
            </div>`;
                    })
                    .join('');
            } else {
                actualAdsGrid.innerHTML = `<div style="grid-column:1/-1;padding:40px;text-align:center;color:var(--text-gray);font-size:16px;">
          لا توجد مواد إعلانية مستخرجة حالياً. الحساب لا يشغل إعلانات أو أن البيانات قيد السحب.
        </div>`;
            }
        }

        // ==========================================
        // PAGE: content.html — Viral Growth Modules
        // Sections 7, 8, 9: Viral Deconstruction,
        // Content Pillars Matrix, Hook Bank + Omnichannel
        // ==========================================
        if (path.includes('content.html')) { try {

            // ── Section 7: Viral Deconstruction ──────────────────
            const vd = ai.viral_deconstruction || null;
            const vdSection = document.getElementById('viralDeconstructionSection');
            if (vd && vdSection) {
                vdSection.style.display = '';

                const vdPostType = document.getElementById('vd_post_type');
                const vdHookAnalysis = document.getElementById('vd_hook_analysis');
                const vdIntentToBuy = document.getElementById('vd_intent_to_buy');
                const vdObjections = document.getElementById('vd_objections');
                const vdEmotion = document.getElementById('vd_emotion');
                const vdGap = document.getElementById('vd_gap_extracted');

                if (vdPostType && vd.post_type) vdPostType.textContent = vd.post_type;
                if (vdHookAnalysis && vd.hook_analysis) vdHookAnalysis.textContent = vd.hook_analysis;
                if (vd.sentiment_diagnosis) {
                    const sd = vd.sentiment_diagnosis;
                    if (vdIntentToBuy) vdIntentToBuy.textContent = sd.intent_to_buy || '—';
                    if (vdObjections) vdObjections.textContent = sd.objections || '—';
                    if (vdEmotion) vdEmotion.textContent = sd.emotion || '—';
                }
                if (vdGap && vd.gap_extracted) vdGap.textContent = vd.gap_extracted;
            }

            // ── Section 8: Content Pillars Matrix ────────────────
            const pillars = Array.isArray(ai.content_pillars_matrix) ? ai.content_pillars_matrix : [];
            const pillarsSection = document.getElementById('contentPillarsSection');
            const pillarsGrid = document.getElementById('contentPillarsGrid');
            const pillarsRatioBar = document.getElementById('pillarsRatioBar');
            const pillarsRatioLegend = document.getElementById('pillarsRatioLegend');

            const pillarColors = [
                { bg: 'rgba(16,185,129,0.08)', border: 'rgba(16,185,129,0.25)', accent: 'var(--green)', bar: '#10b981' },
                { bg: 'rgba(139,92,246,0.08)', border: 'rgba(139,92,246,0.25)', accent: 'var(--purple)', bar: '#8b5cf6' },
                { bg: 'rgba(245,142,26,0.08)', border: 'rgba(245,142,26,0.25)', accent: 'var(--primary)', bar: '#f58e1a' },
            ];

            if (pillars.length > 0 && pillarsSection) {
                pillarsSection.style.display = '';
                if (pillarsGrid) {
                    pillarsGrid.innerHTML = pillars.map((p, i) => {
                        const c = pillarColors[i % pillarColors.length];
                        const pct = p.percentage || 0;
                        return `
                        <div style="background:${c.bg};border:1px solid ${c.border};border-radius:18px;padding:28px;position:relative;overflow:hidden;">
                          <div style="position:absolute;top:0;left:0;right:0;height:4px;background:${c.bar};"></div>
                          <div style="font-size:28px;margin-bottom:14px;">
                            ${i === 0 ? '🏆' : i === 1 ? '🔥' : '💰'}
                          </div>
                          <div style="font-size:14px;font-weight:900;color:${c.accent};margin-bottom:6px;">${sanitize(p.pillar || '')}</div>
                          <div style="font-size:13px;font-weight:600;color:var(--text-gray);line-height:1.7;margin-bottom:16px;">${sanitize(p.desc || '')}</div>
                          <div style="background:rgba(0,0,0,0.2);border-radius:10px;padding:14px 16px;border:1px solid rgba(255,255,255,0.05);">
                            <div style="font-size:11px;font-weight:800;color:var(--text-gray);margin-bottom:6px;">مثال مخصص لحسابك:</div>
                            <div style="font-size:13px;font-weight:700;color:var(--text-dark);line-height:1.6;">${sanitize(p.example || '—')}</div>
                          </div>
                          <div style="margin-top:16px;display:flex;align-items:center;justify-content:space-between;">
                            <div style="font-size:12px;font-weight:800;color:var(--text-gray);">النسبة المقترحة</div>
                            <div style="font-size:26px;font-weight:900;color:${c.accent};">${pct}%</div>
                          </div>
                          <div style="height:6px;background:rgba(255,255,255,0.07);border-radius:99px;overflow:hidden;margin-top:8px;">
                            <div style="height:100%;width:${pct}%;background:${c.bar};border-radius:99px;transition:width 1.2s ease;"></div>
                          </div>
                        </div>`;
                    }).join('');
                }

                if (pillarsRatioBar) {
                    pillarsRatioBar.innerHTML = pillars.map((p, i) => {
                        const c = pillarColors[i % pillarColors.length];
                        return `<div style="flex:${p.percentage || 1};background:${c.bar};transition:flex 0.8s ease;" title="${p.pillar}: ${p.percentage}%"></div>`;
                    }).join('');
                }

                if (pillarsRatioLegend) {
                    pillarsRatioLegend.innerHTML = pillars.map((p, i) => {
                        const c = pillarColors[i % pillarColors.length];
                        return `<div style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;color:var(--text-gray);">
                          <div style="width:14px;height:14px;border-radius:4px;background:${c.bar};flex-shrink:0;"></div>
                          <span>${sanitize(p.pillar || '')}</span>
                          <span style="color:${c.accent};font-weight:900;">${p.percentage || 0}%</span>
                        </div>`;
                    }).join('');
                }
            }

            // ── Section 9: Hook Bank + Omnichannel Strategy ───────
            const hooks = Array.isArray(ai.hook_bank) ? ai.hook_bank : [];
            const hookSection = document.getElementById('hookBankSection');
            const hookGrid = document.getElementById('hookBankGrid');

            const hookIcons = ['🪝', '⚡', '🔥'];
            const hookColors = [
                { bg: 'rgba(245,142,26,0.07)', border: 'rgba(245,142,26,0.25)', accent: 'var(--primary)' },
                { bg: 'rgba(139,92,246,0.07)', border: 'rgba(139,92,246,0.25)', accent: 'var(--purple)' },
                { bg: 'rgba(16,185,129,0.07)', border: 'rgba(16,185,129,0.25)', accent: 'var(--green)' },
            ];

            if (hooks.length > 0 && hookSection) {
                hookSection.style.display = '';
                if (hookGrid) {
                    hookGrid.innerHTML = hooks.map((h, i) => {
                        const c = hookColors[i % hookColors.length];
                        const copyText = escapeHtml(h.example || h.formula || '');
                        return `
                        <div style="background:${c.bg};border:1px solid ${c.border};border-radius:16px;padding:24px 28px;display:flex;gap:20px;align-items:flex-start;">
                          <div style="font-size:32px;flex-shrink:0;">${hookIcons[i % hookIcons.length]}</div>
                          <div style="flex:1;">
                            <div style="font-size:13px;font-weight:900;color:${c.accent};margin-bottom:8px;text-transform:uppercase;letter-spacing:1px;">${sanitize(h.type || 'خطاف')}</div>
                            <div style="font-size:16px;font-weight:900;color:var(--text-dark);line-height:1.5;margin-bottom:14px;">${sanitize(h.formula || '')}</div>
                            <div style="background:rgba(0,0,0,0.3);border-radius:12px;padding:14px 18px;border:1px solid rgba(255,255,255,0.06);">
                              <div style="font-size:11px;font-weight:800;color:var(--text-gray);margin-bottom:6px;text-transform:uppercase;letter-spacing:1px;">✏️ مثال جاهز للنشر</div>
                              <div style="font-size:14px;font-weight:700;color:var(--text-dark);line-height:1.7;">${sanitize(h.example || '—')}</div>
                            </div>
                            <button data-copy="${copyText}" class="hook-copy-btn" style="margin-top:12px;background:transparent;border:1px solid ${c.border};color:${c.accent};padding:7px 16px;border-radius:8px;font-size:12px;font-weight:800;cursor:pointer;transition:0.2s;">
                              📋 نسخ الخطاف
                            </button>
                          </div>
                        </div>`;
                    }).join('');

                    // CSP-safe: event delegation for copy buttons
                    hookGrid.addEventListener('click', e => {
                        const btn = e.target.closest('.hook-copy-btn');
                        if (!btn) return;
                        const text = btn.getAttribute('data-copy') || '';
                        if (navigator.clipboard) {
                            navigator.clipboard.writeText(text).then(() => {
                                const orig = btn.textContent;
                                btn.textContent = '✅ تم النسخ!';
                                setTimeout(() => { btn.textContent = orig; }, 1800);
                            });
                        }
                    });
                }

                // Omnichannel Strategy
                const omni = ai.omnichannel_strategy || null;
                const omniSection = document.getElementById('omnichannelSection');
                if (omni && omniSection) {
                    omniSection.style.display = '';
                    const omniCore = document.getElementById('omni_core');
                    if (omniCore) omniCore.textContent = omni.core_content || '—';

                    const omniList = document.getElementById('omniDistributionList');
                    if (omniList && Array.isArray(omni.distribution)) {
                        const platformIcons = ['📸', '🎵', '🐦', '📺', '💬'];
                        const platformColors = [
                            { bg: 'rgba(245,142,26,0.07)', border: 'rgba(245,142,26,0.2)' },
                            { bg: 'rgba(59,130,246,0.07)', border: 'rgba(59,130,246,0.2)' },
                            { bg: 'rgba(139,92,246,0.07)', border: 'rgba(139,92,246,0.2)' },
                        ];
                        omniList.innerHTML = omni.distribution.map((item, i) => {
                            const pc = platformColors[i % platformColors.length];
                            return `
                            <div style="background:${pc.bg};border:1px solid ${pc.border};border-radius:12px;padding:16px 20px;display:flex;gap:14px;align-items:flex-start;">
                              <div style="font-size:22px;flex-shrink:0;">${platformIcons[i % platformIcons.length]}</div>
                              <div style="font-size:14px;font-weight:700;color:var(--text-dark);line-height:1.7;">${sanitize(item)}</div>
                            </div>`;
                        }).join('');
                    }
                }
            }
        } catch (__err_content_html) {
            console.error("[RC] Phase 4.4: تعطل قسم content.html:", __err_content_html);
        }
        } // end content.html

        // تشغيل animations
        setTimeout(() => {
            animateCounters();
            animateRings();
        }, 200);
    } // end renderAdsSection
});
