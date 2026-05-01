// دوال لوحة تحكم الأدمن الخاصة بنظام ROAS
document.addEventListener('DOMContentLoaded', () => {
    checkAuth();
});

async function checkAuth() {
    try {
        const res = await fetch('../api/admin/list.php');
        if (res.status === 401) {
            window.location.href = 'login.html';
            return;
        }
        loadStats();
        loadTable();
    } catch (error) {
        console.error("Auth check failed.", error);
    }
}

async function loadStats() {
    try {
        const res = await fetch('../api/admin/stats.php');
        const data = await res.json();
        
        if (data.success) {
            document.getElementById('total_leads').innerText = data.data.total_leads;
            document.getElementById('total_budget').innerText = new Intl.NumberFormat('ar-SA').format(data.data.total_ad_budget) + ' ر.س';
            document.getElementById('total_bleeding').innerText = data.data.bleeding;
            document.getElementById('total_profitable').innerText = data.data.profitable;
        }
    } catch (e) { console.error('Error loading stats', e); }
}

async function loadTable() {
    try {
        const res = await fetch('../api/admin/list.php');
        const data = await res.json();
        const tbody = document.getElementById('tableBody');
        tbody.innerHTML = '';

        if (data.success && data.data.length > 0) {
            data.data.forEach(item => {
                const tr = document.createElement('tr');
                
                // تحديد شكل الحالة المالية
                let badgeClass = '';
                let badgeText = '';
                if (item.financial_status === 'bleeding') { badgeClass = 'bleeding'; badgeText = 'نزيف (خسارة)'; }
                else if (item.financial_status === 'breakeven') { badgeClass = 'breakeven'; badgeText = 'نقطة تعادل'; }
                else if (item.financial_status === 'profitable') { badgeClass = 'profitable'; badgeText = 'رابح (يحتاج نمو)'; }

                const profitColor = parseFloat(item.current_profit) < 0 ? 'var(--neon-red)' : 'var(--neon-green)';

                tr.innerHTML = `
                    <td style="color:var(--text-muted); font-size:0.9rem">${item.created_at}</td>
                    <td>
                        <strong>${item.full_name}</strong><br>
                        <span style="font-size:0.85rem; color:var(--text-muted)">${item.company_name || 'بدون شركة'}</span>
                    </td>
                    <td><a href="https://wa.me/${item.phone}" target="_blank" style="color:var(--neon-green); text-decoration:none;">📱 ${item.phone}</a></td>
                    <td style="font-weight:bold">${Number(item.monthly_budget).toLocaleString()} ر.س</td>
                    <td>${item.current_cpa} ر.س</td>
                    <td style="color:${profitColor}; font-weight:bold; font-family:monospace" dir="ltr">${Number(item.current_profit).toLocaleString()} ر.س</td>
                    <td><span class="badge ${badgeClass}">${badgeText}</span></td>
                `;
                tbody.appendChild(tr);
            });
        } else {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;">لا يوجد أي عملاء حتى الآن</td></tr>';
        }
    } catch (e) {
        document.getElementById('tableBody').innerHTML = '<tr><td colspan="7" style="text-align:center; color:red;">خطأ في جلب البيانات من الخادم</td></tr>';
    }
}

async function logout() {
    await fetch('../api/admin/logout.php');
    window.location.href = 'login.html';
}

function exportData() {
    window.open('../api/admin/list.php?export=csv', '_blank'); // يمكن برمجة التصدير المباشر بالفروه إذا طُلب لاحقا
    alert('تصدير CSV قيد التطوير!');
}
