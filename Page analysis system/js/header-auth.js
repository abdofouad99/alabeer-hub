/* ============================================================
 * js/header-auth.js
 * شريط تنقل عائم (Floating Auth Bar) يُعرض على كل الصفحات
 * ─────────────────────────────────────────────────────────────
 *  - يفحص /api/customer/auth.php?action=check
 *  - لو مسجَّل: يعرض اسم العميل + رابط "تقاريري" + زر خروج
 *  - لو غير مسجَّل: يعرض رابط "تسجيل دخول"
 *
 *  الاستخدام في أي HTML:
 *      <script src="js/header-auth.js" defer></script>
 *
 *  لا يتعارض مع أي تصميم موجود — يحقن نفسه أعلى الصفحة بـ
 *  position: fixed و z-index عالٍ، بحيث لا يكسر تخطيط الصفحات.
 * ============================================================ */

(function () {
  'use strict';

  // لا تشغّل في صفحات الأدمن (لها نظامها الخاص)
  if (location.pathname.indexOf('/admin/') !== -1) return;

  // ── Helpers ────────────────────────────────────────────────
  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (k) {
        if (k === 'class')      node.className = attrs[k];
        else if (k === 'text')  node.textContent = attrs[k];
        else if (k === 'style') node.setAttribute('style', attrs[k]);
        else                    node.setAttribute(k, attrs[k]);
      });
    }
    if (children) {
      (Array.isArray(children) ? children : [children]).forEach(function (c) {
        if (c == null) return;
        if (typeof c === 'string') node.appendChild(document.createTextNode(c));
        else node.appendChild(c);
      });
    }
    return node;
  }

  function safeJson(text) {
    try { return JSON.parse(text); } catch (e) { return null; }
  }

  // ── حقن CSS مرة واحدة فقط ──────────────────────────────────
  function injectStyles() {
    if (document.getElementById('alabeer-header-auth-styles')) return;
    var css = ''
      + '.alabeer-auth-bar{'
      + '  position:fixed;top:14px;left:50%;transform:translateX(-50%);'
      + '  z-index:9990;display:flex;align-items:center;gap:8px;'
      + '  padding:8px 14px;border-radius:999px;'
      + '  background:rgba(255,255,255,0.92);'
      + '  backdrop-filter:blur(20px) saturate(180%);'
      + '  -webkit-backdrop-filter:blur(20px) saturate(180%);'
      + '  border:1px solid rgba(0,0,0,0.06);'
      + '  box-shadow:0 8px 24px rgba(0,0,0,0.08);'
      + '  font-family:"Cairo",sans-serif;'
      + '  font-size:13px;font-weight:700;'
      + '  opacity:0;transform:translate(-50%,-8px);'
      + '  transition:opacity .35s ease,transform .35s ease;'
      + '  pointer-events:none;'
      + '}'
      + '.alabeer-auth-bar.show{opacity:1;transform:translate(-50%,0);pointer-events:auto;}'
      + '.alabeer-auth-bar a,.alabeer-auth-bar button{'
      + '  font-family:inherit;font-size:13px;font-weight:800;'
      + '  text-decoration:none;cursor:pointer;'
      + '  padding:6px 12px;border-radius:999px;border:none;'
      + '  transition:all .2s ease;'
      + '  display:inline-flex;align-items:center;gap:5px;line-height:1;'
      + '}'
      + '.alabeer-auth-bar .auth-name{'
      + '  color:#1D1D1F;background:transparent;padding:6px 6px 6px 12px;cursor:default;'
      + '  max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;'
      + '}'
      + '.alabeer-auth-bar .auth-link{color:#f58e1a;background:rgba(245,142,26,0.10);}'
      + '.alabeer-auth-bar .auth-link:hover{background:rgba(245,142,26,0.20);}'
      + '.alabeer-auth-bar .auth-primary{'
      + '  color:#fff;background:linear-gradient(135deg,#f58e1a,#ef4444);'
      + '  box-shadow:0 4px 12px -4px rgba(245,142,26,0.5);'
      + '}'
      + '.alabeer-auth-bar .auth-primary:hover{transform:translateY(-1px);}'
      + '.alabeer-auth-bar .auth-logout{'
      + '  color:#ef4444;background:rgba(239,68,68,0.10);'
      + '}'
      + '.alabeer-auth-bar .auth-logout:hover{background:#ef4444;color:#fff;}'
      + '.alabeer-auth-bar .auth-divider{'
      + '  width:1px;height:18px;background:rgba(0,0,0,0.10);margin:0 2px;'
      + '}'
      + '@media (max-width:520px){'
      + '  .alabeer-auth-bar{top:8px;padding:6px 10px;font-size:12px;}'
      + '  .alabeer-auth-bar .auth-name{max-width:90px;}'
      + '  .alabeer-auth-bar a,.alabeer-auth-bar button{padding:5px 10px;font-size:12px;}'
      + '}';
    var style = document.createElement('style');
    style.id = 'alabeer-header-auth-styles';
    style.textContent = css;
    document.head.appendChild(style);
  }

  // ── حصول على/إنشاء شريط الـ DOM ────────────────────────────
  function getOrCreateBar() {
    var bar = document.getElementById('alabeerAuthBar');
    if (bar) return bar;
    bar = document.createElement('div');
    bar.id = 'alabeerAuthBar';
    bar.className = 'alabeer-auth-bar';
    bar.setAttribute('role', 'navigation');
    bar.setAttribute('aria-label', 'حساب العميل');
    document.body.appendChild(bar);
    return bar;
  }

  // ── رسم الـ markup حسب حالة المصادقة ───────────────────────
  function renderAuthed(bar, customer) {
    bar.innerHTML = '';
    var displayName = customer.full_name || customer.email || 'حسابي';

    bar.appendChild(el('span', {
      class: 'auth-name',
      title: customer.email || '',
      text: '👋 ' + displayName
    }));
    bar.appendChild(el('span', { class: 'auth-divider' }));

    // رابط تقاريري (إخفاؤه إذا كنا فيها فعلاً)
    if (!isOnPage('my-reports.html')) {
      bar.appendChild(el('a', {
        class: 'auth-link',
        href: 'my-reports.html',
        text: '📊 تقاريري'
      }));
    }

    // زر خروج
    var logoutBtn = el('button', {
      type: 'button',
      class: 'auth-logout',
      text: 'خروج'
    });
    logoutBtn.addEventListener('click', handleLogout);
    bar.appendChild(logoutBtn);

    requestAnimationFrame(function () { bar.classList.add('show'); });
  }

  function renderGuest(bar) {
    bar.innerHTML = '';

    // لا تعرض زر "تحليل" إذا كنا في صفحة scan.html نفسها
    if (!isOnPage('scan.html')) {
      bar.appendChild(el('a', {
        class: 'auth-primary',
        href: 'scan.html',
        text: '✨ ابدأ تحليل'
      }));
    }

    // لا تعرض زر "دخول" إذا كنا في login.html
    if (!isOnPage('login.html')) {
      bar.appendChild(el('a', {
        class: 'auth-link',
        href: 'login.html',
        text: '🔐 تسجيل دخول'
      }));
    }

    if (bar.children.length === 0) return; // لا شيء لعرضه
    requestAnimationFrame(function () { bar.classList.add('show'); });
  }

  function isOnPage(filename) {
    var path = (location.pathname || '').toLowerCase();
    return path.endsWith('/' + filename) || path.endsWith(filename);
  }

  // ── تسجيل الخروج ───────────────────────────────────────────
  function handleLogout() {
    var btn = this;
    if (btn) { btn.disabled = true; btn.textContent = '...'; }

    fetch('api/customer/auth.php?action=logout', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Accept': 'application/json' }
    })
      .catch(function () { /* المتابعة عند فشل الـ network */ })
      .then(function () {
        try { sessionStorage.removeItem('customer_full_name'); } catch (e) {}
        // حدِّث الشريط بدل reload كامل
        var bar = document.getElementById('alabeerAuthBar');
        if (bar) {
          bar.classList.remove('show');
          setTimeout(function () { renderGuest(bar); }, 250);
        }
        // لو في صفحة محمية → انقله لـ login
        if (isOnPage('my-reports.html')) {
          window.location.replace('login.html');
        }
      });
  }

  // ── جلب حالة الجلسة ────────────────────────────────────────
  function fetchAuthStatus() {
    return fetch('api/customer/auth.php?action=check', {
      method: 'GET',
      credentials: 'include',
      headers: { 'Accept': 'application/json' },
      cache: 'no-store'
    })
      .then(function (r) { return r.text(); })
      .then(function (txt) { return safeJson(txt); })
      .catch(function () { return null; });
  }

  // ── Boot ───────────────────────────────────────────────────
  function boot() {
    injectStyles();
    var bar = getOrCreateBar();

    fetchAuthStatus().then(function (data) {
      if (data && data.ok && data.data && data.data.authed) {
        renderAuthed(bar, data.data);
      } else {
        renderGuest(bar);
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
