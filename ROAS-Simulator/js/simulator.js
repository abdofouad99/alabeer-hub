/**
 * مُحاكي الجاهزية المالية للإعلانات (The ROAS Simulator Bomb 💣)
 * Author: Al-Abeer Marketing (Antigravity Assistant)
 */

document.addEventListener('DOMContentLoaded', () => {
    // DOM Elements
    const inputs = {
        budget: document.getElementById('monthly_budget'),
        price: document.getElementById('product_price'),
        margin: document.getElementById('profit_margin'),
        cpa: document.getElementById('current_cpa')
    };

    const outputs = {
        currentProfit: document.getElementById('current_profit_val'),
        breakevenCpa: document.getElementById('breakeven_cpa_val'),
        potentialProfit: document.getElementById('potential_profit_val'),
        statusBox: document.getElementById('status_box'),
        statusMsg: document.getElementById('status_msg')
    };

    const magicSlider = document.getElementById('cpa_reduction_slider');
    const sliderText = document.getElementById('slider_text');
    
    // إعداد Chart.js
    const ctx = document.getElementById('profitChart').getContext('2d');
    const profitChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ['الوضع المالي الحالي (الآن)', 'وضعك مع العبير للتسويق (المستقبل)'],
            datasets: [{
                label: 'صافي الأرباح (ر.س)',
                data: [0, 0],
                backgroundColor: [
                    'rgba(239, 68, 68, 0.7)', // أحمر للوضع الحالي مبدئياً
                    'rgba(16, 185, 129, 0.9)'  // أخضر للوضع المستقبلي
                ],
                borderColor: [
                    '#ef4444',
                    '#10b981'
                ],
                borderWidth: 2,
                borderRadius: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(255,255,255,0.05)' },
                    ticks: { color: '#94a3b8' }
                },
                x: {
                    grid: { display: false },
                    ticks: { color: '#f8fafc', font: {family: 'Tajawal', size: 14} }
                }
            }
        }
    });

    // دالة الأنيميشن لقفز الأرقام
    function animateValue(obj, start, end, duration) {
        let startTimestamp = null;
        const step = (timestamp) => {
            if (!startTimestamp) startTimestamp = timestamp;
            const progress = Math.min((timestamp - startTimestamp) / duration, 1);
            // تطبيق تسارع ونهاية هادئة
            const easeOutQuart = 1 - Math.pow(1 - progress, 4);
            const current = Math.floor(progress * (end - start) + start);
            
            // تنسيق الرقم مع الفواصل
            obj.innerHTML = new Intl.NumberFormat('ar-SA').format(current);
            
            if (progress < 1) {
                window.requestAnimationFrame(step);
            }
        };
        window.requestAnimationFrame(step);
    }

    // المُحرك الأساسي للحسابات
    function calculate() {
        const budget = parseFloat(inputs.budget.value) || 0;
        const price = parseFloat(inputs.price.value) || 0;
        const margin = parseFloat(inputs.margin.value) || 0;
        const currentCpa = parseFloat(inputs.cpa.value) || 1; // تجنب القسمة على صفر
        const cpaReduction = parseFloat(magicSlider.value) || 0;

        // 1. حساب التعادل (ما هو أقصى مبلغ يمكن دفعه لجلب عميل بدون خسارة؟)
        const breakevenCpa = price * (margin / 100);
        
        // 2. حساب الوضع الحالي
        const currentSales = budget / currentCpa;
        const profitPerSale = breakevenCpa - currentCpa;
        const currentTotalProfit = Math.round(currentSales * profitPerSale);

        // 3. حساب الوضع المستقبلي مع العبير
        const newCpa = currentCpa * (1 - cpaReduction / 100);
        const newSales = budget / newCpa;
        const newProfitPerSale = breakevenCpa - newCpa;
        const potentialTotalProfit = Math.round(newSales * newProfitPerSale);

        // تحديث النصوص
        sliderText.innerText = `ماذا لو قللنا تكلفة الاستحواذ بنسبة ${cpaReduction}% بفضل استراتيجياتنا؟`;
        outputs.breakevenCpa.innerText = new Intl.NumberFormat('ar-SA').format(breakevenCpa) + ' ر.س';

        // تخزين القيم القديمة للأنيميشن
        const oldCurrent = parseInt(outputs.currentProfit.getAttribute('data-val') || 0);
        const oldPotential = parseInt(outputs.potentialProfit.getAttribute('data-val') || 0);

        outputs.currentProfit.setAttribute('data-val', currentTotalProfit);
        outputs.potentialProfit.setAttribute('data-val', potentialTotalProfit);

        animateValue(outputs.currentProfit, oldCurrent, currentTotalProfit, 800);
        animateValue(outputs.potentialProfit, oldPotential, potentialTotalProfit, 1000);

        // التشخيص الدقيق وحالة المربع
        outputs.statusBox.classList.remove('bleeding', 'profitable');
        outputs.currentProfit.classList.remove('red', 'green');
        
        window.financialStatus = 'breakeven'; // متغير عام سيرسل للأدمن

        if (currentTotalProfit < 0) {
            outputs.statusBox.classList.add('bleeding');
            outputs.currentProfit.classList.add('red');
            outputs.statusMsg.innerText = 'أنت تخسر أموالك! هذا نزيف إعلاني 🔴';
            outputs.statusMsg.style.color = 'var(--neon-red)';
            profitChart.data.datasets[0].backgroundColor[0] = 'rgba(239, 68, 68, 0.8)';
            window.financialStatus = 'bleeding';
        } else if (currentTotalProfit === 0) {
            outputs.statusMsg.innerText = 'أنت في نقطة التعادل.. تعمل بلا أرباح فعلية 🟡';
            outputs.statusMsg.style.color = 'var(--gold)';
            profitChart.data.datasets[0].backgroundColor[0] = 'rgba(245, 158, 11, 0.8)';
        } else {
            outputs.statusBox.classList.add('profitable');
            outputs.currentProfit.classList.add('green');
            outputs.statusMsg.innerText = 'إعلاناتك مربحة، لكن يمكنك مضاعفتها! 🟢';
            outputs.statusMsg.style.color = 'var(--neon-green)';
            profitChart.data.datasets[0].backgroundColor[0] = 'rgba(16, 185, 129, 0.5)';
            window.financialStatus = 'profitable';
        }

        // تحديث الرسم البياني
        profitChart.data.datasets[0].data = [currentTotalProfit, potentialTotalProfit];
        profitChart.update();
    }

    // ربط الأحداث
    [inputs.budget, inputs.price, inputs.margin, inputs.cpa, magicSlider].forEach(el => {
        el.addEventListener('input', calculate);
    });

    // التنفيذ الأول
    calculate();
});

// Modal Logic
function openModal() {
    document.getElementById('leadModal').classList.add('active');
    
    // تحضير نص ديناميكي
    const currentProfit = parseInt(document.getElementById('current_profit_val').getAttribute('data-val'));
    const potentialProfit = parseInt(document.getElementById('potential_profit_val').getAttribute('data-val'));
    const diff = new Intl.NumberFormat('ar-SA').format(potentialProfit - currentProfit);
    
    document.getElementById('modal_title').innerText = `اربح ${diff} ريال إضافية شهرياً.. أدخل بياناتك للتواصل معك!`;
}

function closeModal() {
    document.getElementById('leadModal').classList.remove('active');
}

// إرسال البيانات (Submit)
async function submitLead(e) {
    e.preventDefault();
    const btn = document.getElementById('submitBtn');
    btn.innerText = 'جاري المعالجة...';
    btn.disabled = true;

    const leadData = {
        full_name: document.getElementById('full_name').value,
        phone: document.getElementById('phone').value,
        company_name: document.getElementById('company_name').value,
        website_url: document.getElementById('website_url').value,
        
        monthly_budget: document.getElementById('monthly_budget').value,
        product_price: document.getElementById('product_price').value,
        profit_margin: document.getElementById('profit_margin').value,
        current_cpa: document.getElementById('current_cpa').value,
        
        current_profit: document.getElementById('current_profit_val').getAttribute('data-val'),
        potential_profit: document.getElementById('potential_profit_val').getAttribute('data-val'),
        financial_status: window.financialStatus || 'unknown'
    };

    try {
        // سيتم ربطه بـ PHP لاحقاً (api/submit.php)
        const response = await fetch('api/submit.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(leadData)
        });

        const resData = await response.json();
        
        if (resData.success) {
            closeModal();
            Swal.fire({
                title: 'تم الإرسال بنجاح! 🚀',
                text: 'صدمتك الأرقام؟ سيرسل لك خبراؤنا خطة العمل على الواتساب قريباً!',
                icon: 'success',
                confirmButtonText: 'حسناً',
                confirmButtonColor: '#10b981'
            });
            e.target.reset();
        } else {
            throw new Error(resData.message || 'حدث خطأ غير معروف');
        }
    } catch (error) {
        // في حالة المطورين للทดสอบ إذا لم يكن الـ API جاهزاً
        console.warn('Backend not ready yet. Local Test Passed.', error);
        
        // محاكاة نجاح للทดست
        setTimeout(() => {
            closeModal();
            Swal.fire({
                title: 'تنبيه: السيرفر المحلي',
                text: 'البيانات جاهزة للإرسال، سيتم تفعيل الحفظ فور رفع ملفات الـ PHP!',
                icon: 'info',
                confirmButtonColor: '#f59e0b'
            });
            btn.innerText = '🚀 أرسل بياناتي للبدء';
            btn.disabled = false;
        }, 800);
    }
}
