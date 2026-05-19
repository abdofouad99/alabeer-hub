/* ============================================================
 * js/my-reports.js
 * منطق صفحة "تقاريري" (my-reports.html)
 * ─────────────────────────────────────────────────────────────
 *  - يفحص الجلسة عند التحميل، يُحوِّل غير المسجَّلين إلى login.html
 *  - يجلب بيانات العميل + إحصائيات من /api/customer/me.php
 *  - يجلب قائمة التقارير من /api/customer/reports.php
 *  - يبني بطاقات التقارير بأمان (textContent — بلا XSS)
 *  - يدعم زر تسجيل الخروج
 * ============================================================ */

(function () {
  'use strict';

  // ── Helpers ────────────────────────────────────────────────
  function $(id) { return document.getElementById(id); }

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (k) {
        if (k === 'class')      node.className = attrs[k];
        else if (k === 'text')  node.textContent = attrs[k];
        else if (k === 'html')  { /* deliberately not used — XSS-safe */ }
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

  function formatDate(iso) {
    if (!iso) return '—';
    try {
      var d = new Date(iso.replace(' ', 'T'));
      if (isNaN(d.getTime())) return '—';
      var months = ['يناير','فبراير','مارس','أبريل','مايو','يونيو',
                    'يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
      return d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
    } catch (e) { return '—'; }
  }

  function scoreClass(score) {
    if (score == null) return 'score-gray';
    if (score >= 75) return 'score-green';
    if (score >= 50) return 'score-yellow';
    return 'score-red';
  }

  function statusInfo(status) {
    switch (status) {
      case 'completed':
      case 'analyzed':
        return { label: 'مكتمل',       cls: 'badge badge-completed' };
      case 'running':
        return { label: 'قيد التحليل',  cls: 'badge badge-running' };
      case 'failed':
        return { label: 'فشل',         cls: 'badge badge-failed' };
      case 'submitted':
      default:
        return { label: 'بانتظار البدء', cls: 'badge badge-pending' };
    }
  }

  function tierBadge(tier) {
    if (!tier) return null;
    var label = tier === 'red' ? 'منخفض' : tier === 'yellow' ? 'متوسط' : tier === 'green' ? 'ممتاز' : tier;
    var cls = 'badge ' + (tier === 'red' ? 'badge-failed' : tier === 'yellow' ? 'badge-pending' : 'badge-completed');
    return el('span', { class: cls, text: label });
  }

  function shortUrl(url) {
    if (!url) return '';
    try {
      var u = url.replace(/^https?:\/\//, '').replace(/^www\./, '');
      return u.length > 40 ? u.slice(0, 37) + '…' : u;
    } catch (e) { return url; }
  }

  // ── 1) فحص الجلسة + تحميل بيانات العميل ────────────────────
  function loadCustomerAndStats() {
    return fetch('api/customer/me.php', {
      method: 'GET',
      credentials: 'include',
      headers: { 'Accept': 'application/json' },
      cache: 'no-store'
    })
      .then(function (r) {
        if (r.status === 401) {
          window.location.replace('login.html');
          return Promise.reject({ unauth: true });
        }
        return r.text();
      })
      .then(function (txt) {
        var data = safeJson(txt);
        if (!data || !data.ok || !data.data) {
          throw new Error('Invalid /me response');
        }
        var c = data.data.customer || {};
        var s = data.data.stats || {};

        // اسم العميل في الـ top-bar والترحيب
        var displayName = c.full_name || c.email || 'عميل العبير';
        var userNameEl = $('userName');
        if (userNameEl) userNameEl.textContent = displayName;

        var welcomeEl = $('welcomeMsg');
        if (welcomeEl) welcomeEl.textContent = 'أهلاً، ' + (c.full_name || 'بك') + ' 👋';

        // Stats
        var total = Number(s.total_reports || 0);
        var done  = Number(s.completed_reports || 0);
        var pend  = Number(s.pending_reports || 0);
        var avg   = (s.avg_score == null) ? null : Number(s.avg_score);

        if ($('statTotal'))     $('statTotal').textContent     = total;
        if ($('statCompleted')) $('statCompleted').textContent = done;
        if ($('statPending'))   $('statPending').textContent   = pend;
        if ($('statAvg'))       $('statAvg').textContent       = (avg == null) ? '—' : avg.toFixed(1);

        return { customer: c, stats: s };
      });
  }

  // ── 2) جلب قائمة التقارير ───────────────────────────────────
  function loadReports() {
    return fetch('api/customer/reports.php', {
      method: 'GET',
      credentials: 'include',
      headers: { 'Accept': 'application/json' },
      cache: 'no-store'
    })
      .then(function (r) {
        if (r.status === 401) {
          window.location.replace('login.html');
          return Promise.reject({ unauth: true });
        }
        return r.text();
      })
      .then(function (txt) {
        var data = safeJson(txt);
        if (!data || !data.ok || !data.data) {
          throw new Error('Invalid /reports response');
        }
        return data.data.reports || [];
      });
  }

  // ── 3) رسم بطاقة تقرير واحدة (آمن من XSS) ───────────────────
  function renderReportCard(report) {
    var status = statusInfo(report.status);

    // الرأس: العنوان + الـ badge
    var head = el('div', { class: 'report-head' }, [
      el('div', null, [
        el('div', { class: 'report-title', text: report.company_name || 'تحليل ' + report.id }),
        report.main_url ? el('div', { class: 'report-url', text: shortUrl(report.main_url) }) : null
      ]),
      el('span', { class: status.cls, text: status.label })
    ]);

    // ميتا داتا: التاريخ + الدرجة
    var metaItems = [];
    metaItems.push(
      el('div', { class: 'meta-item' }, [
        el('div', { class: 'label', text: 'التاريخ' }),
        el('div', { class: 'value', text: formatDate(report.created_at) })
      ])
    );

    if (report.score != null) {
      var scoreCircle = el('div', { class: 'score-circle ' + scoreClass(report.score), text: String(report.score) });
      metaItems.push(
        el('div', { class: 'meta-item', style: 'display:flex;flex-direction:column;align-items:flex-start;' }, [
          el('div', { class: 'label', text: 'النتيجة' }),
          scoreCircle
        ])
      );
    } else {
      metaItems.push(
        el('div', { class: 'meta-item' }, [
          el('div', { class: 'label', text: 'النتيجة' }),
          el('div', { class: 'value', text: '—' })
        ])
      );
    }

    var tier = tierBadge(report.tier);
    if (tier) {
      metaItems.push(
        el('div', { class: 'meta-item' }, [
          el('div', { class: 'label', text: 'التصنيف' }),
          el('div', { class: 'value' }, [tier])
        ])
      );
    }

    var meta = el('div', { class: 'report-meta' }, metaItems);

    // الإجراءات
    var canView = (report.status === 'completed' || report.status === 'analyzed');
    var viewLink = el(
      'a',
      {
        class: 'btn-view' + (canView ? '' : ' disabled'),
        href: canView ? ('report.html?id=' + encodeURIComponent(report.id)) : '#',
        'aria-disabled': canView ? 'false' : 'true'
      },
      [
        el('span', { text: '📄' }),
        el('span', { text: canView ? 'عرض التقرير' : (report.status === 'failed' ? 'فشل التحليل' : 'جاري المعالجة') })
      ]
    );

    var actions = el('div', { class: 'report-actions' }, [viewLink]);

    return el('article', { class: 'report-card', 'data-id': report.id }, [head, meta, actions]);
  }

  // ── 4) عرض الحالات (empty / error) ─────────────────────────
  function renderEmptyState(container) {
    container.innerHTML = '';
    var card = el('div', { class: 'state-card' }, [
      el('div', { class: 'icon', text: '✨' }),
      el('h2', { text: 'ابدأ تحليلك الأول' }),
      el('p', { text: 'لا توجد تقارير في حسابك حتى الآن. اضغط الزر أدناه لإطلاق أول تحليل لموقعك مجاناً.' }),
      el('a', { class: 'btn-new-analysis', href: 'scan.html' }, [
        el('span', { text: '+' }),
        el('span', { text: 'تحليل جديد' })
      ])
    ]);
    container.appendChild(card);
  }

  function renderErrorState(container, msg) {
    container.innerHTML = '';
    var card = el('div', { class: 'state-card' }, [
      el('div', { class: 'icon', text: '⚠️' }),
      el('h2', { text: 'تعذّر تحميل التقارير' }),
      el('p', { text: msg || 'حدث خطأ أثناء تحميل البيانات. يرجى تحديث الصفحة بعد قليل.' }),
      el('a', { class: 'btn-new-analysis', href: 'my-reports.html' }, [
        el('span', { text: '↻' }),
        el('span', { text: 'إعادة المحاولة' })
      ])
    ]);
    container.appendChild(card);
  }

  function renderReportsList(container, reports) {
    container.innerHTML = '';
    if (!reports || reports.length === 0) {
      renderEmptyState(container);
      return;
    }
    var grid = el('div', { class: 'reports-grid' });
    reports.forEach(function (r) {
      grid.appendChild(renderReportCard(r));
    });
    container.appendChild(grid);
  }

  // ── 5) تسجيل الخروج ────────────────────────────────────────
  function handleLogout() {
    var btn = $('logoutBtn');
    if (btn) { btn.disabled = true; btn.textContent = 'جاري الخروج...'; }

    fetch('api/customer/auth.php?action=logout', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Accept': 'application/json' }
    })
      .catch(function () { /* المتابعة حتى عند فشل الـ network */ })
      .then(function () {
        try { sessionStorage.removeItem('customer_full_name'); } catch (e) {}
        window.location.replace('login.html');
      });
  }

  // ── 6) Boot ────────────────────────────────────────────────
  function boot() {
    var container = $('reportsContainer');

    var btnLogout = $('logoutBtn');
    if (btnLogout) btnLogout.addEventListener('click', handleLogout);

    Promise.all([loadCustomerAndStats(), loadReports()])
      .then(function (results) {
        var reports = results[1];
        renderReportsList(container, reports);
      })
      .catch(function (err) {
        if (err && err.unauth) return; // تم التحويل لـ login.html
        if (window.console && console.error) console.error('[my-reports]', err);
        renderErrorState(container, 'حدث خطأ في الاتصال بالخادم.');
      });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
