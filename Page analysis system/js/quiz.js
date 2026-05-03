// ============================================================
// js/quiz.js — منطق الاستبيان الكامل v3.0
// ============================================================

const SCORE_KEYS = [
  'page_url',
  'brand_logo_ready','brand_message_clear','brand_guidelines',
  'content_strategy','content_frequency','content_formats',
  'platforms_active','profile_optimized','seo_optimized',
  'ads_running','ads_objective','pixel_setup','retargeting_campaigns',
  'ad_budget','landing_page_exists','offer_clarity','checkout_friction',
  'email_marketing','reviews_collected','analytics_installed','kpis_tracked','ltv_known'
];

const STEP_LABELS = [
  'رابط صفحتك + معلومات أساسية',
  'الهوية والمحتوى',
  'الظهور الرقمي والإعلانات',
  'مسار التحويل والقياس'
];

const CONTENT_FORMATS  = ['Reels 🎬','Posts 🖼️','Stories ⏳','Before/After ✨','Testimonials ⭐','Carousel 📄','Live 📡'];
const PLATFORMS_ACTIVE = ['Instagram 📸','TikTok 🎵','Snapchat 👻','Facebook 📘','YouTube 🎥','X 𝕏'];
const PLATFORM_FIELDS  = {
  'Instagram 📸': {id:'instagram_url', ph:'https://instagram.com/username'},
  'TikTok 🎵':    {id:'tiktok_url',    ph:'https://tiktok.com/@username'},
  'Facebook 📘':  {id:'facebook_url',  ph:'https://facebook.com/pagename'},
  'YouTube 🎥':   {id:'youtube_url',   ph:'https://youtube.com/@channel'},
};

const LS_KEY = 'growth_fp_v3';
let state = loadState();
let step  = 1;

// ── LocalStorage ─────────────────────────────────────────────
function loadState() {
  try { return JSON.parse(localStorage.getItem(LS_KEY) || '{}'); } catch { return {}; }
}
function saveState() {
  try { localStorage.setItem(LS_KEY, JSON.stringify(state)); } catch {}
  flashSaved();
}
function flashSaved() {
  const el = document.getElementById('savedBadge');
  if (!el) return;
  el.style.display = 'inline-flex';
  clearTimeout(el._t);
  el._t = setTimeout(() => el.style.display = 'none', 2000);
}

// ── URL Validation & Live Preview ────────────────────────────
let urlDebounceTimer;
async function handleUrlChange(url) {
  const statusEl  = document.getElementById('urlStatus');
  const previewEl = document.getElementById('pagePreview');
  const errEl     = document.getElementById('err_page_url');

  url = url.trim();
  if (!url) {
    statusEl.className = 'url-status';
    previewEl.classList.remove('show');
    return;
  }

  // تطبيع الرابط
  if (!url.match(/^https?:\/\//i)) url = 'https://' + url;
  state.page_url = url;

  // تحديد النوع فوراً
  const type = detectUrlType(url);
  state.page_url_type = type;

  if (!isValidUrl(url)) {
    statusEl.textContent  = '❌ غير صالح';
    statusEl.className    = 'url-status bad';
    previewEl.classList.remove('show');
    errEl.textContent     = '⚠ الرابط غير صالح — تأكد من صحته';
    return;
  }

  statusEl.textContent = '⏳ فحص...';
  statusEl.className   = 'url-status checking';
  previewEl.classList.remove('show');
  errEl.textContent    = '';
  updateProgress();
  saveState();

  // استدعاء API للمعاينة السريعة
  try {
    const r = await fetch(`api/scan.php?url=${encodeURIComponent(url)}`);
    const d = await r.json();

    if (d.success) {
      statusEl.textContent = '✅ تم الفحص';
      statusEl.className   = 'url-status ok';

      // إظهار preview
      const og     = d.og     || {};
      const social = d.social || {};
      const name   = og.title || og.site_name || extractDomain(url);
      const imgSrc = og.image || '';
      const meta   = buildPreviewMeta(d);

      document.getElementById('previewName').textContent    = name;
      document.getElementById('previewMeta').textContent    = meta;
      document.getElementById('previewImg').src             = imgSrc;
      document.getElementById('previewImg').style.display   = imgSrc ? 'block' : 'none';
      document.getElementById('previewBadge').textContent   = '✅ ' + typeLabel(type);

      previewEl.classList.add('show');
      state.og_preview = { title: name, meta };
    } else {
      statusEl.textContent = '⚠ رابط صالح';
      statusEl.className   = 'url-status ok';
      previewEl.classList.remove('show');
    }
  } catch {
    statusEl.textContent = '✅ رابط صالح';
    statusEl.className   = 'url-status ok';
  }
}

function buildPreviewMeta(data) {
  const parts = [];
  const type  = data.type || 'website';
  if (type === 'facebook' || type === 'instagram') parts.push(typeLabel(type));
  const social = data.social || {};
  if (social.followers) parts.push(`${quizFormatNum(social.followers)} متابع`);
  if (social.is_verified) parts.push('✅ موثقة');
  const og = data.og || {};
  if (og.description) parts.push(og.description.substring(0, 60) + (og.description.length > 60 ? '…' : ''));
  return parts.join(' · ') || 'جاري التحليل...';
}

function typeLabel(type) {
  if (type === 'facebook')  return 'صفحة فيسبوك';
  if (type === 'instagram') return 'حساب انستقرام';
  return 'موقع إلكتروني';
}
function detectUrlType(url) {
  if (/facebook\.com/i.test(url)) return 'facebook';
  if (/instagram\.com/i.test(url)) return 'instagram';
  return 'website';
}
function isValidUrl(url) {
  try { new URL(url); return true; } catch { return false; }
}
function extractDomain(url) {
  try { return new URL(url).hostname.replace('www.', ''); } catch { return url; }
}
function quizFormatNum(n) {
  if (n >= 1e6) return (n/1e6).toFixed(1) + 'M';
  if (n >= 1e3) return (n/1e3).toFixed(1) + 'K';
  return n.toString();
}

// ── Chip builder ──────────────────────────────────────────────
function buildChips(containerId, options, stateKey) {
  const c = document.getElementById(containerId);
  if (!c) return;
  c.innerHTML = '';
  options.forEach(opt => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'chip' + ((state[stateKey] || []).includes(opt) ? ' active' : '');
    btn.textContent = opt;
    btn.onclick = () => {
      const arr = state[stateKey] || [];
      state[stateKey] = arr.includes(opt) ? arr.filter(x => x !== opt) : [...arr, opt];
      btn.className = 'chip' + (state[stateKey].includes(opt) ? ' active' : '');
      if (stateKey === 'platforms_active') renderPlatformLinks();
      updateProgress();
      saveState();
    };
    c.appendChild(btn);
  });
}

// ── Platform links ────────────────────────────────────────────
function renderPlatformLinks() {
  const active = state.platforms_active || [];
  const box    = document.getElementById('platformLinks');
  const grid   = document.getElementById('platformLinksGrid');
  if (!box || !grid) return;
  box.style.display  = active.length ? 'block' : 'none';
  grid.innerHTML = '';
  active.forEach(p => {
    if (!PLATFORM_FIELDS[p]) return;
    const {id, ph} = PLATFORM_FIELDS[p];
    // لا تكرر رابط الصفحة الرئيسية
    if (id === 'facebook_url' && state.page_url_type === 'facebook') return;
    if (id === 'instagram_url' && state.page_url_type === 'instagram') return;
    const inp = document.createElement('input');
    inp.className   = 'input';
    inp.type        = 'url';
    inp.placeholder = p + ' — ' + ph;
    inp.value       = state[id] || '';
    inp.oninput     = () => { state[id] = inp.value; saveState(); };
    grid.appendChild(inp);
  });
}

// ── Sync checkbox label ───────────────────────────────────────
function syncChk(el, lblId) {
  document.getElementById(lblId).classList.toggle('checked', el.checked);
  state[el.id] = el.checked;
  updateProgress();
  saveState();
}

// ── Restore state ─────────────────────────────────────────────
function restoreForm() {
  const textIds = ['full_name','phone','email','company_name','page_url'];
  textIds.forEach(id => { const el = document.getElementById(id); if (el && state[id]) el.value = state[id]; });

  const selects = ['project_type','country','platform','content_strategy','ad_budget','checkout_friction'];
  selects.forEach(id => { const el = document.getElementById(id); if (el && state[id]) el.value = state[id]; });

  const checks  = ['brand_logo_ready','brand_message_clear','brand_guidelines','profile_optimized','seo_optimized',
    'ads_running','ads_objective','pixel_setup','retargeting_campaigns','landing_page_exists','offer_clarity',
    'email_marketing','reviews_collected','analytics_installed','kpis_tracked','ltv_known'];
  checks.forEach(id => {
    const el  = document.getElementById(id);
    const lbl = document.getElementById('lbl_' + id);
    if (el && state[id]) { el.checked = true; if (lbl) lbl.classList.add('checked'); }
  });

  if (state.content_frequency !== undefined) {
    const el = document.getElementById('content_frequency');
    if (el) el.value = state.content_frequency;
  }

  // Restore URL preview
  if (state.page_url) {
    clearTimeout(urlDebounceTimer);
    urlDebounceTimer = setTimeout(() => handleUrlChange(state.page_url), 500);
  }
}

// ── Capture step ──────────────────────────────────────────────
function captureStep() {
  if (step === 1) {
    ['full_name','phone','email','company_name'].forEach(id => {
      const el = document.getElementById(id);
      if (el) state[id] = el.value.trim();
    });
    const pageUrl = document.getElementById('page_url');
    if (pageUrl) {
      let u = pageUrl.value.trim();
      if (u && !u.match(/^https?:\/\//i)) u = 'https://' + u;
      state.page_url = u;
    }
    ['tiktok_url','youtube_url','facebook_url','instagram_url'].forEach(id => {
      const el = document.getElementById(id);
      if (el) state[id] = el.value.trim();
    });
    ['project_type','country','platform'].forEach(id => {
      const el = document.getElementById(id);
      if (el) state[id] = el.value;
    });
  }
  if (step === 2) {
    const cf = document.getElementById('content_frequency');
    if (cf) state.content_frequency = Number(cf.value) || 0;
    const cs = document.getElementById('content_strategy');
    if (cs) state.content_strategy = cs.value;
  }
  if (step === 3) {
    const ab = document.getElementById('ad_budget');
    if (ab) state.ad_budget = ab.value;
  }
  if (step === 4) {
    const ch = document.getElementById('checkout_friction');
    if (ch) state.checkout_friction = ch.value;
  }
}

// ── Validate Step 1 ───────────────────────────────────────────
function validateStep1() {
  const fields = [
    {id:'full_name',    msg:'الاسم مطلوب'},
    {id:'phone',        msg:'رقم الجوال مطلوب'},
    {id:'company_name', msg:'اسم النشاط مطلوب'},
    {id:'project_type', msg:'نوع المشروع مطلوب'},
    {id:'country',      msg:'الدولة مطلوبة'},
  ];
  let valid = true;
  fields.forEach(({id, msg}) => {
    const el  = document.getElementById(id);
    const err = document.getElementById('err_' + id);
    const val = el ? el.value.trim() : '';
    if (!val) {
      if (el)  el.classList.add('err');
      if (err) err.textContent = '⚠ ' + msg;
      valid = false;
    } else {
      if (el)  el.classList.remove('err');
      if (err) err.textContent = '';
    }
  });
  return valid;
}

// ── Progress ──────────────────────────────────────────────────
function updateProgress() {
  const filled = SCORE_KEYS.filter(k => {
    const v = state[k];
    return v !== undefined && v !== null && v !== '' && !(Array.isArray(v) && !v.length);
  }).length;
  const pct = Math.round((filled / SCORE_KEYS.length) * 100);
  const el  = document.getElementById('progressPct');
  if (el) el.textContent = pct + '%';
}

// ── Render step ───────────────────────────────────────────────
function renderStep() {
  for (let i = 1; i <= 4; i++) {
    const el = document.getElementById('step' + i);
    if (el) el.style.display = (i === step) ? 'block' : 'none';
    const ps = document.getElementById('ps' + i);
    if (ps) ps.className = 'progress-step' + (i === step ? ' active' : i < step ? ' completed' : '');
  }
  const _sb = document.getElementById('stepBadge'); if (_sb) _sb.innerHTML = `الخطوة <b>${step}</b> / 4`;
  const _sl = document.getElementById('stepLabel'); if (_sl) _sl.textContent = STEP_LABELS[step - 1];
  document.getElementById('btnBack').textContent  = step === 1 ? '🗑️ مسح البيانات' : '← السابق';
  const _bn = document.getElementById('btnNext');
  if (_bn) {
    _bn.textContent = step === 4 ? '🚀 استخرج تقريري الآن!' : 'التالي ←';
    _bn.className = step === 4 ? 'btn btn-gold' : 'btn';
  }
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ── Navigation ─────────────────────────────────────────────────
function handleNext() {
  captureStep();
  saveState();
  if (step === 1 && !validateStep1()) return;
  if (step < 4) { step++; renderStep(); }
  else submitQuiz();
}

function handleBack() {
  captureStep();
  if (step === 1) resetAll();
  else { step--; renderStep(); }
}

function resetAll() {
  localStorage.removeItem(LS_KEY);
  state = {};
  location.reload();
}

// ── Scan Progress Simulation ──────────────────────────────────
const SCAN_STEPS = [
  {icon:'🔍', title:'فحص رابط الصفحة...', label:'جاري الاتصال بالصفحة '},
  {icon:'📊', title:'استخراج البيانات...', label:'قراءة OG Tags والمعلومات العامة'},
  {icon:'⚡', title:'تحليل السرعة...', label:'فحص Google PageSpeed'},
  {icon:'🎯', title:'فحص التسويق...', label:'التحقق من Pixel & Analytics'},
  {icon:'🤖', title:'التوصيات الذكية...', label:'Gemini AI يُحلل النتائج'},
  {icon:'✅', title:'اكتمل الفحص!', label:'جاري توليد التقرير...'},
];
let scanInterval;

function startScanAnimation() {
  clearInterval(scanInterval);
  const navBtns = document.getElementById('navButtons');
  const scanProg = document.getElementById('scanProgress');
  if (navBtns) navBtns.style.display = 'none';
  if (scanProg) scanProg.classList.add('show');

  let si = 0;
  const fill = document.getElementById('scanProgressFill');
  if (fill) { fill.style.animation = 'none'; fill.style.width = '0%'; }

  function advance() {
    if (si >= SCAN_STEPS.length) return;
    const s = SCAN_STEPS[si];
    document.getElementById('scanIcon').textContent       = s.icon;
    document.getElementById('scanTitle').textContent      = s.title;
    document.getElementById('scanStepLabel').textContent  = s.label;
    if (fill) fill.style.width = ((si + 1) / SCAN_STEPS.length * 100) + '%';
    si++;
  }
  advance();
  scanInterval = setInterval(advance, 6000);
}

function stopScanAnimation() {
  clearInterval(scanInterval);
}

// ── Submit ─────────────────────────────────────────────────────
async function submitQuiz() {
  const errBox = document.getElementById('submitError');
  if (errBox) errBox.style.display = 'none';

  // تحقق من وجود رابط الصفحة
  const pageUrlVal = (state.page_url || '').trim();
  if (!pageUrlVal) {
    const urlEl = document.getElementById('page_url');
    const errEl = document.getElementById('err_page_url');
    if (urlEl)  urlEl.classList.add('err');
    if (errEl)  errEl.textContent = '⚠ رابط الصفحة مطلوب قبل استخراج التقرير';
    // عودة للخطوة 1 لإدخال الرابط
    step = 1;
    renderStep();
    setTimeout(() => urlEl?.focus(), 300);
    return;
  }

  startScanAnimation();

  // ── بناء بيانات Lead بدون تكرار ──────────────────────────────
  // تأكد من تنظيف الرابط قبل الإرسال
  const cleanUrl = (pageUrlVal || '').replace(/^(https?:\/\/)+/i, 'https://');
  const urlType  = state.page_url_type || detectUrlType(cleanUrl);

  const lead = {
    full_name:     (state.full_name    || '').trim(),
    phone:         (state.phone        || '').trim(),
    email:         (state.email        || '').trim(),
    company_name:  (state.company_name || '').trim(),
    project_type:  state.project_type  || '',
    platform:      state.platform      || '',
    country:       state.country       || '',
    website_url:   urlType === 'website'   ? cleanUrl : '',
    facebook_url:  urlType === 'facebook'  ? cleanUrl : (state.facebook_url  || ''),
    instagram_url: urlType === 'instagram' ? cleanUrl : (state.instagram_url || ''),
    youtube_url:   state.youtube_url || '',
  };

  const answerKeys = [
    'brand_logo_ready','brand_message_clear','brand_guidelines','content_strategy',
    'content_frequency','content_formats','platforms_active','profile_optimized','seo_optimized',
    'ads_running','ads_objective','pixel_setup','retargeting_campaigns','ad_budget',
    'landing_page_exists','offer_clarity','checkout_friction','email_marketing',
    'reviews_collected','analytics_installed','kpis_tracked','ltv_known'
  ];
  const answers = {};
  answerKeys.forEach(k => { if (state[k] !== undefined) answers[k] = state[k]; });

  try {
    // TODO: دمج PR #10 لتفعيل CSRF كاملاً.
    // حتى ذلك الحين نحاول جلب التوكن، ولو فشل نتابع الإرسال بدونه
    // (السيرفر في الـ main لا يفرض CSRF بعد، فالعطل وحيد الجانب: غياب الملف يكسر إرسال الاستبيان كله).
    let csrfToken = '';
    try {
      const csrfResp = await fetch('api/csrf.php');
      if (csrfResp.ok) {
        const csrfData = await csrfResp.json().catch(() => ({}));
        csrfToken = (csrfData && csrfData.csrf_token) || '';
      } else {
        console.warn('[quiz] api/csrf.php returned ' + csrfResp.status + ' — continuing without CSRF (PR #10 not merged yet).');
      }
    } catch (csrfErr) {
      console.warn('[quiz] CSRF endpoint unavailable — continuing without token (PR #10 not merged yet).', csrfErr);
    }

    const res = await fetch('api/submit.php', {
      method:  'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      body:    JSON.stringify({lead, answers}),
    });
    if (!res.ok) {
      let errData = {};
      try { errData = await res.json(); } catch(_) {}
      throw new Error(errData.error || 'Server error: ' + res.status);
    }
    const data = await res.json();
    if (!data.assessment_id) throw new Error(data.error || 'خطأ غير معروف');
    localStorage.removeItem(LS_KEY);
    stopScanAnimation();
    // result.html تمت إعادة تسميتها إلى report.html في PR #11
    window.location.href = `report.html?id=${data.assessment_id}`;
  } catch (e) {
    stopScanAnimation();
    const navBtns  = document.getElementById('navButtons');
    const scanProg = document.getElementById('scanProgress');
    if (navBtns)  navBtns.style.display  = 'flex';
    if (scanProg) scanProg.classList.remove('show');
    if (errBox) errBox.textContent = '⚠️ ' + e.message;
    if (errBox) errBox.style.display = 'flex';
    const _bnErr = document.getElementById('btnNext'); if (_bnErr) _bnErr.disabled = false;
  }
}

// ── Init ───────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  buildChips('chips_content_formats',  CONTENT_FORMATS,  'content_formats');
  buildChips('chips_platforms_active', PLATFORMS_ACTIVE, 'platforms_active');
  restoreForm();
  renderPlatformLinks();
  renderStep();
  updateProgress();

  // URL input — debounce
  const urlEl = document.getElementById('page_url');
  if (urlEl) {
    urlEl.addEventListener('input', () => {
      clearTimeout(urlDebounceTimer);
      urlDebounceTimer = setTimeout(() => handleUrlChange(urlEl.value), 1200);
    });
    urlEl.addEventListener('blur', () => {
      clearTimeout(urlDebounceTimer);
      handleUrlChange(urlEl.value);
    });
  }

  // Text inputs
  ['full_name','phone','email','company_name'].forEach(id => {
    document.getElementById(id)?.addEventListener('input', () => {
      state[id] = document.getElementById(id).value.trim();
      saveState(); updateProgress();
    });
  });

  // Selects
  ['tiktok_url','youtube_url','facebook_url','instagram_url'].forEach(id => {
      const el = document.getElementById(id);
      if (el) state[id] = el.value.trim();
    });
    ['project_type','country','platform'].forEach(id => {
    document.getElementById(id)?.addEventListener('change', () => {
      state[id] = document.getElementById(id).value;
      saveState(); updateProgress();
    });
  });

  document.getElementById('content_frequency')?.addEventListener('input', () => {
    state.content_frequency = Number(document.getElementById('content_frequency').value);
    saveState(); updateProgress();
  });
  document.getElementById('content_strategy')?.addEventListener('change', () => {
    state.content_strategy = document.getElementById('content_strategy').value;
    saveState(); updateProgress();
  });
  document.getElementById('ad_budget')?.addEventListener('change', () => {
    state.ad_budget = document.getElementById('ad_budget').value;
    saveState(); updateProgress();
  });
  document.getElementById('checkout_friction')?.addEventListener('change', () => {
    state.checkout_friction = document.getElementById('checkout_friction').value;
    saveState(); updateProgress();
  });
});
