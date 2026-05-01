document.addEventListener('DOMContentLoaded', async () => {
    // ─── 1. Auth Check + Stats ───
    try {
        const statsRes = await fetch('../api/admin/stats.php');
        if (statsRes.status === 401) {
            window.location.href = 'login.html';
            return;
        }
        const statsData = await statsRes.json();
        if (statsData.success) {
            document.getElementById('totalLeads').innerText = statsData.data.total_leads;
            document.getElementById('todayLeads').innerText = statsData.data.today_leads;
        }

        // ─── 2. Load Leads ───
        const listRes = await fetch('../api/admin/list.php');
        const listData = await listRes.json();
        if (listData.success) {
            renderTable(listData.data);
        }
    } catch (e) {
        console.error('Dashboard load error:', e);
    }

    // ─── Render Table ───
    function renderTable(leads) {
        const tbody = document.querySelector('#leadsTable tbody');
        tbody.innerHTML = '';

        if (leads.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:30px; color:#5A5A70;">لا توجد بيانات بعد</td></tr>';
            return;
        }

        leads.forEach(lead => {
            // Parse scores to determine status
            let statusBadge = '<span class="badge badge-yellow">جديد</span>';
            let weakestPoint = '';
            
            try {
                const scores = JSON.parse(lead.scores_json || '{}');
                if (scores.client) {
                    const clientAvg = Object.values(scores.client).reduce((a, b) => a + b, 0) / Object.values(scores.client).length;
                    if (clientAvg <= 40) {
                        statusBadge = '<span class="badge badge-red">ضعيف جداً 🔴</span>';
                    } else if (clientAvg <= 70) {
                        statusBadge = '<span class="badge badge-yellow">متوسط 🟡</span>';
                    } else {
                        statusBadge = '<span class="badge badge-green">جيد 🟢</span>';
                    }

                    // Find weakest metric for WhatsApp message
                    const metrics = { speed: 'السرعة', ssl: 'الأمان', pixel: 'التتبع', seo: 'SEO', content: 'المحتوى', adReady: 'الجاهزية' };
                    let minVal = 100, minKey = 'speed';
                    Object.keys(metrics).forEach(k => {
                        if (scores.client[k] !== undefined && scores.client[k] < minVal) {
                            minVal = scores.client[k];
                            minKey = k;
                        }
                    });
                    weakestPoint = metrics[minKey];
                }
            } catch (e) {}

            // Parse competitor domains
            let compLinks = '';
            try {
                const comps = JSON.parse(lead.competitors_domains || '[]');
                compLinks = comps.map((c, i) => `<a href="https://${c}" target="_blank" class="link">منافس ${i + 1}: ${c}</a>`).join('');
            } catch (e) {
                compLinks = lead.competitors_domains || '-';
            }

            const phoneClean = (lead.client_phone || '').replace(/[^0-9]/g, '');
            const waMsg = encodeURIComponent(`مرحباً ${lead.client_name}، قمنا بتحليل موقعك ${lead.client_domain} مقارنة بمنافسيك وظهرت لدينا فرصة كبيرة لتحسين ${weakestPoint}. هل يمكننا مساعدتك؟`);

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${lead.id}</td>
                <td>${lead.client_name}</td>
                <td style="direction:ltr; font-family:'Space Mono',monospace; font-size:12px;">${lead.client_phone || '-'}</td>
                <td><a href="https://${lead.client_domain}" target="_blank" class="link">${lead.client_domain}</a></td>
                <td><div class="comp-links">${compLinks}</div></td>
                <td>${statusBadge}</td>
                <td>${new Date(lead.created_at).toLocaleDateString('ar-SA')}</td>
                <td>
                    <input class="notes-input" value="${lead.notes || ''}" data-id="${lead.id}" placeholder="ملاحظة...">
                    <br><button class="btn-save-note" onclick="saveNote(${lead.id}, this)">حفظ</button>
                </td>
                <td>
                    <button class="btn-wa" onclick="window.open('https://wa.me/${phoneClean}?text=${waMsg}', '_blank')">
                        <i class="fa-brands fa-whatsapp"></i> رسالة
                    </button>
                </td>
            `;
            tbody.appendChild(tr);
        });
    }

    // ─── Save Note ───
    window.saveNote = async function(id, btn) {
        const input = btn.previousElementSibling.previousElementSibling;
        const notes = input.value;
        try {
            await fetch('../api/admin/update_notes.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id, notes })
            });
            btn.textContent = '✅ تم';
            setTimeout(() => { btn.textContent = 'حفظ'; }, 2000);
        } catch (e) {
            alert('خطأ في الحفظ');
        }
    };

    // ─── Export Excel ───
    document.getElementById('exportBtn').addEventListener('click', () => {
        const table = document.getElementById('leadsTable');
        let csv = '\uFEFF'; // BOM for Arabic
        const rows = table.querySelectorAll('tr');
        rows.forEach(row => {
            const cols = row.querySelectorAll('th, td');
            const rowData = [];
            cols.forEach(col => {
                let text = col.innerText.replace(/"/g, '""').replace(/\n/g, ' ');
                rowData.push('"' + text + '"');
            });
            csv += rowData.join(',') + '\n';
        });

        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'competitor_leads_' + new Date().toISOString().slice(0, 10) + '.csv';
        link.click();
    });

    // ─── Logout ───
    document.getElementById('logoutBtn').addEventListener('click', async () => {
        await fetch('../api/admin/logout.php');
        window.location.href = 'login.html';
    });
});
