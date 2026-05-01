// ============================================================
// admin.js — لوحة إدارة فحص الجاهزية الرقمية
// ============================================================
'use strict';

const API = '../api/admin';
const STAGE_LABELS = {
  offline:  '🔴 غير رقمي',
  beginner: '🟠 مبتدئ',
  growing:  '🟡 نامي',
  advanced: '🟢 متقدم',
  digital:  '🔵 رقمي بالكامل',
};
const STAGE_COLORS = {
  offline:  '#ef4444',
  beginner: '#f59e0b',
  growing:  '#eab308',
  advanced: '#10b981',
  digital:  '#06b6d4',
};

let allRows = [];

// ── Auth Check ──
(async function init() {
  const res  = await fetch(`${API}/auth.php?action=check`, { credentials:'include' });
  const data = await res.json();
  if (!data.authed) window.location.href = 'login.html';

  loadStats();
  loadAssessments();
})();

async function logout() {
  await fetch(`${API}/auth.php?action=logout`, { method:'POST', credentials:'include' });
  window.location.href = 'login.html';
}

// ── Stats ──
async function loadStats() {
  const res  = await fetch(`${API}/stats.php`, { credentials:'include' });
  const data = await res.json();

  document.getElementById('statTotal').textContent = data.total ?? 0;
  document.getElementById('statAvg').textContent   = data.avg ?? 0;

  // Stage distribution
  const distBox = document.getElementById('stageDistribution');
  distBox.innerHTML = '';
  const stages = data.stages || {};
  const total  = data.total || 1;
  Object.keys(STAGE_LABELS).forEach(key => {
    const cnt = stages[key] || 0;
    const pct = Math.round((cnt / total) * 100);
    distBox.innerHTML += `
      <div class="kpi">
        <span>${STAGE_LABELS[key]}</span>
        <span><b style="color:${STAGE_COLORS[key]}">${cnt}</b> <span class="muted">(${pct}%)</span></span>
      </div>`;
  });

  // Timeline chart
  const timeline = data.timeline || [];
  if (timeline.length && window.Chart) {
    new Chart(document.getElementById('timelineChart'), {
      type: 'bar',
      data: {
        labels: timeline.map(t => t.d),
        datasets: [{
          label: 'تقييمات',
          data: timeline.map(t => t.cnt),
          backgroundColor: 'rgba(139,92,246,0.5)',
          borderColor: '#8b5cf6',
          borderWidth: 1,
          borderRadius: 6,
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
          x: { grid: { display: false }, ticks: { color: '#6b7a99', font: { size: 10 } } },
          y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#6b7a99', stepSize: 1 } },
        }
      }
    });
  }
}

// ── Assessments List ──
async function loadAssessments() {
  const res  = await fetch(`${API}/list.php?limit=200`, { credentials:'include' });
  allRows    = await res.json();

  document.getElementById('countBadge').textContent = allRows.length;
  renderList(allRows);

  // Search
  document.getElementById('searchInput').addEventListener('input', e => {
    const q = e.target.value.toLowerCase();
    const filtered = allRows.filter(r =>
      (r.full_name || '').toLowerCase().includes(q) ||
      (r.company_name || '').toLowerCase().includes(q) ||
      String(r.score).includes(q)
    );
    renderList(filtered);
  });
}

function renderList(rows) {
  const box = document.getElementById('assessmentList');
  box.innerHTML = '';
  rows.forEach(row => {
    const st = STAGE_LABELS[row.stage] || row.stage || '—';
    const clr = STAGE_COLORS[row.stage] || '#6b7a99';
    box.innerHTML += `
      <div class="kpi" style="cursor:pointer" onclick="showLead(${row.lead_id}, ${row.id})">
        <div>
          <div style="font-weight:700;font-size:14px">${row.full_name || 'بدون اسم'}</div>
          <div class="muted" style="font-size:12px">${row.company_name || ''} • ${(row.created_at || '').slice(0,10)}</div>
        </div>
        <div style="text-align:left">
          <span class="badge" style="border-color:${clr};color:${clr}">${st}</span>
          <span class="badge badge-purple" style="margin-right:6px">${row.score ?? '—'} /100</span>
        </div>
      </div>`;
  });
}

// ── Lead Detail ──
async function showLead(leadId, assessmentId) {
  document.getElementById('leadPlaceholder').style.display = 'none';
  const detail = document.getElementById('leadDetail');
  detail.style.display = '';
  detail.innerHTML = '<div class="spinner" style="font-size:24px;text-align:center">⏳</div>';

  try {
    const res  = await fetch(`${API}/lead.php?id=${leadId}`, { credentials:'include' });
    const lead = await res.json();

    const fields = [
      ['الاسم', lead.full_name],
      ['الشركة', lead.company_name],
      ['الجوال', lead.phone],
      ['البريد', lead.email],
      ['المجال', lead.industry],
      ['الموظفين', lead.employees],
      ['الدولة', lead.country],
      ['الموقع', lead.website_url],
      ['المصدر', lead.source],
      ['الحالة', lead.status],
      ['التاريخ', (lead.created_at || '').slice(0, 16)],
    ];

    detail.innerHTML = `
      <div class="grid" style="gap:8px;margin-top:12px">
        ${fields.filter(f => f[1]).map(f => `
          <div class="kpi" style="padding:10px 14px">
            <span class="muted" style="font-size:12px">${f[0]}</span>
            <span style="font-size:14px;font-weight:600">${f[1]}</span>
          </div>
        `).join('')}
      </div>
      <div style="margin-top:14px">
        <textarea class="input" id="leadNotes" placeholder="ملاحظات...">${lead.notes || ''}</textarea>
        <div class="row" style="margin-top:10px">
          <select class="select" id="leadStatus" style="flex:1">
            <option value="new" ${lead.status==='new'?'selected':''}>جديد</option>
            <option value="contacted" ${lead.status==='contacted'?'selected':''}>تم التواصل</option>
            <option value="qualified" ${lead.status==='qualified'?'selected':''}>مؤهّل</option>
            <option value="converted" ${lead.status==='converted'?'selected':''}>تم التحويل</option>
            <option value="lost" ${lead.status==='lost'?'selected':''}>مفقود</option>
          </select>
          <button class="btn" style="padding:10px 18px" onclick="saveLead(${leadId})">💾 حفظ</button>
        </div>
      </div>
      <a href="../result.html?id=${assessmentId}" target="_blank" class="btn2" style="width:100%;margin-top:12px;text-align:center">📊 عرض التقرير</a>
      <a href="https://wa.me/${lead.phone?.replace(/[^0-9]/g,'')}" target="_blank" class="btn-green btn" style="width:100%;margin-top:8px">📱 تواصل واتساب</a>
    `;
  } catch (e) {
    detail.innerHTML = '<div class="alert alert-error">خطأ في تحميل البيانات</div>';
  }
}

async function saveLead(id) {
  await fetch(`${API}/lead.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'include',
    body: JSON.stringify({
      id,
      status: document.getElementById('leadStatus').value,
      notes:  document.getElementById('leadNotes').value,
    }),
  });
  const btn = event.target;
  btn.textContent = '✅ تم!';
  setTimeout(() => { btn.textContent = '💾 حفظ'; }, 1500);
}

// ── Export CSV ──
function exportCSV() {
  if (!allRows.length) return;
  const headers = ['ID','Name','Company','Score','Stage','Date'];
  const csvRows = [headers.join(',')];
  allRows.forEach(r => {
    csvRows.push([
      r.id,
      `"${(r.full_name || '').replace(/"/g, '""')}"`,
      `"${(r.company_name || '').replace(/"/g, '""')}"`,
      r.score ?? '',
      r.stage ?? '',
      (r.created_at || '').slice(0, 10),
    ].join(','));
  });
  const blob = new Blob(['\uFEFF' + csvRows.join('\n')], { type: 'text/csv;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = `digital_readiness_${new Date().toISOString().slice(0,10)}.csv`;
  a.click();
}
