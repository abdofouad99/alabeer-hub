// ============================================================
// js/admin.js — لوحة تحكم Admin لبصمة النمو
// ============================================================

const API = '../api/admin';
let allRows = [];
let pieChartInst = null, lineChartInst = null;
let activeTab = 'list';

// ── Auth check ───────────────────────────────────────────────
async function checkAuth() {
  const res  = await fetch(`${API}/auth.php?action=check`, { credentials:'include' });
  const data = await res.json();
  if (!data.authed) window.location.href = 'login.html';
}

// ── Logout ───────────────────────────────────────────────────
async function doLogout() {
  await fetch(`${API}/auth.php?action=logout`, { method:'POST', credentials:'include' });
  window.location.href = 'login.html';
}

// ── Tab switch ───────────────────────────────────────────────
function switchTab(tab) {
  activeTab = tab;
  document.getElementById('tabList').style.display   = tab==='list'  ? 'block' : 'none';
  document.getElementById('tabStats').style.display  = tab==='stats' ? 'block' : 'none';
  document.getElementById('tabListBtn').className    = tab==='list'  ? 'btn'  : 'btn2';
  document.getElementById('tabStatsBtn').className   = tab==='stats' ? 'btn'  : 'btn2';
  if (tab==='stats') loadStats();
}

// ── Load stats ───────────────────────────────────────────────
async function loadStats() {
  const res  = await fetch(`${API}/stats.php`, { credentials:'include' });
  const data = await res.json();

  document.getElementById('s_total').textContent  = data.total;
  document.getElementById('s_avg').textContent    = data.avg;
  document.getElementById('s_green').textContent  = data.green;
  document.getElementById('s_yellow').textContent = data.yellow;
  document.getElementById('s_red').textContent    = data.red;

  // Pie Chart
  const pieCtx = document.getElementById('pieChart').getContext('2d');
  if (pieChartInst) pieChartInst.destroy();
  pieChartInst = new Chart(pieCtx, {
    type: 'doughnut',
    data: {
      labels: ['🟢 انطلاق','🟡 نمو','🔴 خطر'],
      datasets: [{ data:[data.green,data.yellow,data.red], backgroundColor:['#10b981','#f0c040','#ef4444'], borderWidth:0 }]
    },
    options: {
      cutout:'55%',
      plugins: { legend:{ labels:{ color:'#8b949e', font:{family:'Cairo',size:12} } } }
    }
  });

  // Line Chart
  const lineCtx = document.getElementById('lineChart').getContext('2d');
  if (lineChartInst) lineChartInst.destroy();
  lineChartInst = new Chart(lineCtx, {
    type: 'line',
    data: {
      labels: (data.timeline||[]).map(r=>r.date),
      datasets: [{
        label:'تسجيلات', data:(data.timeline||[]).map(r=>Number(r.count)),
        borderColor:'#3b82f6', backgroundColor:'rgba(59,130,246,.1)',
        borderWidth:2, pointRadius:4, pointBackgroundColor:'#3b82f6', tension:.3, fill:true
      }]
    },
    options: {
      scales: {
        x:{ ticks:{ color:'#6b7a99', font:{family:'Cairo'} }, grid:{ color:'rgba(255,255,255,.05)' } },
        y:{ ticks:{ color:'#6b7a99', font:{family:'Cairo'}, precision:0 }, grid:{ color:'rgba(255,255,255,.05)' } }
      },
      plugins:{ legend:{ display:false } }
    }
  });
}

// ── Tier helpers ─────────────────────────────────────────────
function tierColor(t) { return t==='red'?'#ef4444':t==='yellow'?'#f0c040':'#10b981'; }
function tierShort(t) { return t==='red'?'🔴 خطر':t==='yellow'?'🟡 نمو':'🟢 انطلاق'; }

// ── Load assessments ─────────────────────────────────────────
async function loadList() {
  const res  = await fetch(`${API}/list.php?limit=200`, { credentials:'include' });
  allRows = await res.json();
  renderList(allRows);
}

function filterList() {
  const q = document.getElementById('searchQ').value.toLowerCase();
  const filtered = !q ? allRows : allRows.filter(r =>
    (r.summary||'').toLowerCase().includes(q) ||
    (r.tier||'').toLowerCase().includes(q) ||
    String(r.score||'').includes(q)
  );
  renderList(filtered);
}

let selectedId = null;
function renderList(rows) {
  const container = document.getElementById('assessmentList');
  document.getElementById('listCount').textContent = rows.length;
  container.innerHTML = '';
  if (!rows.length) {
    container.innerHTML = '<p class="muted" style="text-align:center;padding:30px 0">لا توجد نتائج</p>';
    return;
  }
  rows.forEach(r => {
    const btn = document.createElement('button');
    btn.className = 'btn2';
    btn.style.cssText = 'text-align:right;' + (selectedId===r.id ? 'border-color:var(--blue);background:var(--blue-dim)' : '');
    btn.innerHTML = `
      <div style="display:flex;justify-content:space-between;margin-bottom:4px">
        <span style="font-weight:700;color:${tierColor(r.tier)}">${tierShort(r.tier)}</span>
        <span class="badge" style="font-size:12px"><b>${r.score??'-'}</b>/100</span>
      </div>
      <div class="muted" style="font-size:13px;line-height:1.5">${((r.summary||'لا يوجد ملخص').slice(0,85))}${(r.summary||'').length>85?'…':''}</div>
      <div class="muted" style="font-size:11px;margin-top:3px">${new Date(r.created_at).toLocaleString('ar-SA')}</div>`;
    btn.onclick = () => { selectedId = r.id; loadLead(r); renderList(rows); };
    container.appendChild(btn);
  });
}

// ── Load lead detail ─────────────────────────────────────────
async function loadLead(row) {
  const detail = document.getElementById('leadDetail');
  detail.innerHTML = '<p class="muted" style="text-align:center;padding:20px 0">جاري التحميل...</p>';
  try {
    const res  = await fetch(`${API}/lead.php?id=${row.lead_id}`, { credentials:'include' });
    const lead = await res.json();
    renderLeadDetail(row, lead);
  } catch {
    detail.innerHTML = '<p class="alert alert-error">فشل تحميل بيانات العميل</p>';
  }
}

function safeUrl(u) { if (!u) return '#'; return u.startsWith('http') ? u : 'https://'+u; }

function renderLeadDetail(row, lead) {
  const col = tierColor(row.tier);
  const wa  = lead.phone ? `https://wa.me/${lead.phone.replace(/\D/g,'')}` : 'https://wa.me/967739537053';
  document.getElementById('leadDetail').innerHTML = `
    <div class="grid" style="gap:9px">
      <div class="row" style="gap:7px">
        <span class="badge badge-blue">درجة: <b>${row.score}</b></span>
        <span class="badge" style="border-color:${col};color:${col}">${tierShort(row.tier)}</span>
        <a href="../result.html?id=${row.id}" target="_blank" class="badge badge-blue" style="font-size:12px">🔗 النتيجة</a>
      </div>
      <div class="hr" style="margin:6px 0"></div>
      <div class="kpi" style="padding:11px 13px"><span class="muted">الاسم</span><b>${lead.full_name||'-'}</b></div>
      <div class="kpi" style="padding:11px 13px"><span class="muted">الجوال</span><b>${lead.phone||'-'}</b></div>
      ${lead.email    ? `<div class="kpi" style="padding:11px 13px"><span class="muted">البريد</span><b>${lead.email}</b></div>` : ''}
      ${lead.company_name ? `<div class="kpi" style="padding:11px 13px"><span class="muted">النشاط</span><b>${lead.company_name}</b></div>` : ''}
      ${lead.country  ? `<div class="kpi" style="padding:11px 13px"><span class="muted">الدولة</span><b>${lead.country}</b></div>` : ''}
      ${lead.website_url  ? `<a href="${safeUrl(lead.website_url)}" target="_blank" class="badge badge-blue" style="font-size:12px">🌐 الموقع</a>` : ''}
      ${lead.instagram_url ? `<a href="${safeUrl(lead.instagram_url)}" target="_blank" class="badge badge-blue" style="font-size:12px">📸 Instagram</a>` : ''}
      <div class="hr" style="margin:6px 0"></div>
      <div>
        <div class="muted" style="font-size:12px;margin-bottom:5px">حالة العميل:</div>
        <select class="select" style="padding:9px 12px;font-size:14px" onchange="updateLead(${lead.id},{status:this.value})">
          <option value="new"       ${lead.status==='new'       ?'selected':''}>🆕 عميل جديد</option>
          <option value="contacted" ${lead.status==='contacted' ?'selected':''}>📞 تم التواصل</option>
          <option value="qualified" ${lead.status==='qualified' ?'selected':''}>✅ مؤهّل</option>
          <option value="closed"    ${lead.status==='closed'    ?'selected':''}>🏁 مُغلق</option>
        </select>
      </div>
      <div>
        <div class="muted" style="font-size:12px;margin-bottom:5px">ملاحظات:</div>
        <textarea class="input" style="min-height:70px" id="notes_${lead.id}"
          onblur="updateLead(${lead.id},{notes:this.value})">${lead.notes||''}</textarea>
      </div>
      <a class="btn btn-green" href="${wa}" target="_blank" rel="noreferrer" style="text-align:center;margin-top:4px">💬 تواصل واتساب</a>
    </div>`;
}

async function updateLead(id, patch) {
  await fetch(`${API}/lead.php`, {
    method:'POST', credentials:'include',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ id, ...patch }),
  });
}

// ── CSV Export ───────────────────────────────────────────────
function exportCSV() {
  const header = ['ID','التاريخ','الدرجة','الفئة','الملخص'];
  const rows   = allRows.map(r=>[
    r.id, new Date(r.created_at).toLocaleString('ar'),
    r.score??0, r.tier??'', `"${(r.summary||'').replace(/"/g,'""')}"`
  ]);
  const csv  = [header.join(','), ...rows.map(r=>r.join(','))].join('\n');
  const blob = new Blob(['\uFEFF'+csv], {type:'text/csv;charset=utf-8;'});
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href = url; a.setAttribute('download','growth_fp_leads.csv');
  document.body.appendChild(a); a.click(); document.body.removeChild(a);
}

// ── Init ─────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', async () => {
  await checkAuth();
  switchTab('list');
  loadList();
});
