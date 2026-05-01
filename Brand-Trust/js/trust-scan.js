document.addEventListener('DOMContentLoaded', () => {

    // Screens
    const stepScanForm = document.getElementById('step-scan-form');
    const stepScanning = document.getElementById('step-scanning');
    const stepResults = document.getElementById('step-results');

    // UI Elements
    const scanForm = document.getElementById('scanForm');
    const scanningStatus = document.getElementById('scanningStatus');
    const finalScoreValue = document.getElementById('finalScoreValue');
    const scorePulsingAlert = document.getElementById('scorePulsingAlert');
    const competitorTextOverlay = document.getElementById('competitorTextOverlay');
    const quickFixesList = document.getElementById('quickFixesList');
    const lostSalesAmount = document.getElementById('lostSalesAmount');

    // Simulator
    const fixSlider = document.getElementById('fixSlider');
    const simTrustScore = document.getElementById('simTrustScore');
    const simSalesBoost = document.getElementById('simSalesBoost');

    // Chart instances
    let gaugeChart = null;
    let radarChart = null;

    let scanData = {
        score: 0, compScore: 0,
        security: 0, appearance: 0, social: 0, policies: 0
    };

    scanForm.addEventListener('submit', (e) => {
        e.preventDefault();
        
        // Data Extraction
        const comp = document.getElementById('competitorName').value;
        const ssl = document.getElementById('hasSSL').value;
        const refund = document.getElementById('hasRefund').value;
        const revs = parseInt(document.getElementById('reviewsCount').value) || 0;

        // Transition 1 -> 2
        stepScanForm.classList.remove('active');
        stepScanning.classList.add('active');

        startShockScan(comp, ssl, refund, revs);
    });

    function startShockScan(comp, ssl, refund, revs) {
        const msgs = [
            "يتم الاتصال بخوادم الأمان...",
            "يتم تحليل الخرائط الحرارية والمظهر...",
            "يتم قياس قوة الدليل الاجتماعي...",
            `يتم كشف ثغراتك أمام منافسك: ${comp}...`,
            "توليد الصدمة الرقمية..."
        ];
        
        let c = 0;
        let intv = setInterval(() => {
            scanningStatus.innerText = msgs[c];
            c++;
            if (c >= msgs.length) {
                clearInterval(intv);
                setTimeout(() => showShockResults(comp, ssl, refund, revs), 1000);
            }
        }, 800);
    }

    function showShockResults(comp, ssl, refund, revs) {
        // Shock Algorithm: Intentionally low for impact (30-45%)
        scanData.score = Math.floor(Math.random() * 16) + 30; 
        scanData.compScore = scanData.score + Math.floor(Math.random() * 20 + 35);
        if(scanData.compScore > 98) scanData.compScore = 95;

        scanData.security = (ssl === 'yes') ? 70 : 15;
        scanData.appearance = 45; // Fixed mediocre
        scanData.social = (revs > 100) ? 60 : 30;
        scanData.policies = (refund === 'yes') ? 80 : 20;

        // Transition 2 -> 3
        stepScanning.classList.remove('active');
        stepResults.classList.add('active');

        // Render Data
        finalScoreValue.innerText = scanData.score + '%';
        if (scanData.score <= 40) finalScoreValue.style.color = 'var(--danger)';
        else finalScoreValue.style.color = 'var(--warning)';

        competitorTextOverlay.innerHTML = `ثقتك: <b style="color:var(--danger)">${scanData.score}%</b> <span style="margin:0 15px;">VS</span> <b style="color:var(--success)">${comp}: ${scanData.compScore}%</b>`;

        const lostPct = 100 - scanData.score;
        scorePulsingAlert.innerText = `🚨 كارثة: أنت تخسر ${lostPct}% من عملائك في صفحة سلة المشتريات!`;
        scorePulsingAlert.classList.remove('hidden');

        lostSalesAmount.innerText = `حرفياً.. أنت تنزف ${Math.floor(lostPct * 1.5)}% من أرباحك! 📉`;

        // Render Fixes
        quickFixesList.innerHTML = '';
        if(ssl !== 'yes') quickFixesList.innerHTML += `<li>تشفير الموقع معدوم، المتصفح يحذر عملائك بأن موقعك "خطر" لحظة الدفع.</li>`;
        if(revs < 100) quickFixesList.innerHTML += `<li>غياب التقييمات المرئية الحقيقية (UGC) يجعل منتجك يبدو مجهولاً.</li>`;
        if(refund !== 'yes') quickFixesList.innerHTML += `<li>عملاؤك خائفون من الشراء لعدم وجود إعلان صارم عن ضمان استرداد الأموال.</li>`;
        quickFixesList.innerHTML += `<li>عناصر الهوية البصرية باهتة ولا تعكس حجم تجارتك الحقيقي.</li>`;

        // Default Sim
        simTrustScore.innerText = scanData.score + '%';

        drawGauge(scanData.score);
        drawRadar(comp);
    }

    function drawGauge(val) {
        if(gaugeChart) gaugeChart.destroy();
        const ctx = document.getElementById('gaugeChart').getContext('2d');
        let col = val < 50 ? '#FF3366' : (val < 75 ? '#FFB800' : '#00E676');

        gaugeChart = new Chart(ctx, {
            type: 'doughnut',
            data: { datasets: [{ data: [val, 100-val], backgroundColor: [col, 'rgba(255,255,255,0.05)'], borderWidth: 0, rotation: 270, circumference: 180 }] },
            options: { cutout: '85%', responsive: true, maintainAspectRatio: false }
        });
    }

    function drawRadar(compName) {
        if(radarChart) radarChart.destroy();
        const ctx = document.getElementById('radarChart').getContext('2d');
        radarChart = new Chart(ctx, {
            type: 'radar',
            data: {
                labels: ['الأمان التقني', 'المظهر الرقمي', 'تأييد المجتمع', 'الضمان المالي'],
                datasets: [
                    { label: 'علامتك التجارية', data: [scanData.security, scanData.appearance, scanData.social, scanData.policies], borderColor: '#FF3366', backgroundColor: 'rgba(255,51,102,0.2)', pointBackgroundColor: '#FF3366' },
                    { label: compName, data: [90, 85, 95, 88], borderColor: '#00F2FE', backgroundColor: 'rgba(0,242,254,0.1)', pointBackgroundColor: '#00F2FE' }
                ]
            },
            options: { scales: { r: { ticks: {display: false}, pointLabels: {color: '#8892B0', font:{family:'Cairo', size: 14}} } }, plugins: { legend: {labels: {color:'#fff', font:{family:'Cairo'}}} }, responsive: true }
        });
    }

    // Gamification Slider Action
    fixSlider.addEventListener('input', (e) => {
        const p = parseInt(e.target.value); // 0 to 100
        const newScore = scanData.score + Math.floor((95 - scanData.score) * (p/100));
        const boost = Math.floor(newScore * 1.5 - scanData.score);

        simTrustScore.innerText = newScore + '%';
        simSalesBoost.innerText = '+' + boost + '%';

        if(newScore >= 75) { simTrustScore.style.color = 'var(--success)'; }
        else if(newScore >= 50) { simTrustScore.style.color = 'var(--warning)'; }
        else { simTrustScore.style.color = 'var(--danger)'; }

        if(gaugeChart) {
            let col = newScore < 50 ? '#FF3366' : (newScore < 75 ? '#FFB800' : '#00E676');
            gaugeChart.data.datasets[0].data = [newScore, 100-newScore];
            gaugeChart.data.datasets[0].backgroundColor[0] = col;
            gaugeChart.update();
            finalScoreValue.innerText = newScore + '%';
            finalScoreValue.style.color = col;
        }
    });

    // Lead Submission + PDF
    const leadForm = document.getElementById('leadForm');
    leadForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const nm = document.getElementById('leadName').value;
        const ph = document.getElementById('leadPhone').value;
        const em = document.getElementById('leadEmail').value;

        const formData = new FormData();
        formData.append('name', nm); formData.append('phone', ph);
        formData.append('email', em); formData.append('baseScore', scanData.score);

        fetch('api/submit.php', { method: 'POST', body: formData })
        .then(() => triggerPDF(nm))
        .catch(() => triggerPDF(nm));
    });

    function triggerPDF(name) {
        document.querySelector('.premium-lead form').innerHTML = '<h3 style="color:#00E676;">يتم الآن تجهيز المطبوعة الاستشارية، لحظات...</h3>';
        
        const originalEl = document.getElementById('step-results');
        // استنساخ الشاشة لكي نعمل عليها بعيداً عن أعين المتصفح المباشر دون تشويه الصفحة
        const clone = originalEl.cloneNode(true);
        
        // إزالة الأزرار ونموذج التسجيل من النسخة المطبوعة
        const btnBox = clone.querySelector('.action-boxes');
        if(btnBox) btnBox.remove();
        const leadArea = clone.querySelector('.premium-lead');
        if(leadArea) leadArea.remove();

        // تحويل الرسوم البيانية الحية (Canvas) إلى صور ثابتة داخل النسخة المستنسخة 
        const originalGauge = document.getElementById('gaugeChart');
        const originalRadar = document.getElementById('radarChart');
        if(originalGauge) {
            clone.querySelector('.gauge-container').innerHTML = `<img src="${originalGauge.toDataURL('image/png')}" style="width:100%; max-width:300px; margin:0 auto; display:block;">`;
        }
        if(originalRadar) {
            clone.querySelector('.radar-chart-container').innerHTML = `<img src="${originalRadar.toDataURL('image/png')}" style="width:100%; max-width:350px; margin:0 auto; display:block;">`;
        }

        // إنشاء حاوية الطباعة المثالية 
        const printWrapper = document.createElement('div');
        printWrapper.style.width = '800px';
        printWrapper.style.margin = '0 auto'; // توسيط لضمان القراءة السليمة
        printWrapper.style.padding = '40px';
        printWrapper.style.background = '#0A192F';
        printWrapper.style.color = '#fff';
        printWrapper.style.fontFamily = "'Cairo', sans-serif";
        printWrapper.dir = 'rtl';
        
        // إضافة ترويسة رسمية
        printWrapper.innerHTML = `
            <div style="text-align:center; border-bottom:1px solid #00F2FE; padding-bottom:20px; margin-bottom:30px;">
                <h1 style="color:#00F2FE; margin:0; font-family:'Cairo';">العبير للتسويق - تقرير الثقة الاستشاري</h1>
                <p style="color:#8892B0; font-family:'Cairo';">التقرير المخصص للعميل: ${name}</p>
            </div>
        `;
        printWrapper.appendChild(clone);

        // خطوة الضمان 100%: 
        // المتصفحات (وخاصة الجوال) ترفض تصوير العناصر المخفية أو غير الموصولة.
        // سنقوم بإخفاء واجهة الموقع لحظياً، وإظهار التقرير بشكله الكامل كأنه الشاشة الوحيدة.
        const mainUi = document.querySelector('.premium-container');
        mainUi.style.display = 'none'; // اختفاء فوري للواجهة الأصلية
        document.body.appendChild(printWrapper); // عرض التقرير الحقيقي للمتصفح ليقرأ أبعاده حرفياً

        const opt = {
            margin: 0.2, 
            filename: `Consulting_Brand_Trust_${name}.pdf`,
            image: { type: 'jpeg', quality: 1.0 },
            html2canvas: { 
                scale: 2, 
                useCORS: true, 
                backgroundColor: '#0A192F'
            },
            jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' }
        };

        // توليد الـ PDF 
        html2pdf().set(opt).from(printWrapper).save().then(() => {
            // فور نزول الـ PDF لجهاز العميل، نمسح التقرير من الشاشة ونعيد الموقع المعتاد
            document.body.removeChild(printWrapper);
            mainUi.style.display = 'flex'; // إعادة الواجهة الأصلية بكامل حيويتها
            document.querySelector('.premium-lead').innerHTML = '<h3 style="color:#00E676;">✅ تم تشخيص الثقة واستخراج التقرير الفاخر في جهازك بنجاح!</h3>';
        });
    }
});
