document.addEventListener('DOMContentLoaded', async () => {
    try {
        const statsRes = await fetch('../api/admin/stats.php');
        if (statsRes.status === 401) { window.location.href = 'login.html'; return; }
        const statsData = await statsRes.json();
        if (statsData.success) {
            document.getElementById('totalLeads').innerText = statsData.data.total_leads;
            document.getElementById('todayLeads').innerText = statsData.data.today_leads;
        }
        const listRes = await fetch('../api/admin/list.php');
        const listData = await listRes.json();
        if (listData.success) renderTable(listData.data);
    } catch (e) { console.error('Load error:', e); }

    function renderTable(leads) {
        const tbody = document.querySelector('#leadsTable tbody');
        tbody.innerHTML = '';
        if (!leads.length) { tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:30px;color:#4a6058;">لا توجد بيانات بعد</td></tr>'; return; }

        leads.forEach(lead => {
            const score = parseInt(lead.campaign_score) || 0;
            let badge, levelClass;
            if (score <= 30) { badge = '🔴 عناية مركزة'; levelClass = 'badge-red'; }
            else if (score <= 55) { badge = '🟠 مريضة'; levelClass = 'badge-orange'; }
            else if (score <= 75) { badge = '🟡 بعكاز'; levelClass = 'badge-yellow'; }
            else { badge = '🟢 صحية'; levelClass = 'badge-green'; }

            // Find weakest point from problems
            let weakestPoint = 'حملته';
            try {
                const probs = JSON.parse(lead.problems_json || '[]');
                if (probs.length > 0) weakestPoint = probs[0].substring(0, 40) + '...';
            } catch (e) {}

            const phoneClean = (lead.client_phone || '').replace(/[^0-9]/g, '');
            const waMsg = encodeURIComponent(`مرحباً ${lead.client_name}، قمنا بتشخيص حملتك الإعلانية (${lead.platform}) — ميزانيتك: ${lead.monthly_budget} — درجتك: ${score}/100. هناك فرصة كبيرة لتحسين نتائجك! هل يمكننا مساعدتك؟`);

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${lead.id}</td>
                <td><strong>${lead.client_name}</strong></td>
                <td style="direction:ltr;font-family:'Space Mono';font-size:12px">${lead.client_phone || '-'}</td>
                <td><span class="platform-tag">${lead.platform || '-'}</span></td>
                <td><span class="budget-tag">💰 ${lead.monthly_budget || '-'}</span></td>
                <td style="font-family:'Space Mono';font-weight:700;font-size:16px;color:${score<=30?'#ff3b3b':score<=55?'#ff6b35':score<=75?'#ffaa00':'#00ff88'}">${score}</td>
                <td><span class="badge ${levelClass}">${badge}</span></td>
                <td>${new Date(lead.created_at).toLocaleDateString('ar-SA')}</td>
                <td>
                    <input class="notes-input" value="${lead.notes || ''}" data-id="${lead.id}" placeholder="ملاحظة...">
                    <br><button class="btn-save" onclick="saveNote(${lead.id}, this)">حفظ</button>
                </td>
                <td>
                    <button class="btn-wa" onclick="window.open('https://wa.me/${phoneClean}?text=${waMsg}','_blank')">
                        <i class="fa-brands fa-whatsapp"></i> رسالة
                    </button>
                </td>
            `;
            tbody.appendChild(tr);
        });
    }

    window.saveNote = async function(id, btn) {
        const input = btn.previousElementSibling.previousElementSibling;
        try {
            await fetch('../api/admin/update_notes.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id, notes: input.value }) });
            btn.textContent = '✅ تم'; setTimeout(() => { btn.textContent = 'حفظ'; }, 2000);
        } catch (e) { alert('خطأ في الحفظ'); }
    };

    document.getElementById('exportBtn').addEventListener('click', () => {
        const table = document.getElementById('leadsTable');
        let csv = '\uFEFF';
        table.querySelectorAll('tr').forEach(row => {
            const cols = row.querySelectorAll('th, td');
            const rowData = [];
            cols.forEach(col => rowData.push('"' + col.innerText.replace(/"/g, '""').replace(/\n/g, ' ') + '"'));
            csv += rowData.join(',') + '\n';
        });
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob); link.download = 'campaign_leads_' + new Date().toISOString().slice(0, 10) + '.csv'; link.click();
    });

    document.getElementById('logoutBtn').addEventListener('click', async () => {
        await fetch('../api/admin/logout.php'); window.location.href = 'login.html';
    });
});
