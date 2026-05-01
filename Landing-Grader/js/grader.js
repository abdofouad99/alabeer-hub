document.addEventListener('DOMContentLoaded', () => {

    // Screens
    const stepInput = document.getElementById('step-input');
    const stepScanning = document.getElementById('step-scanning');
    const stepQuiz = document.getElementById('step-quiz');
    const stepResults = document.getElementById('step-results');

    // Forms & Inputs
    const urlForm = document.getElementById('urlForm');
    const targetUrlInput = document.getElementById('targetUrl');
    const graderLeadForm = document.getElementById('graderLeadForm');
    
    // Scanner Elements
    const scanLine = document.getElementById('scanLine');
    const scanLogs = document.getElementById('scanLogs');
    const displayUrl = document.getElementById('displayUrl');

    // Quiz Elements
    const quizProgress = document.getElementById('quizProgress');
    const questionTitle = document.getElementById('questionTitle');
    const questionDesc = document.getElementById('questionDesc');
    const optionsGrid = document.getElementById('optionsGrid');

    // Results Elements
    const finalScoreVal = document.getElementById('finalScoreVal');
    const scoreMessage = document.getElementById('scoreMessage');
    const feedbackGrid = document.getElementById('feedbackGrid');
    const resultUrl = document.getElementById('resultUrl');
    const pdfUrlDisplay = document.getElementById('pdfUrlDisplay');
    const warningAlert = document.getElementById('warningAlert');

    let currentUrl = '';
    let currentQuestionIndex = 0;
    let userAnswers = [];
    let gaugeChart = null;

    // Database of Questions
    const questions = [
        {
            title: "السؤال 1: الوعد التسويقي (The Hook)",
            desc: "هل العنوان الرئيسي في أول صفحتك يركز على مميزات المنتج أم على حل مشكلة العميل العميقة؟",
            options: [
                { text: "يركز بقوة على حل ألم العميل ونتيجته النهائية المرجوة.", score: 12, feedback: "العنوان الرئيسي ممتاز، يخلق تواصلاً عاطفياً فورياً.", isGood: true },
                { text: "يركز على ميزات تقنية أو وصف مباشر للمنتج فقط.", score: 5, feedback: "العنوان يصف المنتج ولكنه يفشل في إثارة دافع الشراء العاطفي (سبب التسرب الأول).", isGood: false },
                { text: "مجرد اسم المنتج وسعره بأحرف كبيرة.", score: 0, feedback: "عنوانك كارثي، العميل لا يشتري المنتج، بل يشتري النتيجة، وهذه الصفحة تفتقد لذلك تماماً.", isGood: false }
            ]
        },
        {
            title: "السؤال 2: العرض البصري الأول (Hero Visuals)",
            desc: "ما هو أول شيء يراه الزائر تحت العنوان مباشرة؟",
            options: [
                { text: "فيديو أو صورة GIF عالية الجودة توضح المنتج أثناء الاستخدام القوي.", score: 12, feedback: "محتوى بصري قوي (UGC) يرفع نسبة التحويل فوراً.", isGood: true },
                { text: "صورة ثابتة للمنتج بخلفية بيضاء أو تصميم جرافيكي عادي.", score: 6, feedback: "الصور الثابتة لا تحفز الشراء العفوي مقارنة بالفيديوهات الحركية.", isGood: false },
                { text: "لا يوجد محتوى مرئي واضح، أو صور رديئة الجودة/مسروقة.", score: 0, feedback: "المحتوى المرئي الضعيف يدمر الثقة في علامتك التجارية فور دخول العميل.", isGood: false }
            ]
        },
        {
            title: "السؤال 3: الدليل الاجتماعي (Social Proof)",
            desc: "هل تعرض آراء أو تقييمات عملاء حقيقيين لإثبات جودة منتجك؟",
            options: [
                { text: "نعم، فيديوهات تقييم ومراجعات مصورة وحقيقية في منتصف الصفحة.", score: 13, feedback: "دليل اجتماعي لا يقهر، يزيل 80% من مخاوف المشتري الجديد.", isGood: true },
                { text: "مجرد نصوص مكتوبة (قابلة للتزييف) وتقييمات بالنجوم فقط.", score: 5, feedback: "نصوص التقييمات فقدت مصداقيتها اليوم، وتحتاج لتوثيق بصري أقوى.", isGood: false },
                { text: "لا أملك تقييمات واضحة في صفحة الهبوط حتى الآن.", score: 0, feedback: "غياب الدليل الاجتماعي (UGC) يجعل منتجك يبدو (مجهولاً ومخاطرة).", isGood: false }
            ]
        },
        {
            title: "السؤال 4: بناء العرض (The Offer)",
            desc: "كيف تقوم بتقديم (سعر وقيمة) منتجك للعميل؟",
            options: [
                { text: "وضّحت أن القيمة أعلى بكثير من السعر، وقدمت ميزة إضافية مجانية (Offer Stack).", score: 13, feedback: "هيكلة العرض (Offer Stacking) لا تقاوم وتلغي مقارنة العميل لمنتجك بالمنافسين.", isGood: true },
                { text: "كتبت (السعر القديم) مشطوباً و(السعر الجديد) فقط كخصم عادي.", score: 6, feedback: "طريقة التسعير تقليدية جداً ولا تخلق (ضرورة ملحة طارئة) للشراء الآن.", isGood: false },
                { text: "السعر موحد، مباشر، ولا يوجد أي خصم أو محفز للاستعجال.", score: 0, feedback: "عرض ميت تسويقياً، لا يمنح العميل سبباً لعدم التأجيل.", isGood: false }
            ]
        },
        {
            title: "السؤال 5: الاحتكاك وصعوبة الدفع (Friction)",
            desc: "كم حقلاً يحتاج العميل لملئه لكي يتمم عملية الشراء؟",
            options: [
                { text: "دفع سريع جداً (الاسم، الهاتف، والدفع المباشر Apple Pay/بطاقة).", score: 12, feedback: "عملية دفع (Frictionless) تضاعف مبيعاتك وتقلل العربات المتروكة.", isGood: true },
                { text: "يطلب منه التسجيل أحياناً، واسم المدينة، والحي، وغيرها.", score: 4, feedback: "كل حقل إضافي يقلل نسبة التحويل بـ 10%. طول صفحة الدفع يقتل رغبة الشراء.", isGood: false },
                { text: "مجرد زر ينقله لصفحة دفع أخرى طويلة خارج صفحة الهبوط.", score: 0, feedback: "تسريب ضخم للعملاء (Drop-off) بسبب كثرة التوجيهات من صفحة لأخرى.", isGood: false }
            ]
        },
        {
            title: "السؤال 6: سرعة تحميل الصفحة (Speed)",
            desc: "عندما تقوم بفتح صفحة الهبوط من (شبكة جوال 4G)، كم تستغرق للظهور؟",
            options: [
                { text: "تفتح فوراً (ثانيتين أو أقل).", score: 12, feedback: "سرعة تحميل ممتازة تحفظ العميل من الملل والمغادرة.", isGood: true },
                { text: "تأخذ حوالي 3 إلى 5 ثوانٍ بسبب الفيديوهات والصور.", score: 5, feedback: "كل ثانية تأخير بعد الـ 3 ثوانٍ تكلّفك 20% من مبيعاتك (بطء قاتل).", isGood: false },
                { text: "ثقيلة جداً أو لا أعرف أرقام السرعة بالضبط.", score: 0, feedback: "أداة قاتلة للمتجر: سرعة الصفحة البطيئة تدفع عملائك للهروب قبل رؤية عرضك.", isGood: false }
            ]
        },
        {
            title: "السؤال 7: معالجة الاعتراضات (Objections)",
            desc: "هل تتوقع الصفحة أسئلة وشكوك العميل الخفية وتجيب عليها مباشرة بحزم؟",
            options: [
                { text: "نعم، هناك قسم مدروس (سؤال وجواب) ومقارنات ضد المنافسين.", score: 13, feedback: "قسم معالجة الشكوك ممتاز، يغلق دائرة التردد ويمهد للدفع بثقة.", isGood: true },
                { text: "وضعت بعض المعلومات العشوائية ضمن وصف المنتج.", score: 5, feedback: "إخفاء إجابات الشكوك وسط وصف طويل لن يقرأه العميل المتشكك الكسول.", isGood: false },
                { text: "لا، العرض مباشر وأفترض أن المنتج يشرح نفسه.", score: 0, feedback: "أكبر خطأ استراتيجي.. العميل لديه 10 أعذار لئلا يشتري، وأنت لم ترد على أي منها.", isGood: false }
            ]
        },
        {
            title: "السؤال 8: ضمان إسقاط المخاطرة (Risk Reversal)",
            desc: "هل تقدم ضماناً مرئياً وعنيفاً يزيل عبء المخاطرة من قلب العميل؟",
            options: [
                { text: "ضمان ذهبي معلن لاسترداد الأموال 100% بدون شروط معقدة.", score: 13, feedback: "ضمان قوي (Risk Reversal) ينقل الخوف منك إلى العميل، مما يحفز التحويل.", isGood: true },
                { text: "شروط الإرجاع موجودة ولكنها قياسية أو معقدة في صفحة أخرى.", score: 5, feedback: "الضمان الخجول أو المعقد لا يقدم طمأنينة ويثير مزيداً من الارتياب.", isGood: false },
                { text: "لا يوجد ضمان معلن بصرياً بجوار أزرار الشراء المتكررة.", score: 0, feedback: "دعوة مكشوفة ومتهورة للعميل لدفع ماله بدون أي حماية نفسية، نسبة التحويل حجب تعاني.", isGood: false }
            ]
        }
    ];

    // Total possible score = 100

    urlForm.addEventListener('submit', (e) => {
        e.preventDefault();
        currentUrl = targetUrlInput.value.trim();
        if(!currentUrl) return;

        displayUrl.innerText = currentUrl;
        if(resultUrl) resultUrl.innerText = "الرابط: " + currentUrl;
        if(pdfUrlDisplay) pdfUrlDisplay.innerText = "الرابط: " + currentUrl;
        
        stepInput.classList.remove('active');
        stepScanning.classList.add('active');
        startSimulatedScan();
    });

    function startSimulatedScan() {
        const msgs = [
            "جاري فحص بروتوكولات الأمان (SSL)...",
            "تحليل سرعة الاستجابة (TTFB)...",
            "استخراج الهيكل البصري للـ Hero Section...",
            "تحليل الكلمات المفتاحية (Copywriting Hook)...",
            "فحص أزرار الدعوة للإجراء (Call to Actions)...",
            "اكتشاف نقاط تسرب المبيعات (Drop-Off Points)...",
            "تهيئة برمجية التحليل العضوي الذكي..."
        ];
        
        let c = 0;
        let p = 0;
        scanLogs.innerHTML = '';
        
        let intv = setInterval(() => {
            if(c < msgs.length) {
                const li = document.createElement('li');
                li.innerHTML = `<i class="fa-solid fa-bug" style="color:#FFCC00; margin-left:10px;"></i> [تحليل] ${msgs[c]}`;
                scanLogs.appendChild(li);
                c++;
            }
            p += 15;
            if(p > 100) p = 100;
            scanLine.style.width = p + '%';
            
            if (p >= 100 && c >= msgs.length) {
                clearInterval(intv);
                setTimeout(() => {
                    stepScanning.classList.remove('active');
                    startQuiz();
                }, 1000);
            }
        }, 600);
    }

    function startQuiz() {
        currentQuestionIndex = 0;
        userAnswers = [];
        stepQuiz.classList.add('active');
        renderQuestion();
    }

    function renderQuestion() {
        const q = questions[currentQuestionIndex];
        questionTitle.innerText = q.title;
        questionDesc.innerText = q.desc;
        
        // Progress
        const prog = ((currentQuestionIndex) / questions.length) * 100;
        quizProgress.style.width = prog + '%';
        
        optionsGrid.innerHTML = '';
        
        q.options.forEach((opt, idx) => {
            const btn = document.createElement('div');
            btn.className = 'option-btn';
            btn.innerHTML = `
                <div class="option-num">${String.fromCharCode(65 + idx)}</div>
                <div style="flex:1;">${opt.text}</div>
            `;
            btn.addEventListener('click', () => {
                userAnswers.push({
                    qTitle: q.title,
                    score: opt.score,
                    feedback: opt.feedback,
                    isGood: opt.isGood
                });
                
                currentQuestionIndex++;
                if(currentQuestionIndex < questions.length) {
                    renderQuestion();
                } else {
                    finishQuiz();
                }
            });
            optionsGrid.appendChild(btn);
        });
    }

    function finishQuiz() {
        stepQuiz.classList.remove('active');
        
        // Calculate Total Score
        let totalScore = userAnswers.reduce((sum, ans) => sum + ans.score, 0);
        // Cap at 100 just in case
        if(totalScore > 100) totalScore = 100;
        
        // Add random slight penalty for realism if they scored perfect (virtually impossible)
        if(totalScore > 90) totalScore -= Math.floor(Math.random() * 5 + 5);

        renderResults(totalScore);
        stepResults.classList.add('active');
    }

    function renderResults(score) {
        finalScoreVal.innerText = score + '%';
        
        let color = '#FF3366'; // Danger
        let msg = "حالة حرجة: تصميم الصفحة يهدر ميزانيتك الإعلانية بلا رحمة!";
        if(score >= 75) {
            color = '#00E676';
            msg = "صفحة قوية: معدل التحويل ممتاز مع بعض ثغرات التسرب البسيطة.";
            warningAlert.style.display = 'none';
        } else if(score >= 50) {
            color = '#FFCC00';
            msg = "حالة متوسطة: نصف زوارك يهربون بسبب هيكلة العرض المترددة.";
            warningAlert.className = 'alert-box warning';
            warningAlert.style.borderRightColor = '#FFCC00';
            warningAlert.style.background = 'rgba(255, 204, 0, 0.1)';
            warningAlert.querySelector('i').style.color = '#FFCC00';
        }
        
        finalScoreVal.style.color = color;
        scoreMessage.innerText = msg;
        scoreMessage.style.color = color;

        drawGauge(score, color);

        // Render Tailored Feedback
        feedbackGrid.innerHTML = '';
        userAnswers.forEach(ans => {
            // Only show negative/warning items to force the 'consultative sale' hook
            // If it's a perfect score answering all good, we still show the good ones.
            const typeClass = ans.isGood ? 'good' : (ans.score > 0 ? 'warning' : 'danger');
            let icon = ans.isGood ? '<i class="fa-solid fa-circle-check" style="color:var(--success)"></i>' : '<i class="fa-solid fa-xmark" style="color:var(--danger)"></i>';
            if(ans.score > 0 && !ans.isGood) icon = '<i class="fa-solid fa-triangle-exclamation" style="color:var(--warning)"></i>';
            
            const box = document.createElement('div');
            box.className = `feedback-item ${typeClass}`;
            box.innerHTML = `
                <h4>${icon} ${ans.qTitle.split(':')[1]}</h4>
                <p>${ans.feedback}</p>
            `;
            feedbackGrid.appendChild(box);
        });
    }

    function drawGauge(val, color) {
        if(gaugeChart) gaugeChart.destroy();
        const ctx = document.getElementById('gaugeChart').getContext('2d');

        gaugeChart = new Chart(ctx, {
            type: 'doughnut',
            data: { 
                datasets: [{ 
                    data: [val, 100-val], 
                    backgroundColor: [color, 'rgba(255,255,255,0.05)'], 
                    borderWidth: 0, 
                    rotation: 270, 
                    circumference: 180 
                }] 
            },
            options: { cutout: '85%', responsive: true, maintainAspectRatio: false }
        });
    }

    // Lead Capture & Export PDF
    graderLeadForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const nm = document.getElementById('leadName').value;
        const ph = document.getElementById('leadPhone').value;
        const totalRawScore = parseInt(finalScoreVal.innerText);

        const btn = document.querySelector('#graderLeadForm button');
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري توليد وتوثيق الخطة...';
        btn.style.pointerEvents = 'none';

        const formData = new FormData();
        formData.append('name', nm);
        formData.append('phone', ph);
        formData.append('url', currentUrl);
        formData.append('score', totalRawScore);

        // Submit to API
        fetch('api/submit.php', { method: 'POST', body: formData })
        .then(() => triggerPDF(nm))
        .catch(() => triggerPDF(nm));
    });

    function triggerPDF(name) {
        const originalEl = document.getElementById('step-results');
        
        // 1. Prepare for Print: Hide unwanted UI elements temporarily
        const btnBox = originalEl.querySelector('.action-boxes');
        if(btnBox) btnBox.style.display = 'none';

        const logoEl = originalEl.querySelector('#printLogo');
        if(logoEl) logoEl.style.display = 'block';
        
        const headerEl = originalEl.querySelector('.print-header');
        if(headerEl) headerEl.style.display = 'block';

        const warningAlert = originalEl.querySelector('#warningAlert');
        if(warningAlert) warningAlert.style.marginBottom = '20px'; // spacing tweak

        // 2. Options for html2pdf
        const opt = {
            margin: 0.3,
            filename: `Landing_Report_${name.replace(/\s+/g, '_')}.pdf`,
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { 
                scale: 2, 
                useCORS: true, 
                backgroundColor: '#0A192F',
                scrollX: 0,
                scrollY: -window.scrollY // fixes blank pdf shifting
            },
            jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' }
        };

        // 3. Render and Download
        html2pdf().set(opt).from(originalEl).save().then(() => {
            // Restore Original View
            if(btnBox) btnBox.style.display = 'flex';
            if(logoEl) logoEl.style.display = 'none';
            if(headerEl) headerEl.style.display = 'none';

            // Show success message
            if(document.getElementById('leadCaptureModule')) {
                document.getElementById('leadCaptureModule').innerHTML = '<h3 style="color:#00E676; text-align:center;"><i class="fa-solid fa-check"></i> تم تشخيص الخلل واستخراج التقرير في جهازك بنجاح! ننتظر رسالتك للعمل على الإنقاذ.</h3>';
            }
        });
    }

});
