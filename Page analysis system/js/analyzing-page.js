/* ═══ analyzing-page.js — CSP-safe JS for analyzing.html ═══
 * Extracted from inline <script> for CSP compliance.
 * No eval, no new Function, no setTimeout(string), no template literals in setTimeout.
 * Uses string concatenation instead of template literals in callbacks.
 * v2.0: Wrapped in DOMContentLoaded to ensure DOM elements exist.
 */

document.addEventListener('DOMContentLoaded', function() {
  'use strict';

  // ============================================================
  // analyzing.html v2.0 — Real Polling System
  // Phase 1: POST to submit.php → get assessment_id instantly
  // Phase 2: Poll result.php every 4s until status = 'analyzed'
  // ============================================================

  var urlParams   = new URLSearchParams(window.location.search);
  var targetUrl   = urlParams.get('url') || '';
  var leadDataRaw = sessionStorage.getItem('lead_data');

  var bar         = document.getElementById('progressBar');
  var pct         = document.getElementById('progressPct');
  var msg         = document.getElementById('statusMsg');
  var phaseBadge  = document.getElementById('phaseBadge');
  var elapsedEl   = document.getElementById('elapsedTime');

  // Timing
  var startTime   = Date.now();
  var MAX_WAIT_MS = 120 * 60 * 1000; // ساعتان (مفتوح فعلياً)
  var POLL_MS     = 8000;           // كل 8 ثوانٍ

  var pollTimer       = null;
  var elapsedTimer    = null;
  var assessmentId    = null;
  var partialShown    = false;

  // ── Replace onclick with addEventListener ──
  var retryBtns = document.querySelectorAll('.retry-btn');
  retryBtns.forEach(function(btn) {
    btn.removeAttribute('onclick');
    btn.addEventListener('click', function(e) {
      e.preventDefault();
      window.location.href = 'scan.html';
    });
  });

  // ── Elapsed Timer ──────────────────────────────────────────
  elapsedTimer = setInterval(function() {
    var sec = Math.floor((Date.now() - startTime) / 1000);
    var m   = Math.floor(sec / 60);
    var s   = sec % 60;
    if (elapsedEl) elapsedEl.textContent = m + ':' + (s < 10 ? '0' : '') + s + ' مضت';
  }, 1000);

  // ── UI Helpers ─────────────────────────────────────────────
  function setProgress(p) {
    if (bar) bar.style.width = Math.min(p, 98) + '%';
    if (pct) pct.textContent = Math.min(p, 98);
  }

  function activateStep(n) {
    for (var i = 1; i <= 5; i++) {
      var el    = document.getElementById('step' + i);
      if (!el) continue;
      var badge = el.querySelector('.step-badge');
      if (i < n) {
        el.className         = 'step-item done';
        if (badge) badge.textContent    = '✓ تم';
      } else if (i === n) {
        el.className         = 'step-item active';
        if (badge) badge.textContent    = 'جاري...';
      } else {
        el.className         = 'step-item';
        if (badge) badge.textContent    = 'انتظار...';
      }
    }
  }

  function showError(text) {
    clearInterval(pollTimer);
    clearInterval(elapsedTimer);
    var errBox = document.getElementById('errorBox');
    if (errBox) errBox.style.display = 'block';
    var errMsg = document.getElementById('errorMsg');
    if (errMsg) errMsg.innerHTML = text;
    // أوقف الـ brain animation
    var brainCenter = document.querySelector('.brain-center');
    if (brainCenter) brainCenter.textContent = '⚠️';
  }

  function redirectToResult() {
    clearInterval(pollTimer);
    clearInterval(elapsedTimer);
    activateStep(5);
    setProgress(100);
    if (msg) msg.textContent = 'التقرير جاهز! جاري توجيهك...';
    setTimeout(function() {
      window.location.href = 'report.html?id=' + assessmentId + '&token=' + encodeURIComponent(sessionStorage.getItem('last_assessment_token') || '');
    }, 1000);
  }

  // ── Redirect guard ──
  if (!targetUrl && !urlParams.get('id')) {
    window.location.href = 'scan.html';
  }

  // ── Phase 1: Submit & Register ─────────────────────────────
  function phase1_submit() {
    activateStep(1);
    setProgress(8);
    if (msg) msg.textContent = 'جاري تسجيل طلبك وبدء الفحص...';

    // Build lead object from sessionStorage
    var lead = {
      url:             targetUrl,
      full_name:       'زائر',
      phone:           '000',
      email:           '',
      website_url:     '',
      instagram_url:   '',
      facebook_url:    '',
      tiktok_url:      '',
      twitter_url:     '',
      objective:       '',
      project_type:    '',
      target_audience: '',
      ad_budget:       '',
      country:         ''
    };

    if (leadDataRaw) {
      try { Object.assign(lead, JSON.parse(leadDataRaw)); } catch(e) {}
    }

    // Normalize URL fields
    if (!lead.website_url && !lead.facebook_url && !lead.instagram_url && !lead.tiktok_url && !lead.twitter_url) {
      if (targetUrl.indexOf('instagram.com') !== -1)                                    lead.instagram_url = targetUrl;
      else if (targetUrl.indexOf('facebook.com') !== -1 || targetUrl.indexOf('fb.com') !== -1) lead.facebook_url = targetUrl;
      else if (targetUrl.indexOf('tiktok.com') !== -1)                                   lead.tiktok_url   = targetUrl;
      else if (targetUrl.indexOf('twitter.com') !== -1 || targetUrl.indexOf('x.com') !== -1)  lead.twitter_url = targetUrl;
      else                                                                               lead.website_url  = targetUrl;
    }

    activateStep(2);
    setProgress(15);
    if (msg) msg.innerHTML = 'يتم الآن فحص: <small style="color:#94a3b8">' + decodeURIComponent(targetUrl) + '</small>';

    fetch('api/submit.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ lead: lead, answers: {} })
    })
    .then(function(resp) { return resp.json(); })
    .then(function(data) {
      if (data.error) { showError(data.error); return; }

      assessmentId = data.assessment_id || data.id;
      if (!assessmentId) { showError('لم يتم إنشاء معرّف التقييم. حاول مجدداً.'); return; }

      // Save id to sessionStorage for recovery
      sessionStorage.setItem('last_assessment_id', assessmentId);
      sessionStorage.setItem('last_assessment_token', data.token || '');

      // If analysis already done (fast path)
      if (data.score != null && data.tier) {
        showPartialResult(data.score);
      }

      // تشغيل التحليل في الخلفية
      fetch('api/run.php?id=' + assessmentId + '&token=' + encodeURIComponent(sessionStorage.getItem('last_assessment_token') || '')).catch(function(e) { console.log('Run triggered', e); });

      // Phase 2: Start polling
      phase2_poll();

    })
    .catch(function(err) {
      showError('تعذّر الاتصال بالخادم: ' + err.message);
    });
  }

  // ── Phase 2: Real Polling ───────────────────────────────────
  function phase2_poll() {
    if (phaseBadge) {
      phaseBadge.textContent = '🔬 المرحلة 2 — تحليل عميق';
      phaseBadge.className   = 'phase-badge phase2';
    }
    activateStep(3);
    setProgress(30);
    if (msg) msg.innerHTML = 'يتم الآن سحب بيانات المنصات والإعلانات عبر Apify...<br><small style="color:#94a3b8">هذه المرحلة قد تستغرق 4-8 دقائق</small>';

    // Start polling interval
    pollTimer = setInterval(function() {
      // Timeout guard
      if (Date.now() - startTime > MAX_WAIT_MS) {
        clearInterval(pollTimer);
        showError('انتهت مهلة التحليل (120 دقيقة). التقرير الأولي قد يكون جاهزاً — <a href="report.html?id=' + assessmentId + '&token=' + encodeURIComponent(sessionStorage.getItem('last_assessment_token') || '') + '" style="color:#f58e1a">اضغط هنا للاطلاع عليه</a>');
        return;
      }

      fetch('api/status.php?id=' + assessmentId + '&token=' + encodeURIComponent(sessionStorage.getItem('last_assessment_token') || ''))
        .then(function(resp) { return resp.json(); })
        .then(function(data) {
          if (!data || data.error) return; // تجاهل الأخطاء المؤقتة
          updateProgressFromStatus(data);
        })
        .catch(function() {
          // Network hiccup — try again next tick
        });
    }, POLL_MS);
  }

  // ── Status → UI Mapping ────────────────────────────────────
  function updateProgressFromStatus(data) {
    var status   = data.status || '';
    var scanStep = data.scan_step || 0;
    var elapsed  = Math.floor((Date.now() - startTime) / 1000);

    // Map scan_step to visual progress
    var stepMap = {
      0: { step: 2, pct: 20,  msg: 'يتم فحص الموقع الإلكتروني...' },
      1: { step: 2, pct: 30,  msg: 'اكتمل فحص الموقع — يبحث عن المنصات...' },
      2: { step: 3, pct: 45,  msg: 'يتم سحب بيانات فيسبوك...' },
      3: { step: 3, pct: 58,  msg: 'يتم سحب بيانات إنستجرام عبر Apify...' },
      4: { step: 3, pct: 70,  msg: 'يفحص مكتبة الإعلانات (Meta Ads Library)...' },
      5: { step: 4, pct: 82,  msg: 'الذكاء الاصطناعي يكتب تقريرك المخصص...' },
      6: { step: 4, pct: 92,  msg: 'تقريبًا انتهى — يتم حفظ النتائج...' },
    };

    if (status === 'analyzed') {
      if (data.score != null) showPartialResult(data.score);
      redirectToResult();
      return;
    }

    if (status === 'failed') {
      showError('فشل التحليل على الخادم. ' + (data.scan_error || ''));
      return;
    }

    // Still running — update UI based on scan_step
    var ui = stepMap[scanStep] || stepMap[Math.min(Math.floor(elapsed / 30), 6)];
    if (ui) {
      activateStep(ui.step);
      setProgress(ui.pct);
      if (msg) msg.textContent = ui.msg;
    }

    // Show partial score if available
    if (data.score != null && !partialShown) {
      showPartialResult(data.score);
    }
  }

  function showPartialResult(score) {
    if (partialShown) return;
    partialShown = true;
    var psEl = document.getElementById('partialScore');
    if (psEl) psEl.textContent = score;
    var prEl = document.getElementById('partialResult');
    if (prEl) prEl.style.display = 'block';
  }

  // ── Start ──────────────────────────────────────────────────
  if (urlParams.get('id')) {
    assessmentId = urlParams.get('id');
    phase2_poll();
  } else {
    phase1_submit();
  }

});
