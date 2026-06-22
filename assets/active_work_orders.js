const activeRows = document.querySelector('#activeRows');
const activeMessage = document.querySelector('#activeMessage');
const activeReportDate = document.querySelector('#activeReportDate');
const currentActiveReportId = document.querySelector('#currentActiveReportId');
const refreshActiveOrders = document.querySelector('#refreshActiveOrders');
const saveActiveReport = document.querySelector('#saveActiveReport');
const exportActiveReport = document.querySelector('#exportActiveReport');
const refreshActiveReports = document.querySelector('#refreshActiveReports');
const savedActiveRows = document.querySelector('#savedActiveRows');
const savedActiveMessage = document.querySelector('#savedActiveMessage');
const activeTotalDuctArea = document.querySelector('#activeTotalDuctArea');
const activeTotalDuctWeight = document.querySelector('#activeTotalDuctWeight');
const activeTotalWoQty = document.querySelector('#activeTotalWoQty');

if (refreshActiveOrders) {
    refreshActiveOrders.addEventListener('click', () => {
        currentActiveReportId.value = '';
        saveActiveReport.textContent = 'Save Report';
        loadActiveWorkOrders();
    });
}

if (saveActiveReport) {
    saveActiveReport.addEventListener('click', saveCurrentActiveReport);
}

if (exportActiveReport) {
    exportActiveReport.addEventListener('click', exportActivePdf);
}

if (refreshActiveReports) {
    refreshActiveReports.addEventListener('click', loadActiveReportList);
}

document.addEventListener('DOMContentLoaded', () => {
    loadActiveWorkOrders();
    loadActiveReportList();
});

async function loadActiveWorkOrders() {
    setActiveMessage('');

    try {
        const response = await fetch('list_active_work_orders.php');
        const payload = await response.json();

        if (!response.ok || !payload.success) {
            setActiveMessage(payload.message || 'Could not load active work orders.');
            return;
        }

        renderActiveRows(payload.items || []);
    } catch (error) {
        setActiveMessage('Could not load active work orders.');
    }
}

function renderActiveRows(items) {
    activeRows.innerHTML = '';

    if (!items.length) {
        activeRows.innerHTML = '<tr class="empty-row"><td colspan="12">No active work orders loaded.</td></tr>';
        updateActiveTotals();
        return;
    }

    items.forEach((item, index) => {
        const row = document.createElement('tr');
        row.dataset.ductArea = numberValue(item.duct_area);
        row.dataset.ductWeight = numberValue(item.duct_weight);
        row.dataset.woQty = numberValue(item.wo_qty);
        row.innerHTML = `
            <td>${index + 1}</td>
            <td>${escapeHtml(item.wo_no || '')}</td>
            <td>${escapeHtml(item.customer_name || '')}</td>
            <td>${escapeHtml(formatDisplayDate(item.edd || ''))}</td>
            <td>${escapeHtml(formatDisplayDate(item.prod_started_date || ''))}</td>
            <td>${escapeHtml(item.status || '')}</td>
            <td>${escapeHtml(item.finish || '')}</td>
            <td>${escapeHtml(item.prod_sup_note || '')}</td>
            <td>${escapeHtml(item.destination || '')}</td>
            <td>${formatNumber(item.duct_area)}</td>
            <td>${formatNumber(item.duct_weight)}</td>
            <td>${formatNumber(item.wo_qty)}</td>
        `;
        activeRows.appendChild(row);
    });

    updateActiveTotals();
}

function updateActiveTotals() {
    const totals = collectActiveItems().reduce((carry, item) => {
        carry.ductArea += numberValue(item.duct_area);
        carry.ductWeight += numberValue(item.duct_weight);
        carry.woQty += numberValue(item.wo_qty);
        return carry;
    }, { ductArea: 0, ductWeight: 0, woQty: 0 });

    activeTotalDuctArea.textContent = formatNumber(totals.ductArea);
    activeTotalDuctWeight.textContent = formatNumber(totals.ductWeight);
    activeTotalWoQty.textContent = formatNumber(totals.woQty);
}

function collectActiveItems() {
    return [...activeRows.querySelectorAll('tr:not(.empty-row)')].map((row) => ({
        wo_no: cellText(row, 1),
        customer_name: cellText(row, 2),
        edd: cellText(row, 3),
        prod_started_date: cellText(row, 4),
        status: cellText(row, 5),
        finish: cellText(row, 6),
        prod_sup_note: cellText(row, 7),
        destination: cellText(row, 8),
        duct_area: numberValue(cellText(row, 9)),
        duct_weight: numberValue(cellText(row, 10)),
        wo_qty: numberValue(cellText(row, 11)),
    }));
}

async function saveCurrentActiveReport() {
    const items = collectActiveItems();
    if (!items.length) {
        setActiveMessage('No active work orders are available to save.');
        return;
    }

    try {
        const response = await fetch('save_active_report.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                report_id: currentActiveReportId.value,
                report_date: activeReportDate.value,
                items,
            }),
        });
        const payload = await response.json();

        if (!response.ok || !payload.success) {
            setActiveMessage(payload.message || 'Save failed.');
            return;
        }

        currentActiveReportId.value = payload.report_id;
        saveActiveReport.textContent = 'Modify Report';
        setActiveMessage(payload.message);
        loadActiveReportList();
    } catch (error) {
        setActiveMessage('Save failed. Please check the server and database connection.');
    }
}

async function loadActiveReportList() {
    if (!savedActiveRows) {
        return;
    }

    try {
        const response = await fetch('list_active_reports.php');
        const payload = await response.json();

        if (!response.ok || !payload.success) {
            savedActiveMessage.textContent = payload.message || 'Could not load saved active reports.';
            return;
        }

        renderSavedActiveReports(payload.reports || []);
    } catch (error) {
        savedActiveMessage.textContent = 'Could not load saved active reports.';
    }
}

function renderSavedActiveReports(reports) {
    savedActiveRows.innerHTML = '';

    if (!reports.length) {
        savedActiveRows.innerHTML = '<tr><td colspan="4">No saved active reports yet.</td></tr>';
        return;
    }

    reports.forEach((report) => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${escapeHtml(report.report_name || '')}</td>
            <td>${escapeHtml(report.report_date || '')}</td>
            <td>${escapeHtml(report.updated_at || '')}</td>
            <td>
                <button class="load-report" type="button">Open</button>
                <button class="delete-report" type="button">Delete</button>
            </td>
        `;
        row.querySelector('.load-report').addEventListener('click', () => loadSavedActiveReport(report.id));
        row.querySelector('.delete-report').addEventListener('click', () => deleteSavedActiveReport(report.id, report.report_name));
        savedActiveRows.appendChild(row);
    });
}

async function loadSavedActiveReport(reportId) {
    try {
        const response = await fetch(`get_active_report.php?id=${encodeURIComponent(reportId)}`);
        const payload = await response.json();

        if (!response.ok || !payload.success) {
            savedActiveMessage.textContent = payload.message || 'Could not open report.';
            return;
        }

        currentActiveReportId.value = payload.report.id;
        activeReportDate.value = payload.report.report_date;
        saveActiveReport.textContent = 'Modify Report';
        renderActiveRows(payload.items || []);
        setActiveMessage(`${payload.report.report_name} loaded for modification.`);
        window.location.hash = '#activeExporter';
    } catch (error) {
        savedActiveMessage.textContent = 'Could not open report.';
    }
}

async function deleteSavedActiveReport(reportId, reportName) {
    if (!confirm(`Delete ${reportName}?`)) {
        return;
    }

    try {
        const response = await fetch('delete_active_report.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: reportId }),
        });
        const payload = await response.json();

        if (!response.ok || !payload.success) {
            savedActiveMessage.textContent = payload.message || 'Could not delete report.';
            return;
        }

        if (currentActiveReportId.value === String(reportId)) {
            currentActiveReportId.value = '';
            saveActiveReport.textContent = 'Save Report';
        }

        savedActiveMessage.textContent = payload.message;
        loadActiveReportList();
    } catch (error) {
        savedActiveMessage.textContent = 'Could not delete report.';
    }
}

function exportActivePdf() {
    const rows = [...activeRows.querySelectorAll('tr:not(.empty-row)')];
    if (!rows.length) {
        setActiveMessage('No active work orders are available to export.');
        return;
    }

    const reportDate = activeReportDate?.value || new Date().toISOString().slice(0, 10);
    const printWindow = window.open('', '_blank');

    if (!printWindow) {
        setActiveMessage('Please allow popups to export the PDF.');
        return;
    }

    const headers = ['SLNO', 'WONO', 'CUSTOMER', 'EDD', 'PRODSTARTEDDATE', 'STATUS', 'FINISH', 'ProdSupNote', 'Destination', 'DuctArea', 'DuctWeight', 'WOQTY'];
    const bodyRows = rows.map((row, rowIndex) => `
        <tr>
            ${headers.map((_, index) => `<td>${escapeHtml(index === 0 ? rowIndex + 1 : cellText(row, index))}</td>`).join('')}
        </tr>
    `).join('');

    printWindow.document.write(`
        <!doctype html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>Active Work Order List-${escapeHtml(reportDate)}</title>
            <style>
                @page { size: A4 landscape; margin: 8mm; }
                * { box-sizing: border-box; }
                body { font-family: Arial, Helvetica, sans-serif; margin: 0; color: #000; }
                h1 { font-size: 18px; margin: 0 0 8px; text-align: center; }
                .meta { display: flex; font-size: 9px; justify-content: space-between; margin-bottom: 6px; }
                table { border-collapse: collapse; table-layout: fixed; width: 100%; }
                th, td {
                    border: 1px solid #111;
                    font-size: 6.5px;
                    font-weight: 700;
                    padding: 3px 2px;
                    text-align: center;
                    vertical-align: middle;
                    word-break: break-word;
                }
                th { background: #f2f4f7; }
                tbody tr:nth-child(odd) td { background: #f8fafc; }
                tfoot td { background: #fff2cc; font-weight: 800; }
                .customer { width: 23%; }
                .note { width: 9%; }
                .small { width: 6%; }
            </style>
        </head>
        <body>
            <h1>Active Work Order List</h1>
            <div class="meta">
                <span>Report Date: ${escapeHtml(reportDate)}</span>
                <span>Printed On: ${escapeHtml(new Date().toLocaleDateString('en-GB'))}</span>
            </div>
            <table>
                <thead>
                    <tr>${headers.map((header) => `<th>${escapeHtml(header)}</th>`).join('')}</tr>
                </thead>
                <tbody>${bodyRows}</tbody>
                <tfoot>
                    <tr>
                        <td colspan="8" style="background: transparent; border: 0;"></td>
                        <td>Totals:</td>
                        <td>${escapeHtml(activeTotalDuctArea.textContent)}</td>
                        <td>${escapeHtml(activeTotalDuctWeight.textContent)}</td>
                        <td>${escapeHtml(activeTotalWoQty.textContent)}</td>
                    </tr>
                </tfoot>
            </table>
            <script>
                window.onload = () => {
                    window.focus();
                    window.print();
                };
            <\/script>
        </body>
        </html>
    `);
    printWindow.document.close();
}

function cellText(row, index) {
    return (row.children[index]?.textContent || '').trim().replace(/\s+/g, ' ');
}

function setActiveMessage(message) {
    activeMessage.textContent = message;
}

function numberValue(value) {
    const parsed = Number.parseFloat(String(value || '').replace(/,/g, ''));
    return Number.isFinite(parsed) ? parsed : 0;
}

function formatNumber(value) {
    return numberValue(value).toLocaleString('en-US', {
        maximumFractionDigits: 2,
    });
}

function formatDisplayDate(value) {
    const raw = String(value || '').trim();
    const match = raw.match(/^(\d{1,2})\/([A-Za-z]{3})\/(\d{4})$/);

    if (!match) {
        return raw;
    }

    return `${match[1].padStart(2, '0')}-${match[2]}-${match[3]}`;
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
