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

  // ── تقديم النموذج ──
  function handleScan(e) {
    e.preventDefault();

    var fullName = document.getElementById('full_name').value.trim();
    var phone    = document.getElementById('phone').value.trim();
    var email    = document.getElementById('email').value.trim();
    var country  = document.getElementById('country').value.trim();
    var city     = document.getElementById('city').value.trim();

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
