document.addEventListener('DOMContentLoaded', async () => {
    // 1. التحقق من الجلسة أولاً (Protect Dashboard)
    try {
        const statsRes = await fetch('../api/admin/stats.php');
        if (statsRes.status === 401) {
            window.location.href = 'login.html';
            return;
        }
        const statsData = await statsRes.json();
        if (statsData.success) {
            document.getElementById('totalLeads').innerText = statsData.data.total_leads;
            document.getElementById('avgScore').innerText = statsData.data.average_conversion + '%';
            document.getElementById('dangerLeads').innerText = statsData.data.danger_leads;
        }
        
        // 2. جلب وتعبئة قائمة العملاء
        const listRes = await fetch('../api/admin/list.php');
        const listData = await listRes.json();
        if (listData.success) {
            const tableBody = document.querySelector('#leadsTable tbody');
            tableBody.innerHTML = '';
            
            listData.data.forEach(lead => {
                let statusBadge = '<span class="badge badge-success">تحويل جيد</span>';
                if (lead.lp_score <= 75) statusBadge = '<span class="badge badge-warning">تسريب ملحوظ</span>';
                if (lead.lp_score <= 50) statusBadge = '<span class="badge badge-danger">تسريب كارثي</span>';
                
                const tr = document.createElement('tr');
                const phoneClean = lead.client_phone ? lead.client_phone.replace(/[^0-9]/g, '') : '';
                tr.innerHTML = `
                    <td>${lead.id}</td>
                    <td>${lead.client_name}</td>
                    <td>${lead.client_phone || 'غير مسجل'}</td>
                    <td><a href="${lead.website_url}" target="_blank" style="color:#00F2FE;">موقع العميل</a></td>
                    <td>${lead.client_email || 'غير مسجل'}</td>
                    <td>${statusBadge} (${lead.lp_score}%)</td>
                    <td>${new Date(lead.created_at).toLocaleDateString('ar-SA')}</td>
                    <td>
                        <button class="action-btn" onclick="window.open('https://wa.me/${phoneClean}', '_blank')">رسالة 💬</button>
                    </td>
                `;
                tableBody.appendChild(tr);
            });
        }
    } catch (e) {
        console.error('Error loading dashboard:', e);
    }

    // Logout
    document.getElementById('logoutBtn').addEventListener('click', async () => {
        await fetch('../api/admin/logout.php');
        window.location.href = 'login.html';
    });
});
