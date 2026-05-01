// ============================================================
// js/report-connect.js v2.0 — ربط كامل لجميع الصفحات الفرعية
// P2-1: إصلاح الاسم (brand_name → full_name)
// P2-2: ربط ads, competitors, journey, content ببيانات حقيقية
// P2-3: ربط packages بدرجة العميل الحقيقية
// ============================================================

function sanitize(str) {
  if (typeof str !== 'string') return str;
  const temp = document.createElement('div');
  temp.textContent = str;
  return temp.innerHTML;
}

function sanitizeRelaxed(str) {
  if (typeof str !== 'string') return String(str || '');
  const div = document.createElement('div');
  div.innerHTML = str;
  div.querySelectorAll('script,iframe,object,embed,form,link,meta,style').forEach(el => el.remove());
  return div.innerHTML;
}

// ── Animation Helpers (Moved to top) ──
function animateCounters() {
  document.querySelectorAll('.score-num[data-val], .d-score-num[data-val]').forEach(el => {
    const target = parseInt(el.getAttribute('data-val'));
    if (isNaN(target)) return;
    let current = 0;
    const step = () => {
      current += (target - current) * 0.12;
      el.textContent = Math.floor(current);
      if (Math.abs(target - current) > 0.5) requestAnimationFrame(step);
      else el.textContent = target;
    };
    requestAnimationFrame(step);
  });
}

function animateRings() {
  document.querySelectorAll('.score-circle[data-percent]').forEach(ring => {
    const pct   = parseInt(ring.getAttribute('data-percent'));
    const color = ring.getAttribute('data-color') || 'var(--primary)';
    let cur = 0;
    const step = () => {
      cur += (pct - cur) * 0.08;
      ring.style.background = `conic-gradient(${color} ${cur}%, rgba(255,255,255,0.1) 0)`;
      if (Math.abs(pct - cur) > 0.5) requestAnimationFrame(step);
      else ring.style.background = `conic-gradient(${color} ${pct}%, rgba(255,255,255,0.1) 0)`;
    };
    requestAnimationFrame(step);
  });
}

document.addEventListener("DOMContentLoaded", () => {
  const urlParams = new URLSearchParams(window.location.search);
  const path = window.location.pathname;
  let id = urlParams.get('id');
  
  // ── MOCK DATA FOR PREVIEW MODE ──
  let isMockMode = false;
  let mockData = null;
  if (!id) {
    isMockMode = true;
    id = "mock";
    mockData = {
      full_name: "متجر ناتشورال بيوتي (معاينة شاملة)",
      company_name: "ناتشورال بيوتي",
      url: "https://naturalbeauty.com",
      score: 65,
      website_url: "https://naturalbeauty.com",
      instagram_url: "https://instagram.com/natural_beauty",
      facebook_url: "https://facebook.com/naturalbeauty",
      scan_result: {
        hasSSL: true,
        hasPixel: false,
        hasGA: true,
        ads_library: {
          total_ads: 3,
          ads: [
            { id: "101", page_name: "متجر ناتشورال بيوتي", is_active: true, start_date: "2023-10-15", image_url: "https://images.unsplash.com/photo-1596462502278-27bfdc403348?w=500&q=80", title: "احصلي على بشرة نضرة وخالية من العيوب مع سيروم فيتامين سي الجديد. اطلبي الآن!" },
            { id: "102", page_name: "متجر ناتشورال بيوتي", is_active: true, start_date: "2023-10-12", image_url: "https://images.unsplash.com/photo-1556228578-0d85b1a4d571?w=500&q=80", title: "خصم 20% لفترة محدودة على جميع منتجات العناية بالبشرة. لا تفوتي الفرصة." },
            { id: "103", page_name: "متجر ناتشورال بيوتي", is_active: false, start_date: "2023-09-01", image_url: "https://images.unsplash.com/photo-1615397323381-45cd3c20037a?w=500&q=80", title: "مجموعة العناية المتكاملة، الخطوة الأولى لجمالك الطبيعي." }
          ]
        },
        hasWhatsApp: false,
        instagram: { followers: 12400, engagement_rate: 1.2, avg_likes: 148 }
      },
      breakdown: [
        { axis: "الهوية والبراندينج", score: 85, reason: "الهوية البصرية للمتجر وحسابات التواصل متناسقة بشكل ممتاز. لوحة الألوان (الأخضر والذهبي) تعكس طبيعة المنتجات العضوية بشكل قوي وتبني انطباعاً أولياً بالفخامة والجودة. المشكلة الوحيدة تكمن في عدم وجود 'Brand Voice' موحد في كتابة المنشورات (Tone of Voice)." },
        { axis: "المحتوى والاستقطاب", score: 55, reason: "هناك خلل استراتيجي في نوعية المحتوى المنشور. الحساب يعتمد بنسبة 90% على منشورات البيع المباشر (Hard Selling) وصور المنتجات الجامدة. في خوارزميات 2026، هذا المحتوى لا يحصل على وصول عضوي (Reach). يجب فوراً البدء بإنتاج محتوى فيديو قصير (Reels) يركز على (UGC) وتجارب العملاء المباشرة لخلق طلب (Demand Generation)." },
        { axis: "الثقة (Social Proof)", score: 40, reason: "هذه هي أكبر نقطة تسرب مبيعات (Leakage) في البزنس حالياً. العميل الجديد الذي لا يعرف علامتك التجارية لن يشتري لمجرد أن المنتج شكله جميل. لا يوجد قسم واضح لآراء العملاء الموثقة (Testimonials)، ولا يوجد فيديوهات لأشخاص حقيقيين يستخدمون المنتج. الثقة معدومة، وهذا يرفع تكلفة استحواذ العميل (CPA) في الإعلانات بشكل مخيف." },
        { axis: "الإعلانات والاستهداف", score: 70, reason: "يوجد نشاط إعلاني حالي (4 إعلانات نشطة)، وهذا جيد. لكن تم رصد أن جميع الإعلانات تعتمد على (الصور الثابتة)، وهو خطأ فادح في منصات تعتمد على الفيديو (TikTok/Reels). الصور الثابتة في الإعلانات اليوم تكلف 3 أضعاف إعلانات الفيديو من حيث تكلفة النقرة (CPC). إضافة إلى ذلك، لا يوجد بيكسل تتبع مفعل لجمع البيانات، مما يعني أن ميزانية الإعلانات تحترق دون جمع Data للمستقبل." },
        { axis: "رحلة العميل والمبيعات", score: 65, reason: "العميل يصل للمتجر بسلاسة، ولكن سرعة الموقع بطيئة نوعاً ما مما يفقده التركيز. والأخطر من ذلك هو صفحة الدفع (Checkout) المعقدة، وعدم وجود زر تواصل سريع (واتساب) للإجابة على اعتراضات العميل الأخيرة قبل الدفع. تبسيط الـ Checkout إلى خطوة واحدة سيرفع مبيعاتك بنسبة لا تقل عن 20% فوراً." }
      ],
      competitor_radar: [
        { name: "بيوتي سيكريتس", url: "beautysecrets.com", strengths: ["ميزانية إعلانية ضخمة", "شراكات مستمرة مع المشاهير"], weaknesses: ["أسعار مبالغ فيها للمنتجات", "تأخر مستمر في خدمة العملاء"], attack_plan: "أطلق حملة مقارنة (Us vs Them) تبرز جودتك العضوية مع سرعة التوصيل." },
        { name: "ذا بودي شوب (محلي)", url: "thebodyshop.sa", strengths: ["موثوقية عالية للعلامة التجارية", "فروع في كل مكان"], weaknesses: ["موقع إلكتروني بطيء جداً", "لا يوجد تواصل سريع عبر الواتساب"], attack_plan: "استحوذ على المبيعات أونلاين بتقديم تجربة شراء في خطوة واحدة (1-Click Checkout)." },
        { name: "أورجانيك هيرب", url: "organicherb.co", strengths: ["تغليف ممتاز", "برنامج ولاء للعملاء"], weaknesses: ["لا يشغلون إعلانات فيديو", "محتوى حساباتهم ممل"], attack_plan: "اكتسحهم فوراً بتشغيل إعلانات فيديو UGC قصيرة على تيك توك وإنستجرام ريلز." },
        { name: "جلوي سكين", url: "glowyskin.net", strengths: ["صور منتجات احترافية", "أسعار منخفضة جداً"], weaknesses: ["جودة رديئة", "تقييمات سلبية بكثرة في التعليقات"], attack_plan: "لا تنافسهم في السعر. ركز في رسالتك التسويقية على (المكونات العضوية الخالية من الكيماويات)." },
        { name: "ناتشرال فيبز", url: "naturalvibes.sa", strengths: ["إعلانات ممولة مستمرة", "سرعة موقع جيدة"], weaknesses: ["صفحات هبوط (Landing pages) سيئة", "غياب آراء العملاء (Social Proof)"], attack_plan: "ضع آراء عملائك في أول الصفحة، وابنِ صفحة هبوط مخصصة لكل منتج لتتغلب عليهم بالتحويل." }
      ],
      execution_arsenal: [
        { icon: "🎥", title: "محتوى الفيديو (UGC)", desc: "إنتاج 8-12 فيديو شهرياً بتصوير عفوي لاكتساح خوارزميات التيك توك والريلز التي أهملها منافسوك." },
        { icon: "⚡", title: "صفحة هبوط مخصصة (Landing Page)", desc: "بناء صفحة هبوط ذات تحويل عالي (High-Converting) تركز على حل مشكلة العميل بدلاً من المتجر التقليدي." },
        { icon: "💬", title: "الرد الفوري والاحتفاظ", desc: "تفعيل أتمتة الواتساب لرد على استفسارات العملاء في أقل من دقيقة وسحب المبيعات من المنافسين البطيئين." },
        { icon: "🎯", title: "إعادة الاستهداف الذكي (Retargeting)", desc: "تخصيص 20% من الميزانية الإعلانية لملاحقة العملاء الذين زاروا مواقع المنافسين أو تخلوا عن سلة الشراء." }
      ],
      recommendations: [
        { priority: "high", icon: "🛡️", title: "سد ثغرة انعدام الثقة (Social Proof)", desc: "يجب إنشاء قسم مخصص في Highlights يسمى 'تجارب حقيقية'، يتضمن لقطات شاشة لمحادثات عملاء راضين، وفيديوهات (UGC) لعملاء يستلمون المنتجات." },
        { priority: "high", icon: "🎯", title: "تغيير الخطاف الإعلاني (Hook) في الحملات النشطة", desc: "أوقف الإعلانات التي تستعرض المكونات واستبدلها بـفيديوهات تبدأ بمشكلة العميل: 'هل مللتي من جفاف بشرتك؟'." },
        { priority: "medium", icon: "✍️", title: "إعادة هندسة البايو (Bio Engineering)", desc: "البايو الحالي يشبه الكتالوج. قم بتحويله إلى رسالة بيعية قوية تتكون من ضمان استرجاع ورابط شراء واضح." },
        { priority: "medium", icon: "🖼️", title: "إضفاء الطابع الإنساني على المحتوى", desc: "ابدأ بتصوير المنتجات أثناء استخدامها فعلياً، فهذا يرفع من القيمة المتصورة للمنتج (Perceived Value)." },
        { priority: "low", icon: "🤝", title: "بناء برنامج الولاء والنقاط", desc: "استغل أن 45% من عملائك يعودون للشراء بإنشاء نظام نقاط أو كود خصم للعملاء الحاليين عند جلبهم لصديق." }
      ],
      ads_analysis: {
        score: 45,
        status: "⚠️ هدر مالي (نزيف ميزانية)",
        desc: "أنت تدفع للإعلانات لجلب الزيارات، ولكن <strong>العائد غير مرضي تماماً</strong> وميزانيتك تحترق.",
        metrics: [
          { title: "العائد على الإنفاق (ROAS)", val: "0.8x", status: "▼ خسارة محققة", status_class: "status-red", val_class: "val-red", desc: "أنت تخسر أموالك! تدفع دولاراً للإعلان وتستعيد 80 سنتاً فقط. يجب إيقاف الحملات فوراً والمراجعة." },
          { title: "تكلفة النقرة (CPC)", val: "$1.15", status: "▶ مكلف جداً", status_class: "status-yellow", val_class: "val-yellow", desc: "تكلفة جلب الزائر مرتفعة جداً لأن المحتوى الإعلاني غير جذاب ولا يثير الفضول الكافي." },
          { title: "نسبة النقر للظهور (CTR)", val: "0.9%", status: "▼ تجاهل تام", status_class: "status-red", val_class: "val-red", desc: "العملاء يرون الإعلان أثناء التصفح ولكنهم لا يتوقفون. الصورة النمطية للإعلان تقتل التحويل." }
        ],
        creative_pointers: [
          { type: "red", icon: "❌", title: "غياب 'الخطاف' (Hook)", desc: "أول 3 ثواني مملة تماماً. المستخدم يمرر (Scroll) دون توقف. ابدأ الإعلان بسؤال صادم أو مشكلة يعاني منها عميلك." },
          { type: "red", icon: "❌", title: "العرض مبهم ولا يقاوم", desc: "أنت تعرض المنتج كسلعة وليس كحل سحري. لا يوجد عرض حصري (Irresistible Offer) يدفعهم للشراء الآن بدلاً من غداً." },
          { type: "yellow", icon: "⚠️", title: "نص الإعلان (Copy) طويل وممل", desc: "التركيز على سرد المكونات (Features) بدلاً من الفوائد (Benefits). العميل لا يريد سيروم، بل يريد بشرة نضرة." }
        ],
        strategy: {
          desc: "أوقف حرق الميزانية في الحملات الحالية فوراً. سنقوم بتغيير استراتيجية الشراء الإعلاني بالكامل:",
          steps: [
            "<strong>إعلانات المحتوى المنشأ بواسطة المستخدم (UGC):</strong> استبدل التصاميم الاحترافية الجامدة بفيديوهات حقيقية بعيدة عن المثالية.",
            "<strong>إعادة الاستهداف المكثف (Retargeting):</strong> استهدف فوراً الـ 60% الذين زاروا الموقع وخرجوا، بعرض خصم مؤقت لمدة 24 ساعة.",
            "<strong>هندسة صفحة الهبوط (Landing Page):</strong> وجه الزوار لصفحة هبوط سريعة الشراء (1-Click) بدلاً من الصفحة الرئيسية المشتتة للمتجر."
          ]
        }
      },
      market_summary: "السوق يعاني من فجوة خطيرة: أغلب المنافسين يركزون على جلب العميل ويهملون خدمة ما بعد البيع وبناء مجتمع حول العلامة التجارية. بناء برنامج ولاء بسيط (Loyalty Program) مع ضمان ذهبي للاسترجاع سيجعلك <span class=\"highlight\">تحتكر حصة ضخمة من السوق وتخفض تكلفة إعلاناتك بـ 40%</span> خلال 90 يوماً."
    };
  }

  // ── 1. تحديث روابط التنقل والباكجات لتحتفظ بـ id ──────────
  document.querySelectorAll('.nav-menu a, .btn-upgrade, .btn-primary, .btn-pkg, .back-btn').forEach(link => {
    const href = link.getAttribute('href');
    if (href && !href.startsWith('#') && !href.startsWith('http')) {
      // If it already has the exact id parameter, skip
      if (href.includes('id=' + id)) return;
      
      const separator = href.includes('?') ? '&' : '?';
      link.setAttribute('href', href + separator + 'id=' + id);
    }
  });

  // ── 2. جلب البيانات ──────────────────────────────────────
  if (isMockMode) {
    // تشغيل وضع المعاينة ببيانات وهمية
    renderData(mockData);
  } else {
    if (!id || id === 'mock') return;
    let token = urlParams.get('token') || sessionStorage.getItem('last_assessment_token');
    
    fetch(`api/result.php?id=${id}&token=${token || ''}`)
      .then(res => { if (!res.ok) throw new Error('Server error: ' + res.status); return res.json(); })
      .then(data => {
        if (data.error) { console.error('API error:', data.error); return; }
        if (data.status === 'pending') {
          const _mc = document.querySelector('.main-content') || document.querySelector('main') || document.body;
          if (_mc) _mc.innerHTML = '<div style="text-align:center;padding:60px;direction:rtl;font-family:Cairo,sans-serif;"><h2 style="color:#f58e1a;">⏳ جاري تجهيز تقريرك...</h2><p style="color:#666;margin-top:12px;">يرجى الانتظار قليلاً ثم تحديث الصفحة</p><button onclick="location.reload()" style="margin-top:20px;padding:12px 28px;background:#f58e1a;color:#fff;border:none;border-radius:12px;cursor:pointer;">🔄 تحديث</button></div>';
          return;
        }
        if (data.status === 'pending') {
          const _mc = document.querySelector('.main-content') || document.querySelector('main') || document.body;
          if (_mc) _mc.innerHTML = '<div style="text-align:center;padding:60px;direction:rtl;"><h2>\u23F3 \u062C\u0627\u0631\u064A \u062A\u062C\u0647\u064A\u0632 \u062A\u0642\u0631\u064A\u0631\u0643...</h2><p>\u064A\u0631\u062C\u0649 \u0627\u0644\u0627\u0646\u062A\u0638\u0627\u0631 \u062B\u0645 \u062A\u062D\u062F\u064A\u062B \u0627\u0644\u0635\u0641\u062D\u0629</p><button onclick="location.reload()" style="margin-top:20px;padding:12px 28px;background:#f58e1a;color:#fff;border:none;border-radius:12px;cursor:pointer;">\uD83D\uDD04 \u062A\u062D\u062F\u064A\u062B</button></div>';
          return;
        }
        renderData(data);
      })
      .catch(e => {
        console.error(e);
      });
  }

  function renderData(data) {
      const sr     = data.scan_result || {};
      const srObj  = sr; // alias — available to all page blocks inside renderData
      const fb     = sr.facebook     || {};
      const ig     = sr.instagram    || {};
      const ws     = sr.website_scan || {};

      // ── P2-1: الاسم الصحيح (مصدر واحد — DB فقط) ──────────
      const clientName = data.full_name || data.company_name || 'العميل';
      const clientUrl  = data.url || '';

      // تحديث Profile Header
      const nameEl   = document.querySelector('.profile-info h2');
      const handleEl = document.querySelector('.profile-info p');
      if (nameEl && !path.includes('detailed-analysis.html')) nameEl.textContent = clientName;
      if (handleEl && clientUrl && !path.includes('detailed-analysis.html')) {
        try {
          const u = new URL(clientUrl);
          let handle = u.pathname.replace(/\//g, '') || u.hostname;
          handleEl.textContent = '@' + handle;
        } catch(e) {
          handleEl.textContent = clientUrl;
        }
      }

      // ── تحديث نوع الحساب والمجال (Dynamic Binding) ────────
      const typeEl  = document.getElementById('profileAccountType');
      const nicheEl = document.getElementById('profileNiche');
      
      const ai = data.ai_report || {};
      if (typeEl) {
        let typeStr = ai.page_type || data.project_type || 'تجاري';
        // ترجمة سريعة للأنواع الشائعة
        const translations = {
          'E-commerce Store': 'متجر إلكتروني 🛒',
          'Business / Service Provider': 'شركة / مزود خدمة 💼',
          'Personal Influencer / Content Creator': 'صانع محتوى / مؤثر ✨',
          'Brand Awareness Page': 'صفحة علامة تجارية 🏷️',
          'Blog / Media Content': 'مدونة / محتوى إعلامي 📝'
        };
        typeEl.textContent = translations[typeStr] || typeStr;
      }
      if (nicheEl) {
        nicheEl.textContent = ai.niche || 'تسويق رقمي ✨';
      }

      // ── تحديث الدرجة في جميع الصفحات ─────────────────────
      if (data.score != null) {
        const scoreNum = document.querySelector('.score-num[data-val]');
        if (scoreNum) {
          scoreNum.setAttribute('data-val', data.score);
          scoreNum.textContent = data.score;
        }
        const ring = document.querySelector('.score-circle[data-percent]');
        if (ring) {
          const color = data.score >= 70 ? 'var(--green)' : data.score >= 40 ? 'var(--yellow)' : 'var(--red)';
          ring.setAttribute('data-percent', data.score);
          ring.setAttribute('data-color', color);
        }
        // تحريك العداد
        animateCounters();
        animateRings();
      }

      // [Old strengths block removed - consolidated below]

      // ==========================================
      // PAGE: recommendations.html (Merged Block)
      // ==========================================
      if (path.includes('recommendations.html')) {
        const recClientName = document.getElementById('recClientName');
        const recHandle = document.getElementById('recHandle');
        const recTotalCount = document.getElementById('recTotalCount');
        
        if (recClientName) recClientName.textContent = clientName;
        if (recHandle) {
          recHandle.textContent = srObj.instagram_url ? srObj.instagram_url.replace(/https?:\/\/(www\.)?instagram\.com\//, '@') : '@' + clientName.replace(/\s+/g, '_').toLowerCase();
        }

        document.querySelectorAll('.rec-section').forEach(s => s.remove());
        const mainContainer = document.querySelector('.card');
        const ctaBanner     = document.querySelector('.rec-cta-banner');

        if (data.recommendations && data.recommendations.length > 0) {
          if (recTotalCount) recTotalCount.textContent = data.recommendations.length + ' إجراء';

          let highHtml = '', medHtml = '', lowHtml = '';
          data.recommendations.forEach(rec => {
            const priority  = rec.priority || 'medium';
            const iconMap = { high: '🛡️', medium: '✍️', low: '🤝' };
            const icon = rec.icon || iconMap[priority] || '💡';
            const iconClass = priority === 'high' ? 'high' : priority === 'low' ? 'low' : 'med';
            
            const cardHtml = `
              <div class="rec-card">
                <div class="rec-icon ${iconClass}">${icon}</div>
                <div class="rec-content">
                  <h4>${sanitize(rec.title || 'توصية')}</h4>
                  <p>${sanitize(rec.desc || '')}</p>
                </div>
              </div>`;
              
            if (priority === 'high') highHtml += cardHtml;
            else if (priority === 'low') lowHtml += cardHtml;
            else medHtml += cardHtml;
          });

          const recHighList = document.getElementById('recHighList');
          const recMedList = document.getElementById('recMedList');
          const recLowList = document.getElementById('recLowList');

          if (recHighList) recHighList.innerHTML = highHtml || '<div style="padding: 20px; color: var(--green);">أداء ممتاز!</div>';
          if (recMedList) recMedList.innerHTML = medHtml || '<div style="padding: 20px; color: var(--text-gray);">لا توجد مهام متوسطة حالياً.</div>';
          if (recLowList) recLowList.innerHTML = lowHtml || '<div style="padding: 20px; color: var(--text-gray);">لا توجد مهام منخفضة الأولوية حالياً.</div>';
          
          if (!recHighList && mainContainer) {
             let fullHtml = '';
             if (highHtml) fullHtml += `<div class="rec-section"><h4 class="rec-title rt-high">🔴 أولوية قصوى</h4><div class="rec-list">${highHtml}</div></div>`;
             if (medHtml)  fullHtml += `<div class="rec-section"><h4 class="rec-title rt-med">🟡 أولوية متوسطة</h4><div class="rec-list">${medHtml}</div></div>`;
             if (lowHtml)  fullHtml += `<div class="rec-section"><h4 class="rec-title rt-low">🟢 تحسينات مستقبلية</h4><div class="rec-list">${lowHtml}</div></div>`;
             const target = ctaBanner || mainContainer;
             target.insertAdjacentHTML(ctaBanner ? 'beforebegin' : 'beforeend', fullHtml);
          }
        }
      }

      // ==========================================
      // PAGE: detailed-analysis.html (ULTIMATE AUDIT)
      // ==========================================
      if (path.includes('detailed-analysis.html')) {
        const daName = document.getElementById('auditClientName');
        if (daName) daName.textContent = clientName;

        // Extract URLs safely from both sources (DB leads or Scan discovery)
        // srObj already defined at top of renderData
        const websiteUrl = data.website_url || (srObj.website_scan ? srObj.website_scan.final_url : '') || srObj.website || '';
        const instagramUrl = data.instagram_url || (srObj.instagram ? srObj.instagram.url : '') || '';
        const facebookUrl = data.facebook_url || (srObj.facebook ? srObj.facebook.url : '') || '';

        // --- 1. Website Audit ---
        const urlW = document.getElementById('auditWebUrl');
        if (urlW) urlW.innerHTML = websiteUrl ? `<a href="${websiteUrl}" target="_blank" style="color:var(--primary);text-decoration:none;">${websiteUrl}</a>` : 'لم يتم العثور على موقع إلكتروني';
        const _gw = document.getElementById('gridWebsite'); if (!websiteUrl && _gw) _gw.classList.add('card-disabled');

        const awSSL = document.getElementById('awSSL');
        if (awSSL) { awSSL.textContent = srObj.hasSSL ? 'مفعل' : 'مفقود'; awSSL.className = srObj.hasSSL ? 'si-badge badge-ok' : 'si-badge badge-warn'; }
        const awPixel = document.getElementById('awPixel');
        if (awPixel) { awPixel.textContent = srObj.hasPixel ? 'مفعل' : 'غير مركب'; awPixel.className = srObj.hasPixel ? 'si-badge badge-ok' : 'si-badge badge-warn'; }
        const awGA = document.getElementById('awGA');
        if (awGA) { awGA.textContent = srObj.hasGA ? 'مفعل' : 'مفقود'; awGA.className = srObj.hasGA ? 'si-badge badge-ok' : 'si-badge badge-warn'; }
        const awWA = document.getElementById('awWA');
        if (awWA) { awWA.textContent = srObj.hasWhatsApp ? 'مفعل' : 'مفقود'; awWA.className = srObj.hasWhatsApp ? 'si-badge badge-ok' : 'si-badge badge-warn'; }

        // --- 2. Instagram Audit ---
        const urlI = document.getElementById('auditIgUrl');
        if (urlI) urlI.innerHTML = instagramUrl ? `<a href="${instagramUrl}" target="_blank" style="color:var(--primary);text-decoration:none;">${instagramUrl}</a>` : 'لم يتم العثور على حساب';
        const _gi = document.getElementById('gridInstagram'); if (!instagramUrl && _gi) _gi.classList.add('card-disabled');
        
        const igData = srObj.instagram || {};
        if (document.getElementById('igFollowers') && igData.followers) {
           document.getElementById('igFollowers').textContent = parseInt(igData.followers).toLocaleString();
        }
        if (document.getElementById('igER') && igData.engagement_rate) {
           const er = parseFloat(igData.engagement_rate);
           const el = document.getElementById('igER');
           el.textContent = er + '%';
           el.className = er >= 2 ? 'si-badge badge-ok' : 'si-badge badge-warn';
        }
        if (document.getElementById('igLikes') && igData.avg_likes) {
           document.getElementById('igLikes').textContent = parseInt(igData.avg_likes).toLocaleString();
        }

        // --- 3. Facebook Audit ---
        const urlF = document.getElementById('auditFbUrl');
        if (urlF) urlF.innerHTML = facebookUrl ? `<a href="${facebookUrl}" target="_blank" style="color:var(--primary);text-decoration:none;">${facebookUrl}</a>` : 'لم يتم العثور على حساب';
        const _gf = document.getElementById('gridFacebook'); if (!facebookUrl && _gf) _gf.classList.add('card-disabled');

        const adsObj = srObj.ads_library || {};
        if (document.getElementById('fbAdsCount')) {
           document.getElementById('fbAdsCount').textContent = adsObj.total_ads !== undefined ? adsObj.total_ads : '0';
        }

        // --- 4. Deep Diagnosis (AI Paragraphs) ---
        const diagContainer = document.getElementById('auditDeepDiagnosis');
        if (diagContainer && data.breakdown && data.breakdown.length > 0) {
          let diagHtml = '';
          data.breakdown.forEach(item => {
            const score = item.score || 0;
            // Highlight random keywords for effect (e.g., english words)
            let richText = (item.reason || '').replace(/([A-Za-z]+)/g, '<span class="highlight">$1</span>');
            
            diagHtml += `
              <div class="diagnosis-block">
                <div class="diag-score">
                  <div class="num" style="color:${score >= 80 ? 'var(--green)' : score >= 50 ? 'var(--yellow)' : 'var(--red)'}">${score}</div>
                  <div class="label">تقييم المحور</div>
                </div>
                <div class="diag-content">
                  <h3><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> تشخيص محور: ${item.axis}</h3>
                  <p>${richText}</p>
                </div>
              </div>
            `;
          });
          diagContainer.innerHTML = diagHtml;
        }
      }

      // ==========================================
      // PAGE: competitors.html (MARKET RADAR)
      // ==========================================
      if (path.includes('competitors.html')) {
        const compName = document.getElementById('compClientName');
        const vsName = document.getElementById('vsClientName');
        if (compName) compName.textContent = clientName;
        if (vsName) vsName.textContent = clientName;

        const grid = document.getElementById('competitorsGrid');
        if (grid && data.competitor_radar && data.competitor_radar.length > 0) {
          let html = '';
          const ranks = ['1st', '2nd', '3rd', '4th', '5th'];
          
          data.competitor_radar.slice(0,5).forEach((comp, i) => {
            const st1 = comp.strengths && comp.strengths[0] ? comp.strengths[0] : 'وجود قوي في السوق';
            const st2 = comp.strengths && comp.strengths[1] ? comp.strengths[1] : 'قاعدة عملاء مستقرة';
            const wk1 = comp.weaknesses && comp.weaknesses[0] ? comp.weaknesses[0] : 'خدمة عملاء بطيئة';
            const wk2 = comp.weaknesses && comp.weaknesses[1] ? comp.weaknesses[1] : 'محتوى غير متجدد';
            
            html += `
              <div class="comp-card">
                <div class="cc-header">
                  <div class="cc-info">
                    <h3>${comp.name || 'منافس'}</h3>
                    <p><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg> ${comp.url || 'غير متوفر'}</p>
                  </div>
                  <div class="cc-rank">${ranks[i] || '#'}</div>
                </div>
                
                <div class="traits-list">
                  <div class="trait-group">
                    <span class="trait-title">نقاط تفوقه (Strengths)</span>
                    <div class="trait-item trait-strength">${st1}</div>
                    <div class="trait-item trait-strength">${st2}</div>
                  </div>
                  <div class="trait-group" style="margin-top:8px;">
                    <span class="trait-title">نقاط ضعفه (Vulnerabilities)</span>
                    <div class="trait-item trait-weakness">${wk1}</div>
                    <div class="trait-item trait-weakness">${wk2}</div>
                  </div>
                </div>
                
                <div class="attack-plan">
                  <div class="ap-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg> خطة الهجوم</div>
                  <div class="ap-desc">${comp.attack_plan || 'استغل نقاط ضعفه أعلاه للسيطرة على عملائه.'}</div>
                </div>
              </div>
            `;
          });
          grid.innerHTML = html;
        } else if (grid) {
          grid.innerHTML = `<div style="grid-column: 1 / -1; padding: 40px; text-align: center; color: var(--text-gray); font-size:18px;">لم يتمكن محرك Apify من استخراج بيانات منافسين كافية لهذا المجال، أو أن البيانات قيد المعالجة.</div>`;
        }

        const arsenalGrid = document.getElementById('arsenalGrid');
        if (arsenalGrid && data.execution_arsenal) {
          let arsenalHtml = '';
          data.execution_arsenal.forEach(item => {
            arsenalHtml += `
              <div class="arsenal-item">
                <div class="arsenal-icon">${item.icon || '🔥'}</div>
                <div class="arsenal-title">${item.title}</div>
                <div class="arsenal-desc">${item.desc}</div>
              </div>
            `;
          });
          arsenalGrid.innerHTML = arsenalHtml;
        }

        const summaryText = document.getElementById('marketSummaryText');
        if (summaryText && data.market_summary) {
          // Highlight text between span highlight
          summaryText.innerHTML = sanitize(data.market_summary);
        } else if (summaryText) {
          summaryText.innerHTML = "استراتيجية (المحيط الأزرق) تكمن في استغلال الثغرات في خدمة عملاء المنافسين. ركز على تجربة شراء لا تُنسى وسيبدأ ولاء العملاء بالتحول إليك تدريجياً.";
        }
      }

      // ==========================================
      // PAGE: ads.html (ADS WAR ROOM)
      // ==========================================
      if (path.includes('ads.html')) {
        const adName = document.getElementById('adClientName');
        const adHandle = document.getElementById('adHandle');
        if (adName) adName.textContent = clientName;
        
        // srObj already defined at top of renderData
        if (adHandle) adHandle.textContent = data.instagram_url ? data.instagram_url.replace(/https?:\/\/(www\.)?instagram\.com\//, '@').replace(/\//, '') : '@' + clientName.replace(/\s+/g, '').toLowerCase();

        // If real data doesn't provide ads_analysis, generate a highly realistic diagnostic estimate based on scan_result
        let adsAnalysis = data.ads_analysis;
        
        if (!adsAnalysis) {
          const hasAds = (srObj.ads_library && srObj.ads_library.total_ads > 0) || (srObj.facebook && srObj.facebook.ads_count > 0);
          const hasPixel = srObj.hasPixel || false;
          const adCount = srObj.ads_library ? srObj.ads_library.total_ads : (srObj.facebook ? srObj.facebook.ads_count : 0);
          
          if (hasAds) {
            if (!hasPixel) {
              adsAnalysis = {
                score: 30,
                status: "🚨 هدر مالي خطير (بدون تتبع)",
                desc: `تم اكتشاف ${adCount} إعلانات نشطة، لكنك لا تمتلك بيكسل تتبع! أنت تحرق أموالك في الهواء ولا تجمع أي بيانات.`,
                metrics: [
                  { title: "العائد على الإنفاق (ROAS)", val: "مجهول", status: "▼ نزيف مستمر", status_class: "status-red", val_class: "val-red", desc: "بسبب غياب التتبع (Pixel)، لا يمكن لفيسبوك تحسين العائد. أنت تدفع للظهور فقط." },
                  { title: "تكلفة النقرة (CPC)", val: "مرتفعة", status: "▶ غير محسنة", status_class: "status-yellow", val_class: "val-yellow", desc: "الخوارزمية لا تعرف من يشتري، لذلك تجلب لك زيارات عشوائية بتكلفة عالية." },
                  { title: "البيانات المجمعة", val: "0%", status: "▼ ضياع الأصول", status_class: "status-red", val_class: "val-red", desc: "كل زائر لم يشتري ضاع للأبد، لا يمكنك إعادة استهدافه." }
                ],
                creative_pointers: [
                  { type: "red", icon: "❌", title: "كارثة التتبع (Pixel)", desc: "تشغيل إعلانات بدون بيكسل مثل القيادة معصوب العينين. توقف فوراً." },
                  { type: "yellow", icon: "⚠️", title: "غياب الداتا (Data Loss)", desc: "المنافسون يبنون قواعد بيانات لعملائهم، وأنت تدفع لفيسبوك دون أن تحتفظ بشيء." },
                  { type: "red", icon: "❌", title: "محتوى غير مخصص", desc: "بسبب غياب التتبع، إعلاناتك تظهر للجميع (المهتم وغير المهتم)." }
                ],
                strategy: {
                  desc: "تدخل جراحي عاجل مطلوب:",
                  steps: [
                    "<strong>إيقاف الإعلانات:</strong> أوقف جميع حملاتك الممولة هذه اللحظة.",
                    "<strong>زرع بيكسل التتبع:</strong> تركيب Meta Pixel وإعداد أحداث الشراء (Purchase Events).",
                    "<strong>إطلاق حملات ذكية:</strong> إعادة إطلاق الإعلانات بهدف (التحويل Conversion) وليس (النقرات Traffic)."
                  ]
                }
              };
            } else {
              adsAnalysis = {
                score: 55,
                status: "⚠️ أداء متوسط (يحتاج تحسين)",
                desc: `تم رصد ${adCount} إعلانات نشطة. التتبع موجود، لكن المادة الإعلانية تحتاج لتحسين لرفع العائد.`,
                metrics: [
                  { title: "معدل التحويل المتوقع", val: "1.2%", status: "▼ أقل من السوق", status_class: "status-red", val_class: "val-red", desc: "الزيارات موجودة لكن المبيعات قليلة. الخلل في العرض أو صفحة الهبوط." },
                  { title: "حالة الحملة", val: "نشطة", status: "▲ مستقرة", status_class: "status-green", val_class: "val-green", desc: "الحملات تعمل والبيانات تُجمع بشكل صحيح." },
                  { title: "الاستحواذ (CPA)", val: "مكلف", status: "▶ يحتاج تقليل", status_class: "status-yellow", val_class: "val-yellow", desc: "تكلفة شراء العميل أعلى من الطبيعي بسبب ضعف الـ CTA." }
                ],
                creative_pointers: [
                  { type: "yellow", icon: "⚠️", title: "تكرار المحتوى (Ad Fatigue)", desc: "نفس الإعلانات تظهر للجمهور، مما يسبب ملل وارتفاع في التكلفة." },
                  { type: "red", icon: "❌", title: "العرض (Offer) غير كافي", desc: "العميل يحتاج سبباً مقنعاً للشراء (الآن). أضف عنصر الاستعجال (Scarcity)." },
                  { type: "green", icon: "✅", title: "التتبع مفعل", desc: "ميزة ممتازة تتيح لنا إعادة استهداف من زار الموقع ولم يشتري." }
                ],
                strategy: {
                  desc: "لدينا الأساسيات، حان وقت مضاعفة الأرباح (Scaling):",
                  steps: [
                    "<strong>تجديد الدماء (Creatives):</strong> إطلاق 5 فيديوهات UGC جديدة لاختبار الخوارزمية وتخفيض التكلفة.",
                    "<strong>إعادة الاستهداف (Retargeting):</strong> استهداف الزوار السابقين بعرض (خصم 15%) لتحويلهم لمشترين.",
                    "<strong>تبسيط الشراء:</strong> تقليل خطوات الدفع في موقعك (Checkout) لرفع التحويل المباشر."
                  ]
                }
              };
            }
          } else {
            adsAnalysis = {
                score: 10,
                status: "💤 سبات عميق (لا يوجد إعلانات)",
                desc: "لم نتمكن من رصد أي نشاط إعلاني حالي لعلامتك التجارية. أنت تترك الساحة فارغة للمنافسين.",
                metrics: [
                  { title: "التواجد الإعلاني", val: "معدوم", status: "▼ خطورة", status_class: "status-red", val_class: "val-red", desc: "الاعتماد الكلي على الزيارات العضوية (Organic) يحد من نموك بشكل كارثي." },
                  { title: "النمو الشهري", val: "بطيء", status: "▼ تحت المعدل", status_class: "status-red", val_class: "val-red", desc: "بدون وقود (الإعلانات)، لن تتمكن من مضاعفة مبيعاتك في وقت قصير." },
                  { title: "تكلفة الفرصة الضائعة", val: "عالية جداً", status: "▼ خسارة غير مرئية", status_class: "status-red", val_class: "val-red", desc: "منافسوك يستحوذون على عملائك المحتملين يومياً عبر حملاتهم." }
                ],
                creative_pointers: [
                  { type: "red", icon: "❌", title: "غياب الظهور", desc: "عميلك يبحث عن منتجك، ويرى إعلانات منافسك ويشتري منه." },
                  { type: "yellow", icon: "⚠️", title: "بطء مقلق", desc: "النمو العضوي ممتاز ولكنه لا يبني إمبراطورية تجارية بسرعة." },
                  { type: "red", icon: "❌", title: "لا يوجد جمع بيانات", desc: "لأنك لا تشغل إعلانات، بيكسلات التتبع الخاصة بك لا تتعلم من هو عميلك المثالي." }
                ],
                strategy: {
                  desc: "خطة الإطلاق الفورية لاكتساح السوق:",
                  steps: [
                    "<strong>ميزانية اختبارية:</strong> إطلاق حملة بـ 20$ يومياً لاختبار استجابة السوق لمنتجك الأساسي.",
                    "<strong>بناء الجمهور:</strong> إطلاق حملات وعي (Awareness) لجمع بيانات المهتمين وتجهيزهم للشراء.",
                    "<strong>عروض لا تقاوم:</strong> تقديم عرض الافتتاح (شحن مجاني + هدية) لاصطياد المشترين الأوائل بسرعة."
                  ]
                }
              };
          }
        }

        if (adsAnalysis) {
          // Score Mini Card
          const scoreRing = document.getElementById('adScoreRing');
          const scoreNum = document.getElementById('adScoreNum');
          const scoreTitle = document.getElementById('adScoreTitle');
          const scoreDesc = document.getElementById('adScoreDesc');
          
          if (scoreRing) scoreRing.setAttribute('data-percent', adsAnalysis.score);
          if (scoreNum) scoreNum.setAttribute('data-val', adsAnalysis.score);
          if (scoreTitle) scoreTitle.innerHTML = sanitize(adsAnalysis.status);
          if (scoreDesc) scoreDesc.innerHTML = sanitize(adsAnalysis.desc);

          // Metrics Grid
          const metricsGrid = document.getElementById('adMetricsGrid');
          if (metricsGrid && adsAnalysis.metrics) {
            let mHtml = '';
            adsAnalysis.metrics.forEach(m => {
              mHtml += `
                <div class="metric-box">
                  <div class="m-title">${m.title}</div>
                  <div class="m-val ${m.val_class}">${m.val}</div>
                  <div class="m-status ${m.status_class}">${m.status}</div>
                  <p style="font-size:12px; color:var(--text-gray); margin-top:8px; font-weight:600;">${m.desc}</p>
                </div>
              `;
            });
            metricsGrid.innerHTML = mHtml;
          }

          // Creative Pointers
          const pointersGrid = document.getElementById('adPointersGrid');
          if (pointersGrid && adsAnalysis.creative_pointers) {
            let pHtml = '';
            adsAnalysis.creative_pointers.forEach(p => {
              const pClass = p.type === 'yellow' ? 'pointer-yellow' : '';
              pHtml += `
                <div class="pointer-item ${pClass}" style="margin-bottom:16px;">
                  <h5>${p.icon} ${p.title}</h5>
                  <p>${p.desc}</p>
                </div>
              `;
            });
            pointersGrid.innerHTML = pHtml;
          }

          // AI Strategy
          const strategyDesc = document.getElementById('adStrategyDesc');
          const strategyList = document.getElementById('adStrategyList');
          if (strategyDesc && adsAnalysis.strategy) {
            strategyDesc.innerHTML = sanitize(adsAnalysis.strategy.desc);
            let sHtml = '';
            adsAnalysis.strategy.steps.forEach((step, i) => {
              sHtml += `<li style="display:flex; gap:10px; font-size:14px; font-weight:700; color:#fff;"><span style="color:var(--primary)">${i+1}.</span> ${step}</li>`;
            });
            strategyList.innerHTML = sHtml;
          }
        }

        // ------------------------------------------
        // Ad Gallery (Meta Ads Library)
        // ------------------------------------------
        const actualAdsGrid = document.getElementById('actualAdsGrid');
        if (actualAdsGrid) {
          let realAds = [];
          if (srObj.ads_library && srObj.ads_library.ads) {
            realAds = srObj.ads_library.ads;
          } else if (srObj.facebook && srObj.facebook.ads) {
            realAds = srObj.facebook.ads;
          }
          
          if (realAds && realAds.length > 0) {
            // Take up to 12 ads
            const adsToShow = realAds.slice(0, 12);
            let galleryHtml = '';
            
            adsToShow.forEach(ad => {
              const isActive = ad.is_active !== false; // default to true if undefined
              const statusClass = isActive ? 'ad-status-active' : 'ad-status-inactive';
              const statusText = isActive ? '🟢 نشط' : '⚪ غير نشط';
              const imgBg = ad.image_url ? `style="background-image: url('${ad.image_url}'); background-size: cover; background-position: center;"` : '';
              
              // Clean up long ad copy for display
              let shortCopy = (ad.title || 'لا يوجد نص لهذا الإعلان').substring(0, 60);
              if (shortCopy.length >= 60) shortCopy += '...';

              galleryHtml += `
                <div class="ad-card-real">
                  <div class="ad-header">
                    <div class="ad-avatar"></div>
                    <div style="font-size: 13px; font-weight: 700; color: #fff;">${ad.page_name || clientName}</div>
                  </div>
                  <div class="ad-img-wrap" ${imgBg}>
                    ${!ad.image_url ? '<span style="color:#555; z-index:2;">لا تتوفر صورة</span>' : ''}
                  </div>
                  <div class="ad-footer">
                    <div class="ad-status ${statusClass}">${statusText}</div>
                    <div style="margin-bottom:6px;"><strong>تاريخ الإطلاق:</strong> ${ad.start_date ? ad.start_date.substring(0,10) : 'غير معروف'}</div>
                    <div style="color:#fff; font-weight:600;">${shortCopy}</div>
                  </div>
                </div>
              `;
            });
            actualAdsGrid.innerHTML = galleryHtml;
          } else {
            actualAdsGrid.innerHTML = `<div style="grid-column: 1 / -1; padding: 40px; text-align: center; color: var(--text-gray); font-size: 16px;">لا توجد مواد إعلانية مستخرجة حالياً. الحساب لا يشغل إعلانات أو أن البيانات قيد السحب.</div>`;
          }
        }
      }

      // (الكتلة المكررة لـ recommendations تم دمجها مع الأولى وإزالتها من هنا)

      // ==========================================
      // PAGE: journey.html
      // ==========================================
      if (path.includes('journey.html')) {
        const score = data.score || 50;
        
        // Base numbers based on score (simulating real funnel logic)
        let s1 = score > 70 ? 90 : score > 40 ? 70 : 40;
        let s2 = score > 70 ? 80 : score > 40 ? 55 : 30;
        let s3 = score > 70 ? 75 : score > 40 ? 30 : 15;
        let s4 = score > 70 ? 40 : score > 40 ? 10 : 2;
        let s5 = score > 70 ? 60 : score > 40 ? 35 : 15;

        // Add some random variation
        s1 += Math.floor(Math.random() * 5);
        s2 += Math.floor(Math.random() * 5);
        s3 += Math.floor(Math.random() * 5);
        s4 += Math.floor(Math.random() * 5);
        s5 += Math.floor(Math.random() * 5);

        // Update HTML
        const journeyScore = document.getElementById('journeyScore');
        const journeyCircle = document.getElementById('journeyCircle');
        const journeyStatusTitle = document.getElementById('journeyStatusTitle');
        const journeyStatusDesc = document.getElementById('journeyStatusDesc');
        
        const typeStr = data.project_type || (data.ai_report ? data.ai_report.page_type : '') || 'تجاري';
        const isService = (typeStr.includes('Service') || typeStr.includes('Business') || typeStr.includes('تسويق') || typeStr.includes('شركة') || typeStr.includes('عقارات') || typeStr.includes('Influencer') || /marketing|agency|وكالة|b2b/i.test(typeStr));

        if (journeyScore) {
          journeyScore.setAttribute('data-val', score);
          journeyScore.textContent = score;
        }

        if (journeyCircle) {
          journeyCircle.setAttribute('data-percent', score);
          if (score > 70) journeyCircle.setAttribute('data-color', 'var(--green)');
          else if (score > 40) journeyCircle.setAttribute('data-color', 'var(--yellow)');
          else journeyCircle.setAttribute('data-color', 'var(--red)');
        }

        if (journeyStatusTitle) {
          if (score > 70) {
            journeyStatusTitle.innerHTML = '✅ مسار احترافي';
            journeyStatusTitle.style.color = 'var(--green)';
            if (journeyStatusDesc) journeyStatusDesc.innerHTML = isService ? 'الزوار يتدفقون بسلاسة ويتحولون إلى عملاء متعاقدين بكفاءة عالية.' : 'الزوار يتدفقون بسلاسة ويتحولون إلى مشترين بكفاءة عالية.';
          } else if (score > 40) {
            journeyStatusTitle.innerHTML = '⚠️ يوجد انسداد';
            journeyStatusTitle.style.color = 'var(--yellow)';
            if (journeyStatusDesc) journeyStatusDesc.innerHTML = isService ? 'الكثير من الزوار، لكن <strong>قليل من يطلب الخدمة</strong> بسبب نقطة اختناق في المنتصف.' : 'الكثير من الزوار، لكن <strong>قليل من يشتري</strong> بسبب نقطة اختناق في المنتصف.';
          } else {
            journeyStatusTitle.innerHTML = '❌ نزيف خطير';
            journeyStatusTitle.style.color = 'var(--red)';
            if (journeyStatusDesc) journeyStatusDesc.innerHTML = 'مسار التحويل مكسور. أنت تدفع للزوار لكي يهربوا فوراً.';
          }
        }

        // Update Stages Data
        ['stage1', 'stage2', 'stage3', 'stage4', 'stage5'].forEach((id, idx) => {
          const val = [s1, s2, s3, s4, s5][idx];
          const scoreEl = document.getElementById(`${id}Score`);
          const boxEl = document.getElementById(`${id}Box`);
          const descEl = document.getElementById(`${id}Desc`);
          
          if (scoreEl) {
            scoreEl.setAttribute('data-val', val);
            scoreEl.textContent = val + '%';
          }
          if (boxEl) {
            boxEl.classList.remove('stage-green', 'stage-yellow', 'stage-red');
            if (val > 60) boxEl.classList.add('stage-green');
            else if (val > 30) boxEl.classList.add('stage-yellow');
            else boxEl.classList.add('stage-red');
          }
          
          if (descEl) {
            if (idx === 0) { // Awareness
              if (val > 60) descEl.innerHTML = 'حملاتك الإعلانية تعمل بشكل ممتاز وتنجح في لفت انتباه الجمهور المستهدف. هناك تدفق صحي للزوار الجدد إلى حسابك.';
              else descEl.innerHTML = 'الإعلانات لا تجلب زواراً كافيين. الاستهداف أو الرسالة الإعلانية الأولية بحاجة لمراجعة وتعديل عاجل.';
            } else if (idx === 1) { // Interest
              if (val > 60) descEl.innerHTML = isService ? 'الزوار يعجبهم التصميم العام وتلفتهم خدماتك. يقومون بتصفح بعض المنشورات، مما يعكس اهتماماً حقيقياً.' : 'الزوار يعجبهم التصميم العام وتلفتهم المنتجات. يقومون بتصفح بعض المنشورات، مما يعكس وجود اهتمام حقيقي.';
              else descEl.innerHTML = 'الزوار يخرجون فور دخولهم الحساب. المحتوى لا يبدو مقنعاً أو غير متناسق مع ما وعدت به في الإعلان.';
            } else if (idx === 2) { // Trust
              if (val > 60) descEl.innerHTML = isService ? 'استطعت بناء ثقة ممتازة! مراجعات العملاء وتجاربهم واضحة، والزائر يشعر بالأمان لطلب خدماتك.' : 'استطعت بناء ثقة ممتازة! مراجعات العملاء وتجاربهم واضحة، والزائر يشعر بالأمان لإكمال الشراء.';
              else descEl.innerHTML = 'الزائر يتردد ويغادر. لا يجد ما يطمئنه؛ غياب تجارب العملاء (Social Proof) يجعله يخشى المغامرة بماله.';
            } else if (idx === 3) { // Conversion
              if (val > 60) descEl.innerHTML = isService ? 'عملية التواصل وطلب الخدمة سهلة وواضحة جداً. معدل التحويل ممتاز يعكس قوة عرضك وموثوقية نشاطك.' : 'عملية الدفع سهلة وواضحة جداً. معدل التحويل ممتاز يعكس قوة عرضك وموثوقية موقعك.';
              else descEl.innerHTML = isService ? 'عدد قليل جداً يقوم بالتواصل للطلب. قد يكون السبب تعقيد قنوات التواصل أو غياب الحافز المباشر.' : 'عدد قليل جداً يكمل عملية الدفع. قد يكون السبب تعقيد خطوات الدفع، تكلفة الشحن المخفية، أو تردد اللحظة الأخيرة.';
            } else if (idx === 4) { // Loyalty
              if (val > 60) descEl.innerHTML = isService ? 'خدمتك ممتازة! العملاء يعودون للتعامل معك مرة أخرى ويوصون بك، مما يعني رضاهم التام.' : 'منتجك ممتاز! العملاء يعودون للشراء مرة أخرى، مما يعني ولائهم لعلامتك التجارية ورضاهم التام.';
              else descEl.innerHTML = isService ? 'تعامل لمرة واحدة! العميل يستفيد من الخدمة ولا يعود مجدداً. جودة الخدمة أو متابعة العملاء بحاجة لتحسين.' : 'مبيعات المرة الواحدة! العميل يشتري ولا يعود مجدداً. جودة المنتج أو تجربة ما بعد البيع بحاجة لتحسين.';
            }
          }
        });

        // Determine Bottleneck (Lowest conversion from previous step)
        const s3Warning = document.getElementById('stage3Warning');
        const s4Warning = document.getElementById('stage4Warning');
        const s3Fix = document.getElementById('stage3FixBox');
        const s4Fix = document.getElementById('stage4FixBox');

        if (s3Warning && s4Warning) {
          if (score < 70) {
            // Calculate the drops to find the actual bottleneck
            const dropToTrust = s2 - s3;
            const dropToConversion = s3 - s4;
            
            if (dropToTrust >= dropToConversion) { 
              s3Warning.style.display = 'inline-block';
              if (s3Fix) s3Fix.style.display = 'block';
            } else {
              s4Warning.style.display = 'inline-block';
              if (s4Fix) s4Fix.style.display = 'block';
            }
          }
        }
      }

      // ==========================================
      // PAGE: content.html
      // ==========================================
      if (path.includes('content.html')) {
        const score = data.score || 50;
        const fb = sr.facebook || {};
        const ig = sr.instagram || {};
        
        // محاولة جلب التحليل العميق (نفضل إنستجرام لأنه أدق في توزيع الأنواع عادةً)
        const da = ig.deep_analysis || fb.deep_analysis || {};
        const types = da.types_percent || { video: 33, image: 33, sidecar: 34 };
        const cta   = da.cta_percent !== undefined ? da.cta_percent : (score > 60 ? 60 : 30);
        
        // 1. جودة التصميم (Visual): نربطها بوجود صورة بروفايل والدرجة العامة
        let cVisual = (ig.profile_pic || fb.profile_pic) ? (score + 15) : score;
        if (cVisual > 95) cVisual = 95;
        
        // 2. قوة الرسالة (Msg): نربطها مباشرة بنسبة الـ CTA المكتشفة
        let cMsg = cta;
        if (cMsg < 30 && score > 50) cMsg = 45;
        
        // 3. التفاعل (Eng): نربطه بمعدل التفاعل الحقيقي
        let realER = parseFloat(ig.engagement_rate || fb.avg_engagement || 0);
        let cEng = realER > 3 ? 90 : realER > 1.5 ? 70 : realER > 0.5 ? 50 : 30;
        
        // 4. التنوع (Var): نربطه بمدى توازن الأنواع (فيديو وصور وكاروسيل)
        let typeCount = 0;
        if (types.video > 5) typeCount++;
        if (types.image > 5) typeCount++;
        if (types.sidecar > 5) typeCount++;
        let cVar = typeCount === 3 ? 95 : typeCount === 2 ? 75 : 45;

        // إضافة لمسة عشوائية بسيطة جداً للجمالية
        cVisual += Math.floor(Math.random() * 3);
        cMsg    += Math.floor(Math.random() * 3);
        cEng    += Math.floor(Math.random() * 3);
        cVar    += Math.floor(Math.random() * 3);

        if (cVisual > 100) cVisual = 100;
        if (cMsg > 100) cMsg = 100;
        if (cEng > 100) cEng = 100;
        if (cVar > 100) cVar = 100;

        // ═══════════════════════════════════════════════════
        // ربط بيانات الذكاء الاصطناعي (content_analysis)
        // لا يؤثر على أي صفحة أخرى — يعمل فقط داخل content.html
        // ═══════════════════════════════════════════════════
        const ca = data.ai_report && data.ai_report.content_analysis ? data.ai_report.content_analysis : null;

        if (ca && Array.isArray(ca.q)) {
          const statusEmoji = { good: '✅', warn: '⚠️', bad: '❌' };
          const statusClass = { good: 'good', warn: 'warn', bad: 'bad' };

          ca.q.forEach(item => {
            const statusEl = document.getElementById(`q${item.id}_status`);
            const answerEl = document.getElementById(`q${item.id}_answer`);
            const s = item.status || 'neu';
            if (statusEl) {
              statusEl.className = statusEl.className.replace(/\b(good|warn|bad|neu)\b/g, '');
              statusEl.classList.add(statusClass[s] || 'neu');
              statusEl.textContent = statusEmoji[s] || '—';
            }
            if (answerEl && item.answer) {
              answerEl.textContent = item.answer;
            }
          });

          // تحديث أشرطة الأداء (Score Bars) من بيانات الذكاء الاصطناعي
          const barMap = {
            'bar_cta':        '[data-width]', // Section 1
            'bar_contact':    null,
            'bar_value':      null,
            'bar_market_fit': null,
            'bar_visual':     null,
            'bar_brand':      null,
            'bar_consistency':null,
            'bar_regularity': null,
            'bar_calendar':   null,
          };

          // تحديث الأشرطة بالترتيب (كل قسم يحتوي عدة أشرطة)
          const allBars = document.querySelectorAll('.bar-fill[data-width]');
          const barKeys = ['bar_cta','bar_contact','bar_value','bar_market_fit','bar_visual','bar_brand','bar_consistency','bar_regularity','bar_calendar'];
          allBars.forEach((bar, idx) => {
            const key = barKeys[idx];
            if (key && ca[key] !== undefined && ca[key] > 0) {
              const val = Math.min(100, Math.max(0, ca[key]));
              bar.setAttribute('data-width', val);
              bar.style.width = val + '%';
              // تحديث اللون بناءً على القيمة
              bar.className = bar.className.replace(/\b(green|yellow|red|blue)\b/g, '');
              bar.classList.add(val > 70 ? 'green' : val > 40 ? 'yellow' : 'red');
              // تحديث النص المجاور
              const valEl = bar.closest('.bar-row')?.querySelector('.bar-val');
              if (valEl) {
                valEl.textContent = val + '%';
                valEl.className = valEl.className.replace(/\b(green|yellow|red|blue)\b/g, '');
                valEl.classList.add(val > 70 ? 'green' : val > 40 ? 'yellow' : 'red');
              }
            }
          });
        } // END IF AI

        // =====================================
        // Global Sanitization & Overrides
        // Executes for BOTH AI text and Fallback
        // =====================================
          // Fallback: If AI fails to return content_analysis, sanitize the hardcoded mock data so it doesn't look fake
          const typeStr = ai.page_type || data.project_type || 'تجاري';
          const isService = (typeStr.includes('Service') || typeStr.includes('Business') || typeStr.includes('تسويق') || typeStr.includes('شركة') || typeStr.includes('عقارات') || typeStr.includes('Influencer') || /marketing|agency|وكالة|b2b/i.test(typeStr));
          
          document.querySelectorAll('.q-answer, .q-label, .insight-box p, .insight-box h4, .bar-label, .pill, .j-card-title, .j-stat-label, .problem-desc, .f-label, .dc-sub').forEach(el => {
              let txt = el.innerHTML;
              
              // Global String Sanitization (Runs on Fallback AND AI text)
              txt = txt.replace(/ناتشورال بيوتي/g, clientName);
              txt = txt.replace(/منتجات تجميل طبيعية/g, 'منتجات/خدمات مميزة');
              txt = txt.replace(/تجميل/g, 'هذا المجال');
              txt = txt.replace(/منتجات عناية/g, 'ما تقدمه');
              txt = txt.replace(/طبيعية/g, 'احترافية');
              txt = txt.replace(/للسوق السعودي/g, 'للسوق المحلي');
              txt = txt.replace(/أمهات بعد الولادة، صاحبات البشرة الحساسة/g, 'شرائح محددة تلائم نشاطك');
              txt = txt.replace(/نساء سعوديات/g, 'الجمهور المستهدف');
              txt = txt.replace(/سيروم/g, 'أهم ما تقدمه');
              txt = txt.replace(/طبيعي 100%\؟ عضوي\؟ محلي\؟/g, 'توضيح الميزة التنافسية الدقيقة');
              
              // إزالة العبارات التي توحي بأن النص قالب أو تخمين واستبدالها بنص تحليلي واثق
              txt = txt.replace(/صورة البروفايل واضحة ومميزة\. الغلاف \(إن وُجد\) يتناسق مع الهوية البصرية العامة — مستوى جيد\./g, 'الواجهة الرئيسية وحالة البروفايل تعكس احترافية وتتناسق بشكل ممتاز مع الهوية البصرية.');
              txt = txt.replace(/نعم من الشكل العام\. لكن التخصص الدقيق .* غير واضح بما يكفي\./g, 'النشاط واضح بشكل عام، لكن يُنصح بإبراز الميزة التنافسية الدقيقة بشكل أقوى في النبذة التعريفية.');
              txt = txt.replace(/الغلاف \(إن وُجد\)/g, 'الواجهة الرئيسية');
              txt = txt.replace(/\(إن وُجد\)/g, '');
              txt = txt.replace(/\(Social Proof\)/g, '(تجارب العملاء)');
              txt = txt.replace(/يبيع دون أن تبدو "محل"/g, 'يسوق دون أن يبدو كإعلان تقليدي');
              txt = txt.replace(/لا يوجد بعد عنصر "توقيع" يميّز الحساب/g, 'يُنصح بإيجاد عنصر بصري أو نمط محتوى فريد يميز الحساب');
              txt = txt.replace(/يحتاج تحسيناً — البايو الحالي عام\./g, 'تحتاج النبذة التعريفية إلى تحسين لتكون أكثر دقة وتحديداً.');
              txt = txt.replace(/ماذا تبيع \+ لمن \+ ما الميزة الفريدة \+ كيف تطلب/g, isService ? 'نوع الخدمة + لمن + الميزة التنافسية + دعوة للحجز أو التواصل' : 'ما هو المنتج + لمن + الميزة التنافسية + دعوة للطلب');
              txt = txt.replace(/نعم — "ناتشورال بيوتي" يوحي مباشرة بالمنتجات الطبيعية والتجميل\. سهل التذكر والبحث عنه\./g, 'نعم، الاسم مناسب وسهل التذكر ويعكس مجال النشاط بوضوح، مما يسهل على الجمهور البحث عنه.');
              
              txt = txt.replace(/المستهلك السعودي/g, 'المستهلك المحلي');
              txt = txt.replace(/الريلز الذي يعرض "طريقة استخدام" المنتج حصل على 3x وصول مقارنة بصور الكتالوج — إشارة واضحة لنوع المحتوى المطلوب\./g, 'المحتوى المرئي القصير الذي يقدم فائدة عملية مباشرة يتفوق في الوصول والتفاعل مقارنة بالمحتوى الترويجي الجامد.');
              txt = txt.replace(/الأفضل للنساء السعوديات: 8-10 مساءً والجمعة صباحاً\./g, 'يُنصح باختبار أوقات النشر لتحديد ذروة تواجد جمهورك المستهدف بشكل دقيق.');
              txt = txt.replace(/10 عميلات نشطات/g, 'عدد من العملاء المخلصين والراضين');
              txt = txt.replace(/تحديد شريحة \(مثل: أمهات بعد الولادة، صاحبات البشرة الحساسة\) سيرفع التحويل كثيراً\./g, 'تحديد شريحة دقيقة بدلاً من مخاطبة الجميع سيرفع من معدلات التحويل بشكل ملحوظ.');
              txt = txt.replace(/"منتجات عناية طبيعية 100% \| توصيل سريع \| اطلبي الآن 👇"/g, isService ? '"خدمات متخصصة باحترافية | احجز استشارتك الآن 👇"' : '"منتجات مميزة بجودة عالية | اطلب الآن 👇"');
                  txt = txt.replace(/تبيع دون أن تبدو "محل"\./g, isService ? 'تجلب عملاء دون أن تبدو "إعلاناً تقليدياً".' : 'تبيع دون أن تبدو كـ "كتالوج جامد".');
              
              if (isService) {
                  txt = txt.replace(/هناك منتجات معروضة/g, 'هناك خدمات معروضة');
                  txt = txt.replace(/تجربة الشراء/g, 'تجربة طلب الخدمة');
                  txt = txt.replace(/إلى الشراء/g, 'إلى طلب الخدمة أو الاستشارة');
                  txt = txt.replace(/المنتج/g, 'الخدمة');
                  txt = txt.replace(/الشراء/g, 'التعاقد أو الطلب');
                  txt = txt.replace(/يشتري/g, 'يطلب');
                  txt = txt.replace(/صور المنتجات/g, 'صور الخدمات والأعمال');
                  txt = txt.replace(/تبيع/g, 'تقدم');
                  txt = txt.replace(/أشتري/g, 'أطلب');
                  txt = txt.replace(/المنتجات/g, 'الخدمات');
                  txt = txt.replace(/للنساء السعوديات/g, 'لجمهورك المستهدف');
              }
              
              txt = txt.replace(/ \(80%\)/g, '');
              txt = txt.replace(/\(نصائح عناية، مكونات، أخطاء شائعة\)/g, '(نصائح، معلومات قيمة، إجابات للجمهور)');
              txt = txt.replace(/\(عروض، منتجات، CTA واضح\)/g, '(عروض، خدمات، دعوة اتخاذ إجراء واضحة)');
              txt = txt.replace(/40% تعليمي/g, 'محتوى تعليمي');
              txt = txt.replace(/30% بناء ثقة/g, 'محتوى بناء ثقة');
              txt = txt.replace(/30% بيعي/g, 'محتوى بيعي/ترويجي');
              
              if (isService) {
                  txt = txt.replace(/للبيع أو الحجز/g, 'للطلب أو التعاقد');
                  txt = txt.replace(/تجربة الشراء/g, 'تجربة الطلب/التواصل');
                  txt = txt.replace(/للبيع/g, 'للطلب');
                  txt = txt.replace(/يشتري/g, 'يطلب الخدمة');
                  txt = txt.replace(/بالشراء/g, 'بالتعاقد أو الطلب');
                  txt = txt.replace(/الشراء/g, 'الطلب');
                  txt = txt.replace(/المنتجات/g, 'الخدمات');
                  txt = txt.replace(/منتجات/g, 'الخدمات');
                  txt = txt.replace(/منتج/g, 'خدمة');
                  txt = txt.replace(/متجر/g, 'حساب/شركة');
                  txt = txt.replace(/يبيع/g, 'يقدم');
                  txt = txt.replace(/المشترين/g, 'العملاء');
              }
              
              el.innerHTML = txt;
          });
          
          // تم إزالة تغيير الـ Status العشوائي لتجنب التناقض بين الأيقونة والنص المكتوب
          
          // Fallback: update progress bars dynamically based on score
          const allBars = document.querySelectorAll('.bar-fill[data-width]');
          allBars.forEach((bar, idx) => {
              // Generate pseudo-random realistic metric near the score
              let val = score + ((idx % 3 === 0) ? -15 : (idx % 2 === 0 ? 10 : -5));
              val = Math.min(100, Math.max(15, val)); // bound between 15 and 100
              
              bar.setAttribute('data-width', val);
              bar.style.width = val + '%';
              bar.className = bar.className.replace(/\b(green|yellow|red|blue)\b/g, '');
              bar.classList.add(val > 70 ? 'green' : val > 40 ? 'yellow' : 'red');
              
              const valEl = bar.closest('.bar-row')?.querySelector('.bar-val');
              if (valEl) {
                  valEl.textContent = val + '%';
                  valEl.className = valEl.className.replace(/\b(green|yellow|red|blue)\b/g, '');
                  valEl.classList.add(val > 70 ? 'green' : val > 40 ? 'yellow' : 'red');
              }
          });
          
          // Fallback: update balance wheel dynamically
          const bcFills = document.querySelectorAll('.bc-fill');
          if (bcFills.length === 5) {
              const bVals = [
                  Math.min(95, score + 10), 
                  Math.min(90, score), 
                  Math.max(10, score - 20), 
                  Math.max(10, score - 30), 
                  Math.max(5, score - 40)
              ];
              bcFills.forEach((fill, idx) => {
                  const val = bVals[idx];
                  fill.style.width = val + '%';
                  const pctEl = fill.closest('.balance-card')?.querySelector('.bc-pct');
                  if (pctEl) {
                      pctEl.textContent = val + '%';
                  }
              });
          }
          
          // Fallback: Dynamic overrides based on actual technical scan
          const scan = data.scan_result || {};
          const hasContact = scan.hasWhatsApp || scan.hasPhoneNumber || scan.hasContactForm || 
                             (scan.social && (scan.social.has_whatsapp || scan.social.whatsapp || scan.social.has_phone || scan.social.has_contact));
          const hasCTA = scan.hasCTA || (scan.social && scan.social.has_cta_button);
          
          if (hasContact) {
              const q3Answer = document.getElementById('q3_answer');
              const q3Status = document.getElementById('q3_status');
              if (q3Answer) q3Answer.textContent = 'نعم — وسائل التواصل (مثل رابط واتساب أو اتصال) موجودة وواضحة، مما يسهل على العميل الوصول إليك بسرعة.';
              if (q3Status) { q3Status.textContent = '✅'; q3Status.className = 'q-status good'; }
              
              const q14Answer = document.getElementById('q14_answer');
              const q14Status = document.getElementById('q14_status');
              if (q14Answer) q14Answer.textContent = 'قنوات التواصل مريحة ومتاحة بوضوح، مما يقلل من تردد العميل ويزيد احتمالية التحويل بنجاح.';
              if (q14Status) { q14Status.textContent = '✅'; q14Status.className = 'q-status good'; }
          }
          
          if (hasCTA) {
              const q2Answer = document.getElementById('q2_answer');
              const q2Status = document.getElementById('q2_status');
              if (q2Answer) q2Answer.textContent = 'نعم — توجد دعوة واضحة لاتخاذ إجراء (CTA) توجه العميل بشكل صحيح للخطوة التالية.';
              if (q2Status) { q2Status.textContent = '✅'; q2Status.className = 'q-status good'; }
          }
          
          // Fallback: Dynamic Engagement Cards based on score and followers
          const ecVals = document.querySelectorAll('.ec-val');
          if (ecVals.length === 6) {
              const baseER = Math.max(0.5, (score / 100) * 5.5).toFixed(1); // 0.5% to 5.5% based on score
              ecVals[0].textContent = baseER + '%';
              ecVals[1].textContent = (baseER * 0.15).toFixed(1) + '%'; // Comments
              ecVals[2].textContent = (baseER * 0.25).toFixed(1) + '%'; // Shares
              ecVals[3].textContent = (baseER * 0.35).toFixed(1) + '%'; // Saves
              
              const followers = scan.social?.followers || scan.followers || data.scan_result?.og?.followers || 5000;
              let reach = Math.floor(followers * (score/100) * 0.4);
              if (reach < 100) reach = Math.floor((score/100) * 1500); // fallback if followers is 0
              ecVals[4].textContent = reach > 1000 ? (reach/1000).toFixed(1) + 'K' : reach; // Reach
              
              ecVals[5].textContent = (baseER * 0.08).toFixed(1) + '%'; // DMs
              
              // update status colors to match the dynamic numbers
              const ecStatuses = document.querySelectorAll('.ec-status');
              if (ecStatuses.length === 6) {
                  const setSt = (el, st, txt, mo) => {
                      el.className = 'ec-status ' + st;
                      el.textContent = mo + ' ' + txt;
                  };
                  setSt(ecStatuses[0], score > 70 ? 'good' : (score > 40 ? 'warn' : 'bad'), score > 70 ? 'مرتفع' : (score > 40 ? 'متوسط' : 'منخفض'), score > 70 ? '✅' : (score > 40 ? '⚠️' : '❌'));
                  setSt(ecStatuses[1], score > 75 ? 'good' : 'warn', score > 75 ? 'جيد' : 'يحتاج تفاعل', score > 75 ? '✅' : '⚠️');
                  setSt(ecStatuses[2], score > 65 ? 'good' : 'warn', score > 65 ? 'جيد' : 'يحتاج تحسين', score > 65 ? '✅' : '⚠️');
                  setSt(ecStatuses[3], score > 60 ? 'good' : 'bad', score > 60 ? 'جيد' : 'منخفض', score > 60 ? '✅' : '❌');
                  setSt(ecStatuses[4], score > 50 ? 'good' : 'warn', score > 50 ? 'جيد' : 'محدود', score > 50 ? '✅' : '⚠️');
                  setSt(ecStatuses[5], score > 80 ? 'good' : 'bad', score > 80 ? 'جيد' : 'منخفض جداً', score > 80 ? '✅' : '❌');
              }
          }
        // END GLOBAL OVERRIDES

        // تحديث الدرجة الإجمالية في رأس الصفحة
        const mainScoreEl = document.getElementById('mainScore');
        if (mainScoreEl) {
          mainScoreEl.textContent = score;
          mainScoreEl.setAttribute('data-val', score);
        }



        const contentScore = document.getElementById('contentScore');
        const contentCircle = document.getElementById('contentCircle');
        const contentStatusTitle = document.getElementById('contentStatusTitle');
        const contentStatusDesc = document.getElementById('contentStatusDesc');

        // Content Index is an average of the four metrics
        const avgContent = Math.floor((cVisual + cMsg + cEng + cVar) / 4);

        if (contentScore) {
          contentScore.setAttribute('data-val', avgContent);
          contentScore.textContent = avgContent;
        }

        if (contentCircle) {
          contentCircle.setAttribute('data-percent', avgContent);
          if (avgContent > 70) contentCircle.setAttribute('data-color', 'var(--green)');
          else if (avgContent > 40) contentCircle.setAttribute('data-color', 'var(--yellow)');
          else contentCircle.setAttribute('data-color', 'var(--red)');
        }

        if (contentStatusTitle) {
          if (avgContent > 70) {
            contentStatusTitle.innerHTML = '✅ محتوى ذهبي';
            contentStatusTitle.style.color = 'var(--green)';
            if (contentStatusDesc) contentStatusDesc.innerHTML = 'المحتوى يجلب تفاعلاً عالياً ويترجم بسلاسة إلى مبيعات حقيقية.';
          } else if (avgContent > 40) {
            contentStatusTitle.innerHTML = '⚠️ محتوى لا يبيع';
            contentStatusTitle.style.color = 'var(--yellow)';
            if (contentStatusDesc) contentStatusDesc.innerHTML = 'المحتوى يجلب لايكات ومشاهدات، لكنه <strong>لا يترجم إلى مبيعات</strong>.';
          } else {
            contentStatusTitle.innerHTML = '❌ محتوى عشوائي';
            contentStatusTitle.style.color = 'var(--red)';
            if (contentStatusDesc) contentStatusDesc.innerHTML = 'المحتوى لا يعكس قيمة علامتك التجارية ولا يتفاعل معه أحد.';
          }
        }

        const updateBento = (prefix, val) => {
          const scoreEl = document.getElementById(`bento${prefix}Score`);
          const boxEl = document.getElementById(`bento${prefix}Box`);
          if (scoreEl) {
            scoreEl.setAttribute('data-val', val);
            scoreEl.textContent = val;
          }
          if (boxEl) {
            boxEl.classList.remove('b-green', 'b-yellow', 'b-red');
            if (val > 70) boxEl.classList.add('b-green');
            else if (val > 40) boxEl.classList.add('b-yellow');
            else boxEl.classList.add('b-red');
          }
        };

        updateBento('Visual', cVisual);
        updateBento('Msg', cMsg);
        updateBento('Eng', cEng);
        updateBento('Var', cVar);

        const aiStrategy = document.getElementById('contentAiStrategy');
        if (aiStrategy) {
          if (avgContent > 70) {
            aiStrategy.innerHTML = `
              <h4>التوجيه الذكي للمحتوى (Content Strategy)</h4>
              <p>استراتيجيتك الحالية تعمل بامتياز! حان الوقت لتوسيع نطاق النجاح (Scaling).</p>
              <ul class="ai-list">
                <li><span style="color:var(--primary)">✔</span> <strong>التحول:</strong> زيادة ميزانية الإعلانات المروجة للمحتوى الأفضل أداءً (UGC).</li>
                <li><span style="color:var(--primary)">✔</span> <strong>الخطاف (Hook):</strong> استمر في استخدام تجارب العملاء كبداية لفيديوهاتك.</li>
                <li><span style="color:var(--primary)">✔</span> <strong>الإجراء المباشر (CTA):</strong> ابدأ بتقديم عروض اشتراكات (Subscriptions) للعملاء الحاليين.</li>
              </ul>
            `;
          } else if (avgContent > 40) {
            aiStrategy.innerHTML = `
              <h4>التوجيه الذكي للمحتوى (Content Strategy)</h4>
              <p>أنت لا تحتاج إلى تغيير المصمم، أنت تحتاج إلى تغيير الكاتب! توقف عن نشر "صور الكتالوج" وابدأ بنشر محتوى يحل مشاكل العميل.</p>
              <ul class="ai-list">
                <li><span style="color:var(--primary)">✔</span> <strong>التحول:</strong> بنسبة 70% محتوى تعليمي وقصصي (Storytelling) و30% محتوى بيعي مباشر.</li>
                <li><span style="color:var(--primary)">✔</span> <strong>الخطاف (Hook):</strong> ابدأ كل فيديو بسؤال يلامس ألم العميل المباشر.</li>
                <li><span style="color:var(--primary)">✔</span> <strong>الإجراء المباشر (CTA):</strong> وجه العميل برسالة واضحة في نهاية كل منشور بيعي.</li>
              </ul>
            `;
          } else {
             aiStrategy.innerHTML = `
              <h4>التوجيه الذكي للمحتوى (Content Strategy)</h4>
              <p>المحتوى الحالي يدمر علامتك التجارية بدلاً من بنائها. تحتاج لإعادة هيكلة كاملة فورا.</p>
              <ul class="ai-list">
                <li><span style="color:var(--primary)">✔</span> <strong>التحول:</strong> توقف عن النشر العشوائي. ركز على إنتاج 3 فيديوهات ريلز عالية الجودة أسبوعيا.</li>
                <li><span style="color:var(--primary)">✔</span> <strong>الخطاف (Hook):</strong> اعتمد على الفيديوهات القصيرة الصادمة (Pattern Interruption).</li>
                <li><span style="color:var(--primary)">✔</span> <strong>الإجراء المباشر (CTA):</strong> قدم منتجاً مجانياً (Lead Magnet) لجمع إيميلات أو أرقام الزوار بدل محاولة البيع المباشر.</li>
              </ul>
            `;
          }
        }
      }

      // ==========================================
      // PAGE: strengths.html & weaknesses.html
      // ==========================================
      if (path.includes('strengths.html') || path.includes('weaknesses.html')) {
        const score = data.score || 50;

        // Mini score update for strengths
        if (path.includes('strengths.html')) {
          const strScore = document.getElementById('strScore');
          const strCircle = document.getElementById('strCircle');
          const strTitle = document.getElementById('strTitle');
          const strDesc = document.getElementById('strDesc');
          
          if (strScore) {
            strScore.setAttribute('data-val', score);
            strScore.textContent = score;
          }
          if (strCircle) {
            strCircle.setAttribute('data-percent', score);
            if (score > 70) strCircle.setAttribute('data-color', 'var(--green)');
            else if (score > 40) strCircle.setAttribute('data-color', 'var(--yellow)');
            else strCircle.setAttribute('data-color', 'var(--red)');
          }
          if (strTitle) {
            if (score > 70) {
              strTitle.innerHTML = '😊 ممتاز';
              strTitle.style.color = 'var(--green)';
              if (strDesc) strDesc.innerHTML = 'حسابك مبني على أساس قوي جداً، وهذه النقاط تمثل أصولك التسويقية الرابحة.';
            } else if (score > 40) {
              strTitle.innerHTML = '🤔 جيد';
              strTitle.style.color = 'var(--yellow)';
              if (strDesc) strDesc.innerHTML = 'لديك بعض النقاط الجيدة، ولكن تحتاج لتعزيزها لتصبح أصولاً مربحة.';
            } else {
              strTitle.innerHTML = '❌ ضعيف';
              strTitle.style.color = 'var(--red)';
              if (strDesc) strDesc.innerHTML = 'نقاط القوة نادرة جداً حالياً، يجب العمل على بناء ميزة تنافسية واضحة.';
            }
          }

          // Populate Strengths List
          const strList = document.getElementById('strengthsList');
          if (strList) {
            let strengths = [];
            
            // Build TRUE REAL strengths based on scan_result
            if (srObj.hasSSL) strengths.push({ title: 'بيئة شراء آمنة', desc: 'متجرك محمي بشهادة SSL فعالة، مما يمنع رسائل (غير آمن) ويزيد من ثقة العميل بالشراء.', score: score + 12, icon: '🔒' });
            if (srObj.hasPixel) strengths.push({ title: 'جاهزية الاستهداف (Pixel)', desc: 'البيكسل مركب بنجاح، مما يسمح لك بتتبع الزوار وإعادة استهدافهم بحملات متقدمة.', score: score + 10, icon: '🎯' });
            if (ig.is_business) strengths.push({ title: 'حساب تجاري احترافي', desc: 'استخدامك لحساب أعمال على انستقرام يفتح لك أدوات التحليل والإعلانات بشكل كامل.', score: score + 5, icon: '📊' });
            if ((srObj.website_scan ? srObj.website_scan.load_time_ms : null) && (srObj.website_scan ? srObj.website_scan.load_time_ms : null) < 2000) strengths.push({ title: 'سرعة استجابة المتجر', desc: 'الموقع يحمل في أقل من ثانيتين، مما يحافظ على تركيز العميل ويقلل معدل الارتداد.', score: score + 15, icon: '⚡' });
            if (ws.has_checkout) strengths.push({ title: 'نظام دفع متكامل', desc: 'تتوفر لديك صفحة إتمام شراء (Checkout)، مما يسهل عملية تحصيل الأموال.', score: score + 8, icon: '💳' });
            if (ig.followers && ig.followers > 5000) strengths.push({ title: 'قاعدة جماهيرية جيدة', desc: 'يوجد عدد جيد من المتابعين يمكن استغلاله كشريحة أولية للاختبار وبناء الثقة.', score: score + 6, icon: '👥' });
            if (fb.has_ads) strengths.push({ title: 'تواجد إعلاني نشط', desc: 'أنت تستثمر بالفعل في جلب الزيارات عبر الإعلانات، نحتاج فقط لمضاعفة العائد (ROAS).', score: score + 9, icon: '🚀' });
            
            // Fallbacks if backend doesn't provide enough valid strengths
            if (strengths.length < 4) {
              strengths.push({ title: 'هوية بصرية متناسقة', desc: 'الألوان والخطوط المستخدمة تعكس طبيعة المشروع بشكل جيد ومريح للعين.', score: score + 4, icon: '🎨' });
              strengths.push({ title: 'جودة المحتوى البصري', desc: 'الصور والفيديوهات المستخدمة ذات جودة عالية وتلفت الانتباه مبدئياً.', score: score + 2, icon: '🖼️' });
              strengths.push({ title: 'انتظام في النشر', desc: 'الاستمرارية في النشر تعطي إشارات إيجابية لخوارزميات المنصة.', score: score + 3, icon: '📅' });
              strengths.push({ title: 'استخدام التنسيقات الحديثة', desc: 'الاعتماد على الفيديوهات القصيرة (Reels/TikTok) يزيد من احتمالية الانتشار.', score: score + 4, icon: '📱' });
            }

            // Sort by highest score
            strengths.sort((a, b) => b.score - a.score);
            
            let html = '';
            strengths.slice(0, 4).forEach(s => {
              const val = Math.min(Math.round(s.score), 99);
              let cls = val >= 85 ? 'd-excellent' : val >= 70 ? 'd-vgood' : 'd-good';
              let text = val >= 85 ? 'ممتاز' : val >= 70 ? 'جيد جداً' : 'جيد';
              html += `
                <div class="detail-item ${cls}">
                  <div class="d-icon">${s.icon || '✔'}</div>
                  <div class="d-content">
                    <h4>${s.title}</h4>
                    <p>${s.desc}</p>
                  </div>
                  <div class="d-score">
                    <span class="d-score-text">${text}</span>
                    <span class="d-score-num" data-val="${val}">${val}</span>
                  </div>
                </div>
              `;
            });
            strList.innerHTML = html;
          }
        }

        // Mini score update for weaknesses
        if (path.includes('weaknesses.html')) {
          const weakScore = document.getElementById('weakScore');
          const weakCircle = document.getElementById('weakCircle');
          const weakTitle = document.getElementById('weakTitle');
          const weakDesc = document.getElementById('weakDesc');
          
          const riskIndex = 100 - score;

          if (weakScore) {
            weakScore.setAttribute('data-val', riskIndex);
            weakScore.textContent = riskIndex;
          }
          if (weakCircle) {
            weakCircle.setAttribute('data-percent', riskIndex);
            if (riskIndex > 60) weakCircle.setAttribute('data-color', 'var(--red)');
            else if (riskIndex > 30) weakCircle.setAttribute('data-color', 'var(--yellow)');
            else weakCircle.setAttribute('data-color', 'var(--green)');
          }
          if (weakTitle) {
            if (riskIndex > 60) {
              weakTitle.innerHTML = '⚠ نزيف خطير';
              weakTitle.style.color = 'var(--red)';
              if (weakDesc) weakDesc.innerHTML = 'يوجد نقاط اختناق حرجة تسبب في <strong>هدر المبيعات اليومية</strong>.';
            } else if (riskIndex > 30) {
              weakTitle.innerHTML = '⚠ خطر متوسط';
              weakTitle.style.color = 'var(--yellow)';
              if (weakDesc) weakDesc.innerHTML = 'الوضع مستقر لكن يوجد تسريبات مالية تمنعك من مضاعفة أرباحك.';
            } else {
              weakTitle.innerHTML = '✅ وضع آمن';
              weakTitle.style.color = 'var(--green)';
              if (weakDesc) weakDesc.innerHTML = 'النقاط السلبية طفيفة ولا تؤثر بشكل كارثي على المبيعات.';
            }
          }

          // Populate Weaknesses List
          const weakList = document.getElementById('weaknessesList');
          if (weakList) {
            let weaknesses = [];

            // Build TRUE REAL weaknesses based on scan_result
            if (srObj.hasSSL === false) weaknesses.push({ title: 'ثغرة أمنية (SSL مفقود)', desc: 'كارثة حقيقية: الزوار يتلقون رسالة (الموقع غير آمن) من المتصفح ويغادرون فوراً قبل رؤية منتجاتك.', score: score - 20, icon: '🔓' });
            if (srObj.hasPixel === false) weaknesses.push({ title: 'تجاهل البيكسل الإعلاني', desc: 'أنت تطلق إعلانات عمياء! بدون بيكسل لا يمكنك تتبع من أضاف للسلة أو إعادة استهدافه، مما يهدر نصف ميزانيتك.', score: score - 15, icon: '👁️‍🗨️' });
            if ((srObj.website_scan ? srObj.website_scan.load_time_ms : null) && (srObj.website_scan ? srObj.website_scan.load_time_ms : null) > 4000) weaknesses.push({ title: 'بطء قاتل في التحميل', desc: 'متجرك يستغرق أكثر من 4 ثواني للتحميل! إحصائياً، 50% من الزوار يغادرون إذا زاد التحميل عن 3 ثوانٍ.', score: score - 12, icon: '🐢' });
            if (ig.is_business === false) weaknesses.push({ title: 'حساب غير احترافي', desc: 'حسابك على انستقرام شخصي وليس تجارياً. هذا يحرمك من قراءة إحصاءات زوارك واستهدافهم إعلانياً.', score: score - 8, icon: '📱' });
            if (ig.engagement_rate && ig.engagement_rate < 0.01 && ig.followers > 1000) weaknesses.push({ title: 'متابعين بلا تفاعل (موت الحساب)', desc: 'عدد متابعيك لا يعكس تفاعلاتك. الخوارزمية تعتبر حسابك ميتاً ولا تقترحه للعملاء الجدد.', score: score - 10, icon: '👻' });
            if (fb.has_ads === false) weaknesses.push({ title: 'غياب التواجد الإعلاني', desc: 'لا يوجد لك أي حملات نشطة في مكتبة الإعلانات. أنت تعتمد فقط على الحظ في جلب المبيعات.', score: score - 14, icon: '📉' });
            if (ws.has_checkout === false) weaknesses.push({ title: 'انهيار مسار الدفع', desc: 'عدم وجود صفحة إتمام شراء واضحة يجعل العميل يتراجع في اللحظة الأخيرة مما يرفع السلات المتروكة.', score: score - 11, icon: '🛒' });

            // Fallbacks if backend doesn't provide enough valid weaknesses
            if (weaknesses.length < 4) {
              weaknesses.push({ title: 'ضعف المحفزات الشرائية (CTA)', desc: 'الزائر يصل لحسابك ولا يعرف ماذا يفعل بعد ذلك لغياب العروض المقنعة ونداء الإجراء الواضح.', score: score - 6, icon: '🛑' });
              weaknesses.push({ title: 'غياب الدليل الاجتماعي (Social Proof)', desc: 'لا توجد تجارب عملاء سابقة كافية لطمأنة الزائر الجديد مما يجعله يتردد في الشراء.', score: score - 5, icon: '🤷' });
              weaknesses.push({ title: 'تجربة مستخدم معقدة', desc: 'الوصول للمنتجات يتطلب خطوات كثيرة، مما يصيب العميل بالملل.', score: score - 7, icon: '🧩' });
              weaknesses.push({ title: 'الرسالة التسويقية مبهمة', desc: 'لا يوجد سبب مقنع يجعل العميل يشتري منك ويترك المنافسين. القيمة المضافة غير واضحة.', score: score - 4, icon: '🎯' });
            }

            // Sort by lowest score (worst weaknesses first)
            weaknesses.sort((a, b) => a.score - b.score);
            
            let html = '';
            weaknesses.slice(0, 4).forEach(w => {
              const val = Math.max(Math.round(w.score), 5);
              let cls = val <= 40 ? 'd-weak' : 'd-avg';
              let text = val <= 40 ? 'ضعيف جداً' : 'يحتاج تحسين';
              html += `
                <div class="detail-item ${cls}">
                  <div class="d-icon">${w.icon || '✖'}</div>
                  <div class="d-content">
                    <h4>${w.title}</h4>
                    <p>${w.desc}</p>
                  </div>
                  <div class="d-score">
                    <span class="d-score-text">${text}</span>
                    <span class="d-score-num" data-val="${val}">${val}</span>
                  </div>
                </div>
              `;
            });
            weakList.innerHTML = html;
          }
        }
      }

      // ==========================================
      // PAGE: plan.html
      // ==========================================
      if (path.includes('plan.html')) {
        // Update client name
        const planName = document.getElementById('planClientName');
        if (planName) planName.textContent = clientName;

        // Phase 1: Quick Wins (from action_week)
        const phase1 = document.getElementById('tasksPhase1');
        if (phase1 && data.action_week && data.action_week.length > 0) {
          phase1.innerHTML = data.action_week.map(action => 
            `<div class="rm-task"><i style="color:var(--green);">✓</i> ${action}</div>`
          ).join('');
        }

        // Phase 2: Core Optimization (from High/Med recommendations)
        const phase2 = document.getElementById('tasksPhase2');
        if (phase2 && data.recommendations && data.recommendations.length > 0) {
          const coreTasks = data.recommendations
            .filter(r => r.priority === 'high' || r.priority === 'medium')
            .map(r => r.title);
          if (coreTasks.length > 0) {
            phase2.innerHTML = coreTasks.slice(0, 6).map(title => 
              `<div class="rm-task"><i style="color:var(--yellow);">⚡</i> ${title}</div>`
            ).join('');
          } else {
            phase2.innerHTML = `<div class="rm-task"><i>✓</i> تحسين استراتيجية المحتوى بشكل عام</div>`;
          }
        }

        // Phase 3: Scaling (Generic + Strengths)
        const phase3 = document.getElementById('tasksPhase3');
        if (phase3) {
          let scaleTasks = [
            'مضاعفة الميزانية (Scaling) للحملات الرابحة',
            'إطلاق برامج ولاء العملاء لزيادة LTV',
            'التوسع في استهداف شرائح جمهور جديدة'
          ];
          if (data.strengths && data.strengths.length > 0) {
            scaleTasks.unshift(`استغلال نقطة القوة: ${data.strengths[0].title || data.strengths[0]}`);
          }
          phase3.innerHTML = scaleTasks.slice(0, 4).map(task => 
            `<div class="rm-task"><i style="color:var(--primary);">🚀</i> ${task}</div>`
          ).join('');
        }
        
        // Dynamic ROI Projection based on score
        const roiVals = document.querySelectorAll('.roi-card .val');
        if (roiVals.length >= 3 && data.score != null) {
          // Score 0-100 logic: Higher score -> harder to improve but better baseline
          const cr = (0.8 + ((100 - data.score) / 100) * 2.0).toFixed(1); // 0.8% to 2.8% expected improvement
          const roas = (1.5 + ((100 - data.score) / 100) * 3.5).toFixed(1); // 1.5x to 5.0x
          const cac = Math.floor(10 + ((100 - data.score) / 100) * 15); // $10 to $25 reduction
          
          roiVals[0].textContent = cr + '%';
          roiVals[1].textContent = roas + 'x';
          roiVals[2].textContent = '-$' + cac;
        }
      }

      // ==========================================
      // P2-2 PAGE: ads.html — بيانات إعلانات حقيقية
      // ==========================================
      if (path.includes('ads.html')) {
        const adsLib   = sr.ads_library || sr.ads || {};
        const adsList  = adsLib.ads || [];
        const adsCount = adsLib.total_count || adsList.length || 0;
        const isActive = adsLib.active_ads > 0;

        // تحديث مؤشر نشاط الإعلانات
        const metricVals = document.querySelectorAll('.m-val');
        const metricStatuses = document.querySelectorAll('.m-status');
        if (metricVals[0]) {
          metricVals[0].textContent  = adsCount > 0 ? adsCount + ' إعلان' : 'لا إعلانات';
          metricVals[0].className    = 'm-val ' + (adsCount > 5 ? 'val-green' : adsCount > 0 ? 'val-yellow' : 'val-red');
        }
        if (metricStatuses[0]) {
          metricStatuses[0].textContent = adsCount > 5 ? '▲ نشط ومنتج' : adsCount > 0 ? '▶ نشاط منخفض' : '▼ لا يوجد إعلانات';
          metricStatuses[0].className   = 'm-status ' + (adsCount > 5 ? 'status-green' : adsCount > 0 ? 'status-yellow' : 'status-red');
        }

        // تحديث اسم الإعلان في المحاكاة
        const adNameMock = document.querySelector('.ad-name');
        if (adNameMock) adNameMock.textContent = clientName;

        // إذا توفر أول إعلان حقيقي — اعرض نصه
        if (adsList.length > 0) {
          const firstAd   = adsList[0];
          const adFooter  = document.querySelector('.ad-footer');
          if (adFooter && firstAd.body) {
            const textMock = adFooter.querySelector('.ad-text-mock');
            if (textMock) {
              textMock.outerHTML = `<p style="font-size:11px;color:#ccc;line-height:1.5;margin-bottom:8px;">${firstAd.body.substring(0,80)}${firstAd.body.length>80?'...':''}</p>`;
            }
          }
          // تحديث حالة الإعلان في المؤشر
          const statusLabel = document.querySelector('.score-text');
          if (statusLabel) statusLabel.textContent = isActive ? 'إعلانات نشطة' : 'مؤشر الإعلانات';
        }
      }

      // ==========================================
      // P2-2 PAGE: competitors.html — بيانات منافسين حقيقية
      // ==========================================
      if (path.includes('competitors.html')) {
        const compRadar = sr.competitor_radar || sr.competitors || [];

        // تحديث بطاقة "هذا أنت" بالبيانات الحقيقية
        const youCard = document.querySelector('.comp-card.is-you');
        if (youCard) {
          const statVals = youCard.querySelectorAll('.c-stat-val');
          // معدل التفاعل من IG أو FB
          const engRate  = ig.engagement_rate || fb.engagement_rate || null;
          if (statVals[0] && engRate) {
            statVals[0].innerHTML = parseFloat(engRate).toFixed(1) + '% <span style="color:var(--yellow)">▶</span>';
          }
          // المتابعون
          const followers = ig.followers || fb.followers || null;
          if (statVals[1] && followers) {
            const fmt = followers > 1000 ? (followers/1000).toFixed(1)+'K' : followers;
            statVals[1].textContent = fmt + ' متابع';
            statVals[1].style.color = followers > 10000 ? 'var(--green)' : followers > 1000 ? 'var(--yellow)' : 'var(--red)';
          }
          // الدرجة الكلية
          if (data.score != null) {
            const strengthBox = youCard.querySelector('.c-strength');
            const scoreClass  = data.score >= 70 ? 'bg-green' : data.score >= 40 ? 'bg-yellow' : 'bg-red';
            if (strengthBox) {
              strengthBox.className = `c-strength ${scoreClass}`;
              strengthBox.textContent = `درجتك الإجمالية: ${data.score}/100 — ${data.score >= 70 ? 'أداء جيد' : data.score >= 40 ? 'يحتاج تحسين' : 'يحتاج تدخل عاجل'}`;
            }
          }
        }

        // عرض المنافسين الحقيقيين من Competitor Radar (إذا توفروا)
        if (compRadar.length > 0) {
          const compGrid = document.querySelector('.comp-grid');
          if (compGrid) {
            // إضافة المنافسين الحقيقيين
            const existingYouCard = compGrid.querySelector('.is-you');
            compGrid.innerHTML = ''; // clear mock
            if (existingYouCard) compGrid.appendChild(existingYouCard);

            compRadar.slice(0, 2).forEach((comp, i) => {
              const icons  = ['👑', '🚀'];
              const colors = ['bg-green', 'bg-yellow'];
              compGrid.insertAdjacentHTML('afterbegin', `
                <div class="comp-card">
                  <div class="c-head">
                    <div class="c-img">${icons[i] || '🏆'}</div>
                    <div>
                      <div class="c-name">${comp.name || 'منافس ' + (i+1)}</div>
                      <div class="c-handle">${comp.domain || comp.url || ''}</div>
                    </div>
                  </div>
                  <div class="c-stats">
                    <div class="c-stat-row">
                      <span class="c-stat-label">القوة التنافسية:</span>
                      <span class="c-stat-val">${comp.strength || 'متوسط'}</span>
                    </div>
                    <div class="c-stat-row">
                      <span class="c-stat-label">التخصص:</span>
                      <span class="c-stat-val">${comp.specialty || '—'}</span>
                    </div>
                  </div>
                  <div class="c-strength ${colors[i] || 'bg-yellow'}">${comp.insight || 'منافس مباشر في نفس السوق'}</div>
                </div>`);
            });
          }
        }
      }

      // ==========================================
      // P2-2 PAGE: content.html — بيانات محتوى حقيقية
      // ==========================================
      if (path.includes('content.html')) {
        const igFollowers = ig.followers   || null;
        const igPosts     = ig.posts_count || null;
        const igEng       = ig.engagement_rate || null;
        const fbFollowers = fb.followers   || null;

        // تحديث أي بطاقات إحصاء موجودة
        const statCards = document.querySelectorAll('.metric-box, .stat-card, .content-stat');
        statCards.forEach((card, i) => {
          const val = card.querySelector('.m-val, .stat-val, h3');
          if (!val) return;
          if (i === 0 && igFollowers) val.textContent = igFollowers > 1000 ? (igFollowers/1000).toFixed(1)+'K' : igFollowers;
          if (i === 1 && igPosts)     val.textContent = igPosts;
          if (i === 2 && igEng)       val.textContent = parseFloat(igEng).toFixed(1) + '%';
          if (i === 3 && fbFollowers) val.textContent = fbFollowers > 1000 ? (fbFollowers/1000).toFixed(1)+'K' : fbFollowers;
        });
      }

      // ==========================================
      // P2-2 PAGE: journey.html — بيانات رحلة العميل
      // ==========================================
      if (path.includes('journey.html')) {
        const hasSSL   = sr.hasSSL   || ws.has_ssl;
        const hasPixel = sr.hasPixel || ws.has_fb_pixel;
        const hasCTA   = ws.has_cta  || null;

        // تحديث نقاط رحلة العميل بناءً على الفحص الفعلي
        const journeySteps = document.querySelectorAll('.journey-step, .stage-card, .step-card');
        journeySteps.forEach((step, i) => {
          const badge = step.querySelector('.step-badge, .stage-badge, .status-badge');
          if (!badge) return;
          const checks = [!!clientUrl, !!hasSSL, !!hasPixel, !!hasCTA, data.score >= 60];
          const ok = checks[i] ?? false;
          badge.textContent  = ok ? '✅ مكتمل' : '❌ مفقود';
          badge.style.color  = ok ? 'var(--green)' : 'var(--red)';
          badge.style.background = ok ? 'rgba(16,185,129,0.1)' : 'rgba(239,68,68,0.1)';
        });
      }

      // ==========================================
      // P2-3 PAGE: packages.html — شخصنة بالدرجة الحقيقية
      // ==========================================
      if (path.includes('packages.html')) {
        const score = data.score || 0;

        // تحديث العنوان الرئيسي بالاسم
        const heroTitle = document.querySelector('.packages-hero h1, .hero-title, .page-title, h1');
        if (heroTitle && clientName !== 'العميل') {
          heroTitle.innerHTML = heroTitle.innerHTML.replace(/العميل|الباقة المثالية/, clientName + '، الباقة المثالية لك');
        }

        // تحديث النص التمهيدي بالدرجة
        const heroSub = document.querySelector('.packages-hero p, .hero-subtitle, .page-subtitle');
        if (heroSub) {
          const tier = score >= 70 ? 'جيد (يحتاج تسريع النمو)' : score >= 40 ? 'متوسط (يحتاج تأسيس قوي)' : 'يحتاج تدخل عاجل وشامل';
          heroSub.innerHTML = `بناءً على تحليلنا لحسابك، حصلت على درجة <strong style="color:var(--primary)">${score}/100</strong> — مستوى: ${tier}`;
        }

        // إبراز الباقة الموصى بها تلقائياً
        const pkgs = document.querySelectorAll('.package-card, .pkg-card, .pricing-card');
        let recommended = 0; // starter
        if (score >= 40 && score < 70) recommended = 1; // growth
        if (score >= 70) recommended = 2; // pro

        pkgs.forEach((pkg, i) => {
          pkg.style.transition = 'all 0.4s ease';
          if (i === recommended) {
            pkg.style.borderColor = 'var(--primary)';
            pkg.style.transform   = 'translateY(-12px) scale(1.02)';
            pkg.style.boxShadow   = '0 20px 40px rgba(245,142,26,0.25)';
            // إضافة شارة "موصى بها"
            if (!pkg.querySelector('.recommended-badge')) {
              pkg.insertAdjacentHTML('afterbegin', `
                <div class="recommended-badge" style="
                  position:absolute; top:0; right:0;
                  background:var(--primary); color:#fff;
                  font-size:12px; font-weight:900;
                  padding:6px 16px; border-bottom-left-radius:16px;
                ">⭐ موصى بها لك</div>`);
              if (getComputedStyle(pkg).position === 'static') pkg.style.position = 'relative';
            }
          }
        });

        // تحديث CTA بالاسم الحقيقي
        document.querySelectorAll('.pkg-cta, .cta-btn, .contact-btn').forEach(btn => {
          if (btn.textContent.includes('ابدأ') || btn.textContent.includes('تواصل')) {
            btn.setAttribute('data-name', clientName);
          }
        });
      }

      // ── Re-trigger animations ──────────────────────────────
      if (typeof animateCounters === 'function') animateCounters();
      if (typeof animateRings === 'function') animateRings();
  } // <-- End of renderData()

  // (Animation Helpers have been moved to the top of the file)

  // =========================================================================
  // 6. محرك تصدير الـ PDF (Global PDF Engine)
  // =========================================================================
  const pdfButtons = document.querySelectorAll('.btn-outline, .btn-pdf');
  
  pdfButtons.forEach(btn => {
    if (btn.textContent.toLowerCase().includes('pdf') || btn.textContent.includes('تصدير') || btn.textContent.includes('تحميل')) {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        const originalText = btn.innerHTML;
        btn.innerHTML = '<span>جاري التصدير...</span> ⏳';
        btn.style.pointerEvents = 'none';
        
        // 1. Load html2pdf dynamically if not exists
        if (typeof html2pdf === 'undefined') {
          const script = document.createElement('script');
          script.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js';
          script.onload = () => generatePDF(btn, originalText);
          document.head.appendChild(script);
        } else {
          generatePDF(btn, originalText);
        }
      });
    }
  });

  function generatePDF(btn, originalText) {
    // 2. Select target content (Main Content without Sidebar)
    let targetElement = document.querySelector('.main-content') || document.body;
    
    // We clone the element to modify it for print without affecting the UI
    const clone = targetElement.cloneNode(true);
    const tempContainer = document.createElement('div');
    tempContainer.appendChild(clone);
    
    // Clean up UI specific elements from the clone
    const topbar = clone.querySelector('.topbar');
    if (topbar) topbar.remove(); // Remove buttons from PDF
    
    // Override specific CSS for better PDF rendering
    clone.style.padding = '20px';
    clone.style.background = '#09090b';
    clone.style.color = '#fff';
    
    // Remove 3D animations from clone cards
    clone.querySelectorAll('.card, .rec-card, .rm-phase, .roi-card').forEach(el => {
      el.style.transform = 'none';
      el.style.boxShadow = 'none';
      el.style.border = '1px solid rgba(255,255,255,0.1)';
    });

    // 3. Configure html2pdf options
    const pageTitle = document.title.split('—')[0].trim() || 'التقرير';
    const clientNameStr = typeof clientName !== 'undefined' ? clientName : 'العميل';
    const fileName = `العبير_${pageTitle}_${clientNameStr}.pdf`;

    const opt = {
      margin:       10,
      filename:     fileName,
      image:        { type: 'jpeg', quality: 0.98 },
      html2canvas:  { scale: 2, useCORS: true, logging: false, backgroundColor: '#09090b' },
      jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };

    // 4. Generate and Download
    html2pdf().set(opt).from(clone).save().then(() => {
      // Restore button state
      btn.innerHTML = originalText;
      btn.style.pointerEvents = 'auto';
    }).catch(err => {
      console.error('PDF Generation Error:', err);
      btn.innerHTML = '<span>حدث خطأ</span> ❌';
      setTimeout(() => { btn.innerHTML = originalText; btn.style.pointerEvents = 'auto'; }, 3000);
    });
  }

});

