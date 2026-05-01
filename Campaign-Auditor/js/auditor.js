/**
 * ═══════════════════════════════════════
 *  مدقق الحملة الإعلانية — Campaign Auditor Engine
 *  العبير للتسويق | بصمة النمو
 * ═══════════════════════════════════════
 */
document.addEventListener('DOMContentLoaded', () => {

    // ─── State ───
    let selectedPlatform = '';
    let currentQ = 0;
    let answers = [];
    let scores = { setup: 0, creative: 0, measurement: 0 };

    const QUESTIONS = [
        // ─── المحور 1: إعداد الحملة (30 نقطة) ───
        { axis: 'setup', axisLabel: 'إعداد الحملة', text: 'ما هدف حملتك الإعلانية الحالية؟', options: [
            { text: 'مبيعات مباشرة (تحويل)', score: 10 },
            { text: 'جذب عملاء محتملين (Leads)', score: 8 },
            { text: 'زيارات للموقع (ترافيك)', score: 4 },
            { text: 'متابعين ووعي بالعلامة فقط', score: 0 }
        ]},
        { axis: 'setup', axisLabel: 'إعداد الحملة', text: 'ما ميزانيتك الإعلانية الشهرية؟', options: [
            { text: 'أكثر من 10,000 ريال', score: 5 },
            { text: '5,000 – 10,000 ريال', score: 5 },
            { text: '2,000 – 5,000 ريال', score: 5 },
            { text: 'أقل من 2,000 ريال', score: 3 }
        ]},
        { axis: 'setup', axisLabel: 'إعداد الحملة', text: 'كم عمر حملتك الإعلانية الحالية؟', options: [
            { text: 'أكثر من 3 أشهر مع تحسين مستمر', score: 5 },
            { text: '1 – 3 أشهر', score: 3 },
            { text: 'أقل من شهر', score: 2 },
            { text: 'أشغّل وأوقف بدون انتظام', score: 0 }
        ]},
        { axis: 'setup', axisLabel: 'إعداد الحملة', text: 'هل تستهدف جمهوراً محدداً ودقيقاً؟', options: [
            { text: 'نعم، جمهور مخصص دقيق (Custom Audience)', score: 10 },
            { text: 'جمهور محدد نوعاً ما', score: 6 },
            { text: 'جمهور عام حسب الاهتمامات فقط', score: 2 },
            { text: 'لا أعرف ما يعنيه هذا', score: 0 }
        ]},
        // ─── المحور 2: جودة الإعلان (35 نقطة) ───
        { axis: 'creative', axisLabel: 'جودة الإعلان', text: 'كم عدد الإعلانات المختلفة التي تشغلها الآن؟', options: [
            { text: '5 إعلانات أو أكثر (تنوع ممتاز)', score: 15 },
            { text: '3 – 4 إعلانات', score: 10 },
            { text: 'إعلانان فقط', score: 5 },
            { text: 'إعلان واحد فقط', score: 0 }
        ]},
        { axis: 'creative', axisLabel: 'جودة الإعلان', text: 'هل تختبر نصوصاً وصوراً مختلفة (A/B Test)؟', options: [
            { text: 'نعم، باستمرار ونحلل النتائج', score: 10 },
            { text: 'أحياناً', score: 5 },
            { text: 'لا أفعل ذلك', score: 0 },
            { text: 'لا أعرف ما هو A/B Test', score: 0 }
        ]},
        { axis: 'creative', axisLabel: 'جودة الإعلان', text: 'ماذا يتحدث إعلانك بشكل رئيسي؟', options: [
            { text: 'عن مشكلة العميل وكيف أحلها', score: 10 },
            { text: 'عن نتائج وتحولات حقيقية (قبل/بعد)', score: 8 },
            { text: 'عن مميزات المنتج والمواصفات', score: 3 },
            { text: 'عن اسم النشاط والعروض والخصومات فقط', score: 0 }
        ]},
        // ─── المحور 3: قياس النتائج (35 نقطة) ───
        { axis: 'measurement', axisLabel: 'قياس النتائج', text: 'ما نسبة التحويل الحالية (Conversion Rate) تقريباً؟', options: [
            { text: 'أعرفها بدقة وهي فوق 3%', score: 15 },
            { text: 'أعرفها وهي أقل من 3%', score: 8 },
            { text: 'لا أعرف النسبة بالضبط', score: 3 },
            { text: 'لا أملك طريقة لقياسها', score: 0 }
        ]},
        { axis: 'measurement', axisLabel: 'قياس النتائج', text: 'هل تعيد استهداف من زار موقعك ولم يشترِ (Retargeting)؟', options: [
            { text: 'نعم، حملة Retargeting مفعّلة ونشطة', score: 10 },
            { text: 'أحياناً', score: 5 },
            { text: 'لا أفعل ذلك', score: 0 },
            { text: 'لا أعرف كيف أفعلها', score: 0 }
        ]},
        { axis: 'measurement', axisLabel: 'قياس النتائج', text: 'هل تعرف تكلفة الحصول على عميل واحد (CPA)؟', options: [
            { text: 'نعم وأتابعها أسبوعياً', score: 10 },
            { text: 'نعم لكن لا أتابعها بانتظام', score: 5 },
            { text: 'لا أعرفها', score: 0 }
        ]}
    ];

    const AXIS_MAX = { setup: 30, creative: 35, measurement: 35 };
    const AXIS_LABELS = { setup: 'إعداد الحملة', creative: 'جودة الإعلان', measurement: 'قياس النتائج' };

    // ─── Step Navigation ───
    function goToStep(id) {
        document.querySelectorAll('.step-container').forEach(el => el.classList.remove('active'));
        document.getElementById(id).classList.add('active');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // ─── Platform Select ───
    document.querySelectorAll('.platform-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            document.querySelectorAll('.platform-btn').forEach(b => b.classList.remove('selected'));
            btn.classList.add('selected');
            selectedPlatform = btn.dataset.platform;
            await sleep(400);
            runScannerAnimation();
        });
    });

    // ─── Scanner Animation ───
    const SCAN_STEPS = [
        { text: 'جاري فحص هدف الحملة...', p: 15 },
        { text: 'تحليل جودة الاستهداف...', p: 30 },
        { text: 'فحص إبداعية الإعلانات...', p: 50 },
        { text: 'قياس نسب التحويل...', p: 65 },
        { text: 'كشف نقاط النزيف المالي...', p: 80 },
        { text: 'إعداد التقرير التشخيصي...', p: 95 }
    ];

    async function runScannerAnimation() {
        goToStep('step-scanner');
        const status = document.getElementById('scanStatus');
        const bar = document.getElementById('scanBar');
        for (const s of SCAN_STEPS) {
            status.textContent = s.text;
            bar.style.width = s.p + '%';
            await sleep(900 + Math.random() * 600);
        }
        bar.style.width = '100%';
        status.textContent = '✅ الفحص مكتمل — ننتقل للاستبيان التشخيصي...';
        await sleep(700);
        startQuiz();
    }

    // ─── Quiz ───
    function startQuiz() {
        currentQ = 0;
        answers = [];
        scores = { setup: 0, creative: 0, measurement: 0 };
        renderQuiz();
        goToStep('step-quiz');
    }

    function renderQuiz() {
        const q = QUESTIONS[currentQ];
        // Progress dots
        const prog = document.getElementById('quizProgress');
        prog.innerHTML = '';
        for (let i = 0; i < QUESTIONS.length; i++) {
            const dot = document.createElement('div');
            dot.className = 'quiz-dot' + (i < currentQ ? ' done' : '') + (i === currentQ ? ' current' : '');
            prog.appendChild(dot);
        }
        document.getElementById('qNum').textContent = `السؤال ${currentQ + 1} من ${QUESTIONS.length}`;
        document.getElementById('qAxis').textContent = `المحور: ${q.axisLabel}`;
        document.getElementById('qText').textContent = q.text;

        const optsEl = document.getElementById('qOptions');
        optsEl.innerHTML = '';
        q.options.forEach((opt, i) => {
            const btn = document.createElement('button');
            btn.className = 'quiz-opt';
            btn.textContent = opt.text;
            btn.addEventListener('click', () => selectAnswer(i));
            optsEl.appendChild(btn);
        });
    }

    async function selectAnswer(idx) {
        const q = QUESTIONS[currentQ];
        const opt = q.options[idx];

        // Visual feedback
        document.querySelectorAll('.quiz-opt').forEach((b, i) => {
            if (i === idx) b.classList.add('selected');
            b.style.pointerEvents = 'none';
        });

        answers.push({ question: q.text, answer: opt.text, score: opt.score, axis: q.axis });
        scores[q.axis] += opt.score;

        await sleep(500);
        currentQ++;
        if (currentQ < QUESTIONS.length) {
            renderQuiz();
        } else {
            runDiagnosis();
        }
    }

    // ─── Diagnosis Animation ───
    async function runDiagnosis() {
        goToStep('step-diagnosis');
        const bar = document.getElementById('diagBar');
        const status = document.getElementById('diagStatus');
        const steps = ['تحليل إجاباتك...', 'كشف نقاط النزيف...', 'بناء التقرير التشخيصي...'];
        for (let i = 0; i < steps.length; i++) {
            status.textContent = steps[i];
            bar.style.width = ((i + 1) / steps.length * 100) + '%';
            await sleep(1000);
        }
        status.textContent = '✅ التشخيص مكتمل!';
        await sleep(600);
        renderResults();
    }

    // ─── Results ───
    function renderResults() {
        const total = scores.setup + scores.creative + scores.measurement;
        let level, verdict, color;
        if (total <= 30) { level = 'red'; verdict = 'حملتك في العناية المركزة — كل ريال تدفعه يذهب لمنافسك'; color = '#ff3b3b'; }
        else if (total <= 55) { level = 'orange'; verdict = 'حملتك مريضة — تخسر نصف ميزانيتك بدون أن تشعر'; color = '#ff6b35'; }
        else if (total <= 75) { level = 'yellow'; verdict = 'حملتك تمشي بعكاز — هناك نزيف خفي يكلفك آلاف الريالات شهرياً'; color = '#ffaa00'; }
        else { level = 'green'; verdict = 'حملتك بصحة جيدة — لكن التحسين يعني مضاعفة أرباحك'; color = '#00ff88'; }

        // Score circle
        const circle = document.getElementById('scoreCircle');
        circle.className = 'score-circle level-' + level;
        document.getElementById('scoreVerdict').textContent = verdict;
        document.getElementById('scoreVerdict').style.color = color;

        // Animate score number
        animateNumber('scoreNum', total);

        // Shock message
        generateShockMessage(level, color);

        // Axis bars
        renderAxisBars();

        // Problems
        renderProblems();

        goToStep('step-results');
    }

    function animateNumber(elId, target) {
        const el = document.getElementById(elId);
        let current = 0;
        const step = Math.max(1, Math.floor(target / 40));
        const interval = setInterval(() => {
            current += step;
            if (current >= target) { current = target; clearInterval(interval); }
            el.textContent = current;
        }, 30);
    }

    function generateShockMessage(level, color) {
        const box = document.getElementById('shockBox');
        box.className = 'shock-box level-' + level;

        // Find weakest axis
        const axisPerc = {
            setup: scores.setup / AXIS_MAX.setup,
            creative: scores.creative / AXIS_MAX.creative,
            measurement: scores.measurement / AXIS_MAX.measurement
        };
        const weakest = Object.keys(axisPerc).reduce((a, b) => axisPerc[a] < axisPerc[b] ? a : b);

        const msgs = {
            setup: {
                title: '⚠️ خلل في إعداد حملتك!',
                body: 'من كل 100 شخص يشوف إعلانك، 90 منهم لن يشتروا أبداً — أنت تدفع لهم جميعاً! مشكلتك في الأساس: الهدف والاستهداف.'
            },
            creative: {
                title: '⚠️ إعلانك يحترق!',
                body: 'إعلانك الوحيد يحترق كل أسبوع — بعدها تدفع أضعاف التكلفة بنصف النتائج! منافسك يغيّر إعلاناته كل 3 أيام وأنت تكرر نفس المحتوى.'
            },
            measurement: {
                title: '⚠️ أنت تقود بعيون مغلقة!',
                body: 'أنت تصرف آلاف الريالات شهرياً ولا تعرف أي ريال منها يعود عليك بربح! بدون قياس = بدون تحسين = هدر مستمر.'
            }
        };

        const m = msgs[weakest];
        document.getElementById('shockTitle').textContent = m.title;
        document.getElementById('shockTitle').style.color = color;
        document.getElementById('shockBody').textContent = m.body;
    }

    function renderAxisBars() {
        const section = document.getElementById('axisSection');
        section.innerHTML = '<div style="font-weight:800; font-size:15px; margin-bottom:14px;">📊 تفصيل المحاور الثلاثة:</div>';
        ['setup', 'creative', 'measurement'].forEach(axis => {
            const perc = Math.round((scores[axis] / AXIS_MAX[axis]) * 100);
            let barColor;
            if (perc <= 40) barColor = '#ff3b3b';
            else if (perc <= 60) barColor = '#ff6b35';
            else if (perc <= 80) barColor = '#ffaa00';
            else barColor = '#00ff88';

            section.innerHTML += `
                <div class="axis-item">
                    <div class="axis-label"><span>${AXIS_LABELS[axis]}</span><span>${scores[axis]}/${AXIS_MAX[axis]}</span></div>
                    <div class="axis-bar-bg"><div class="axis-bar" style="width:${perc}%; background:${barColor};"></div></div>
                </div>
            `;
        });
    }

    function renderProblems() {
        const list = document.getElementById('problemsList');
        list.innerHTML = '';
        const problems = [];

        // Check answers for problems
        if (answers[0] && answers[0].score <= 4) problems.push('تدفع للوعي والترافيك وتريد مبيعات — المنصة تأخذ أموالك وتعطيك مشاهدات لا مشترين!');
        if (answers[3] && answers[3].score <= 2) problems.push('جمهورك واسع جداً — أنت تدفع لعرض إعلانك على أشخاص لن يشتروا أبداً!');
        if (answers[4] && answers[4].score <= 5) problems.push('إعلان واحد أو اثنين فقط يحترقان خلال أسبوع — بعدها تدفع أضعاف التكلفة بنصف النتائج!');
        if (answers[5] && answers[5].score === 0) problems.push('لا تختبر إعلاناتك (A/B Test) — تصرف بدون ما تعرف أي إعلان يجيب نتائج حقيقية!');
        if (answers[7] && answers[7].score <= 3) problems.push('تقود سيارتك بعيون مغلقة — تصرف بدون أن تعرف ما الذي يعمل وما الذي يهدر أموالك!');
        if (answers[8] && answers[8].score <= 0) problems.push('70% من زوار موقعك لن يعودوا أبداً — وأنت لا تعيد استهدافهم (Retargeting)!');
        if (answers[9] && answers[9].score <= 0) problems.push('لا تعرف كم يكلفك العميل الواحد (CPA) — قد تكون تخسر في كل عملية بيع بدون أن تدري!');

        if (problems.length === 0) problems.push('حملتك جيدة بشكل عام — لكن التحسين المستمر يضاعف أرباحك!');

        problems.forEach(p => {
            list.innerHTML += `<div class="problem-item"><span class="p-icon">🔴</span><span class="p-text">${p}</span></div>`;
        });
    }

    // ─── Lead & PDF ───
    document.getElementById('downloadBtn').addEventListener('click', () => {
        const name = document.getElementById('leadName').value.trim();
        const phone = document.getElementById('leadPhone').value.trim();
        if (!name || !phone) { alert('يرجى إدخال اسمك ورقم الواتساب!'); return; }

        // Get budget answer
        let budget = 'غير محدد';
        if (answers[1]) budget = answers[1].answer;

        // Get problems
        const problemsArr = [];
        document.querySelectorAll('.problem-item .p-text').forEach(el => problemsArr.push(el.textContent));

        // Save to DB
        const fd = new FormData();
        fd.append('name', name);
        fd.append('phone', phone);
        fd.append('platform', selectedPlatform);
        fd.append('monthly_budget', budget);
        fd.append('campaign_score', scores.setup + scores.creative + scores.measurement);
        fd.append('problems_json', JSON.stringify(problemsArr));
        fd.append('answers_json', JSON.stringify(answers));
        try { fetch('api/submit.php', { method: 'POST', body: fd }); } catch (e) { console.warn(e); }

        triggerPDF(name);
    });

    function triggerPDF(name) {
        const el = document.getElementById('step-results');
        const leadBox = document.getElementById('leadBox');
        const printHdr = document.getElementById('printHdr');

        if (leadBox) leadBox.style.display = 'none';
        if (printHdr) { printHdr.style.display = 'block'; document.getElementById('pdfDate').textContent = new Date().toLocaleDateString('ar-SA'); }

        const opt = {
            margin: 0.3,
            filename: `Campaign_Audit_${name.replace(/\s+/g, '_')}.pdf`,
            image: { type: 'jpeg', quality: 0.95 },
            html2canvas: { scale: 2, useCORS: true, backgroundColor: '#080c14', scrollY: -window.scrollY },
            jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' }
        };

        html2pdf().set(opt).from(el).save().then(() => {
            if (leadBox) leadBox.style.display = '';
            if (printHdr) printHdr.style.display = 'none';
            goToStep('step-thankyou');
        });
    }

    function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }
});
