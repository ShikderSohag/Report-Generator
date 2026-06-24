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
const appTimeZone = 'Asia/Riyadh';
const exporterConfig = {
    listEndpoint: document.body.dataset.listEndpoint || 'list_active_work_orders.php',
    saveEndpoint: document.body.dataset.saveEndpoint || 'save_active_report.php',
    listReportsEndpoint: document.body.dataset.listReportsEndpoint || 'list_active_reports.php',
    getReportEndpoint: document.body.dataset.getReportEndpoint || 'get_active_report.php',
    deleteReportEndpoint: document.body.dataset.deleteReportEndpoint || 'delete_active_report.php',
    exportTitle: document.body.dataset.exportTitle || 'Active Work Order List',
    emptyText: document.body.dataset.emptyText || 'No active work orders loaded.',
    noRowsText: document.body.dataset.noRowsText || 'No active work orders are available.',
    savedEmptyText: document.body.dataset.savedEmptyText || 'No saved active reports yet.',
    loadedHash: document.body.dataset.loadedHash || '#activeExporter',
    highlightEdd: document.body.dataset.highlightEdd === 'age',
};

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
        const response = await fetch(exporterConfig.listEndpoint);
        const payload = await readJsonResponse(response);

        if (!response.ok || !payload.success) {
            setActiveMessage(payload.message || `Could not load ${exporterConfig.exportTitle.toLowerCase()}.`);
            return;
        }

        renderActiveRows(payload.items || []);
    } catch (error) {
        setActiveMessage(error.message || `Could not load ${exporterConfig.exportTitle.toLowerCase()}.`);
    }
}

function renderActiveRows(items) {
    activeRows.innerHTML = '';

    if (!items.length) {
        activeRows.innerHTML = `<tr class="empty-row"><td colspan="12">${escapeHtml(exporterConfig.emptyText)}</td></tr>`;
        updateActiveTotals();
        return;
    }

    items.forEach((item, index) => {
        const row = document.createElement('tr');
        if (isReceivedStatus(item.status)) {
            row.classList.add('received-row');
        }
        row.dataset.ductArea = numberValue(item.duct_area);
        row.dataset.ductWeight = numberValue(item.duct_weight);
        row.dataset.woQty = numberValue(item.wo_qty);
        const eddStyle = exporterConfig.highlightEdd ? dateAgeStyle(item.edd) : '';
        row.innerHTML = `
            <td>${index + 1}</td>
            <td>${escapeHtml(item.wo_no || '')}</td>
            <td>${escapeHtml(item.customer_name || '')}</td>
            <td${eddStyle}>${escapeHtml(formatDisplayDate(item.edd || ''))}</td>
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
        setActiveMessage(exporterConfig.noRowsText);
        return;
    }

    try {
        const response = await fetch(exporterConfig.saveEndpoint, {
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
        const response = await fetch(exporterConfig.listReportsEndpoint);
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
        savedActiveRows.innerHTML = `<tr><td colspan="4">${escapeHtml(exporterConfig.savedEmptyText)}</td></tr>`;
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
        const response = await fetch(`${exporterConfig.getReportEndpoint}?id=${encodeURIComponent(reportId)}`);
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
        window.location.hash = exporterConfig.loadedHash;
    } catch (error) {
        savedActiveMessage.textContent = 'Could not open report.';
    }
}

async function deleteSavedActiveReport(reportId, reportName) {
    if (!confirm(`Delete ${reportName}?`)) {
        return;
    }

    try {
        const response = await fetch(exporterConfig.deleteReportEndpoint, {
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
        setActiveMessage(exporterConfig.noRowsText);
        return;
    }

    const reportDate = activeReportDate?.value || appDateIso();
    const printWindow = window.open('', '_blank');

    if (!printWindow) {
        setActiveMessage('Please allow popups to export the PDF.');
        return;
    }

    const headers = ['SLNO', 'WONO', 'CUSTOMER', 'EDD', 'PRODSTARTEDDATE', 'STATUS', 'FINISH', 'ProdSupNote', 'Destination', 'DuctArea', 'DuctWeight', 'WOQTY'];
    const rowsPerPage = 36;
    const pages = chunkRows(rows, rowsPerPage);
    const printedPages = pages.map((pageRows, pageIndex) => {
        const bodyRows = pageRows.map((row, rowIndex) => {
            const serial = (pageIndex * rowsPerPage) + rowIndex + 1;

            return `
                <tr class="${isReceivedStatus(cellText(row, 5)) ? 'received-row' : ''}">
                    ${headers.map((_, index) => {
                        const value = index === 0 ? serial : cellText(row, index);
                        const style = exporterConfig.highlightEdd && index === 3 ? dateAgeStyle(value) : '';
                        return `<td${style}>${escapeHtml(value)}</td>`;
                    }).join('')}
                </tr>
            `;
        }).join('');
        const totals = pageIndex === pages.length - 1 ? `
            <table class="totals-table">
                <colgroup>
                    <col class="print-col-slno">
                    <col class="print-col-wono">
                    <col class="print-col-customer">
                    <col class="print-col-date">
                    <col class="print-col-date">
                    <col class="print-col-status">
                    <col class="print-col-finish">
                    <col class="print-col-note">
                    <col class="print-col-destination">
                    <col class="print-col-number">
                    <col class="print-col-number">
                    <col class="print-col-qty">
                </colgroup>
                <tr>
                    <td class="blank" colspan="8"></td>
                    <td>Totals:</td>
                    <td>${escapeHtml(activeTotalDuctArea.textContent)}</td>
                    <td>${escapeHtml(activeTotalDuctWeight.textContent)}</td>
                    <td>${escapeHtml(activeTotalWoQty.textContent)}</td>
                </tr>
            </table>
        ` : '';

        return `
            <section class="print-page">
                <h1>${escapeHtml(exporterConfig.exportTitle)}</h1>
                <div class="meta">
                    <span>Report Date: ${escapeHtml(reportDate)}</span>
                    <span>Printed On: ${escapeHtml(appDateDisplay())}</span>
                </div>
                <table>
                    <colgroup>
                        <col class="print-col-slno">
                        <col class="print-col-wono">
                        <col class="print-col-customer">
                        <col class="print-col-date">
                        <col class="print-col-date">
                        <col class="print-col-status">
                        <col class="print-col-finish">
                        <col class="print-col-note">
                        <col class="print-col-destination">
                        <col class="print-col-number">
                        <col class="print-col-number">
                        <col class="print-col-qty">
                    </colgroup>
                    <thead>
                        <tr>${headers.map((header) => `<th>${escapeHtml(header)}</th>`).join('')}</tr>
                    </thead>
                    <tbody>${bodyRows}</tbody>
                </table>
                ${totals}
                <div class="page-number">Page - ${pageIndex + 1}/${pages.length}</div>
            </section>
        `;
    }).join('');

    printWindow.document.write(`
        <!doctype html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>${escapeHtml(exporterConfig.exportTitle)}-${escapeHtml(reportDate)}</title>
            <style>
                @page { size: A4 landscape; margin: 6mm; }
                * { box-sizing: border-box; }
                body { font-family: Arial, Helvetica, sans-serif; margin: 0; color: #000; }
                .print-page {
                    break-after: page;
                    min-height: 195mm;
                    page-break-after: always;
                    position: relative;
                }
                .print-page:last-child {
                    break-after: auto;
                    page-break-after: auto;
                }
                h1 { font-size: 15px; margin: 0 0 4px; text-align: center; }
                .meta { display: flex; font-size: 8px; justify-content: space-between; margin-bottom: 3px; }
                table { border-collapse: collapse; table-layout: fixed; width: 100%; }
                th, td {
                    border: 1px solid #111;
                    font-size: 5.8px;
                    font-weight: 700;
                    height: 4.75mm;
                    line-height: 1.05;
                    max-height: 4.75mm;
                    overflow: hidden;
                    padding: 1px 2px;
                    text-align: center;
                    vertical-align: middle;
                    word-break: break-word;
                }
                th { background: #4b5563; color: #fff; }
                tbody tr:nth-child(odd) td { background: #f8fafc; }
                tbody tr.received-row td { background: #d9d9d9 !important; }
                .totals-table {
                    margin-top: 1mm;
                    page-break-inside: avoid;
                }
                .totals-table td {
                    background: #fff2cc;
                    font-weight: 800;
                }
                .totals-table .blank {
                    background: transparent;
                    border: 0;
                }
                .page-number {
                    bottom: 0;
                    font-size: 8px;
                    font-weight: 700;
                    left: 0;
                    position: absolute;
                    right: 0;
                    text-align: center;
                }
                .print-col-slno { width: 3.2%; }
                .print-col-wono { width: 8%; }
                .print-col-customer { width: 22%; }
                .print-col-date { width: 7.5%; }
                .print-col-status { width: 8%; }
                .print-col-finish { width: 7%; }
                .print-col-note { width: 10%; }
                .print-col-destination { width: 8%; }
                .print-col-number { width: 5.4%; }
                .print-col-qty { width: 4.6%; }
            </style>
        </head>
        <body>
            ${printedPages}
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

async function readJsonResponse(response) {
    const text = await response.text();

    try {
        return JSON.parse(text);
    } catch (error) {
        throw new Error(text.trim() || `Could not load ${exporterConfig.exportTitle.toLowerCase()}.`);
    }
}

function chunkRows(rows, size) {
    const chunks = [];

    for (let index = 0; index < rows.length; index += size) {
        chunks.push(rows.slice(index, index + size));
    }

    return chunks;
}

function setActiveMessage(message) {
    activeMessage.textContent = message;
}

function isReceivedStatus(status) {
    return String(status || '').trim().toLowerCase() === 'received';
}

function dateAgeStyle(value) {
    const date = parseWorkOrderDate(value);
    if (!date) {
        return '';
    }

    const today = appToday();
    const daysOld = Math.max(0, Math.floor((today - date) / 86400000));
    let color = '#92d050';

    if (daysOld === 1) {
        color = '#fff2cc';
    } else if (daysOld > 1) {
        color = interpolateColor('#fff2cc', '#f8696b', Math.min((daysOld - 1) / 6, 1));
    }

    return ` style="background: ${color} !important;"`;
}

function parseWorkOrderDate(value) {
    const raw = String(value || '').trim();
    const monthNames = {
        jan: 0,
        feb: 1,
        mar: 2,
        apr: 3,
        may: 4,
        jun: 5,
        jul: 6,
        aug: 7,
        sep: 8,
        oct: 9,
        nov: 10,
        dec: 11,
    };
    let match = raw.match(/^(\d{1,2})[/-]([A-Za-z]{3})[/-](\d{4})$/);

    if (match) {
        const date = new Date(Number(match[3]), monthNames[match[2].toLowerCase()], Number(match[1]));
        date.setHours(0, 0, 0, 0);
        return Number.isNaN(date.getTime()) ? null : date;
    }

    match = raw.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (match) {
        const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
        date.setHours(0, 0, 0, 0);
        return Number.isNaN(date.getTime()) ? null : date;
    }

    return null;
}

function appToday() {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: appTimeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(new Date());
    const values = Object.fromEntries(parts.map((part) => [part.type, part.value]));
    return new Date(Number(values.year), Number(values.month) - 1, Number(values.day));
}

function appDateIso() {
    const today = appToday();
    return [
        today.getFullYear(),
        String(today.getMonth() + 1).padStart(2, '0'),
        String(today.getDate()).padStart(2, '0'),
    ].join('-');
}

function appDateDisplay() {
    return new Intl.DateTimeFormat('en-GB', {
        timeZone: appTimeZone,
    }).format(new Date());
}

function interpolateColor(start, end, amount) {
    const from = hexToRgb(start);
    const to = hexToRgb(end);
    const mixed = from.map((channel, index) => Math.round(channel + (to[index] - channel) * amount));
    return `rgb(${mixed[0]}, ${mixed[1]}, ${mixed[2]})`;
}

function hexToRgb(value) {
    const hex = value.replace('#', '');
    return [
        Number.parseInt(hex.slice(0, 2), 16),
        Number.parseInt(hex.slice(2, 4), 16),
        Number.parseInt(hex.slice(4, 6), 16),
    ];
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
