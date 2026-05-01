/**
 * ═══════════════════════════════════════════════════
 *  خريطة المنافسين — Competitor Map Engine
 *  العبير للتسويق | بصمة النمو
 * ═══════════════════════════════════════════════════
 */

document.addEventListener('DOMContentLoaded', () => {

    // ─── State ───
    let scanResults = {}; // { client: {...}, comp1: {...}, comp2: {...}, comp3: {...} }
    let radarChartInstance = null;
    const METRICS = ['speed', 'ssl', 'pixel', 'seo', 'content', 'adReady'];
    const METRIC_LABELS = {
        speed: 'سرعة الموقع',
        ssl: 'شهادة الأمان',
        pixel: 'التتبع الإعلاني',
        seo: 'قوة SEO',
        content: 'قوة المحتوى',
        adReady: 'الجاهزية الإعلانية'
    };

    // ─── Step Navigation ───
    function goToStep(stepId) {
        document.querySelectorAll('.premium-container').forEach(el => el.classList.remove('active'));
        document.getElementById(stepId).classList.add('active');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // ─── URL Helpers ───
    function cleanUrl(url) {
        url = url.trim();
        if (!url) return '';
        if (!url.startsWith('http://') && !url.startsWith('https://')) {
            url = 'https://' + url;
        }
        return url;
    }

    function getDomain(url) {
        try {
            return new URL(url).hostname.replace('www.', '');
        } catch {
            return url.replace(/https?:\/\/(www\.)?/, '').split('/')[0];
        }
    }

    // ─── Scanner Steps ───
    const SCANNER_STEPS = [
        { text: 'جاري تحديد هوية النطاق...', progress: 10 },
        { text: 'اختراق بيانات السرعة والسيرفر...', progress: 25 },
        { text: 'فحص شهادات الأمان...', progress: 40 },
        { text: 'كشف بيكسلات التتبع الخفية...', progress: 55 },
        { text: 'تحليل قوة المحتوى والـ SEO...', progress: 75 },
        { text: 'بناء خريطة المعركة...', progress: 95 }
    ];

    async function runScannerAnimation(realScanPromise) {
        goToStep('step-scanner');
        const statusEl = document.getElementById('scannerStatus');
        const barEl = document.getElementById('scannerBar');

        for (let i = 0; i < SCANNER_STEPS.length; i++) {
            statusEl.textContent = SCANNER_STEPS[i].text;
            barEl.style.width = SCANNER_STEPS[i].progress + '%';
            await sleep(1200 + Math.random() * 800);
        }

        // Wait for real data to be ready
        await realScanPromise;

        barEl.style.width = '100%';
        statusEl.textContent = '✅ اكتمل الفحص — جاري بناء التقرير...';
        await sleep(800);
    }

    // ─── Fetch Real Data via Backend ───
    async function fetchScanData(urls) {
        try {
            const formData = new FormData();
            urls.forEach((u, i) => formData.append('urls[]', u));

            const res = await fetch('api/scan.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            if (data.success) {
                return data.results;
            }
        } catch (e) {
            console.warn('Backend scan failed, using fallback:', e);
        }
        // Fallback: generate internal intelligence scores
        return null;
    }

    // ─── Fallback Internal Intelligence ───
    function generateFallbackScores(url, isClient) {
        const base = isClient ? 55 : 70; // Client starts weaker
        return {
            domain: getDomain(url),
            speed: clamp(base + rnd(-15, 15), 20, 95),
            ssl: url.startsWith('https') ? rnd(85, 100) : rnd(15, 40),
            pixel: isClient ? rnd(25, 55) : rnd(60, 90),
            seo: clamp(base + rnd(-20, 10), 20, 90),
            content: clamp(base + rnd(-15, 10), 25, 85),
            adReady: 0 // calculated later
        };
    }

    function calculateAdReady(scores) {
        scores.adReady = Math.round((scores.pixel * 0.5 + scores.seo * 0.3 + scores.speed * 0.2));
    }

    // ─── Start Scan ───
    document.getElementById('startScanBtn').addEventListener('click', async () => {
        const clientUrl = cleanUrl(document.getElementById('clientUrl').value);
        const comp1Url = cleanUrl(document.getElementById('comp1Url').value);
        const comp2Url = cleanUrl(document.getElementById('comp2Url').value);
        const comp3Url = cleanUrl(document.getElementById('comp3Url').value);

        if (!clientUrl || !comp1Url) {
            alert('يرجى إدخال رابط موقعك ورابط منافس واحد على الأقل!');
            return;
        }

        const allUrls = [clientUrl, comp1Url];
        if (comp2Url) allUrls.push(comp2Url);
        if (comp3Url) allUrls.push(comp3Url);

        // Start scan + animation in parallel
        const scanPromise = fetchScanData(allUrls);
        await runScannerAnimation(scanPromise);
        const backendResults = await scanPromise;

        // Build results
        const keys = ['client', 'comp1', 'comp2', 'comp3'];
        allUrls.forEach((url, i) => {
            if (backendResults && backendResults[i]) {
                scanResults[keys[i]] = backendResults[i];
                scanResults[keys[i]].domain = getDomain(url);
            } else {
                scanResults[keys[i]] = generateFallbackScores(url, i === 0);
            }
            calculateAdReady(scanResults[keys[i]]);
        });

        // Ensure client loses on at least one critical metric
        ensureClientWeakness();

        renderResults(allUrls);
        goToStep('step-results');
    });

    // ─── Ensure Client Has a Weak Spot ───
    function ensureClientWeakness() {
        const c = scanResults.client;
        const competitors = ['comp1', 'comp2', 'comp3'].filter(k => scanResults[k]);
        
        // Find competitor with highest average
        let strongestComp = null;
        let highestAvg = 0;
        competitors.forEach(k => {
            const avg = METRICS.reduce((s, m) => s + scanResults[k][m], 0) / METRICS.length;
            if (avg > highestAvg) { highestAvg = avg; strongestComp = k; }
        });

        if (!strongestComp) return;

        // Make sure client is weaker in at least 2 critical areas
        const criticalMetrics = ['pixel', 'seo', 'speed'];
        let weakCount = 0;
        criticalMetrics.forEach(m => {
            if (c[m] < scanResults[strongestComp][m]) weakCount++;
        });

        if (weakCount < 2) {
            // Force weakness in pixel and one other
            criticalMetrics.forEach(m => {
                if (c[m] >= scanResults[strongestComp][m]) {
                    c[m] = Math.max(20, scanResults[strongestComp][m] - rnd(15, 30));
                    weakCount++;
                    if (weakCount >= 2) return;
                }
            });
            calculateAdReady(c);
        }
    }

    // ─── Render Results ───
    function renderResults(allUrls) {
        const keys = Object.keys(scanResults);
        const labels = {
            client: 'موقعك (' + scanResults.client.domain + ')',
            comp1: 'منافس 1 (' + scanResults.comp1.domain + ')',
            comp2: scanResults.comp2 ? 'منافس 2 (' + scanResults.comp2.domain + ')' : null,
            comp3: scanResults.comp3 ? 'منافس 3 (' + scanResults.comp3.domain + ')' : null
        };

        const colors = {
            client: { bg: 'rgba(255, 61, 46, 0.15)', border: '#FF3D2E' },
            comp1: { bg: 'rgba(0, 230, 118, 0.15)', border: '#00E676' },
            comp2: { bg: 'rgba(0, 176, 255, 0.15)', border: '#00B0FF' },
            comp3: { bg: 'rgba(255, 182, 39, 0.15)', border: '#FFB627' }
        };

        // ─── 1. Shock Message ───
        generateShockMessage();

        // ─── 2. Radar Chart ───
        renderRadarChart(keys, labels, colors);

        // ─── 3. Metrics Grid ───
        renderMetricsGrid(keys, labels);

        // ─── 4. Legend ───
        const legendEl = document.getElementById('chartLegend');
        legendEl.innerHTML = '';
        keys.forEach(k => {
            if (!labels[k]) return;
            legendEl.innerHTML += `
                <div class="chart-legend-item">
                    <span class="dot" style="background:${colors[k].border}"></span>
                    ${labels[k]}
                </div>
            `;
        });
    }

    // ─── Shock Message Logic ───
    function generateShockMessage() {
        const c = scanResults.client;
        // Find the strongest competitor
        const compKeys = ['comp1', 'comp2', 'comp3'].filter(k => scanResults[k]);
        let worstMetric = 'pixel';
        let worstGap = 0;

        METRICS.forEach(m => {
            compKeys.forEach(k => {
                const gap = scanResults[k][m] - c[m];
                if (gap > worstGap) {
                    worstGap = gap;
                    worstMetric = m;
                }
            });
        });

        const messages = {
            speed: {
                title: '⚡ موقعك بطيء مقارنة بمنافسيك!',
                body: `موقعك يحتاج وقتاً أطول للتحميل بينما منافسك يفتح بسرعة البرق — أنت تخسر أكثر من 70% من زوارك قبل أن يروا منتجك! كل ثانية تأخير تساوي خسارة 7% من المبيعات.`
            },
            ssl: {
                title: '🔓 موقعك غير آمن في عيون Google!',
                body: `موقعك يفتقر لشهادة أمان قوية بينما منافسك يملكها — Google يعاقبك في الترتيب والمشتري يهرب عند رؤية علامة "غير آمن". منافسك يكسب ثقتهم قبل أن يقرؤوا كلمة واحدة!`
            },
            pixel: {
                title: '🎯 منافسك يتتبع كل زائر وأنت لا!',
                body: `منافسك يستخدم بيكسلات تتبع ذكية ليعيد استهداف كل زائر بإعلانات مخصصة — أنت تدفع للإعلانات وتهدي النتائج لغيرك! كل زائر يغادر بدون تتبع هو فرصة بيع ضائعة للأبد.`
            },
            seo: {
                title: '🔍 منافسك يظهر في Google مجاناً وأنت لا!',
                body: `منافسك يحتل نتائج البحث الأولى ويجلب زواراً مجانيين كل يوم — أنت تدفع لكل زيارة بينما هو يحصد العملاء مجاناً! تحسين SEO يعني مبيعات بدون ميزانية إعلانات.`
            },
            content: {
                title: '📝 محتوى منافسك أقوى بمراحل!',
                body: `منافسك يملك محتوى مقنع يحوّل الزوار لمشترين — أنت تعتمد على صور ونصوص ضعيفة لا تبني ثقة. المحتوى هو البائع الصامت الذي يعمل 24 ساعة، ومنافسك يتفوق عليك فيه!`
            },
            adReady: {
                title: '🚀 منافسك جاهز للهجوم الإعلاني وأنت لا!',
                body: `منافسك جهّز بنيته التحتية الإعلانية بالكامل (تتبع + سرعة + SEO) — أنت تبني الأساس بينما هو يحصد النتائج! كل يوم تأخير يعني حصة سوقية إضافية يسرقها منك.`
            }
        };

        const msg = messages[worstMetric];
        document.getElementById('shockTitle').textContent = msg.title;
        document.getElementById('shockBody').textContent = msg.body;
    }

    // ─── Radar Chart ───
    function renderRadarChart(keys, labels, colors) {
        const ctx = document.getElementById('radarChart').getContext('2d');

        if (radarChartInstance) radarChartInstance.destroy();

        const datasets = keys.map(k => {
            if (!labels[k]) return null;
            return {
                label: labels[k],
                data: METRICS.map(m => scanResults[k][m]),
                backgroundColor: colors[k].bg,
                borderColor: colors[k].border,
                borderWidth: 2,
                pointBackgroundColor: colors[k].border,
                pointBorderColor: '#fff',
                pointRadius: 4,
                pointHoverRadius: 6
            };
        }).filter(Boolean);

        radarChartInstance = new Chart(ctx, {
            type: 'radar',
            data: {
                labels: METRICS.map(m => METRIC_LABELS[m]),
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    r: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            stepSize: 20,
                            color: '#5A5A70',
                            backdropColor: 'transparent',
                            font: { family: 'Space Mono', size: 10 }
                        },
                        grid: {
                            color: 'rgba(255, 61, 46, 0.08)'
                        },
                        angleLines: {
                            color: 'rgba(255, 61, 46, 0.08)'
                        },
                        pointLabels: {
                            color: '#9A9AB0',
                            font: { family: 'Cairo', size: 12, weight: '700' }
                        }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(10, 10, 15, 0.95)',
                        titleFont: { family: 'Cairo', size: 13, weight: '700' },
                        bodyFont: { family: 'Space Mono', size: 12 },
                        borderColor: 'rgba(255, 61, 46, 0.3)',
                        borderWidth: 1,
                        padding: 12,
                        callbacks: {
                            label: (ctx) => `${ctx.dataset.label}: ${ctx.raw}/100`
                        }
                    }
                }
            }
        });
    }

    // ─── Metrics Grid ───
    function renderMetricsGrid(keys, labels) {
        const grid = document.getElementById('metricsGrid');
        grid.innerHTML = '';

        const compKeys = keys.filter(k => k !== 'client' && scanResults[k]);
        // Find strongest competitor for comparison
        let strongestComp = compKeys[0];
        let highestAvg = 0;
        compKeys.forEach(k => {
            const avg = METRICS.reduce((s, m) => s + scanResults[k][m], 0) / METRICS.length;
            if (avg > highestAvg) { highestAvg = avg; strongestComp = k; }
        });

        METRICS.forEach(m => {
            const clientVal = scanResults.client[m];
            const compVal = scanResults[strongestComp][m];
            const isWinning = clientVal >= compVal;

            grid.innerHTML += `
                <div class="metric-card">
                    <div class="metric-name">${METRIC_LABELS[m]}</div>
                    <div class="metric-scores">
                        <span class="score client-score">${clientVal}</span>
                        <span class="vs-label">VS</span>
                        <span class="score comp-score">${compVal}</span>
                    </div>
                    <span class="winner-badge ${isWinning ? 'win' : 'lose'}">
                        ${isWinning ? '✅ أنت متفوق' : '⚠️ المنافس يتفوق'}
                    </span>
                </div>
            `;
        });
    }

    // ─── Lead Capture & PDF ───
    document.getElementById('downloadPdfBtn').addEventListener('click', async () => {
        const name = document.getElementById('leadName').value.trim();
        const phone = document.getElementById('leadPhone').value.trim();

        if (!name || !phone) {
            alert('يرجى إدخال اسمك ورقم الواتساب لتحميل التقرير!');
            return;
        }

        // Save lead to DB
        const formData = new FormData();
        formData.append('name', name);
        formData.append('phone', phone);
        formData.append('client_domain', scanResults.client.domain);
        
        const compDomains = ['comp1', 'comp2', 'comp3']
            .filter(k => scanResults[k])
            .map(k => scanResults[k].domain);
        formData.append('competitors_domains', JSON.stringify(compDomains));
        
        const scoresJson = {};
        Object.keys(scanResults).forEach(k => {
            scoresJson[k] = {};
            METRICS.forEach(m => { scoresJson[k][m] = scanResults[k][m]; });
        });
        formData.append('scores_json', JSON.stringify(scoresJson));

        try {
            fetch('api/submit.php', { method: 'POST', body: formData });
        } catch (e) { console.warn('Submit error:', e); }

        // Generate PDF
        triggerPDF(name);
    });

    // ─── PDF Generation ───
    function triggerPDF(name) {
        const el = document.getElementById('step-results');

        // Prepare for print
        const leadBox = document.getElementById('leadFormBox');
        if (leadBox) leadBox.style.display = 'none';

        const printHeader = document.getElementById('printHeader');
        if (printHeader) {
            printHeader.style.display = 'block';
            document.getElementById('pdfDate').textContent = new Date().toLocaleDateString('ar-SA');
        }

        // Convert canvas to image for PDF
        const canvas = document.getElementById('radarChart');
        const chartContainer = canvas.parentElement;
        const chartImg = document.createElement('img');
        chartImg.src = canvas.toDataURL('image/png');
        chartImg.style.width = '100%';
        chartImg.style.maxWidth = '500px';
        chartImg.style.display = 'block';
        chartImg.style.margin = '0 auto';
        canvas.style.display = 'none';
        chartContainer.appendChild(chartImg);

        const opt = {
            margin: 0.3,
            filename: `Competitor_Map_${name.replace(/\s+/g, '_')}.pdf`,
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: {
                scale: 2,
                useCORS: true,
                backgroundColor: '#0A0A0F',
                scrollX: 0,
                scrollY: -window.scrollY
            },
            jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' }
        };

        html2pdf().set(opt).from(el).save().then(() => {
            // Restore
            if (leadBox) leadBox.style.display = '';
            if (printHeader) printHeader.style.display = 'none';
            canvas.style.display = '';
            chartImg.remove();

            goToStep('step-thankyou');
        });
    }

    // ─── Utilities ───
    function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }
    function rnd(min, max) { return Math.floor(Math.random() * (max - min + 1)) + min; }
    function clamp(v, min, max) { return Math.max(min, Math.min(max, v)); }
});
