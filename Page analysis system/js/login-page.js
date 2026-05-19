/* ============================================================
 * js/login-page.js
 * منطق صفحة تسجيل دخول العملاء (login.html)
 * ─────────────────────────────────────────────────────────────
 *  - إذا كان العميل مسجَّل دخول مسبقاً → ينقله مباشرة إلى my-reports.html
 *  - يرسل POST إلى /api/customer/auth.php?action=login
 *  - يدعم credentials: 'include' لتمرير الـ session cookies
 *  - رسائل خطأ واضحة بالعربية
 * ============================================================ */

(function () {
  'use strict';

  // ── Helpers ────────────────────────────────────────────────
  function $(id) { return document.getElementById(id); }

  function showError(msg) {
    var box = $('formError');
    if (!box) return;
    box.textContent = msg || 'حدث خطأ غير متوقع، يرجى المحاولة لاحقاً';
    box.classList.add('show');
    box.style.display = 'block';
  }

  function hideError() {
    var box = $('formError');
    if (!box) return;
    box.classList.remove('show');
    box.style.display = 'none';
  }

  function setSubmitting(submitting) {
    var btn = $('submitBtn');
    if (!btn) return;
    btn.disabled = !!submitting;
    btn.textContent = submitting ? 'جاري الدخول...' : 'تسجيل الدخول';
  }

  function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  }

  function safeJson(text) {
    try { return JSON.parse(text); } catch (e) { return null; }
  }

  // ── 1) فحص الجلسة عند التحميل ──────────────────────────────
  function checkExistingSession() {
    fetch('api/customer/auth.php?action=check', {
      method: 'GET',
      credentials: 'include',
      headers: { 'Accept': 'application/json' },
      cache: 'no-store'
    })
      .then(function (r) { return r.text(); })
      .then(function (txt) {
        var data = safeJson(txt);
        if (data && data.ok && data.data && data.data.authed) {
          // مسجَّل بالفعل → انقله لصفحة تقاريره
          window.location.replace('my-reports.html');
        }
      })
      .catch(function () { /* غير حرج — تابع عرض النموذج */ });
  }

  // ── 2) معالجة إرسال النموذج ────────────────────────────────
  function handleSubmit(e) {
    e.preventDefault();
    hideError();

    var emailEl = $('email');
    var passEl  = $('password');
    if (!emailEl || !passEl) return;

    var email = (emailEl.value || '').trim().toLowerCase();
    var password = passEl.value || '';

    if (!isValidEmail(email)) {
      showError('يرجى إدخال بريد إلكتروني صالح');
      emailEl.focus();
      return;
    }
    if (password.length < 8) {
      showError('كلمة المرور يجب أن تكون 8 حروف على الأقل');
      passEl.focus();
      return;
    }

    setSubmitting(true);

    fetch('api/customer/auth.php?action=login', {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify({ email: email, password: password })
    })
      .then(function (response) {
        return response.text().then(function (text) {
          return { status: response.status, body: safeJson(text), raw: text };
        });
      })
      .then(function (res) {
        setSubmitting(false);

        if (res.status === 200 && res.body && res.body.ok && res.body.data && res.body.data.authed) {
          // نجاح
          var btn = $('submitBtn');
          if (btn) btn.textContent = '✓ تم الدخول';

          // حفظ الاسم محلياً (مساعد بصري — ليس للأمان)
          try {
            if (res.body.data.full_name) {
              sessionStorage.setItem('customer_full_name', res.body.data.full_name);
            }
          } catch (e) { /* ignore */ }

          setTimeout(function () {
            window.location.href = 'my-reports.html';
          }, 400);
          return;
        }

        // فشل
        var errMsg = (res.body && (res.body.error || res.body.message))
          || (res.status === 429 ? 'محاولات كثيرة، حاول لاحقاً'
              : res.status === 401 ? 'البريد أو كلمة المرور غير صحيحة'
              : 'تعذّر تسجيل الدخول، حاول مرة أخرى');
        showError(errMsg);
      })
      .catch(function (err) {
        setSubmitting(false);
        showError('تعذّر الاتصال بالخادم. تأكد من الإنترنت ثم حاول مجدداً');
        if (window.console && console.error) console.error('[login]', err);
      });
  }

  // ── 3) تشغيل ───────────────────────────────────────────────
  function boot() {
    checkExistingSession();

    var form = $('loginForm');
    if (form) form.addEventListener('submit', handleSubmit);

    // إخفاء رسالة الخطأ تلقائياً عند التعديل
    var emailEl = $('email');
    var passEl  = $('password');
    if (emailEl) emailEl.addEventListener('input', hideError);
    if (passEl)  passEl.addEventListener('input', hideError);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
