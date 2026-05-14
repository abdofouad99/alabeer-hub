/* ═══ checkout-page.js — CSP-safe JS for checkout.html ═══
 * Extracted from inline <script> for CSP compliance.
 * No eval, no new Function, no setTimeout(string), no template literals in callbacks.
 * Uses string concatenation instead of template literals.
 * Uses createElement + textContent instead of innerHTML where safe.
 * v1.0
 */

document.addEventListener("DOMContentLoaded", function() {
  var urlParams = new URLSearchParams(window.location.search);
  var id = urlParams.get('id');
  if (!id) {
    alert("معرّف التقرير مفقود (id). يرجى العودة للتحليل أولاً.");
    window.history.back();
    return;
  }
  var plan = urlParams.get('plan') || 'shamel';

  var pkgName = document.getElementById('pkg-name');
  var pkgDesc = document.getElementById('pkg-desc');
  var pkgPrice = document.getElementById('pkg-price');
  var pkgFeatures = document.getElementById('pkg-features');
  var btnSubmit = document.getElementById('btn-submit');

  var shieldSvg = ' <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>';

  if (plan === 'free') {
    if (pkgName) pkgName.textContent = 'الباقة المجانية';
    if (pkgDesc) pkgDesc.textContent = 'تجربة أولى لاكتشاف وضع حسابك الحالي.';
    if (pkgPrice) pkgPrice.textContent = '$0';
    if (btnSubmit) btnSubmit.innerHTML = 'تأكيد وبدء التحليل ($0)' + shieldSvg;
    var pmEl = document.querySelector('.payment-methods');
    if (pmEl) pmEl.style.display = 'none';
    var cdEl = document.querySelector('.card-details');
    if (cdEl) cdEl.style.display = 'none';
    var h2s = document.querySelectorAll('h2');
    if (h2s[1]) h2s[1].style.display = 'none';
  }
  else if (plan === 'pro') {
    if (pkgName) pkgName.textContent = 'الباقة الاحترافية ⭐';
    if (pkgDesc) pkgDesc.textContent = 'تحليل شامل مع التوصيات الفورية وخطة أولية.';
    if (pkgPrice) pkgPrice.textContent = '$10';
    if (btnSubmit) btnSubmit.innerHTML = 'إتمام الدفع واستلام التقرير ($10)' + shieldSvg;
    if (pkgFeatures) pkgFeatures.innerHTML =
      '<div class="feature-item"><div class="f-icon">✓</div><div class="f-text">تقرير كامل PDF (نقاط القوة والضعف)</div></div>' +
      '<div class="feature-item"><div class="f-icon">✓</div><div class="f-text">رحلة العميل وتوصيات فورية</div></div>' +
      '<div class="feature-item"><div class="f-icon">✓</div><div class="f-text">خطة أولية للنمو</div></div>';
  }
  else if (plan === 'vip') {
    if (pkgName) pkgName.innerHTML = 'VIP للنخبة ὅ1';
    if (pkgDesc) pkgDesc.textContent = 'تنفيذ كامل وإدارة حساب وحملات إعلانية.';
    if (pkgPrice) pkgPrice.textContent = 'حسب الحالة';
    if (btnSubmit) btnSubmit.innerHTML = 'تأكيد طلب التميز (VIP) للتواصل والتنفيذ' + shieldSvg;
    if (pkgFeatures) pkgFeatures.innerHTML =
      '<div class="feature-item"><div class="f-icon">✓</div><div class="f-text">تنفيذ كامل بواسطة المؤسسة</div></div>' +
      '<div class="feature-item"><div class="f-icon">✓</div><div class="f-text">إدارة الحساب والإعلانات والمحتوى</div></div>';
  }

  // Payment method toggle logic
  var pmCards = document.querySelectorAll('.pm-card');
  pmCards.forEach(function(card) {
    card.addEventListener('click', function() {
      pmCards.forEach(function(c) { c.classList.remove('active'); });
      this.classList.add('active');

      var method = this.getAttribute('data-method');
      var cardDetails = document.querySelector('.card-details');
      var transferDetails = document.querySelector('.transfer-details');

      if (method === 'card') {
        if (cardDetails) cardDetails.style.display = 'block';
        if (transferDetails) transferDetails.style.display = 'none';
      } else if (method === 'transfer') {
        if (cardDetails) cardDetails.style.display = 'none';
        if (transferDetails) transferDetails.style.display = 'block';
      } else if (method === 'paypal') {
        if (cardDetails) cardDetails.style.display = 'none';
        if (transferDetails) transferDetails.style.display = 'none';
      }
    });
  });

  // Handle Submit Simulation
  if (btnSubmit) {
    btnSubmit.addEventListener('click', function() {
      btnSubmit.innerHTML = 'جاري المعالجة... <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="spin"><circle cx="12" cy="12" r="10"></circle><path d="M12 2v4"></path></svg>';

      // Add spin animation
      if (!document.getElementById('spinStyle')) {
        var style = document.createElement('style');
        style.id = 'spinStyle';
        style.textContent = '@keyframes spinner { to { transform: rotate(360deg); } } .spin { animation: spinner 1s linear infinite; }';
        document.head.appendChild(style);
      }

      setTimeout(function() {
        btnSubmit.style.background = 'var(--green)';
        btnSubmit.textContent = 'تم الدفع بنجاح! ✔';

        setTimeout(function() {
          var checkId = urlParams.get('id');
          var checkToken = urlParams.get('token') || sessionStorage.getItem('last_assessment_token') || '';
          if (checkId) {
            window.location.href = 'report.html?id=' + checkId + '&token=' + encodeURIComponent(checkToken);
          } else {
            window.location.href = 'report.html';
          }
        }, 1000);
      }, 2000);
    });
  }
});
