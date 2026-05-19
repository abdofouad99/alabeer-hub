/* ═══ scan-page.js — CSP-safe JS for scan.html ═══
 * Extracted from inline <script> for CSP compliance.
 * No eval, no new Function, no setTimeout(string), no template literals in callbacks.
 * Uses string concatenation, var instead of const/let for broader compat.
 * v2.0: Wrapped in DOMContentLoaded to ensure DOM is ready.
 */

// ── التحكم في حقل "أخرى" لمجال العمل ──
function toggleOtherProject(val) {
  var wrap = document.getElementById('other_project_wrap');
  if (wrap) wrap.style.display = (val === 'other') ? 'block' : 'none';
}

document.addEventListener('DOMContentLoaded', function() {
  // ── اختيار الجمهور المستهدف (Pills) ──
  var audiencePills = document.querySelectorAll('#audienceWrap .pill');
  var audienceHidden = document.getElementById('audience-hidden');
  var otherAudienceWrap = document.getElementById('other_audience_wrap');
  var selectedAudience = [];

  audiencePills.forEach(function(pill) {
    pill.addEventListener('click', function() {
      pill.classList.toggle('active');
      var val = pill.dataset.audience;

      if (selectedAudience.indexOf(val) !== -1) {
        selectedAudience = selectedAudience.filter(function(x) { return x !== val; });
      } else {
        selectedAudience.push(val);
      }
      if (audienceHidden) audienceHidden.value = selectedAudience.join(',');

      // إظهار حقل "أخرى" إذا تم اختياره
      if (val === 'other' && otherAudienceWrap) {
        otherAudienceWrap.style.display = pill.classList.contains('active') ? 'block' : 'none';
      }
    });
  });

  // ── اختيار الأهداف التسويقية ──
  var goalPills = document.querySelectorAll('#goalsWrap .pill');
  var goalsHidden = document.getElementById('goals-hidden');
  var selectedGoals = [];

  goalPills.forEach(function(pill) {
    pill.addEventListener('click', function() {
      pill.classList.toggle('active');
      var g = pill.dataset.goal;
      if (selectedGoals.indexOf(g) !== -1) {
        selectedGoals = selectedGoals.filter(function(x) { return x !== g; });
      } else {
        selectedGoals.push(g);
      }
      if (goalsHidden) goalsHidden.value = selectedGoals.join(',');
    });
  });

  // ─────────────────────────────────────────────────
  // ── منطق الحساب: فحص الإيميل اللحظي + تطابق كلمة المرور
  // ─────────────────────────────────────────────────
  var emailInput            = document.getElementById('email');
  var passwordInput         = document.getElementById('password');
  var passwordConfirmInput  = document.getElementById('passwordConfirm');
  var passwordConfirmWrap   = document.getElementById('passwordConfirmWrap');
  var emailExistsNotice     = document.getElementById('emailExistsNotice');
  var accountModeHint       = document.getElementById('accountModeHint');
  var passwordMatchHint     = document.getElementById('passwordMatchHint');

  // 'register' = إيميل جديد، 'login' = إيميل موجود
  var accountMode = 'register';

  function isValidEmail(em) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em);
  }

  function setAccountMode(mode) {
    accountMode = mode;
    if (mode === 'login') {
      if (emailExistsNotice) emailExistsNotice.style.display = 'block';
      if (passwordConfirmWrap) passwordConfirmWrap.style.display = 'none';
      if (passwordConfirmInput) {
        passwordConfirmInput.required = false;
        passwordConfirmInput.value = '';
      }
      if (accountModeHint) accountModeHint.textContent = 'أدخل كلمة المرور الحالية';
      if (passwordInput) passwordInput.setAttribute('autocomplete', 'current-password');
    } else {
      if (emailExistsNotice) emailExistsNotice.style.display = 'none';
      if (passwordConfirmWrap) passwordConfirmWrap.style.display = '';
      if (passwordConfirmInput) passwordConfirmInput.required = true;
      if (accountModeHint) accountModeHint.textContent = 'اختر كلمة مرور قوية';
      if (passwordInput) passwordInput.setAttribute('autocomplete', 'new-password');
    }
  }

  // فحص الإيميل لحظياً مع debounce
  var emailCheckTimer = null;
  function scheduleEmailCheck() {
    if (emailCheckTimer) clearTimeout(emailCheckTimer);
    emailCheckTimer = setTimeout(checkEmailExists, 500);
  }

  function checkEmailExists() {
    if (!emailInput) return;
    var em = (emailInput.value || '').trim().toLowerCase();
    if (!isValidEmail(em)) {
      setAccountMode('register');
      return;
    }
    fetch('api/customer/auth.php?action=password-check', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ email: em })
    })
      .then(function(r) { return r.text(); })
      .then(function(txt) {
        var data = null;
        try { data = JSON.parse(txt); } catch (e) {}
        if (data && data.ok && data.data && data.data.exists === true) {
          setAccountMode('login');
        } else {
          setAccountMode('register');
        }
      })
      .catch(function() { /* ignore — keep current mode */ });
  }

  if (emailInput) {
    emailInput.addEventListener('blur', checkEmailExists);
    emailInput.addEventListener('input', scheduleEmailCheck);
  }

  // فحص تطابق كلمة المرور لحظياً
  function checkPasswordMatch() {
    if (!passwordInput || !passwordConfirmInput || !passwordMatchHint) return;
    if (accountMode === 'login') return; // لا حاجة في وضع login
    var p1 = passwordInput.value;
    var p2 = passwordConfirmInput.value;
    if (!p2) {
      passwordMatchHint.textContent = 'يجب أن تتطابق';
      passwordMatchHint.style.color = '';
      return;
    }
    if (p1 === p2) {
      passwordMatchHint.textContent = '✓ متطابقة';
      passwordMatchHint.style.color = '#10b981';
    } else {
      passwordMatchHint.textContent = '✕ غير متطابقة';
      passwordMatchHint.style.color = '#ef4444';
    }
  }
  if (passwordInput)        passwordInput.addEventListener('input', checkPasswordMatch);
  if (passwordConfirmInput) passwordConfirmInput.addEventListener('input', checkPasswordMatch);

  // ── تقديم النموذج ──
  function handleScan(e) {
    e.preventDefault();

    var fullName = document.getElementById('full_name').value.trim();
    var phone    = document.getElementById('phone').value.trim();
    var email    = (document.getElementById('email').value || '').trim().toLowerCase();
    var country  = document.getElementById('country').value.trim();
    var city     = document.getElementById('city').value.trim();

    var password         = (passwordInput        && passwordInput.value)        || '';
    var passwordConfirm  = (passwordConfirmInput && passwordConfirmInput.value) || '';

    var web = document.getElementById('url-web').value.trim();
    var ig  = document.getElementById('url-ig').value.trim();
    var fb  = document.getElementById('url-fb').value.trim();
    var tk  = document.getElementById('url-tk').value.trim();
    var tw  = document.getElementById('url-tw').value.trim();

    var primaryUrl = web || ig || fb || tk || tw;

    // معالجة "مجال العمل"
    var projectType = document.getElementById('project_type').value;
    if (projectType === 'other') {
      projectType = 'أخرى: ' + document.getElementById('project_type_other').value.trim();
    }

    // معالجة "الجمهور المستهدف"
    var audienceFinal = selectedAudience.join(', ');
    if (selectedAudience.indexOf('other') !== -1) {
      var otherVal = document.getElementById('audience_other').value.trim();
      audienceFinal = audienceFinal.replace('other', 'أخرى: ' + otherVal);
    }

    // التحقق
    if (!fullName) { alert('الرجاء إدخال اسمك الكريم.'); return; }
    if (!phone)    { alert('الرجاء إدخال رقم الواتساب.'); return; }
    if (!email || !isValidEmail(email)) {
      alert('الرجاء إدخال بريد إلكتروني صحيح.');
      if (emailInput) emailInput.focus();
      return;
    }
    if (!password || password.length < 8) {
      alert('كلمة المرور يجب أن تكون 8 حروف على الأقل.');
      if (passwordInput) passwordInput.focus();
      return;
    }
    if (accountMode === 'register' && password !== passwordConfirm) {
      alert('كلمتا المرور غير متطابقتين.');
      if (passwordConfirmInput) passwordConfirmInput.focus();
      return;
    }
    if (!country)  { alert('الرجاء إدخال الدولة.'); return; }
    if (!city)     { alert('الرجاء إدخال المدينة.'); return; }
    if (!primaryUrl) { alert('الرجاء إدخال رابط منصة واحدة على الأقل.'); return; }
    if (selectedGoals.length === 0) { alert('الرجاء اختيار هدف تسويقي واحد على الأقل.'); return; }

    // تعطيل الزر أثناء الإرسال
    var btn = document.getElementById('submitBtn');
    if (btn) {
      btn.disabled = true;
      btn.textContent = 'جاري التجهيز... ⏳';
    }

    // حفظ البيانات كاملة في sessionStorage
    var leadData = {
      full_name:       fullName,
      phone:           phone,
      email:           email,
      password:        password,            // ← يُمرَّر إلى submit.php (يُحذف بعد إنشاء الحساب)
      url:             primaryUrl,
      website_url:     web,
      instagram_url:   ig,
      facebook_url:    fb,
      tiktok_url:      tk,
      twitter_url:     tw,
      objective:       selectedGoals.join(','),
      project_type:    projectType,
      target_audience: audienceFinal,
      ad_budget:       document.getElementById('ad_budget').value,
      country:         country,
      city:            city
    };

    sessionStorage.setItem('lead_data', JSON.stringify(leadData));

    // الانتقال لصفحة التحليل
    window.location.href = 'analyzing.html?url=' + encodeURIComponent(primaryUrl);
  }

  // ── Wire onsubmit via addEventListener (CSP-safe, no onsubmit attribute) ──
  var scanForm = document.getElementById('scanForm');
  if (scanForm) {
    scanForm.addEventListener('submit', handleScan);
  }

  // ── Wire project_type select onchange (replaces inline onchange) ──
  var projectTypeSelect = document.getElementById('project_type');
  if (projectTypeSelect) {
    projectTypeSelect.addEventListener('change', function() {
      toggleOtherProject(this.value);
    });
  }
});
