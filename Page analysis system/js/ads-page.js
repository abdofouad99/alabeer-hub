/* ═══ ads-page.js — CSP-safe JS for ads.html ═══
 * Extracted from inline <script> for CSP compliance.
 * No eval, no new Function, no setTimeout(string).
 * Uses createElement + textContent instead of innerHTML where possible.
 * v1.0
 */

// ═══ ربط حساب Meta Ads ═══
function handleMetaLink() {
  var token = prompt('الصق Access Token من Meta Ads Manager هنا:\n(من: business.facebook.com > الإعدادات > رموز الوصول)');
  if (!token || token.trim().length < 20) return;

  var btn = document.getElementById('btnLinkMeta');
  if (btn) {
    btn.textContent = '⏳ جاري الاتصال...';
    btn.disabled = true;
  }

  // استخراج id من الـ URL
  var id = new URLSearchParams(window.location.search).get('id');
  if (!id) { alert('لم يتم تحديد معرّف الفحص في الرابط.'); if(btn) btn.disabled=false; return; }

  fetch('api/ads-fetch.php?id=' + id + '&action=real-metrics&meta_token=' + encodeURIComponent(token))
    .then(function(r) { return r.json(); })
    .then(function(resp) {
      if (resp.success && resp.real_metrics && resp.real_metrics.connected) {
        var m = resp.real_metrics;
        // إظهار قسم الأرقام
        var rmSection = document.getElementById('realMetricsSection');
        if (rmSection) rmSection.style.display = 'block';
        var mlBanner = document.getElementById('metaLinkBanner');
        if (mlBanner) mlBanner.style.display = 'none';
        var rmName = document.getElementById('rmAccountName');
        if (rmName) rmName.textContent = m.account_name || 'حسابك';
        var rmRoas = document.getElementById('rmRoas');
        if (rmRoas) rmRoas.textContent = m.roas || '—';
        var rmSpend = document.getElementById('rmSpend');
        if (rmSpend) rmSpend.textContent = m.spend || '—';
        var rmRevenue = document.getElementById('rmRevenue');
        if (rmRevenue) rmRevenue.textContent = m.revenue || '—';
        var rmCpc = document.getElementById('rmCpc');
        if (rmCpc) rmCpc.textContent = m.cpc || '—';
        var rmCpm = document.getElementById('rmCpm');
        if (rmCpm) rmCpm.textContent = m.cpm || '—';
        var rmCtr = document.getElementById('rmCtr');
        if (rmCtr) rmCtr.textContent = m.ctr || '—';

        var statEl = document.getElementById('rmRoasStat');
        if (statEl) { statEl.textContent = m.status_label || ''; statEl.style.color = m.roas_raw >= 3 ? 'var(--green)' : m.roas_raw >= 1.5 ? 'var(--yellow)' : 'var(--red)'; }
        var lbl = document.getElementById('rmStatusLabel');
        if (lbl) { lbl.textContent = m.status_label; lbl.style.background = m.roas_raw >= 3 ? 'rgba(16,185,129,0.15)' : 'rgba(234,179,8,0.15)'; lbl.style.color = m.roas_raw >= 3 ? 'var(--green)' : 'var(--yellow)'; }

        var meta = document.getElementById('adsDeepMeta');
        if (meta) meta.innerHTML = '<span class="ads-chip">المصدر: Meta Ads Manager المربوط</span><span class="ads-chip">أرقام آخر 30 يوم</span><span class="ads-chip">ROAS: ' + (m.roas || '—') + '</span><span class="ads-chip">Spend: ' + (m.spend || '—') + '</span>';

        var summary = document.getElementById('adsCampaignSummary');
        if (summary) summary.textContent = 'تم تفعيل المسار الثاني: التحليل الآن مدعوم بأرقام فعلية من حساب Meta Ads Manager، مع استمرار استخدام مكتبة الإعلانات العامة لتحليل الرسائل والمواد الإعلانية.';
      } else {
        var err = (resp.real_metrics && resp.real_metrics.error) ? resp.real_metrics.error : 'فشل الاتصال. تحقق من صحة التوكن وصلاحياته.';
        alert('❌ ' + err);
        if (btn) {
          btn.textContent = '🔗 ربط حساب Meta Ads';
          btn.disabled = false;
        }
      }
    })
    .catch(function(e) { alert('خطأ: ' + e.message); if(btn) btn.disabled=false; });
}

// ═══ Animations & Event Listeners ═══
document.addEventListener("DOMContentLoaded", function() {
  // Replace onclick="handleMetaLink()" with addEventListener
  var btnLinkMeta = document.getElementById('btnLinkMeta');
  if (btnLinkMeta) {
    btnLinkMeta.removeAttribute('onclick');
    btnLinkMeta.addEventListener('click', function(e) {
      e.preventDefault();
      handleMetaLink();
    });
  }

  // Number Counter
  var animateValue = function(obj, start, end, duration) {
    var startTimestamp = null;
    var step = function(timestamp) {
      if (!startTimestamp) startTimestamp = timestamp;
      var progress = Math.min((timestamp - startTimestamp) / duration, 1);
      var easeOut = 1 - Math.pow(1 - progress, 3);
      obj.textContent = Math.floor(easeOut * (end - start) + start);
      if (progress < 1) window.requestAnimationFrame(step);
      else obj.textContent = end;
    };
    window.requestAnimationFrame(step);
  };

  setTimeout(function() {
    var numberElements = document.querySelectorAll('.score-num[data-val]');
    numberElements.forEach(function(el) {
      var target = parseInt(el.getAttribute('data-val'));
      if (!isNaN(target)) animateValue(el, 0, target, 2000);
    });
  }, 500);

  // Ring Charts
  setTimeout(function() {
    var rings = document.querySelectorAll('.score-circle[data-percent]');
    rings.forEach(function(ring) {
      var percent = parseInt(ring.getAttribute('data-percent'));
      var color = ring.getAttribute('data-color');
      var currentPercent = 0;
      var animateRing = function() {
        currentPercent += (percent - currentPercent) * 0.08;
        ring.style.background = 'conic-gradient(' + color + ' ' + currentPercent + '%, rgba(255,255,255,0.1) 0)';
        if (percent - currentPercent > 0.5) requestAnimationFrame(animateRing);
        else ring.style.background = 'conic-gradient(' + color + ' ' + percent + '%, rgba(255,255,255,0.1) 0)';
      };
      requestAnimationFrame(animateRing);
    });
  }, 800);

  // Parallax Orbs
  var orbs = document.querySelectorAll('.bg-orb');
  window.addEventListener('scroll', function() {
    var scrolled = window.scrollY;
    if (orbs[0]) orbs[0].style.transform = 'translateY(' + (scrolled * 0.2) + 'px)';
    if (orbs[1]) orbs[1].style.transform = 'translateY(' + (scrolled * 0.15) + 'px)';
    if (orbs[2]) orbs[2].style.transform = 'translateY(' + (scrolled * -0.1) + 'px) translateX(' + (scrolled * 0.05) + 'px)';
  });
});
