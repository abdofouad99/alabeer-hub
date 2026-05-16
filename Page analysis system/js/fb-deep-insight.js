/* ============================================================
 * fb-deep-insight.js — V3 deep Facebook insight renderer
 * ------------------------------------------------------------
 * يستهلك بيانات scrapeFacebook (V3) ويملأ القسم #fbDeepInsight
 * في report.html. يعمل بهدوء (silent) إن لم تتوفر البيانات الكاملة.
 *
 * يستمع لحدث reportDataReady من report-connect.js ثم يستخرج
 * scan_result.facebook ويرسم كل الطبقات.
 * ============================================================ */
(function () {
  'use strict';

  // -------- helpers --------
  const $ = (id) => document.getElementById(id);
  const fmt  = (n) => (n == null || n === '' || isNaN(n)) ? '—' : Number(n).toLocaleString('en-US');
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

  function pickFB(sr) {
    if (!sr) return null;
    return sr.facebook || sr.scan_result?.facebook || null;
  }

  // -------- Renderers --------

  function renderKpis(fb) {
    setText('fbAvgLikes',     fmt1(fb.avg_likes));
    setText('fbAvgComments',  fmt1(fb.avg_comments));
    setText('fbAvgShares',    fmt1(fb.avg_shares));
    setText('fbAvgVideoViews',fmt(fb.avg_video_views));
    setText('fbPostsPerWeek', fmt1(fb.posts_per_week));
    const lpd = fb.last_post_days;
    setText('fbLastPost', lpd == null ? '—' : (lpd === 0 ? 'اليوم' : (lpd + ' يوم')));
    const er = fb.engagement_rate;
    setText('fbEngagementRate', er == null ? '—' : er + '%');
    const rc = fb.reviews_summary?.count ?? 0;
    const rt = fb.reviews_summary?.avg_rating;
    setText('fbReviewsSummary', rc > 0 ? (rt + '⭐ · ' + rc + ' مراجعة') : '—');
  }

  function renderHealth(fb) {
    const h = fb.page_health;
    if (!h) return;
    const score = +h.score || 0;
    setText('fbHealthScore', score);
    setText('fbHealthGrade', 'تقييم: ' + (h.grade || '—'));
    const grade = $('fbHealthGrade'); if (grade) grade.style.color = gradeColor(h.grade);
    const gauge = $('fbHealthGauge');
    if (gauge) gauge.style.setProperty('--p', score);
    const badge = $('fbHealthBadge');
    if (badge) badge.textContent = `Health ${score}/100 · ${h.grade || '—'}`;
    setHTML('fbHealthStrengths', (h.strengths || []).slice(0, 6).map(s => `<li>${safe(s)}</li>`).join('') || '<li class="muted">—</li>');
    setHTML('fbHealthIssues',    (h.issues    || []).slice(0, 6).map(s => `<li>${safe(s)}</li>`).join('') || '<li class="muted">—</li>');
  }

  function renderPageOpt(fb) {
    const o = fb.page_optimization;
    if (!o) return;
    const score = +o.score || 0;
    setText('fbOptScore', score);
    setText('fbOptGrade', 'تقييم: ' + (o.grade || '—'));
    const grade = $('fbOptGrade'); if (grade) grade.style.color = gradeColor(o.grade);
    const gauge = $('fbOptGauge');
    if (gauge) gauge.style.setProperty('--p', score);
    const flags = [
      ['has_phone',       'هاتف'],
      ['has_whatsapp',    'واتساب'],
      ['has_email',       'إيميل'],
      ['has_website',     'موقع'],
      ['has_address',     'عنوان'],
      ['has_hours',       'ساعات'],
      ['has_services',    'خدمات'],
      ['is_verified',     'موثّق'],
      ['has_profile_pic', 'صورة'],
      ['has_cover',       'غلاف'],
      ['has_cta',         'CTA'],
      ['has_category',    'تصنيف'],
    ];
    setHTML('fbOptFlags', flags.map(([k, lbl]) =>
      `<span class="fb-flag ${o[k] ? 'ok' : 'bad'}">${o[k] ? '✓' : '×'} ${lbl}</span>`
    ).join(''));
    setHTML('fbOptStrengths', (o.strengths || []).slice(0, 5).map(s => `<li>${safe(s)}</li>`).join('') || '<li class="muted">—</li>');
    setHTML('fbOptIssues',    (o.issues    || []).slice(0, 5).map(s => `<li>${safe(s)}</li>`).join('') || '<li class="muted">—</li>');
  }

  function renderContentDist(fb) {
    const c = fb.content_distribution;
    if (!c || !c.percent) return;
    const order = [
      ['photo', 'صور'], ['video', 'فيديو'], ['reel', 'Reels'],
      ['album', 'ألبوم'], ['link', 'روابط'], ['live', 'بث مباشر'],
      ['status', 'نص'], ['event', 'فعاليات'],
    ];
    const html = order
      .filter(([k]) => (c.percent[k] || 0) > 0)
      .map(([k, lbl]) => {
        const pct = c.percent[k] || 0;
        const cnt = (c.counts && c.counts[k]) || 0;
        return `<div class="fb-bar-row"><span class="lbl">${lbl}</span>` +
               `<span class="bar"><div style="width:${pct}%"></div></span>` +
               `<span class="pct">${pct}% <small style="color:#94a3b8">(${cnt})</small></span></div>`;
      }).join('');
    setHTML('fbContentDist', html || '<div class="muted">—</div>');
    if (c.avg_album_photos) {
      setText('fbAlbumPhotos', `متوسط صور الألبوم: ${c.avg_album_photos}`);
    } else {
      setText('fbAlbumPhotos', '');
    }
  }

  function renderHeatmap(fb) {
    const h = fb.posting_heatmap;
    if (!h || !h.grid_engagement) return;

    const days = ['Sat', 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
    const dayLabels = { Sat: 'سبت', Sun: 'أحد', Mon: 'إثن', Tue: 'ثلا', Wed: 'أرب', Thu: 'خمي', Fri: 'جمع' };

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
      table += `<tr><th style="padding-inline-end:6px;text-align:start;font-weight:800;color:#1e3a8a;">${dayLabels[d]}</th>`;
      const row = h.grid_engagement[d] || [];
      for (let hr = 0; hr < 24; hr++) {
        const v = row[hr] || 0;
        const t = v / maxEng;
        const bg = v === 0 ? '#f1f5f9' : `rgba(59,130,246,${0.18 + t * 0.82})`;
        const cls = v > 0 ? 'has' : '';
        const title = v > 0 ? `يوم ${dayLabels[d]} – الساعة ${hr}: تفاعل ${v}` : '';
        table += `<td class="${cls}" style="background:${bg};" title="${safe(title)}"></td>`;
      }
      table += '</tr>';
    });
    table += '</tbody>';
    setHTML('fbHeatmap', table);

    const meta = [];
    if (h.best_day !== null && h.best_day !== undefined && h.best_day !== '')   meta.push(`أفضل يوم: ${dayLabels[h.best_day] || h.best_day}`);
    if (h.best_hour !== null && h.best_hour !== undefined && h.best_hour !== '') meta.push(`أفضل ساعة: ${h.best_hour}:00`);
    if (h.timezone) meta.push(`المنطقة الزمنية: ${h.timezone}`);
    setText('fbHeatmapMeta', meta.join(' · '));
  }

  function renderHashtags(fb) {
    const h = fb.hashtags_analysis;
    if (!h || !h.top || !h.top.length) {
      setHTML('fbHashtagCloud', '<div class="muted" style="font-size:12px">لا توجد هاشتاجات</div>');
      return;
    }
    const html = h.top.slice(0, 20).map(t =>
      `<span class="fb-tag">#${safe(t.tag)}<span class="c">×${t.count}</span></span>`
    ).join('');
    setHTML('fbHashtagCloud', html);
  }

  function renderMentions(fb) {
    const m = fb.mentions_analysis;
    const mentList = (m && m.top_mentions) || [];
    const tagList  = (m && m.top_tagged)   || [];
    setHTML('fbMentionsList',
      mentList.slice(0, 8).map(x => `<li><span>@${safe(x.user)}</span><span class="c">×${x.count}</span></li>`).join('')
      || '<li class="muted">—</li>');
    setHTML('fbTaggedList',
      tagList.slice(0, 8).map(x => `<li><span>${safe(x.page)}</span><span class="c">×${x.count}</span></li>`).join('')
      || '<li class="muted">—</li>');
  }

  function renderLocations(fb) {
    const l = fb.locations;
    if (!l || !l.top || !l.top.length) {
      setHTML('fbLocationsList', '<li class="muted">لا توجد مواقع موسومة</li>');
    } else {
      setHTML('fbLocationsList',
        l.top.slice(0, 6).map(x => `<li><span>${safe(x.name)}</span><span class="c">×${x.count}</span></li>`).join(''));
    }
    const lm = fb.language_mix;
    if (lm) {
      const rows = [
        ['arabic_pct', 'عربي'], ['english_pct', 'إنجليزي'], ['mixed_pct', 'مختلط'], ['empty_pct', 'بلا نص'],
      ];
      setHTML('fbLanguageMix', rows.map(([k, lbl]) => {
        const v = lm[k] || 0;
        return `<div class="fb-bar-row"><span class="lbl">${lbl}</span>` +
               `<span class="bar"><div style="width:${v}%"></div></span>` +
               `<span class="pct">${v}%</span></div>`;
      }).join(''));
    }
  }

  function renderReactions(fb) {
    const r = fb.reactions_breakdown;
    if (!r) return;
    const total = Object.values(r).reduce((a, b) => a + (+b || 0), 0);
    if (total === 0) { setHTML('fbReactions', '<div class="muted">لا تتوفر بيانات Reactions</div>'); return; }
    const emojiMap = { like: '👍', love: '❤️', haha: '😂', wow: '😮', sad: '😢', angry: '😠', care: '🤗' };
    const labels   = { like: 'إعجاب', love: 'حب', haha: 'هاها', wow: 'واو', sad: 'حزن', angry: 'غضب', care: 'اهتمام' };
    const order = ['like','love','haha','wow','sad','angry','care'];
    const html = order.map(k => {
      const v = +r[k] || 0;
      const pct = total ? Math.round((v / total) * 100) : 0;
      return `<div class="fb-react-row">
        <span class="emoji">${emojiMap[k]}</span>
        <span class="lbl">${labels[k]}</span>
        <span class="bar"><div style="width:${pct}%"></div></span>
        <span class="pct">${fmt(v)} (${pct}%)</span>
      </div>`;
    }).join('');
    setHTML('fbReactions', html);
  }

  function renderReviews(fb) {
    const r = fb.reviews_summary;
    if (!r || r.count === 0) { setHTML('fbReviewsBox', '<div class="muted" style="padding:14px">لا توجد مراجعات</div>'); return; }
    const stars = r.distribution || {};
    const totalStars = Object.values(stars).reduce((a, b) => a + (+b || 0), 0) || 1;
    const distHtml = [5,4,3,2,1].map(s => {
      const v = +stars[s] || 0;
      const pct = Math.round((v / totalStars) * 100);
      return `<div class="fb-bar-row"><span class="lbl">${s}⭐</span>` +
             `<span class="bar"><div style="width:${pct}%; background:linear-gradient(90deg,#facc15,#eab308);"></div></span>` +
             `<span class="pct">${v} (${pct}%)</span></div>`;
    }).join('');

    const summary = `<div style="margin-bottom:10px;">
      <strong>متوسط التقييم:</strong> ${r.avg_rating || '—'}⭐ · ${r.count} مراجعة
    </div>${distHtml}`;

    const pos = (r.positive || []).slice(0, 4).map(t => `<li>${safe(t)}</li>`).join('');
    const neg = (r.negative || []).slice(0, 4).map(t => `<li>${safe(t)}</li>`).join('');

    setHTML('fbReviewsBox', summary +
      `<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px;">
        <div><div class="fb-list-title">⭐ إيجابي</div><ul class="fb-list fb-list-ok">${pos || '<li class="muted">—</li>'}</ul></div>
        <div><div class="fb-list-title">⚠️ سلبي</div><ul class="fb-list fb-list-bad">${neg || '<li class="muted">—</li>'}</ul></div>
      </div>`);
  }

  function postCard(p) {
    // pick image with fallback so empty/broken URLs don't render white squares
    const candidates = [p.displayUrl, p.imageUrl, p.image, p.full_picture, p.picture, (p.photos && p.photos[0])];
    let img = '';
    for (const c of candidates) {
      if (typeof c === 'string') {
        const v = c.trim();
        if (v && v !== '#' && v !== 'undefined' && v !== 'null' && /^(https?:|data:)/i.test(v)) {
          img = v;
          break;
        }
      }
    }
    if (!img) {
      img = 'data:image/svg+xml;utf8,' + encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200">' +
        '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">' +
        '<stop offset="0" stop-color="#1e3a8a"/><stop offset="1" stop-color="#1e1b4b"/>' +
        '</linearGradient></defs>' +
        '<rect width="200" height="200" fill="url(#g)"/>' +
        '<text x="100" y="115" font-size="64" text-anchor="middle" font-family="sans-serif">📘</text>' +
        '</svg>'
      );
    }
    const url = p.url || p.postUrl || p.permalink || '#';
    const text = (p.caption || p.text || p.message || '').replace(/\s+/g, ' ').slice(0, 80);
    const type = p.isReel ? 'Reel' : (p.type || '').toLowerCase();
    const likes    = p.likesCount || p.likes || p.reactionsCount || 0;
    const comments = p.commentsCount || p.comments || p.commentCount || 0;
    const shares   = p.sharesCount || p.shareCount || p.shares || 0;
    return `<a class="fb-top-card" href="${safe(url)}" target="_blank" rel="noopener">
      <div class="img" style="background-image:url('${safe(img)}')">
        <span class="badge-type">${safe(type)}</span>
      </div>
      <div class="body">
        <div title="${safe(text)}">${safe(text)}${text.length >= 80 ? '…' : ''}</div>
        <div class="stats">👍 ${fmt(likes)} · 💬 ${fmt(comments)} · 🔄 ${fmt(shares)}</div>
      </div>
    </a>`;
  }

  function renderTopPosts(fb) {
    const arr = fb.top_5_posts || [];
    if (!arr.length) { setHTML('fbTop5Posts', '<div class="muted" style="font-size:12px">لا توجد منشورات</div>'); return; }
    setHTML('fbTop5Posts', arr.map(postCard).join(''));
  }

  function renderSentiment(fb) {
    const s = fb.comments_sentiment;
    if (!s || !s.success) return;
    show('fbSentimentBox', true);
    const pos = +s.positive_pct || 0;
    const neu = +s.neutral_pct  || 0;
    const neg = +s.negative_pct || 0;
    setHTML('fbSentimentBar',
      `<div class="pos" style="width:${pos}%"></div>` +
      `<div class="neu" style="width:${neu}%"></div>` +
      `<div class="neg" style="width:${neg}%"></div>`);
    setText('fbSentimentMeta',
      `إيجابي ${pos}% · محايد ${neu}% · سلبي ${neg}% · أسئلة ${s.questions_pct || 0}% — ` +
      `إجمالي ${s.total_comments} تعليق من ${s.posts_sampled || 0} منشور — ` +
      `معدل ردود الصفحة: ${s.response_rate || 0}%`);
    setHTML('fbObjections', (s.top_objections || []).slice(0, 5).map(t => `<li>${safe(t)}</li>`).join('') || '<li class="muted">—</li>');
    setHTML('fbPraise',     (s.top_praise     || []).slice(0, 5).map(t => `<li>${safe(t)}</li>`).join('') || '<li class="muted">—</li>');
    setHTML('fbQuestions',  (s.top_questions  || []).slice(0, 5).map(t => `<li>${safe(t)}</li>`).join('') || '<li class="muted">—</li>');

    if (s.ai_summary && typeof s.ai_summary === 'object') {
      const ai = s.ai_summary;
      const recs = (ai.recommendations || []).map(r => `<li>${safe(r)}</li>`).join('');
      setHTML('fbAiSentimentSummary',
        `<div style="font-weight:900;margin-bottom:6px">🤖 خلاصة AI: ${safe(ai.overall || '')}</div>` +
        (recs ? `<ul style="margin:0;padding-inline-start:18px">${recs}</ul>` : ''));
      show('fbAiSentimentSummary', true);
    }
  }

  function renderVision(fb) {
    const v = fb.vision_analysis;
    if (!v || !v.success || !v.images || !v.images.length) return;
    show('fbVisionBox', true);
    setHTML('fbVisionGrid', v.images.map(im => {
      const tags = (im.tags || []).slice(0, 5).map(t => `<span class="tag">${safe(t)}</span>`).join('');
      const meta = [];
      if (im.has_logo)  meta.push('🏷 شعار');
      if (im.has_price) meta.push('💲 سعر');
      if (im.has_offer) meta.push('🔥 عرض');
      if (im.image_quality) meta.push('جودة: ' + im.image_quality);
      const ocr = im.ocr_text ? `<div style="font-size:10px;color:#475569;margin-top:4px"><b>نص الصورة:</b> ${safe(im.ocr_text.slice(0, 100))}</div>` : '';
      return `<div class="fb-vision-card">
        <div class="img" style="background-image:url('${safe(im.image_url)}')"></div>
        <div class="body">
          <div>${safe((im.description || '').slice(0, 90))}</div>
          <div class="tags">${tags}</div>
          ${meta.length ? `<div style="margin-top:4px;font-size:10px;color:#1e3a8a;font-weight:700">${meta.join(' · ')}</div>` : ''}
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
    setText('fbVisionSummary', sum.join(' · '));
  }

  function renderServices(fb) {
    const s = fb.services || [];
    if (!s.length) { setHTML('fbServicesGrid', '<div class="muted" style="font-size:12px">لا توجد خدمات</div>'); return; }
    setHTML('fbServicesGrid', s.slice(0, 12).map(svc =>
      `<div class="fb-service-card">
        <div class="name">${safe(svc.name || '—')}</div>
        ${svc.description ? `<div class="desc">${safe(svc.description.slice(0, 80))}</div>` : ''}
        ${svc.price ? `<div class="price">${safe(svc.price)}</div>` : ''}
      </div>`
    ).join(''));
  }

  function renderHours(fb) {
    const h = fb.opening_hours || {};
    const keys = Object.keys(h);
    if (!keys.length) { setHTML('fbHoursList', '<li class="muted">غير متوفّرة</li>'); return; }
    const dayLabels = {
      monday: 'الإثنين', tuesday: 'الثلاثاء', wednesday: 'الأربعاء',
      thursday: 'الخميس', friday: 'الجمعة', saturday: 'السبت', sunday: 'الأحد'
    };
    setHTML('fbHoursList', keys.map(k =>
      `<li><span>${dayLabels[k.toLowerCase()] || safe(k)}</span><span class="c">${safe(h[k])}</span></li>`
    ).join(''));
  }

  // -------- Main --------
  function renderAll(fb) {
    if (!fb) return;
    const isDeep = fb.fb_version === 'v3' || fb.page_health || fb.posting_heatmap || fb.hashtags_analysis;
    if (!isDeep && !fb.success) return;

    show('fbDeepInsight', true);

    try { renderKpis(fb); }         catch (e) { console.warn('FB kpis', e); }
    try { renderHealth(fb); }       catch (e) { console.warn('FB health', e); }
    try { renderPageOpt(fb); }      catch (e) { console.warn('FB pageOpt', e); }
    try { renderContentDist(fb); }  catch (e) { console.warn('FB content', e); }
    try { renderHeatmap(fb); }      catch (e) { console.warn('FB heatmap', e); }
    try { renderHashtags(fb); }     catch (e) { console.warn('FB hashtags', e); }
    try { renderMentions(fb); }     catch (e) { console.warn('FB mentions', e); }
    try { renderLocations(fb); }    catch (e) { console.warn('FB locations', e); }
    try { renderReactions(fb); }    catch (e) { console.warn('FB reactions', e); }
    try { renderReviews(fb); }      catch (e) { console.warn('FB reviews', e); }
    try { renderTopPosts(fb); }     catch (e) { console.warn('FB top5', e); }
    try { renderSentiment(fb); }    catch (e) { console.warn('FB sentiment', e); }
    try { renderVision(fb); }       catch (e) { console.warn('FB vision', e); }
    try { renderServices(fb); }     catch (e) { console.warn('FB services', e); }
    try { renderHours(fb); }        catch (e) { console.warn('FB hours', e); }
  }

  function tryRenderFromGlobals() {
    const root = window.__reportData || window.__SR__ || window.scanResult || window.scanData;
    if (!root) return;
    const sr = root.scan_result || root;
    const fb = pickFB(sr);
    if (fb) renderAll(fb);
  }

  document.addEventListener('reportDataReady', (ev) => {
    const root = ev?.detail || window.__reportData;
    if (!root) return;
    const sr = root.scan_result || root;
    const fb = pickFB(sr);
    if (fb) renderAll(fb);
  });
  document.addEventListener('scan-data-ready', (ev) => {
    const sr = ev?.detail?.scan_result || ev?.detail || window.__SR__;
    const fb = pickFB(sr);
    if (fb) renderAll(fb);
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', tryRenderFromGlobals);
  } else {
    tryRenderFromGlobals();
  }
  setTimeout(tryRenderFromGlobals, 800);
  setTimeout(tryRenderFromGlobals, 2500);

  window.renderFBDeepInsight = renderAll;
})();
