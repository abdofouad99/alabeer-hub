/* ============================================================
 * ig-deep-insight.js — V3 deep Instagram insight renderer
 * ------------------------------------------------------------
 * يستهلك بيانات scrapeInstagram (apify_ig_v3) ويملأ القسم
 * #igDeepInsight (في report.html). يعمل بهدوء (silent) إن لم
 * تتوفر البيانات الكاملة (مسار web_profile_info / html_regex).
 *
 * يقرأ البيانات من window.__SR__ أو window.scanResult كما يفعل
 * report-page.js. إن لم يجد، يستمع لحدث "scan-data-ready".
 * ============================================================ */
(function () {
  'use strict';

  // -------- helpers --------
  const $ = (id) => document.getElementById(id);
  const fmt = (n) => (n == null || n === '' || isNaN(n)) ? '—' : Number(n).toLocaleString('en-US');
  const fmt1 = (n) => (n == null || n === '' || isNaN(n)) ? '—' : (Math.round(n * 10) / 10).toLocaleString('en-US');
  const setText = (id, v) => { const el = $(id); if (el) el.textContent = v; };
  const setHTML = (id, v) => { const el = $(id); if (el) el.innerHTML = v; };
  const show = (id, v = true) => { const el = $(id); if (el) el.style.display = v ? '' : 'none'; };
  const safe = (s) => String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

  function gradeColor(grade) {
    return ({ A: '#10b981', B: '#84cc16', C: '#f59e0b', D: '#f97316', F: '#ef4444' })[grade] || '#94a3b8';
  }

  function pickIG(sr) {
    if (!sr) return null;
    return sr.instagram || sr.scan_result?.instagram || null;
  }

  function pickFromAny() {
    const root = window.__reportData || window.__SR__ || window.scanResult || window.scanData;
    if (!root) return null;
    const sr = root.scan_result || root;
    return pickIG(sr);
  }

  // -------- Renderers --------

  function renderKpis(ig) {
    setText('igAvgLikes',     fmt1(ig.avg_likes));
    setText('igAvgComments',  fmt1(ig.avg_comments));
    setText('igAvgSaves',     fmt1(ig.avg_saves));
    setText('igReelsCount',   fmt(ig.reels_count));
    setText('igPostsPerWeek', fmt1(ig.posts_per_week));
    const lpd = ig.last_post_days;
    setText('igLastPost', lpd == null ? '—' : (lpd === 0 ? 'اليوم' : (lpd + ' يوم')));
    setText('igFFRatio',  ig.followers_following_ratio != null ? ig.followers_following_ratio : '—');
    setText('igHighlights', fmt(ig.highlight_reel_count ?? ig.highlights));
  }

  function renderHealth(ig) {
    const h = ig.account_health;
    if (!h) return;
    const score = +h.score || 0;
    setText('igHealthScore', score);
    setText('igHealthGrade', 'تقييم: ' + (h.grade || '—'));
    const grade = $('igHealthGrade'); if (grade) grade.style.color = gradeColor(h.grade);
    const gauge = $('igHealthGauge');
    if (gauge) gauge.style.setProperty('--p', score);
    const badge = $('igHealthBadge');
    if (badge) badge.textContent = `Health ${score}/100 · ${h.grade || '—'}`;
    setHTML('igHealthStrengths', (h.strengths || []).slice(0, 6).map(s => `<li>${safe(s)}</li>`).join('') || '<li class="muted">—</li>');
    setHTML('igHealthIssues',    (h.issues    || []).slice(0, 6).map(s => `<li>${safe(s)}</li>`).join('') || '<li class="muted">—</li>');
  }

  function renderBio(ig) {
    const b = ig.bio_optimization;
    if (!b) return;
    const score = +b.score || 0;
    setText('igBioScore', score);
    setText('igBioGrade', 'تقييم: ' + (b.grade || '—'));
    const grade = $('igBioGrade'); if (grade) grade.style.color = gradeColor(b.grade);
    const gauge = $('igBioGauge');
    if (gauge) gauge.style.setProperty('--p', score);
    const flags = [
      ['has_link', 'رابط'], ['has_cta', 'CTA'], ['has_phone', 'هاتف'],
      ['has_email', 'إيميل'], ['has_whatsapp', 'واتساب'], ['has_emoji', 'إيموجي'],
    ];
    setHTML('igBioFlags', flags.map(([k, lbl]) =>
      `<span class="ig-flag ${b[k] ? 'ok' : 'bad'}">${b[k] ? '✓' : '×'} ${lbl}</span>`
    ).join(''));
    setHTML('igBioStrengths', (b.strengths || []).slice(0, 5).map(s => `<li>${safe(s)}</li>`).join('') || '<li class="muted">—</li>');
    setHTML('igBioIssues',    (b.issues    || []).slice(0, 5).map(s => `<li>${safe(s)}</li>`).join('') || '<li class="muted">—</li>');
  }

  function renderContentDist(ig) {
    const c = ig.content_distribution;
    if (!c || !c.percent) return;
    const order = [
      ['image', 'صور'], ['carousel', 'كاروسيل'], ['video', 'فيديو'], ['reel', 'Reels'],
    ];
    const html = order.map(([k, lbl]) => {
      const pct = c.percent[k] || 0;
      const cnt = (c.counts && c.counts[k]) || 0;
      return `<div class="ig-bar-row"><span class="lbl">${lbl}</span>` +
             `<span class="bar"><div style="width:${pct}%"></div></span>` +
             `<span class="pct">${pct}% <small style="color:#94a3b8">(${cnt})</small></span></div>`;
    }).join('');
    setHTML('igContentDist', html);
    if (c.avg_carousel_slides) {
      setText('igCarouselSlides', `متوسط شرائح الكاروسيل: ${c.avg_carousel_slides}`);
    } else {
      setText('igCarouselSlides', '');
    }
  }

  function renderHeatmap(ig) {
    const h = ig.posting_heatmap;
    if (!h || !h.grid_engagement) return;

    const days = ['Sat', 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
    const dayLabels = { Sat: 'سبت', Sun: 'أحد', Mon: 'إثن', Tue: 'ثلا', Wed: 'أرب', Thu: 'خمي', Fri: 'جمع' };

    // build max from data
    let maxEng = 0;
    days.forEach(d => {
      const row = h.grid_engagement[d] || [];
      row.forEach(v => { if (v > maxEng) maxEng = v; });
    });
    if (maxEng === 0) maxEng = 1;

    let table = '<thead><tr><th></th>';
    for (let hr = 0; hr < 24; hr++) table += `<th>${hr}</th>`;
    table += '</tr></thead><tbody>';
    days.forEach(d => {
      table += `<tr><th style="padding-inline-end:6px;text-align:start;font-weight:800;color:#9f1239;">${dayLabels[d]}</th>`;
      const row = h.grid_engagement[d] || [];
      for (let hr = 0; hr < 24; hr++) {
        const v = row[hr] || 0;
        const t = v / maxEng;
        const bg = v === 0 ? '#f1f5f9' : `rgba(236,72,153,${0.18 + t * 0.82})`;
        const cls = v > 0 ? 'has' : '';
        const title = v > 0 ? `يوم ${dayLabels[d]} – الساعة ${hr}: تفاعل ${v}` : '';
        table += `<td class="${cls}" style="background:${bg};" title="${safe(title)}"></td>`;
      }
      table += '</tr>';
    });
    table += '</tbody>';
    setHTML('igHeatmap', table);

    const meta = [];
    if (h.best_day !== null && h.best_day !== undefined && h.best_day !== '')   meta.push(`أفضل يوم: ${dayLabels[h.best_day] || h.best_day}`);
    if (h.best_hour !== null && h.best_hour !== undefined && h.best_hour !== '') meta.push(`أفضل ساعة: ${h.best_hour}:00`);
    if (h.timezone) meta.push(`المنطقة الزمنية: ${h.timezone}`);
    setText('igHeatmapMeta', meta.join(' · '));
  }

  function renderHashtags(ig) {
    const h = ig.hashtags_analysis;
    if (!h || !h.top || !h.top.length) { setHTML('igHashtagCloud', '<div class="muted" style="font-size:12px">لا توجد هاشتاجات</div>'); return; }
    const html = h.top.slice(0, 20).map(t =>
      `<span class="ig-tag">#${safe(t.tag)}<span class="c">×${t.count}</span></span>`
    ).join('');
    setHTML('igHashtagCloud', html);
  }

  function renderMentions(ig) {
    const m = ig.mentions_analysis;
    const mentList = (m && m.top_mentions) || [];
    const tagList  = (m && m.top_tagged)   || [];
    setHTML('igMentionsList',
      mentList.slice(0, 8).map(x => `<li><span>@${safe(x.user)}</span><span class="c">×${x.count}</span></li>`).join('')
      || '<li class="muted">—</li>');
    setHTML('igTaggedList',
      tagList.slice(0, 8).map(x => `<li><span>@${safe(x.user)}</span><span class="c">×${x.count}</span></li>`).join('')
      || '<li class="muted">—</li>');
  }

  function renderLocations(ig) {
    const l = ig.locations;
    if (!l || !l.top || !l.top.length) {
      setHTML('igLocationsList', '<li class="muted">لا توجد مواقع موسومة</li>');
    } else {
      setHTML('igLocationsList',
        l.top.slice(0, 6).map(x => `<li><span>${safe(x.name)}</span><span class="c">×${x.count}</span></li>`).join(''));
    }
    const lm = ig.language_mix;
    if (lm) {
      const rows = [
        ['arabic_pct', 'عربي'], ['english_pct', 'إنجليزي'], ['mixed_pct', 'مختلط'], ['empty_pct', 'بلا نص'],
      ];
      setHTML('igLanguageMix', rows.map(([k, lbl]) => {
        const v = lm[k] || 0;
        return `<div class="ig-bar-row"><span class="lbl">${lbl}</span>` +
               `<span class="bar"><div style="width:${v}%"></div></span>` +
               `<span class="pct">${v}%</span></div>`;
      }).join(''));
    }
  }

  function postCard(p) {
    const img = p.displayUrl || p.image || (p.images && p.images[0]) || '';
    const url = p.url || (p.shortCode ? `https://www.instagram.com/p/${p.shortCode}/` : '#');
    const text = (p.caption || p.text || '').replace(/\s+/g, ' ').slice(0, 80);
    const type = p.isReel ? 'Reel' : (p.type || '').toLowerCase().includes('sidecar') ? 'Carousel'
                : (p.type || '').toLowerCase().includes('video') ? 'Video' : 'Image';
    const likes = p.likesCount || p.likes || 0;
    const comments = p.commentsCount || p.comments || 0;
    return `<a class="ig-top-card" href="${safe(url)}" target="_blank" rel="noopener">
      <div class="img" style="background-image:url('${safe(img)}')">
        <span class="badge-type">${type}</span>
      </div>
      <div class="body">
        <div title="${safe(text)}">${safe(text)}${text.length >= 80 ? '…' : ''}</div>
        <div class="stats">❤ ${fmt(likes)} · 💬 ${fmt(comments)}</div>
      </div>
    </a>`;
  }

  function renderTopPosts(ig) {
    const arr = ig.top_5_posts || [];
    if (!arr.length) { setHTML('igTop5Posts', '<div class="muted" style="font-size:12px">لا توجد منشورات</div>'); return; }
    setHTML('igTop5Posts', arr.map(postCard).join(''));
  }

  function renderReels(ig) {
    const r = ig.reels_performance;
    if (!r || !r.count) return;
    show('igReelsBox', true);
    setText('igReelsTotalCount', fmt(r.count));
    setText('igReelsAvgPlays',   fmt(r.avg_plays));
    setText('igReelsAvgViews',   fmt(r.avg_views));
    setText('igReelsAvgDur',     r.avg_duration_sec ?? '—');
    setText('igReelsEngPerPlay', r.engagement_per_play != null ? r.engagement_per_play + '%' : '—');
  }

  function renderSentiment(ig) {
    const s = ig.comments_sentiment;
    if (!s || !s.success) return;
    show('igSentimentBox', true);
    const pos = +s.positive_pct || 0;
    const neu = +s.neutral_pct  || 0;
    const neg = +s.negative_pct || 0;
    setHTML('igSentimentBar',
      `<div class="pos" style="width:${pos}%"></div>` +
      `<div class="neu" style="width:${neu}%"></div>` +
      `<div class="neg" style="width:${neg}%"></div>`);
    setText('igSentimentMeta',
      `إيجابي ${pos}% · محايد ${neu}% · سلبي ${neg}% · أسئلة ${s.questions_pct || 0}% — ` +
      `إجمالي ${s.total_comments} تعليق من ${s.posts_sampled || 0} منشور — ` +
      `معدل ردود الصاحب: ${s.response_rate || 0}%`);
    setHTML('igObjections', (s.top_objections || []).slice(0, 5).map(t => `<li>${safe(t)}</li>`).join('') || '<li class="muted">—</li>');
    setHTML('igPraise',     (s.top_praise     || []).slice(0, 5).map(t => `<li>${safe(t)}</li>`).join('') || '<li class="muted">—</li>');
    setHTML('igQuestions',  (s.top_questions  || []).slice(0, 5).map(t => `<li>${safe(t)}</li>`).join('') || '<li class="muted">—</li>');

    if (s.ai_summary && typeof s.ai_summary === 'object') {
      const ai = s.ai_summary;
      const recs = (ai.recommendations || []).map(r => `<li>${safe(r)}</li>`).join('');
      setHTML('igAiSentimentSummary',
        `<div style="font-weight:900;margin-bottom:6px">🤖 خلاصة AI: ${safe(ai.overall || '')}</div>` +
        (recs ? `<ul style="margin:0;padding-inline-start:18px">${recs}</ul>` : ''));
      show('igAiSentimentSummary', true);
    }
  }

  function renderVision(ig) {
    const v = ig.vision_analysis;
    if (!v || !v.success || !v.images || !v.images.length) return;
    show('igVisionBox', true);
    setHTML('igVisionGrid', v.images.map(im => {
      const tags = (im.tags || []).slice(0, 5).map(t => `<span class="tag">${safe(t)}</span>`).join('');
      const meta = [];
      if (im.has_logo)  meta.push('🏷 شعار');
      if (im.has_price) meta.push('💲 سعر');
      if (im.has_offer) meta.push('🔥 عرض');
      if (im.image_quality) meta.push('جودة: ' + im.image_quality);
      const ocr = im.ocr_text ? `<div style="font-size:10px;color:#475569;margin-top:4px"><b>نص الصورة:</b> ${safe(im.ocr_text.slice(0, 100))}</div>` : '';
      return `<div class="ig-vision-card">
        <div class="img" style="background-image:url('${safe(im.image_url)}')"></div>
        <div class="body">
          <div>${safe((im.description || '').slice(0, 90))}</div>
          <div class="tags">${tags}</div>
          ${meta.length ? `<div style="margin-top:4px;font-size:10px;color:#9f1239;font-weight:700">${meta.join(' · ')}</div>` : ''}
          ${ocr}
        </div>
      </div>`;
    }).join(''));
    const sum = [];
    if (v.analyzed_count) sum.push(`تحليل ${v.analyzed_count} صورة`);
    if (v.logos_present)  sum.push(`شعار في ${v.logos_present}`);
    if (v.prices_present) sum.push(`سعر ظاهر في ${v.prices_present}`);
    if (v.offers_present) sum.push(`عرض في ${v.offers_present}`);
    if (v.top_tags && v.top_tags.length) sum.push('أبرز الموضوعات: ' + v.top_tags.slice(0, 5).join(' · '));
    setText('igVisionSummary', sum.join(' · '));
  }

  function renderStories(ig) {
    const s = ig.stories_data;
    if (!s || !s.success) return;
    show('igStoriesBox', true);
    setText('igStoriesMeta',
      `Stories حالية: ${s.stories_count || 0} · Highlights: ${s.highlights_count || 0}`);
    const hs = (s.highlights || []).slice(0, 12);
    setHTML('igHighlightsGrid', hs.map(h =>
      `<div class="ig-highlight-card">
         <div class="cover" style="background-image:url('${safe(h.cover || h.image || '')}')"></div>
         <div>${safe(h.title || '—')}</div>
       </div>`
    ).join('') || '');
  }

  function renderRelated(ig) {
    const arr = ig.related_profiles || [];
    if (!arr.length) return;
    show('igRelatedBox', true);
    setHTML('igRelatedGrid', arr.slice(0, 12).map(r =>
      `<a class="ig-related-card" href="https://www.instagram.com/${safe(r.username)}/" target="_blank" rel="noopener">
        <img src="${safe(r.profile_pic || '')}" onerror="this.style.display='none'" alt="">
        <div style="font-weight:800">@${safe(r.username)}${r.verified ? ' ✓' : ''}</div>
        <div style="color:#64748b;font-size:10px">${safe(r.full_name || '')}</div>
      </a>`
    ).join(''));
  }

  // -------- Main --------
  function renderAll(ig) {
    if (!ig) return;
    // النموذج الكامل (apify_ig_v3) فقط هو الذي فيه deep analytics
    const isDeep = ig.source === 'apify_ig_v3' || ig.account_health || ig.posting_heatmap || ig.hashtags_analysis;
    if (!isDeep) {
      // قد يكون مسار web_profile_info — نظهر الجزء البسيط فقط (bio + health إن توفّرا)
      if (!ig.bio_optimization && !ig.account_health) return;
    }

    show('igDeepInsight', true);

    try { renderKpis(ig); }         catch (e) { console.warn('IG kpis', e); }
    try { renderHealth(ig); }       catch (e) { console.warn('IG health', e); }
    try { renderBio(ig); }          catch (e) { console.warn('IG bio', e); }
    try { renderContentDist(ig); }  catch (e) { console.warn('IG content', e); }
    try { renderHeatmap(ig); }      catch (e) { console.warn('IG heatmap', e); }
    try { renderHashtags(ig); }     catch (e) { console.warn('IG hashtags', e); }
    try { renderMentions(ig); }     catch (e) { console.warn('IG mentions', e); }
    try { renderLocations(ig); }    catch (e) { console.warn('IG locations', e); }
    try { renderTopPosts(ig); }     catch (e) { console.warn('IG top5', e); }
    try { renderReels(ig); }        catch (e) { console.warn('IG reels', e); }
    try { renderSentiment(ig); }    catch (e) { console.warn('IG sentiment', e); }
    try { renderVision(ig); }       catch (e) { console.warn('IG vision', e); }
    try { renderStories(ig); }      catch (e) { console.warn('IG stories', e); }
    try { renderRelated(ig); }      catch (e) { console.warn('IG related', e); }
  }

  function tryRenderFromGlobals() {
    const ig = pickFromAny();
    if (ig) renderAll(ig);
  }

  // Listen for the events that report-connect.js / scan flow may dispatch
  document.addEventListener('reportDataReady', (ev) => {
    const root = ev?.detail || window.__reportData;
    if (!root) return;
    const sr = root.scan_result || root;
    const ig = pickIG(sr);
    if (ig) renderAll(ig);
  });
  document.addEventListener('scan-data-ready', (ev) => {
    const sr = ev?.detail?.scan_result || ev?.detail || window.__SR__;
    const ig = pickIG(sr);
    if (ig) renderAll(ig);
  });

  // Try once at load + after a small delay (some scripts populate globals async)
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', tryRenderFromGlobals);
  } else {
    tryRenderFromGlobals();
  }
  setTimeout(tryRenderFromGlobals, 800);
  setTimeout(tryRenderFromGlobals, 2500);

  // Expose for manual debug
  window.renderIGDeepInsight = renderAll;
})();
