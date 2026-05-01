// ============================================================
// quiz.js — منطق استبيان فحص الجاهزية الرقمية
// ============================================================
'use strict';

const STEPS = 3;
let step = 1;
const answers = {};
const selectedPlatforms = new Set();

// ── DOM ──
const panels = document.querySelectorAll('.step-panel');
const progressSteps = document.querySelectorAll('.progress-step');

// ── Check Labels ──
document.querySelectorAll('.check-label').forEach(lbl => {
  const cb = lbl.querySelector('input[type=checkbox]');
  const key = lbl.dataset.key;
  cb.addEventListener('change', () => {
    lbl.classList.toggle('checked', cb.checked);
    answers[key] = cb.checked;
  });
});

// ── Chips (multi-select) ──
document.querySelectorAll('#platformChips .chip').forEach(chip => {
  chip.addEventListener('click', () => {
    const v = chip.dataset.val;
    chip.classList.toggle('active');
    if (selectedPlatforms.has(v)) selectedPlatforms.delete(v);
    else selectedPlatforms.add(v);
    answers.platforms_used = [...selectedPlatforms];
  });
});

// ── Navigation ──
function showStep(n) {
  step = n;
  panels.forEach((p, i) => { p.style.display = (i === n - 1) ? '' : 'none'; });
  progressSteps.forEach((s, i) => {
    s.classList.remove('active', 'completed');
    if (i < n - 1) s.classList.add('completed');
    if (i === n - 1) s.classList.add('active');
  });
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ── Validation ──
function validateStep1() {
  let ok = true;
  ['full_name', 'phone', 'company_name'].forEach(id => {
    const el = document.getElementById(id);
    const err = document.getElementById('err_' + id);
    if (!el.value.trim()) {
      el.classList.add('err');
      if (err) err.textContent = 'هذا الحقل مطلوب';
      ok = false;
    } else {
      el.classList.remove('err');
      if (err) err.textContent = '';
    }
  });
  return ok;
}

// ── Step 1 → 2 ──
document.getElementById('btnNext1').addEventListener('click', () => {
  if (!validateStep1()) return;
  showStep(2);
});

// ── Step 2 → 3 ──
document.getElementById('btnNext2').addEventListener('click', () => { showStep(3); });
document.getElementById('btnPrev2').addEventListener('click', () => { showStep(1); });

// ── Step 3 ──
document.getElementById('btnPrev3').addEventListener('click', () => { showStep(2); });

// ── Submit ──
document.getElementById('btnSubmit').addEventListener('click', async () => {
  const btn = document.getElementById('btnSubmit');
  const loading = document.getElementById('submitLoading');
  btn.style.display = 'none';
  document.getElementById('btnPrev3').style.display = 'none';
  loading.style.display = '';

  const lead = {
    full_name:    document.getElementById('full_name').value.trim(),
    phone:        document.getElementById('phone').value.trim(),
    company_name: document.getElementById('company_name').value.trim(),
    industry:     document.getElementById('industry').value,
    employees:    document.getElementById('employees').value,
    country:      document.getElementById('country').value,
    website_url:  document.getElementById('website_url').value.trim(),
  };

  try {
    const res = await fetch('api/submit.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ lead, answers }),
    });
    const data = await res.json();
    if (data.error) throw new Error(data.error);
    window.location.href = `result.html?id=${data.assessment_id}`;
  } catch (err) {
    console.error(err);
    loading.innerHTML = `
      <div class="alert alert-error">❌ حصل خطأ: ${err.message}</div>
      <button class="btn" style="margin-top:14px" onclick="location.reload()">حاول مرة أخرى</button>
    `;
  }
});

// ── localStorage persistence ──
document.querySelectorAll('.input, .select').forEach(el => {
  const savedVal = localStorage.getItem('dr_' + el.id);
  if (savedVal) el.value = savedVal;
  el.addEventListener('input', () => localStorage.setItem('dr_' + el.id, el.value));
  el.addEventListener('change', () => localStorage.setItem('dr_' + el.id, el.value));
});
