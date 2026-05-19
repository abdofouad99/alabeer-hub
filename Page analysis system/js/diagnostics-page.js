/* ═══ diagnostics-page.js ═══
 * عرض diagnostics_log كـ timeline تفاعلي.
 * No external dependencies. CSP-safe.
 */
(function() {
  'use strict';

  function getScanId() {
    var p = new URLSearchParams(window.location.search);
    return parseInt(p.get('id'), 10) || null;
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function formatJson(obj) {
    try {
      return JSON.stringify(obj, null, 2);
    } catch (e) {
      return String(obj);
    }
  }

  function eventIcon(stage) {
    if (/\.ERROR$/i.test(stage)) return '❌';
    if (/\.WARN$/i.test(stage))  return '⚠️';
    if (/openai/i.test(stage))   return '🤖';
    if (/gemini/i.test(stage))   return '✨';
    if (/nvidia/i.test(stage))   return '🟩';
    if (/apify|scrape/i.test(stage)) return '🕷️';
    if (/db\.|save/i.test(stage)) return '💾';
    if (/frontend|payload/i.test(stage)) return '📤';
    if (/parsed/i.test(stage)) return '🧩';
    return '📦';
  }

  function eventClass(stage) {
    if (/\.ERROR$/i.test(stage)) return 'ERR';
    if (/\.WARN$/i.test(stage))  return 'WRN';
    return '';
  }

  function applyFilters(state) {
    var query = (state.search || '').toLowerCase().trim();
    var filter = state.filter || 'all';
    var nodes = document.querySelectorAll('.event');
    nodes.forEach(function(node) {
      var stage = (node.getAttribute('data-stage') || '').toLowerCase();
      var matchesSearch = !query || stage.indexOf(query) !== -1;
      var matchesFilter = true;
      if (filter === 'errors')      matchesFilter = /\.error$/i.test(stage);
      else if (filter === 'warnings') matchesFilter = /\.warn$/i.test(stage);
      else if (filter === 'apify')    matchesFilter = /apify|scrape/i.test(stage);
      else if (filter === 'openai')   matchesFilter = /openai/i.test(stage);
      else if (filter === 'gemini')   matchesFilter = /gemini/i.test(stage);
      else if (filter === 'db')       matchesFilter = /db\.|save/i.test(stage);
      node.classList.toggle('hidden', !(matchesSearch && matchesFilter));
    });
  }

  function renderMeta(meta) {
    var host = document.getElementById('metaInfo');
    if (!meta) { host.innerHTML = '<div class="loading">لا يوجد meta</div>'; return; }
    host.innerHTML = [
      ['ID', meta.id],
      ['الاسم', meta.full_name || meta.company_name || '—'],
      ['Facebook', meta.facebook_url || '—'],
      ['Instagram', meta.instagram_url || '—'],
      ['Website', meta.website_url || '—'],
      ['تاريخ الفحص', meta.created_at || '—'],
      ['الحالة', meta.status || '—'],
    ].map(function(p) {
      return '<div class="meta-item"><label>' + escapeHtml(p[0]) + '</label><value>' + escapeHtml(p[1]) + '</value></div>';
    }).join('');
  }

  function renderStats(log) {
    var host = document.getElementById('statsRow');
    var events = (log && log.events) || [];
    var errors = (log && log.errors) || [];
    var warnings = (log && log.warnings) || [];
    host.innerHTML = [
      '<div class="stat ok"><div class="num">' + events.length + '</div><div class="lbl">حدث مسجَّل</div></div>',
      '<div class="stat err"><div class="num">' + errors.length + '</div><div class="lbl">خطأ</div></div>',
      '<div class="stat warn"><div class="num">' + warnings.length + '</div><div class="lbl">تحذير</div></div>',
      '<div class="stat"><div class="num">' + (log ? log.duration_total : '—') + 's</div><div class="lbl">المدة الإجمالية</div></div>',
      '<div class="stat"><div class="num" style="font-size:14px;font-weight:700;">' + (log ? escapeHtml(log.started_at) : '—') + '</div><div class="lbl">بدأ</div></div>',
    ].join('');
  }

  function renderTimeline(log) {
    var host = document.getElementById('timeline');
    var events = (log && log.events) || [];
    if (!events.length) {
      host.innerHTML = '<div class="empty">لا يوجد أحداث مسجَّلة بعد لهذا الفحص.</div>';
      return;
    }
    host.innerHTML = events.map(function(ev, idx) {
      var icon = eventIcon(ev.stage);
      var cls = eventClass(ev.stage);
      var dataStr = ev.data == null ? 'null' : (typeof ev.data === 'string' ? ev.data : formatJson(ev.data));
      return [
        '<div class="event" data-stage="' + escapeHtml(ev.stage) + '" data-idx="' + idx + '">',
          '<div class="event-row">',
            '<div class="event-time">+' + escapeHtml(ev.t) + 's</div>',
            '<div class="event-icon">' + icon + '</div>',
            '<div class="event-stage ' + cls + '">' + escapeHtml(ev.stage) + '</div>',
            '<div class="event-toggle">▾</div>',
          '</div>',
          '<div class="event-data">' + escapeHtml(dataStr) + '</div>',
        '</div>'
      ].join('');
    }).join('');

    // toggle expand on row click
    host.querySelectorAll('.event-row').forEach(function(row) {
      row.addEventListener('click', function() {
        row.parentElement.classList.toggle('expanded');
      });
    });
  }

  function setActiveChip(value) {
    document.querySelectorAll('.filter-chip').forEach(function(c) {
      c.classList.toggle('active', c.getAttribute('data-filter') === value);
    });
  }

  function init() {
    var scanId = getScanId();
    var timeline = document.getElementById('timeline');
    if (!scanId) {
      timeline.innerHTML = '<div class="error-box">يجب توفير ?id={scan_id} في الرابط.</div>';
      return;
    }

    var state = { search: '', filter: 'all', log: null };

    function load() {
      timeline.innerHTML = '<div class="loading">جاري تحميل diagnostics للفحص #' + scanId + '...</div>';
      fetch('api/diagnostics-view.php?id=' + scanId)
        .then(function(r) { return r.json(); })
        .then(function(resp) {
          if (!resp.success) {
            timeline.innerHTML = '<div class="error-box">' + escapeHtml(resp.error || 'خطأ غير معروف') + '</div>';
            return;
          }
          renderMeta(resp.meta);
          renderStats(resp.log);
          if (!resp.log) {
            timeline.innerHTML = '<div class="empty">' + escapeHtml(resp.message || 'لا يوجد log') + '</div>';
            return;
          }
          state.log = resp.log;
          renderTimeline(resp.log);
          applyFilters(state);
        })
        .catch(function(e) {
          timeline.innerHTML = '<div class="error-box">فشل التحميل: ' + escapeHtml(e.message) + '</div>';
        });
    }

    load();

    document.getElementById('searchBox').addEventListener('input', function(e) {
      state.search = e.target.value;
      applyFilters(state);
    });

    document.querySelectorAll('.filter-chip').forEach(function(chip) {
      chip.addEventListener('click', function() {
        var v = chip.getAttribute('data-filter');
        state.filter = v;
        setActiveChip(v);
        applyFilters(state);
      });
    });

    document.getElementById('expandAllBtn').addEventListener('click', function() {
      document.querySelectorAll('.event:not(.hidden)').forEach(function(n) { n.classList.add('expanded'); });
    });
    document.getElementById('collapseAllBtn').addEventListener('click', function() {
      document.querySelectorAll('.event').forEach(function(n) { n.classList.remove('expanded'); });
    });
    document.getElementById('reloadBtn').addEventListener('click', load);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
