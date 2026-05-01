// ============================================================
// result.js — عرض نتيجة فحص الجاهزية الرقمية
// ============================================================
'use strict';

const AXIS_LABELS = {
  presence:   '🌐 التواجد الرقمي',
  selling:    '🛒 البيع الإلكتروني',
  marketing:  '📱 التسويق الرقمي',
  automation: '⚙️ الأتمتة والأنظمة',
  data:       '📊 البيانات والقياس',
};
const AXIS_MAX = { presence: 25, selling: 20, marketing: 25, automation: 15, data: 15 };

const STAGE_MAP = {
  offline:  { label: '🔴 غير رقمي',       css: 'stage-offline' },
  beginner: { label: '🟠 مبتدئ',           css: 'stage-beginner' },
  growing:  { label: '🟡 نامي',            css: 'stage-growing' },
  advanced: { label: '🟢 متقدم',           css: 'stage-advanced' },
  digital:  { label: '🔵 رقمي بالكامل',     css: 'stage-digital' },
};

async function loadResult() {
  const id = new URLSearchParams(location.search).get('id');
  if (!id) { document.getElementById('app').innerHTML = '<div class="card" style="text-align:center"><div class="h2">⚠️ لا يوجد تقييم</div></div>'; return; }

  const res  = await fetch(`api/result.php?id=${encodeURIComponent(id)}`);
  const d    = await res.json();
  if (d.error) { document.getElementById('app').innerHTML = `<div class="card"><div class="alert alert-error">${d.error}</div></div>`; return; }

  document.getElementById('skeletonBlock').style.display = 'none';
  document.getElementById('resultContent').style.display = '';

  // ── Score Ring ──
  const score = d.score ?? 0;
  const arc = (score / 100) * 314;
  setTimeout(() => { document.getElementById('scoreArc').setAttribute('stroke-dasharray', `${arc} 314`); }, 100);

  let displayed = 0;
  const scoreEl = document.getElementById('scoreNum');
  const timer = setInterval(() => { displayed += 1; scoreEl.textContent = displayed; if (displayed >= score) clearInterval(timer); }, 18);

  // ── Stage Badge ──
  const st = STAGE_MAP[d.stage] || STAGE_MAP.offline;
  const stageBadge = document.getElementById('stageBadge');
  stageBadge.textContent = st.label;
  stageBadge.className = 'stage-badge ' + st.css;

  // ── Summary ──
  document.getElementById('summaryText').textContent = d.summary || '';
  document.getElementById('summaryFull').textContent = d.summary || '';

  // ── Breakdown Bars ──
  const barsBox = document.getElementById('breakdownBars');
  const bd = d.breakdown || {};
  Object.keys(AXIS_LABELS).forEach(key => {
    const val = bd[key] ?? 0;
    const max = AXIS_MAX[key];
    const pct = Math.round((val / max) * 100);
    barsBox.innerHTML += `
      <div class="bd-item">
        <div class="bd-row">
          <span>${AXIS_LABELS[key]}</span>
          <span style="color:var(--purple);font-weight:800">${val}/${max}</span>
        </div>
        <div class="bd-bg"><div class="bd-fill" style="width:0" data-w="${pct}%"></div></div>
      </div>`;
  });
  setTimeout(() => { barsBox.querySelectorAll('.bd-fill').forEach(el => { el.style.width = el.dataset.w; }); }, 200);

  // ── Strengths ──
  const sBox = document.getElementById('strengthsBox');
  const strengths = d.strengths || [];
  sBox.innerHTML = `<div class="h3 text-green" style="margin-bottom:10px">🚀 نقاط القوة</div>
    <ul style="padding-right:18px;line-height:2">${strengths.map(s => `<li>${s}</li>`).join('')}</ul>`;

  // ── Weaknesses ──
  const wBox = document.getElementById('weaknessBox');
  const weaknesses = d.weaknesses || [];
  wBox.innerHTML = `<div class="h3 text-red" style="margin-bottom:10px">⚠️ نقاط الضعف</div>
    <ul style="padding-right:18px;line-height:2">${weaknesses.map(w => `<li>${w}</li>`).join('')}</ul>`;

  // ── Quick Wins ──
  const qwBox = document.getElementById('quickWinsList');
  const quickWins = d.quick_wins || [];
  quickWins.forEach((item, i) => {
    qwBox.innerHTML += `<div class="kpi"><span>📌 ${item}</span><span class="badge badge-purple">${i + 1}</span></div>`;
  });

  // ── Monthly Plan ──
  const mpBox = document.getElementById('monthlyPlan');
  const monthlyPlan = d.monthly_plan || [];
  monthlyPlan.forEach(p => {
    mpBox.innerHTML += `
      <div class="roadmap-step">
        <div class="roadmap-week">${p.week}</div>
        <div class="roadmap-tasks">${p.tasks}</div>
      </div>`;
  });

  // ── Tools ──
  const toolsBox = document.getElementById('toolsList');
  const tools = d.tools_suggested || [];
  tools.forEach(t => {
    toolsBox.innerHTML += `
      <div class="tool-card">
        <div style="flex:1">
          <div class="tool-name">${t.name}</div>
          <div class="tool-desc">${t.desc}</div>
        </div>
        <span class="${t.free ? 'tool-free' : 'tool-paid'}">${t.free ? '✅ مجاني' : '💰 مدفوع'}</span>
      </div>`;
  });
}

loadResult();
