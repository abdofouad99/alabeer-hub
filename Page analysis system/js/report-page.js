// ============================================================
// js/report-page.js v6.0 — Platform cards rendering for report.html
// Works TOGETHER with report-connect.js (which handles fetch + main data).
// This file only renders platform-specific cards (website, facebook,
// instagram, ads, tiktok, twitter) using data already fetched by
// report-connect.js. No duplicate fetch.
// ============================================================

document.addEventListener("DOMContentLoaded", () => {

  // ── Inline SVG placeholder for posts with missing/broken images ──
  // Returns a data: URI so it never makes a network request. The two
  // platform-specific helpers below pick a colored variant + emoji so the
  // empty card still feels like the right network rather than a white box.
  // (We keep this as data-URI on background-image because most callers
  //  use CSS background-image, which can't trigger an onerror handler.)
  const POST_PLACEHOLDER_FB = 'data:image/svg+xml;utf8,' + encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200">' +
    '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">' +
    '<stop offset="0" stop-color="#1e3a8a"/><stop offset="1" stop-color="#1e1b4b"/>' +
    '</linearGradient></defs>' +
    '<rect width="200" height="200" fill="url(#g)"/>' +
    '<text x="100" y="115" font-size="64" text-anchor="middle" font-family="sans-serif">📘</text>' +
    '</svg>'
  );
  const POST_PLACEHOLDER_IG = 'data:image/svg+xml;utf8,' + encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200">' +
    '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">' +
    '<stop offset="0" stop-color="#7c2d92"/><stop offset="0.5" stop-color="#db2777"/><stop offset="1" stop-color="#f59e0b"/>' +
    '</linearGradient></defs>' +
    '<rect width="200" height="200" fill="url(#g)"/>' +
    '<text x="100" y="115" font-size="64" text-anchor="middle" font-family="sans-serif">📷</text>' +
    '</svg>'
  );

  // pickPostImage: returns a usable image URL or the platform placeholder.
  // It treats empty strings, '#', whitespace, and obviously-broken values
  // (e.g. 'undefined') as missing — so users never see white squares.
  function pickPostImage(post, platform) {
    const candidates = platform === 'ig'
      ? [post.displayUrl, post.image_url, post.thumbnail, post.image, post.media_url]
      : [post.image_url, post.displayUrl, post.full_picture, post.picture, post.imageUrl, post.image];
    for (const c of candidates) {
      if (typeof c !== 'string') continue;
      const v = c.trim();
      if (!v || v === '#' || v === 'undefined' || v === 'null') continue;
      // basic URL sanity (http/https/data)
      if (/^(https?:|data:)/i.test(v)) return v;
    }
    return platform === 'ig' ? POST_PLACEHOLDER_IG : POST_PLACEHOLDER_FB;
  }

  // ── Helper functions (same as report-connect.js extractText) ──
  const __extractText = (item, fallback) => {
    if (item == null) return fallback;
    if (typeof item === 'string') return item;
    if (typeof item === 'number' || typeof item === 'boolean') return String(item);
    if (typeof item === 'object') {
      const keys = ['title','name','point','text','heading','label','item','desc','description','task'];
      for (const k of keys) {
        const v = item[k];
        if (typeof v === 'string' && v.trim()) return v;
      }
      return fallback;
    }
    return String(item);
  };

  // ── Render platform cards using shared data ──
  function renderPlatformCards(data) {
    if (!data || !data.scan_result) return;

    const sr = data.scan_result;

    // 1. Website Card
    const hasWebsiteUrl = data.website_url || sr.website_scan?.url || sr.website_scan?.final_url;
    if (sr.website_scan || hasWebsiteUrl) {
      const w = sr.website_scan || {};
      const webCard = document.getElementById('platWebsite');
      if (webCard) webCard.style.display = 'block';
      if (document.getElementById('webUrl')) document.getElementById('webUrl').textContent = w.final_url || w.url || hasWebsiteUrl || '';
      if (document.getElementById('webTitle')) document.getElementById('webTitle').textContent = w.title || '—';
      if (document.getElementById('webSpeed')) document.getElementById('webSpeed').textContent = w.load_time_s ? w.load_time_s + 's' : (w.loadTime ? w.loadTime + 's' : '—');
      if (document.getElementById('webSSL')) document.getElementById('webSSL').textContent = w.has_ssl ? 'مفعّل' : (w.hasSSL ? 'مفعّل' : 'مفقود');

      const badge = document.getElementById('webBadge');
      if (badge) {
        const hasPix = w.has_fb_pixel || w.hasPixel;
        const hasGa = w.has_ga || w.hasGA;
        badge.textContent = hasPix && hasGa ? 'محسّن' : 'يحتاج عمل';
        badge.style.background = hasPix && hasGa ? 'rgba(16,185,129,0.2)' : 'rgba(245,158,11,0.2)';
        badge.style.color = hasPix && hasGa ? '#10b981' : '#f59e0b';
      }

      const signals = document.getElementById('webSignals');
      if (signals) {
        const list = [
          { label: 'Pixel', val: w.has_fb_pixel || w.hasPixel },
          { label: 'GA4', val: w.has_ga || w.hasGA },
          { label: 'SSL', val: w.has_ssl || w.hasSSL },
          { label: 'WhatsApp', val: w.has_whatsapp || w.hasWhatsApp },
          { label: 'OG Tags', val: w.has_og_tags || w.hasOG },
          { label: 'Schema', val: w.has_schema || w.hasSchema },
          { label: 'H1', val: !!w.h1 || w.hasH1 },
          { label: 'Meta Desc', val: !!w.description || w.hasDescription }
        ];
        signals.innerHTML = list.map(s => '<span class="' + (s.val ? 'sig-ok' : 'sig-no') + '">' + (s.val ? '✅' : '❌') + ' ' + s.label + '</span>').join('');
      }
    }

    // 2. Facebook Card
    const hasFacebookUrl = data.facebook_url || (sr.facebook && sr.facebook.url) || (sr.website_scan && sr.website_scan.facebook_url);
    if ((sr.facebook && (sr.facebook.success || sr.facebook.followers != null || sr.facebook.page_name)) || hasFacebookUrl) {
      const f = sr.facebook || {};
      const fbCard = document.getElementById('platFacebook');
      if (fbCard) fbCard.style.display = 'block';
      const fmtNum = (v) => v != null ? Number(v).toLocaleString() : '—';

      if (document.getElementById('fbName')) document.getElementById('fbName').textContent = f.page_name || f.full_name || f.name || 'غير متوفر';
      if (document.getElementById('fbCategory')) document.getElementById('fbCategory').textContent = f.category || '';
      if (document.getElementById('fbFollowers')) document.getElementById('fbFollowers').textContent = fmtNum(f.followers);
      if (document.getElementById('fbLikes')) document.getElementById('fbLikes').textContent = fmtNum(f.likes);
      if (document.getElementById('fbRating')) document.getElementById('fbRating').textContent = f.rating ? '★ ' + f.rating : '—';
      if (document.getElementById('fbPosts')) document.getElementById('fbPosts').textContent = f.posts_count != null ? f.posts_count : '—';
      if (document.getElementById('fbEngagement')) {
        const fbEng = f.engagement_rate ?? f.avg_engagement;
        document.getElementById('fbEngagement').textContent = fbEng != null
          ? (f.engagement_rate != null ? fbEng + '%' : Number(fbEng).toLocaleString())
          : '—';
      }
      if (document.getElementById('fbPhone')) document.getElementById('fbPhone').textContent = f.phone || '';
      if (document.getElementById('fbWhatsapp')) document.getElementById('fbWhatsapp').textContent = f.whatsapp || '';
      if (document.getElementById('fbEmail')) document.getElementById('fbEmail').textContent = f.email || '';
      if (document.getElementById('fbWebsite')) {
        let ws = f.website || f.website_url || '';
        if (ws && ws.length > 30) ws = ws.substring(0, 27) + '...';
        document.getElementById('fbWebsite').textContent = ws;
      }

      const fbBadge2 = document.getElementById('fbBadge');
      if (fbBadge2) {
        fbBadge2.textContent = f.is_verified ? 'موثّق ✔️' : 'عادي';
        fbBadge2.style.background = f.is_verified ? 'rgba(16,185,129,0.2)' : 'rgba(100,116,139,0.2)';
        fbBadge2.style.color = f.is_verified ? '#10b981' : '#94a3b8';
      }

      const fbSignals2 = document.getElementById('fbSignals');
      if (fbSignals2) {
        const list = [
          { label: 'موثّق', val: f.is_verified },
          { label: 'هاتف', val: f.phone },
          { label: 'WhatsApp', val: f.whatsapp },
          { label: 'إيميل', val: f.email },
          { label: 'موقع', val: f.website },
          { label: 'متجر', val: f.has_shop }
        ];
        fbSignals2.innerHTML = list.map(s => '<span class="' + (s.val ? 'sig-ok' : 'sig-no') + '">' + (s.val ? '✅' : '❌') + ' ' + s.label + '</span>').join('');
      }
      if (f.ads_running !== undefined) {
        if (document.getElementById('fbAdsStatus')) document.getElementById('fbAdsStatus').textContent = f.ads_running ? 'نشط 🔥' : 'متوقف ❄️';
        if (document.getElementById('fbAdsCount')) document.getElementById('fbAdsCount').textContent = f.ads_count ?? '0';
      }
    }

    // 3. Instagram Card
    const hasInstagramUrl = data.instagram_url || (sr.instagram && sr.instagram.url) || (sr.website_scan && sr.website_scan.instagram_url);
    if ((sr.instagram && (sr.instagram.success || sr.instagram.followers != null || sr.instagram.username)) || hasInstagramUrl) {
      const i = sr.instagram || {};
      const igCard = document.getElementById('platInstagram');
      if (igCard) igCard.style.display = 'block';
      if (document.getElementById('igUsername')) document.getElementById('igUsername').textContent = i.username ? '@' + i.username : '—';
      const igFollowersRaw = i.followers ?? i.followersCount;
      if (document.getElementById('igFollowers')) document.getElementById('igFollowers').textContent = igFollowersRaw != null ? Number(igFollowersRaw).toLocaleString() : '—';
      const igPostsRaw = i.posts_count ?? i.postsCount ?? 0;
      if (document.getElementById('igPosts')) document.getElementById('igPosts').textContent = igPostsRaw || '—';
      const engagement = i.engagement_rate ?? i.avg_engagement;
      if (document.getElementById('igEng')) document.getElementById('igEng').textContent = engagement != null ? engagement + '%' : '—';
      const igSignals = document.getElementById('igSignals');
      if (igSignals) {
        const list = [
          { label: 'Verified', val: i.is_verified },
          { label: 'Bio Opt', val: !!i.bio },
          { label: 'Business', val: i.is_business },
          { label: 'Active', val: (parseInt(igPostsRaw || 0) > 50) }
        ];
        igSignals.innerHTML = list.map(s => '<span class="' + (s.val ? 'sig-ok' : 'sig-no') + '">' + (s.val ? '✅' : '❌') + ' ' + s.label + '</span>').join('');
      }
    }

    // 4. Ads Card
    if (sr.ads_library || sr.ads) {
      const a = sr.ads_library || sr.ads;
      const adsCard = document.getElementById('platAds');
      if (adsCard) adsCard.style.display = 'block';
      const totalAds  = Number(a.total_ads  || 0);
      const activeAds = Number(a.active_ads || 0);
      const isActive = activeAds > 0;
      if (document.getElementById('adsTotal'))  document.getElementById('adsTotal').textContent  = totalAds;
      if (document.getElementById('adsActive')) document.getElementById('adsActive').textContent = activeAds;
      if (document.getElementById('adsStatus')) {
        let label;
        if (isActive)        label = 'نشط 🔥';
        else if (totalAds)   label = 'متوقف ❄️ (إعلانات سابقة)';
        else                 label = 'متوقف ❄️';
        document.getElementById('adsStatus').textContent = label;
      }
      const adsBadge = document.getElementById('adsBadge');
      if (adsBadge) {
        adsBadge.textContent = isActive ? 'نشط' : 'متوقف';
        adsBadge.style.background = isActive ? 'rgba(16,185,129,0.2)' : 'rgba(100,116,139,0.2)';
        adsBadge.style.color      = isActive ? '#10b981' : '#94a3b8';
      }
    }

    // 5. TikTok Card
    const hasTikTokUrl = data.tiktok_url || (sr.tiktok && sr.tiktok.url) || (sr.website_scan && sr.website_scan.tiktok_url);
    if ((sr.tiktok && (sr.tiktok.success || sr.tiktok.followers != null)) || hasTikTokUrl) {
      const t = sr.tiktok || {};
      const card = document.getElementById('platTikTok');
      if (card) {
        card.style.display = 'block';
        const formatNum = (v) => v != null ? (typeof v === 'number' ? v.toLocaleString() : v) : '0';
        if (document.getElementById('ttUsername')) document.getElementById('ttUsername').textContent = t.username ? '@' + t.username : '—';
        if (document.getElementById('ttFollowers')) document.getElementById('ttFollowers').textContent = formatNum(t.followers);
        if (document.getElementById('ttLikes')) document.getElementById('ttLikes').textContent = formatNum(t.likes);
        if (document.getElementById('ttVideos')) document.getElementById('ttVideos').textContent = formatNum(t.video_count);
      }
    }

    // 6. Twitter Card
    const twData = sr.twitter || null;
    const twUrl = data.twitter_url || sr.twitter?.url || '';
    if (twData || twUrl) {
      const tw = twData || {};
      const card = document.getElementById('platTwitter');
      if (card) {
        card.style.display = 'block';
        const formatNum = (v) => v != null ? (typeof v === 'number' ? v.toLocaleString() : v) : '—';
        const ok = (tw.success === true) || tw.followers != null;
        if (document.getElementById('twUsername')) document.getElementById('twUsername').textContent = tw.username ? '@' + tw.username : '—';
        if (document.getElementById('twFollowers')) document.getElementById('twFollowers').textContent = ok ? formatNum(tw.followers) : '—';
        if (document.getElementById('twPosts'))     document.getElementById('twPosts').textContent     = ok ? formatNum(tw.posts_count) : '—';
        if (document.getElementById('twLocation'))  document.getElementById('twLocation').textContent  = tw.location || '—';
        const twBadge = document.getElementById('twBadge');
        if (twBadge) {
          if (ok) {
            twBadge.textContent = 'نشط';
            twBadge.style.background = 'rgba(16,185,129,0.2)';
            twBadge.style.color = '#10b981';
          } else {
            twBadge.textContent = 'تعذّر الجلب';
            twBadge.style.background = 'rgba(245,158,11,0.2)';
            twBadge.style.color = '#f59e0b';
            twBadge.title = tw.error || 'لم يتم جلب البيانات';
          }
        }
      }
    }
  }

  // ── Render growth card scores ──
  function renderGrowthCard(data) {
    if (data.score == null) return;
    const gOld = document.querySelector('.g-old');
    const gNew = document.querySelector('.g-new .score-num');
    if (gOld) gOld.textContent = data.score + '/100';
    const projected = Math.min(100, data.score + Math.round((100 - data.score) * 0.45));
    if (gNew) gNew.setAttribute('data-val', projected);
  }

  // ── Render AI report deep sections ──
  function renderAIReport(data) {
    const ai = data.ai_report || {};
    const mainContent = document.querySelector('.main-content');
    if (!mainContent || Object.keys(ai).length === 0) return;

    // الفرصة السوقية
    if (ai.market_opportunity) {
      const oppCard = document.createElement('div');
      oppCard.className = 'card animate-up delay-4';
      oppCard.style.marginTop = '24px';
      const h3 = document.createElement('h3');
      h3.className = 'card-title';
      h3.style.color = 'var(--green)';
      h3.textContent = '🌟 الفرصة السوقية الذهبية';
      const p = document.createElement('p');
      p.style.cssText = 'font-size:16px; line-height:1.7; opacity:0.9;';
      p.textContent = ai.market_opportunity;
      oppCard.appendChild(h3);
      oppCard.appendChild(p);
      mainContent.appendChild(oppCard);
    }

    // استراتيجية المنصات
    if (ai.platform_strategy && ai.platform_strategy.primary_platform) {
      const platCard = document.createElement('div');
      platCard.className = 'card animate-up delay-5';
      platCard.style.marginTop = '24px';
      platCard.innerHTML =
        '<h3 class="card-title">📱 الاستراتيجية الرقمية (' + ai.platform_strategy.primary_platform + ')</h3>' +
        '<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">' +
        '<div style="background:rgba(255,255,255,0.02); padding:16px; border-radius:12px; border:1px solid rgba(255,255,255,0.05);">' +
        '<h4 style="margin-bottom:10px; color:var(--primary);">ركائز المحتوى</h4>' +
        '<ul style="padding-right:20px; opacity:0.8;">' +
        (ai.platform_strategy.content_pillars || []).map(function(p) { return '<li>' + p + '</li>'; }).join('') +
        '</ul></div>' +
        '<div style="background:rgba(255,255,255,0.02); padding:16px; border-radius:12px; border:1px solid rgba(255,255,255,0.05);">' +
        '<h4 style="margin-bottom:10px; color:var(--primary);">تكتيك النمو</h4>' +
        '<p style="opacity:0.8;">' + (ai.platform_strategy.growth_tactic || '') + '</p>' +
        '<hr style="border-color:rgba(255,255,255,0.05); margin:10px 0;">' +
        '<p style="opacity:0.8; font-size:13px;">' + (ai.platform_strategy.content_mix || '') + '</p>' +
        '</div></div>';
      mainContent.appendChild(platCard);
    }

    // خطة إطلاق الإعلانات
    if (ai.ads_strategy && ai.ads_strategy.first_campaign) {
      const adsCard2 = document.createElement('div');
      adsCard2.className = 'card animate-up delay-5';
      adsCard2.style.marginTop = '24px';
      adsCard2.innerHTML =
        '<h3 class="card-title" style="color:var(--yellow);">📢 خطة إطلاق الإعلانات</h3>' +
        '<div style="background:rgba(255,255,255,0.03); padding:20px; border-radius:12px; border-left:4px solid var(--yellow);">' +
        '<h4 style="margin-bottom:8px;">🎯 الحملة الأولى: ' + ai.ads_strategy.first_campaign + '</h4>' +
        '<p style="opacity:0.8; margin-bottom:16px;"><strong>الجمهور:</strong> ' + (ai.ads_strategy.target_audience_description || '') + '</p>' +
        '<p style="opacity:0.8;"><strong>توزيع الميزانية:</strong> ' + (ai.ads_strategy.recommended_budget_distribution || '') + '</p>' +
        '</div>';
      mainContent.appendChild(adsCard2);
    }
  }
  // ── Render Deep Anatomy (Hero Posts) ──
  function renderDeepAnatomy(data) {
    if (!data || !data.scan_result) return;
    const sr = data.scan_result;
    const deepSection = document.getElementById('deepAnatomySection');
    if (!deepSection) return;

    console.log('[RP] Deep Anatomy Data Check:', sr);

    let hasData = false;

    // Facebook Posts
    const fb = sr.facebook || {};
    const fbPostsContainer = document.getElementById('fbHeroPosts');
    let fbPosts = [];
    if (Array.isArray(fb.posts)) fbPosts = fb.posts;
    else if (Array.isArray(fb.top_posts)) fbPosts = fb.top_posts;
    else if (Array.isArray(fb.latestPosts)) fbPosts = fb.latestPosts;
    else if (Array.isArray(fb.latest_posts)) fbPosts = fb.latest_posts;
    
    if (fbPostsContainer && fbPosts.length > 0) {
      hasData = true;
      fbPostsContainer.innerHTML = '';
      fbPosts.slice(0, 4).forEach(post => {
        const text = post.text || post.caption || post.message || 'بدون نص';
        const img = pickPostImage(post, 'fb');
        const likes = post.likes || post.likesCount || 0;
        const comments = post.comments || post.commentsCount || 0;
        const url = post.url || post.permalink_url || '#';
        
        fbPostsContainer.innerHTML += `
          <a href="${url}" target="_blank" class="hero-item" style="text-decoration:none; display:block;">
            <div class="hero-img" style="background-image: url('${img}')"></div>
            <div class="hero-body">
              <div class="hero-text">${text}</div>
              <div class="hero-meta">
                <span class="h-meta-item">❤️ ${Number(likes).toLocaleString()}</span>
                <span class="h-meta-item">💬 ${Number(comments).toLocaleString()}</span>
              </div>
            </div>
          </a>
        `;
      });
    } else {
      const fbDeepSection = document.getElementById('fbDeepSection');
      if (fbDeepSection) fbDeepSection.style.display = 'none';
    }

    // Instagram Posts
    const ig = sr.instagram || {};
    const igPostsContainer = document.getElementById('heroPosts');
    let igPosts = [];
    // أضف top_5_posts (أول مفتاح يُنتجه Apify scraper) لمنع البطاقات الفارغة
    if (Array.isArray(ig.top_5_posts)) igPosts = ig.top_5_posts;
    else if (Array.isArray(ig.latestPosts)) igPosts = ig.latestPosts;
    else if (Array.isArray(ig.top_posts)) igPosts = ig.top_posts;
    else if (Array.isArray(ig.posts)) igPosts = ig.posts;
    else if (Array.isArray(ig.latest_posts)) igPosts = ig.latest_posts;
    
    if (igPostsContainer && igPosts.length > 0) {
      hasData = true;
      igPostsContainer.innerHTML = '';
      igPosts.slice(0, 4).forEach(post => {
        const text = post.caption || post.text || 'بدون نص';
        const img = pickPostImage(post, 'ig');
        const likes = post.likesCount || post.likes || 0;
        const comments = post.commentsCount || post.comments || 0;
        const url = post.url || '#';
        
        igPostsContainer.innerHTML += `
          <a href="${url}" target="_blank" class="hero-item" style="text-decoration:none; display:block;">
            <div class="hero-img" style="background-image: url('${img}')"></div>
            <div class="hero-body">
              <div class="hero-text">${text}</div>
              <div class="hero-meta">
                <span class="h-meta-item">❤️ ${Number(likes).toLocaleString()}</span>
                <span class="h-meta-item">💬 ${Number(comments).toLocaleString()}</span>
              </div>
            </div>
          </a>
        `;
      });
    } else {
      const igDeepSection = document.getElementById('igDeepSection');
      if (igDeepSection) igDeepSection.style.display = 'none';
    }

    if (hasData) {
      deepSection.style.display = 'block';
    }
  }

  // ── Listen for data from report-connect.js ──
  // report-connect.js dispatches a custom event after fetching data
  // OR we listen for it via window.__reportData
  function tryRenderWithSharedData() {
    const sharedData = window.__reportData;
    if (sharedData) {
      console.log('[RP] Using shared data from report-connect.js');
      renderPlatformCards(sharedData);
      renderGrowthCard(sharedData);
      renderAIReport(sharedData);
      renderDeepAnatomy(sharedData);
      return true;
    }
    return false;
  }

  // Try immediately (in case report-connect.js already fetched)
  if (!tryRenderWithSharedData()) {
    // Not ready yet — listen for the custom event
    document.addEventListener('reportDataReady', function(e) {
      console.log('[RP] Received reportDataReady event');
      const data = e.detail || window.__reportData;
      if (data) {
        renderPlatformCards(data);
        renderGrowthCard(data);
        renderAIReport(data);
        renderDeepAnatomy(data);
      }
    });

    // Fallback: also try polling for shared data (in case event was missed)
    let pollCount = 0;
    const pollInterval = setInterval(function() {
      pollCount++;
      if (tryRenderWithSharedData() || pollCount > 30) {
        clearInterval(pollInterval);
      }
    }, 500);
  }
});
