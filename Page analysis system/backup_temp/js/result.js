// ============================================================
// js/result.js — عرض تقرير بصمة النمو v3.0 (بيانات حقيقية)
// ============================================================

const AXIS_LABELS = {
  brand:'🎨 الهوية', content:'📅 المحتوى', presence:'🌐 الظهور',
  ads:'💸 الإعلانات', conversion:'🎯 التحويل', analytics:'📊 التحليلات'
};
const AXIS_MAX = { brand:15, content:20, presence:15, ads:20, conversion:20, analytics:10 };

// ── Helpers ───────────────────────────────────────────────────
function tierLabel(tier) {
  if (tier === 'red')    return '🔴 خطر — يحتاج إنقاذاً سريعاً';
  if (tier === 'yellow') return '🟡 قابل للنمو — 3 نقاط بعيد عن الانطلاق';
  return '🟢 جاهز للنمو السريع!';
}
function tierColor(tier) {
  if (tier === 'red')    return '#ef4444';
  if (tier === 'yellow') return '#f0c040';
  return '#10b981';
}
function formatNum(n) {
  if (!n && n !== 0) return '—';
  if (n >= 1e6) return (n/1e6).toFixed(1) + 'M';
  if (n >= 1e3) return (n/1e3).toFixed(1) + 'K';
  return Number(n).toLocaleString('ar');
}
function animateTo(el, target, dur = 1400) {
  if (!el) return;
  const start = Date.now();
  const tick = () => {
    const p = Math.min((Date.now() - start) / dur, 1);
    const e = 1 - Math.pow(1 - p, 3);
    el.textContent = Math.round(e * target);
    if (p < 1) requestAnimationFrame(tick);
  };
  requestAnimationFrame(tick);
}

// ── Score Ring ────────────────────────────────────────────────
function renderScoreRing(score, tier) {
  const ring = document.getElementById('scoreRing');
  if (!ring) return;
  const deg = score * 3.6;
  const col = tierColor(tier);
  ring.style.background = `conic-gradient(${col} ${deg}deg, rgba(255,255,255,0.05) 0deg)`;
  ring.style.boxShadow  = `0 0 30px ${col}55, 0 0 60px ${col}22`;
  const lbl = document.getElementById('tierLabel');
  if (lbl) { lbl.textContent = tierLabel(tier); lbl.style.color = col; }
}

// ── Score Bar ─────────────────────────────────────────────────
function makeBar(label, value, max) {
  const pct = Math.min(100, Math.round((value / max) * 100));
  const fillClass = pct >= 70 ? 'high' : pct >= 40 ? 'medium' : 'low';
  const div = document.createElement('div');
  div.className = 'bd-item';
  div.innerHTML = `
    <div class="bd-row">
      <span>${label}</span>
      <span style="color:var(--blue);font-weight:800">${value}<span style="color:var(--muted);font-weight:400;font-size:12px">/${max}</span></span>
    </div>
    <div class="bd-bg"><div class="bd-fill ${fillClass}" style="width:0%" data-pct="${pct}"></div></div>
    <div class="muted" style="font-size:11px;text-align:left">${pct}%</div>`;
  return div;
}

// ── Website Report Badges ─────────────────────────────────────
function renderWebsiteBadges(scan) {
  if (!scan) return;
  const grid = document.getElementById('websiteBadgesGrid');
  if (!grid) return;
  document.getElementById('websiteReport').style.display = 'block';

  let foundWeb = [];
  let missedWeb = [];

  const addBadge = (ok, yes, no) => {
    if (ok === null || ok === undefined) return;
    if (!ok && !no) return;
    const cls  = ok ? 'scan-ok' : (no?.startsWith('🔴') ? 'scan-bad' : 'scan-warn');
    const el   = document.createElement('div');
    el.className = `scan-badge ${cls}`;
    el.textContent = ok ? yes : no;
    grid.appendChild(el);
    if (ok && yes) foundWeb.push(yes.replace('✅ ', ''));
    if (!ok && no) missedWeb.push(no.replace('🔴 ', '').replace('⚠️ ', ''));
  };

  addBadge(scan.hasSSL === true,   '✅ HTTPS مفعّل',            '🔴 لا HTTPS — خطر!');
  addBadge(scan.hasPixel === true, '✅ Meta Pixel مثبت',        '🔴 لا Pixel — خسارة!');
  addBadge(scan.hasGA === true,    '✅ Google Analytics',       '⚠️ لا Analytics');
  addBadge(scan.hasTikTok === true,'✅ TikTok Pixel',          null);
  addBadge(scan.hasWhatsApp === true,'✅ زر واتساب',             '⚠️ لا زر واتساب');
  addBadge(scan.hasOGTags,     '✅ OG Tags ممتازة',       '⚠️ لا OG Tags');
  addBadge(scan.hasSchema,     '✅ Schema Markup',         '⚠️ لا Schema');
  addBadge(!!scan.title,       '✅ Title محسّن',            '🔴 لا Title!');
  addBadge(!!scan.description, '✅ Meta Description',      '⚠️ لا Description');
  addBadge(scan.hasLiveChat,   '✅ Live Chat',             null);
  addBadge(scan.hasCTA,        '✅ CTA واضح',             '⚠️ لا CTA');
  addBadge(!!scan.h1,          '✅ H1 موجود',              '⚠️ لا H1');

  let summaryText = "أظهر الفحص الدقيق للموقع ";
  if (foundWeb.length) summaryText += "أنه يتضمن أساسيات مثل: " + foundWeb.slice(0,3).join("، ") + ". ";
  if (missedWeb.length) summaryText += "لكن هناك نواقص هامة مثل: " + missedWeb.slice(0,2).join("، ") + " والتي تؤثر سلباً على تجربة المستخدم.";
  document.getElementById('websiteSummaryText').textContent = summaryText;
}

// ── Facebook Section (بيانات حقيقية) ──────────────────────────
function renderFacebook(fbData) {
  if (!fbData) return;
  // دعم كلا الهيكلين: البيانات مباشرة أو ضمن social
  const social = fbData.platform === 'facebook' || fbData.platform === undefined ? fbData : fbData;

  const section = document.getElementById('facebookReport');
  if (!section) return;
  section.style.display = 'block';

  const container = document.getElementById('facebookStats');
  if (!container) return;
  container.innerHTML = ''; // مسح القديم

  // ── البيانات الجوهرية ────────────────────────────────────────
  const rows = [
    { icon:'👥', label:'المتابعون',        val: social.followers   ? formatNum(social.followers)       : '—', highlight: !!social.followers },
    { icon:'👍', label:'الإعجابات',        val: social.likes       ? formatNum(social.likes)           : '—', highlight: !!social.likes },
    (social.rating && !String(social.rating).includes('Not yet')) ? { icon:'⭐', label:'التقييم', val: String(social.rating).replace('recommend', '').replace('/5', '').trim() + '/5 ⭐', highlight: true } : null,
    { icon:'📝', label:'عدد المنشورات',    val: social.posts_count ? formatNum(social.posts_count)     : '—', highlight: !!social.posts_count },
    { icon:'🔥', label:'متوسط التفاعل',   val: social.avg_engagement ? formatNum(social.avg_engagement) + ' تفاعل/منشور' : '—', highlight: !!social.avg_engagement },
    { icon:'🛡️', label:'صفحة موثّقة',      val: social.is_verified ? '✅ نعم' : '❌ لا', color: social.is_verified ? '#10b981' : '#94a3b8' },
    { icon:'📞', label:'معلومات الاتصال',  val: social.has_contact ? '✅ موجودة' : '❌ مفقودة', color: social.has_contact ? '#10b981' : '#ef4444' },
    { icon:'🌐', label:'موقع إلكتروني',   val: social.has_website || social.website ? '✅ مرتبط' : '❌ غير مرتبط', color: social.has_website ? '#10b981' : '#f59e0b' },
  ].filter(Boolean);

  rows.forEach(s => {
    if (s.val === '—' && !s.highlight) return;
    const el = document.createElement('div');
    el.className = 'metric-card';
    el.innerHTML = `
      <div class="metric-label">${s.icon} ${s.label}</div>
      <div class="metric-value" style="color:${s.color || (s.highlight ? 'var(--blue)' : 'var(--text)')};font-size:${s.highlight ? '22px' : '16px'}">${s.val}</div>`;
    container.appendChild(el);
  });

  document.getElementById('facebookSummaryText').textContent = 
    `تحليل صفحة الفيسبوك أظهر امتلاكها ${social.followers ? formatNum(social.followers) : 'عدد غير معروف'} متابع${social.avg_engagement ? ' بمتوسط تفاعل ' + formatNum(social.avg_engagement) : ''}. ` +
    (social.has_website || social.website ? 'الصفحة مرتبطة بالموقع، وهذا ممتاز لمسار التحويل.' : 'ينقص الصفحة بعض التفاصيل الأساسية للربط.');

  if (social.deep_analysis) {
    renderDeepAnalysis(social.deep_analysis, 'fbDeepContent', 'var(--blue)');
  }
}

// ── Ads Library Section (إعلانات حقيقية) ─────────────────────
function renderAdsLibrary(scan) {
  const ads = scan?.ads_library;
  if (!ads) return;

  const section = document.getElementById('adsSection');
  if (!section) return;
  section.style.display = 'block';

  const totalAds  = ads.total_ads  || 0;
  const activeAds = ads.active_ads || 0;
  const adsItems  = ads.ads        || [];
  const isRunning = ads.is_running_ads || ads.is_advertising || totalAds > 0;

  // ── ملخص الأرقام ──────────────────────────────────────────────
  const summary = document.getElementById('adsSummary');
  if (summary) {
    const sumText = document.getElementById('adsSummaryText');
    if (isRunning) {
        sumText.textContent = `ممتاز! رصدنا وجود إعلانات مدفوعة نشطة حالياً. تم العثور على ${activeAds} إعلان، وهذا يشير إلى حيوية في جهود التسويق.`;
    } else {
        sumText.textContent = `لم نرصد أي إعلانات مدفوعة نشطة حالياً. التوقف عن الإعلان المدفوع يفقدك الوصول إلى شرائح جديدة ويُقلل من زخم التحويلات.`;
    }

    summary.innerHTML = `
      <div class="metric-card">
        <div class="metric-label">📊 إجمالي الإعلانات</div>
        <div class="metric-value" style="color:${totalAds > 0 ? '#3b82f6' : '#94a3b8'};font-size:32px;font-weight:900">${totalAds}</div>
      </div>
      <div class="metric-card">
        <div class="metric-label">🟢 إعلانات نشطة</div>
        <div class="metric-value" style="color:${activeAds > 0 ? '#10b981' : '#94a3b8'};font-size:32px;font-weight:900">${activeAds}</div>
      </div>
      <div class="metric-card">
        <div class="metric-label">📢 حالة الإعلان</div>
        <div class="metric-value" style="color:${isRunning ? '#10b981' : '#ef4444'};font-size:16px;font-weight:700;margin-top:8px">
          ${isRunning ? '✅ يُعلن الآن' : '❌ لا إعلانات نشطة'}
        </div>
      </div>`;
  }

  // ── قائمة الإعلانات ───────────────────────────────────────────
  const listEl = document.getElementById('adsList');
  if (!listEl) return;

  if (adsItems.length === 0) {
    listEl.innerHTML = `<p style="color:var(--muted);text-align:center;padding:20px 0">
      ${isRunning ? '⏳ تم رصد إعلانات — لم تُحمَّل التفاصيل بعد' : '❌ لا توجد إعلانات حالياً في مكتبة Meta للإعلانات'}
    </p>`;
    return;
  }

  listEl.innerHTML = `<div style="color:var(--blue);font-weight:700;margin-bottom:12px">📋 آخر ${Math.min(adsItems.length, 10)} إعلانات:</div>`;
  adsItems.slice(0, 10).forEach(ad => {
    const platforms = Array.isArray(ad.platforms) ? ad.platforms.join(' · ') : (ad.platforms || '');
    const title = (ad.title || '').substring(0, 200);
    const card = document.createElement('div');
    card.style.cssText = 'background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:14px;margin-bottom:10px';
    card.innerHTML = `
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:${title ? '10px' : '0'}">
        <span style="color:${ad.is_active ? '#10b981' : '#64748b'};font-size:13px;font-weight:700;background:${ad.is_active ? 'rgba(16,185,129,.1)' : 'rgba(100,116,139,.1)'};padding:4px 10px;border-radius:20px">
          ${ad.is_active ? '🟢 نشط' : '⭕ منتهي'}
        </span>
        ${ad.start_date ? `<span style="color:var(--muted);font-size:12px">📅 ${ad.start_date}</span>` : ''}
        ${platforms ? `<span style="color:var(--subtle);font-size:12px">📱 ${platforms}</span>` : ''}
        ${ad.spend?.lower_bound !== undefined ? `<span style="color:#f59e0b;font-size:12px;font-weight:700">💰 ${ad.spend.lower_bound}–${ad.spend.upper_bound} USD</span>` : ''}
      </div>
      ${title ? `<div style="color:var(--subtle);font-size:13px;line-height:1.7">${title}${(ad.title||'').length > 200 ? '...' : ''}</div>` : ''}
      ${ad.image_url ? `<img src="${ad.image_url}" style="max-width:100%;border-radius:8px;margin-top:10px;max-height:180px;object-fit:cover" loading="lazy" onerror="this.style.display='none'">` : ''}`;
    listEl.appendChild(card);
  });
}

// ── Instagram Section ─────────────────────────────────────────
function renderInstagramData(igData) {
  if (!igData) return;
  const social = igData;

  const section = document.getElementById('instagramReport');
  if (!section) return;
  section.style.display = 'block';
  const container = document.getElementById('instagramStats');
  if (!container) return;
  container.innerHTML = ''; // مسح القديم

  const rows = [
    { icon:'👥', label:'المتابعون',       val: social.followers     ? formatNum(social.followers)     : '—', h: true },
    { icon:'👤', label:'يتابع',           val: social.following     ? formatNum(social.following)     : '—', h: false },
    { icon:'📸', label:'عدد المنشورات',   val: social.posts_count   ? formatNum(social.posts_count)   : '—', h: true },
    { icon:'📈', label:'معدل التفاعل',    val: social.engagement_rate ? social.engagement_rate + '%'  : '—', h: true },
    { icon:'❤️', label:'اللايكات/المنشور',val: social.avg_likes     ? formatNum(social.avg_likes)     : '—', h: true },
    { icon:'✅', label:'موثّق',           val: social.is_verified ? '✅ نعم' : '❌ لا', color: social.is_verified ? '#10b981' : '#94a3b8' },
    { icon:'🎬', label:'Reels',           val: social.has_reels ? '✅ موجود' : '—', color: social.has_reels ? '#10b981' : '#94a3b8' },
    { icon:'📝', label:'البايو',          val: social.bio ? social.bio.substring(0, 80) : '—', h: false },
  ];

  rows.forEach(s => {
    if (s.val === '—') return;
    const el = document.createElement('div');
    el.className = 'metric-card';
    el.innerHTML = `
      <div class="metric-label">${s.icon} ${s.label}</div>
      <div class="metric-value" style="color:${s.color || (s.h ? 'var(--blue)' : 'var(--text)')};font-size:${s.h ? '22px' : '14px'}">${s.val}</div>`;
    container.appendChild(el);
  });

  const igSumEl = document.getElementById('instagramSummaryText');
  if (igSumEl) igSumEl.textContent = 
    `اكتشفنا حساب الانستقرام @${social.username || ''} بـ ${social.followers ? formatNum(social.followers) : 'عدد غير معروف'} متابع. ` + 
    (social.engagement_rate && social.engagement_rate > 1 ? 'معدل التفاعل ممتاز ويدل على جمهور حقيقي ومهتم.' : 'معدل التفاعل يحتاج إلى خطة تنشيط لزيادة حماس المتابعين.');

  if (social.deep_analysis) {
    renderDeepAnalysis(social.deep_analysis, 'igDeepContent', '#f472b6');
  }
}

// ── المحلل العميق للواجهة ─────────────────────────────────────
function renderDeepAnalysis(deepData, containerId, colorTheme) {
    if (!deepData || !deepData.posts_analyzed) return;
    const container = document.getElementById(containerId);
    if (!container) return;
    container.style.display = 'block';
    
    let html = `<h3 style="color:${colorTheme}; margin-bottom: 18px; font-size: 17px; display:flex; align-items:center; gap:8px; font-weight:800">
        <span>🧠</span> تشريح المحتوى العميق (لآخر ${deepData.posts_analyzed} منشور)
    </h3>`;
    
    // Types Distribution
    const t = deepData.types_percent;
    html += `
    <div style="margin-bottom:20px; background:rgba(0,0,0,0.1); padding:16px; border-radius:12px; border:1px solid rgba(255,255,255,0.05)">
      <div style="font-size:14px; font-weight:800; margin-bottom:12px; color:var(--text)">📊 توزيع أنواع المحتوى</div>
      <div style="display:flex; height:28px; border-radius:8px; overflow:hidden;">
        ${t.video > 0 ? `<div style="width:${t.video}%; background:var(--gold); display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:bold; color:#000;">فيديو/Reels ${t.video}%</div>` : ''}
        ${t.image > 0 ? `<div style="width:${t.image}%; background:var(--blue); display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:bold; color:#fff;">صور ${t.image}%</div>` : ''}
        ${t.carousel > 0 ? `<div style="width:${t.carousel}%; background:#8b5cf6; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:bold; color:#fff;">ألبومات ${t.carousel}%</div>` : ''}
      </div>
    </div>`;
    
    // Extra Metrics
    html += `<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px; margin-bottom:20px">
      <div class="metric-card" style="background:rgba(255,255,255,0.02)">
        <div class="metric-label">✍️ كيمياء النصوص</div>
        <div class="metric-value" style="font-size:18px">${deepData.avg_words} كلمة/منشور</div>
        <div class="muted" style="font-size:11px;margin-top:6px">${deepData.avg_words > 40 ? '✅ نصوص تفصيلية ممتازة' : '⚠️ نصوص قصيرة جداً'}</div>
      </div>
      <div class="metric-card" style="background:rgba(255,255,255,0.02)">
        <div class="metric-label">🎯 تحفيز الشراء (CTA)</div>
        <div class="metric-value" style="font-size:24px; font-weight:900; color:${deepData.cta_percent > 30 ? '#10b981' : '#ef4444'}">${deepData.cta_percent}%</div>
        <div class="muted" style="font-size:11px;margin-top:6px">من المنشورات تحتوي دعوة لاتخاذ إجراء</div>
      </div>
    </div>`;
    
    // Hashtags
    if (deepData.top_hashtags && deepData.top_hashtags.length > 0) {
        let tags = deepData.top_hashtags.map(ht => `<span style="display:inline-block; padding:5px 12px; background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.1); border-radius:12px; font-size:12px; margin:4px; color:var(--text); transition:0.2s">#${ht}</span>`).join('');
        html += `<div style="margin-bottom:24px; padding:16px; border:1px dashed rgba(255,255,255,0.1); border-radius:12px">
          <div style="font-size:14px; font-weight:800; margin-bottom:12px; color:var(--text)">🏷️ أكثر الهاشتاجات تكراراً</div>
          <div>${tags}</div>
        </div>`;
    }
    
    // Top 5 Posts
    if (deepData.top_5_posts && deepData.top_5_posts.length > 0) {
        html += `<div style="font-size:15px; font-weight:800; margin-bottom:14px; color:var(--text)">🔥 المحتوى البطل (أقوى 5 منشورات تداخلاً)</div>
        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap:12px;">`;
        
        deepData.top_5_posts.forEach((p, idx) => {
            let imgHtml = p.image ? `<img src="${p.image}" style="width:100%; height:130px; object-fit:cover; border-radius:8px; margin-bottom:10px" onerror="this.style.display='none'" />` : `<div style="width:100%; height:130px; background:rgba(255,255,255,0.05); border-radius:8px; display:flex; align-items:center; justify-content:center; text-align:center; font-size:10px; padding:10px; margin-bottom:10px; color:var(--muted)">بدون غلاف مرئي</div>`;
            
            html += `<a href="${p.url || '#'}" target="_blank" style="display:block; text-decoration:none; padding:12px; background:rgba(0,0,0,0.25); border:1px solid rgba(255,255,255,0.08); border-radius:12px; transition:0.3s;">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px">
                <span style="font-size:13px; font-weight:900; color:var(--gold); background:rgba(240,192,64,0.1); padding:2px 8px; border-radius:6px">#${idx+1}</span>
              </div>
              ${imgHtml}
              <div style="display:flex; justify-content:space-between; font-size:12px; color:var(--text); font-weight:700">
                <span style="color:#ef4444">❤️ ${formatNum(p.likes || 0)}</span>
                <span style="color:#3b82f6">💬 ${formatNum(p.comments || 0)}</span>
              </div>
              <div style="font-size:10px; color:var(--subtle); margin-top:8px; line-height:1.6; height:32px; overflow:hidden">${p.text ? p.text : ''}</div>
            </a>`;
        });
        
        html += `</div>`;
    }
    
    container.innerHTML = html;
}

// ── PageSpeed Section ─────────────────────────────────────────
function renderPageSpeed(scan) {
  const ps = scan?.pagespeed;
  if (!ps || ps.error) return;
  document.getElementById('websiteReport').style.display = 'block';
  const container = document.getElementById('pagespeedBars');

  [
    { label:'⚡ الأداء (Performance)', value: ps.performance },
    { label:'🔍 السيو (SEO)',           value: ps.seo },
    { label:'🛡️ أفضل الممارسات',       value: ps.best_practices },
    { label:'♿ إمكانية الوصول',        value: ps.accessibility },
  ].filter(m => m.value != null).forEach(m => {
    const col = m.value >= 90 ? 'var(--green)' : m.value >= 70 ? 'var(--gold)' : 'var(--red)';
    const cls = m.value >= 90 ? 'high' : m.value >= 70 ? 'medium' : 'low';
    const el = document.createElement('div');
    el.className = 'bd-item';
    el.innerHTML = `
      <div class="bd-row"><span>${m.label}</span><span style="color:${col};font-weight:900">${m.value}/100</span></div>
      <div class="bd-bg"><div class="bd-fill ${cls}" style="width:0%" data-pct="${m.value}"></div></div>`;
    container.appendChild(el);
  });
}

// ── Page Info Badges ──────────────────────────────────────────
function renderPageInfoBadges(data) {
  const container = document.getElementById('pageInfoBadges');
  if (!container) return;
  const scan   = data.scan_result || {};
  const social = scan.social      || {};
  const ads    = scan.ads_library || {};

  const badges = [
    data.company_name ? { cls:'badge-blue',   text:`🏢 ${data.company_name}` }            : null,
    data.country      ? { cls:'',             text:`📍 ${data.country}` }                 : null,
    (social.username || social.biography) ? { cls:'badge-purple', text:'📸 انستقرام' }    : null,
    (social.pageName || social.likes)     ? { cls:'badge-purple', text:'📘 فيسبوك' }      : null,
    (!social.username && !social.pageName && scan.url_type === 'website') ? { cls:'badge-purple', text:'🌐 موقع' } : null,
    social.is_verified         ? { cls:'badge-green',  text:'✅ صفحة موثقة' }            : null,
    social.followers           ? { cls:'badge-blue',   text:`👥 ${formatNum(social.followers)} متابع` } : null,
    (ads.total_ads > 0)        ? { cls:'badge-gold',   text:`📢 ${ads.total_ads} إعلان` } : null,
    (ads.active_ads > 0)       ? { cls:'badge-green',  text:`🟢 ${ads.active_ads} نشط` }  : null,
  ].filter(Boolean);

  badges.forEach(b => {
    const el = document.createElement('span');
    el.className = `badge ${b.cls}`;
    el.textContent = b.text;
    container.appendChild(el);
  });
}

// ── Recommendations ───────────────────────────────────────────
function renderRecommendations(recs) {
  const list = document.getElementById('recsList');
  if (!list || !recs?.length) return;
  const MAP = { 'عاجل':'pri-urgent','urgent':'pri-urgent','مهم':'pri-important','important':'pri-important','اختياري':'pri-optional','optional':'pri-optional' };

  recs.forEach((r, idx) => {
    const div = document.createElement('div');
    div.className = 'rec-card animate';
    div.style.animationDelay = (idx * 0.08) + 's';
    const priCls = MAP[r.priority?.toLowerCase?.()] || MAP[r.priority] || 'pri-important';
    div.innerHTML = `
      <div class="rec-priority ${priCls}">${r.priority || 'مهم'}</div>
      <div style="color:var(--blue);font-weight:800;font-size:15px;margin-bottom:10px">${idx + 1}. ${r.title}</div>
      <ul style="padding-inline-start:22px;line-height:2;margin:0;color:var(--subtle)">
        ${(r.bullets || []).map(b => `<li>${b}</li>`).join('')}
      </ul>
      ${r.roi ? `<div style="margin-top:10px;font-size:12px;padding:6px 12px;background:rgba(16,185,129,.08);border-radius:8px;color:var(--green)">🎯 النتيجة المتوقعة: ${r.roi}</div>` : ''}`;
    list.appendChild(div);
  });
}

// ── Competitors ─────────────────────────────────────────────────
function renderCompetitors(competitors) {
  const section = document.getElementById('competitorSection');
  const list = document.getElementById('competitorList');
  if (!section || !list || !competitors || !competitors.length) return;

  list.innerHTML = '';
  competitors.forEach(c => {
    const tr = document.createElement('tr');
    tr.style.borderBottom = '1px solid rgba(255,255,255,.05)';
    tr.innerHTML = `
      <td style="padding:14px;color:var(--text);font-weight:700">${c.name || 'منافس'}</td>
      <td style="padding:14px;color:var(--subtle);line-height:1.6">${c.strategy || '-'}</td>
      <td style="padding:14px;color:var(--green);line-height:1.6">${c.how_to_beat || '-'}</td>
    `;
    list.appendChild(tr);
  });
  section.style.display = 'block';
}

// ── Action Plan ───────────────────────────────────────────────
function renderActionPlan(data) {
  const weekUl  = document.getElementById('actionWeek');
  const monthUl = document.getElementById('actionMonth');
  if (!weekUl || !monthUl) return;

  const weekTasks = data.action_week || ['مراجعة Bio وتحسينه.','تركيب Pixel إذا لم يكن مثبتاً.','نشر أول Reel أو فيديو قصير.'];
  weekTasks.forEach(t => { const li = document.createElement('li'); li.textContent = t; weekUl.appendChild(li); });

  const monthTasks = data.action_month || ['تنفيذ استراتيجية محتوى قائمة على الفيديو.','اختبار A/B لصفحة الهبوط.','توسيع الإعلانات لشرائح Lookalike جديدة.'];
  monthTasks.forEach(t => { const li = document.createElement('li'); li.textContent = t; monthUl.appendChild(li); });
}

// ── PDF ───────────────────────────────────────────────────────
async function downloadPdf() {
  const btn = document.getElementById('downloadBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner">⏳</span> جاري التحضير...';
  try {
    if (!window.html2canvas) {
      await new Promise((resolve) => {
        const s = document.createElement('script');
        s.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
        s.onload = resolve;
        document.head.appendChild(s);
      });
    }
    if (!window.jspdf) {
      await new Promise((resolve) => {
        const s = document.createElement('script');
        s.src = 'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js';
        s.onload = resolve;
        document.head.appendChild(s);
      });
    }

    const jsPDF = window.jspdf.jsPDF;
    const id    = new URLSearchParams(location.search).get('id') || 'report';
    const card  = document.getElementById('reportCard');
    
    // إخفاء بعض العناصر غير المرغوبة في الـ PDF (مثل أزرار المشاركة إذا كانت داخل الكارد)
    const originalShadow = card.style.boxShadow;
    card.style.boxShadow = 'none';

    const canvas= await html2canvas(card, { scale: 2, backgroundColor: '#050810', useCORS: true, allowTaint: true });
    
    card.style.boxShadow = originalShadow;

    const pdf   = new jsPDF('p', 'mm', 'a4');
    const pageW = 210, pageH = 297;
    const imgH  = (canvas.height * pageW) / canvas.width;
    let posY = 0, remaining = imgH;
    while (remaining > 0) {
      pdf.addImage(canvas.toDataURL('image/jpeg', 0.95), 'JPEG', 0, -posY, pageW, imgH);
      remaining -= pageH; posY += pageH;
      if (remaining > 0) pdf.addPage();
    }
    pdf.save(`بصمة-النمو-${id}.pdf`);
  } catch (e) {
    alert('⚠️ حدث خطأ في تحميل PDF. يرجى المحاولة مرة أخرى.'); 
    console.error('PDF Error:', e);
  } finally {
    btn.disabled = false;
    btn.textContent = '📄 تحميل PDF';
  }
}

function copyLink(btn) {
  const url = window.location.href;
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(url).then(() => {
      if(btn) btn.textContent = '✅ تم النسخ!';
      setTimeout(() => { if(btn) btn.textContent = '🔗 مشاركة النتيجة'; }, 2000);
    });
  } else {
    // Fallback لمتصفحات الجوال التي لا تدعم Clipboard API بدون HTTPS
    let textArea = document.createElement("textarea");
    textArea.value = url;
    textArea.style.position = "fixed";
    textArea.style.left = "-999999px";
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    try {
      document.execCommand('copy');
      if(btn) btn.textContent = '✅ تم النسخ!';
      setTimeout(() => { if(btn) btn.textContent = '🔗 مشاركة النتيجة'; }, 2000);
    } catch (err) {
      prompt('انسخ الرابط يدوياً:', url);
    }
    textArea.remove();
  }
}

// ── MAIN ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', async () => {
  const id = new URLSearchParams(location.search).get('id');
  if (!id) { showError('معرّف التقييم غير موجود في الرابط'); return; }

  document.getElementById('waBtn').href = 'https://wa.me/967739537053?text=' + encodeURIComponent('السلام عليكم، حصلت على تقريري من بصمة النمو وأريد خطة تسويقية كاملة.');

  try {
    const res  = await fetch(`api/result.php?id=${encodeURIComponent(id)}`);
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'خطأ غير معروف');

    document.getElementById('skeleton').style.display      = 'none';
    document.getElementById('resultContent').style.display = 'block';

    if (data.updated_at || data.created_at) {
      const d = new Date(data.updated_at || data.created_at);
      document.getElementById('reportDate').textContent = d.toLocaleDateString('ar', {year:'numeric',month:'long',day:'numeric'});
    }

    // Score
    renderScoreRing(data.score, data.tier);
    animateTo(document.getElementById('scoreVal'), data.score);

    // Badges
    const scan = data.scan_result || {};
    renderPageInfoBadges({...data, scan_result: scan});

    // Report type
    const typeBadge = document.getElementById('reportTypeBadge');
    if (typeBadge && scan.url_type) {
      typeBadge.textContent = scan.url_type === 'facebook' ? '📘 تقرير صفحة فيسبوك' : scan.url_type === 'instagram' ? '📸 تقرير انستقرام' : '🌐 تقرير موقع';
    }

    // Company name
    if (data.company_name) {
      const el = document.getElementById('reportSubtitle');
      if (el) el.textContent = `${data.company_name} — بصمة النمو v3.0`;
    }

    // Summary
    const sumEl = document.getElementById('summaryText');
    if (sumEl) sumEl.textContent = data.summary || '';

    // Breakdown bars
    const barsContainer = document.getElementById('breakdownBars');
    const breakdown = data.breakdown || {};
    Object.entries(breakdown).forEach(([key, val]) => {
      barsContainer.appendChild(makeBar(AXIS_LABELS[key] || key, val, AXIS_MAX[key] || 20));
    });
    setTimeout(() => document.querySelectorAll('.bd-fill').forEach(el => { el.style.width = el.dataset.pct + '%'; }), 250);

    // Website Report
    if (scan.hasSSL !== null && scan.hasSSL !== undefined || scan.hasPixel !== null && scan.hasPixel !== undefined || scan.pagespeed) {
        renderWebsiteBadges(scan);
        renderPageSpeed(scan);
    }

    // ── Facebook ─────────────────────────────────────────────────
    // في البنية الجديدة: scan.facebook
    // في البنية القديمة: scan.social (platform=facebook)
    const fbData = scan.facebook || (scan.social?.platform !== 'instagram' ? scan.social : null);
    if (fbData) renderFacebook(fbData);

    // ── Instagram ────────────────────────────────────────────────
    // في البنية الجديدة: scan.instagram
    // في البنية القديمة: scan.social (platform=instagram)
    const igData = scan.instagram || (scan.social?.platform === 'instagram' ? scan.social : null);
    if (igData) renderInstagramData(igData);

    // ── Ads Library ──────────────────────────────────────────────
    if (scan.ads_library) {
        renderAdsLibrary(scan);
    }

    // Strengths & Weaknesses
    const strengths  = data.strengths  || [];
    const weaknesses = data.weaknesses || [];
    if (strengths.length || weaknesses.length) {
      const sw = document.getElementById('swSection');
      if (sw) sw.style.display = 'block';
      strengths.forEach(s  => { const li = document.createElement('li'); li.textContent = s; document.getElementById('strengthsList')?.appendChild(li); });
      weaknesses.forEach(w => { const li = document.createElement('li'); li.textContent = w; document.getElementById('weaknessesList')?.appendChild(li); });
    }

    // Recommendations
    renderRecommendations(data.recommendations || []);

    // Competitor Radar
    if (data.competitor_analysis) {
        renderCompetitors(data.competitor_analysis);
    }

    // Action plan
    renderActionPlan(data);

    // Debug (dev only)
    console.log('📊 بيانات التقرير:', data);
    console.log('📡 scan_result:', scan);
    console.log('👥 social:', scan.social);
    console.log('📢 ads:', scan.ads_library);

  } catch (e) {
    showError(e.message || 'حدث خطأ غير متوقع');
  }
});

function showError(msg) {
  document.getElementById('skeleton').style.display = 'none';
  const el = document.getElementById('errBox');
  if (el) { el.style.display = 'flex'; el.innerHTML = `⚠️ ${msg} — <a href="index.html" style="color:var(--blue);margin-right:6px">العودة للتقييم</a>`; }
}
