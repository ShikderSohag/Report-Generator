const fileInput = document.querySelector('#excel_file');
const fileName = document.querySelector('#fileName');
const dropZone = document.querySelector('.drop-zone');
const reportForm = document.querySelector('#reportForm');
const lookupMessage = document.querySelector('#lookupMessage');
const reportRows = document.querySelector('#reportRows');
const dnPicker = document.querySelector('#dnPicker');
const dnSelect = document.querySelector('#dnSelect');
const addSelectedDn = document.querySelector('#addSelectedDn');
const exportReport = document.querySelector('#exportReport');
const saveReport = document.querySelector('#saveReport');
const currentReportId = document.querySelector('#currentReportId');
const refreshReports = document.querySelector('#refreshReports');
const savedReportRows = document.querySelector('#savedReportRows');
const savedReportMessage = document.querySelector('#savedReportMessage');
const totalWoQty = document.querySelector('#totalWoQty');
const totalMnf = document.querySelector('#totalMnf');
const totalFixAnc = document.querySelector('#totalFixAnc');
const totalShipment = document.querySelector('#totalShipment');
const totalMnfQty = document.querySelector('#totalMnfQty');
const addedWorkOrders = new Set();
let pendingLookup = null;

if (fileInput && fileName && dropZone) {
    fileInput.addEventListener('change', () => {
        fileName.textContent = fileInput.files.length ? selectedFileLabel(fileInput.files) : '';
    });

    ['dragenter', 'dragover'].forEach((eventName) => {
        dropZone.addEventListener(eventName, (event) => {
            event.preventDefault();
            dropZone.classList.add('dragging');
        });
    });

    ['dragleave', 'drop'].forEach((eventName) => {
        dropZone.addEventListener(eventName, (event) => {
            event.preventDefault();
            dropZone.classList.remove('dragging');
        });
    });

    dropZone.addEventListener('drop', (event) => {
        const files = event.dataTransfer.files;
        if (!files.length) {
            return;
        }

        fileInput.files = files;
        fileName.textContent = selectedFileLabel(files);
    });
}

function selectedFileLabel(files) {
    if (files.length === 1) {
        return files[0].name;
    }

    return `${files.length} files selected`;
}

if (reportForm) {
    reportForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        lookupMessage.textContent = '';
        hideDnPicker();

        const woNo = new FormData(reportForm).get('wo_no').trim();
        if (!woNo) {
            lookupMessage.textContent = 'Enter a WO number.';
            return;
        }

        try {
            const response = await fetch(`lookup.php?wo_no=${encodeURIComponent(woNo)}`);
            const payload = await response.json();

            if (!response.ok || !payload.success) {
                lookupMessage.textContent = payload.message || 'WO number was not found.';
                return;
            }

            const deliveries = payload.deliveries || [];
            if (deliveries.length > 1) {
                pendingLookup = payload;
                showDnPicker(payload.data, deliveries);
                return;
            }

            const selected = deliveries.length === 1 ? mergeDelivery(payload.data, deliveries[0]) : payload.data;
            if (isAlreadyAdded(selected)) {
                lookupMessage.textContent = 'This WO/DN is already in the report list.';
                return;
            }

            addReportRow(selected);
            reportForm.reset();
        } catch (error) {
            lookupMessage.textContent = 'Lookup failed. Please check the server and database connection.';
        }
    });
}

if (addSelectedDn) {
    addSelectedDn.addEventListener('click', () => {
        if (!pendingLookup) {
            return;
        }

        const selectedDelivery = pendingLookup.deliveries[Number(dnSelect.value)];
        const selected = mergeDelivery(pendingLookup.data, selectedDelivery);

        if (isAlreadyAdded(selected)) {
            lookupMessage.textContent = 'This WO/DN is already in the report list.';
            return;
        }

        addReportRow(selected);
        reportForm.reset();
        hideDnPicker();
        lookupMessage.textContent = '';
    });
}

if (exportReport) {
    exportReport.addEventListener('click', () => {
        const rows = [...reportRows.querySelectorAll('tr:not(.empty-row)')];
        if (!rows.length) {
            lookupMessage.textContent = 'Add at least one row before exporting.';
            return;
        }

        exportPdf(rows);
    });
}

if (saveReport) {
    saveReport.addEventListener('click', async () => {
        const items = collectReportItems();
        if (!items.length) {
            lookupMessage.textContent = 'Add at least one row before saving.';
            return;
        }

        try {
            const response = await fetch('save_report.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    report_id: currentReportId.value,
                    report_date: document.querySelector('#report_date').value,
                    items,
                }),
            });
            const payload = await response.json();

            if (!response.ok || !payload.success) {
                lookupMessage.textContent = payload.message || 'Save failed.';
                return;
            }

            currentReportId.value = payload.report_id;
            saveReport.textContent = 'Modify Report';
            lookupMessage.textContent = payload.message;
            loadReportList();
        } catch (error) {
            lookupMessage.textContent = 'Save failed. Please check the server and database connection.';
        }
    });
}

if (refreshReports) {
    refreshReports.addEventListener('click', loadReportList);
}

document.addEventListener('DOMContentLoaded', loadReportList);

function addReportRow(data) {
    const emptyRow = reportRows.querySelector('.empty-row');
    if (emptyRow) {
        emptyRow.remove();
    }

    const normalizedWoNo = (data.wo_no || data.delivery_note || '').toUpperCase();
    const rowKey = reportRowKey(data);
    addedWorkOrders.add(rowKey);

    const row = document.createElement('tr');
    const mnfWeight = numberValue(data.mnf_weight);
    const fixAncWeight = numberValue(data.fix_anc_weight);
    row.dataset.woNo = normalizedWoNo;
    row.dataset.rowKey = rowKey;
    row.dataset.woQty = numberValue(data.duct_weight) || mnfWeight + fixAncWeight;
    row.dataset.mnfQty = numberValue(data.wo_qty);
    row.innerHTML = `
        <td class="serial"></td>
        <td>
            <div class="main-value">${escapeHtml(data.customer_name || '')}</div>
        </td>
        <td><input class="cell-input" type="text" placeholder="Project" value="${escapeHtml(data.project_name || '')}"></td>
        <td>${escapeHtml(data.wo_no || data.delivery_note || '')}</td>
        <td><input class="cell-input short" type="text" placeholder="DN" value="${escapeHtml(data.dn_number || '')}"></td>
        <td><input class="cell-input" type="text" placeholder="Destination" value="${escapeHtml(data.destination || '')}"></td>
        <td>
            <select class="cell-input short">
                <option ${data.added_to_delivery === 'Yes' ? 'selected' : ''}>Yes</option>
                <option ${data.added_to_delivery === 'No' ? 'selected' : ''}>No</option>
            </select>
        </td>
        <td><input class="cell-input number manual-wo-qty" type="text" inputmode="decimal" value="${formatRawNumber(row.dataset.woQty)}"></td>
        <td><input class="cell-input number manual-mnf" type="number" min="0" step="0.01" value="${formatRawNumber(mnfWeight)}"></td>
        <td><input class="cell-input number manual-fix" type="number" min="0" step="0.01" value="${formatRawNumber(fixAncWeight)}"></td>
        <td class="shipment-total">0 KGs</td>
        <td class="mnf-qty">${formatPcs(data.wo_qty)}</td>
        <td class="shipment-percent">0%</td>
        <td><input class="cell-input number manual-prev" type="number" min="0" step="1" value="${formatRawNumber(data.previous_delivered_percent || 0)}"></td>
        <td class="delivered-total">0%</td>
        <td><textarea class="cell-input remark" rows="2" placeholder="Remark">${escapeHtml(data.remark || '')}</textarea></td>
        <td><button class="remove-row" type="button">Remove</button></td>
    `;

    row.querySelectorAll('.manual-wo-qty, .manual-mnf, .manual-fix, .manual-prev').forEach((input) => {
        input.addEventListener('input', () => updateRowCalculations(row));
    });

    row.querySelector('.remove-row').addEventListener('click', () => {
        addedWorkOrders.delete(row.dataset.rowKey);
        row.remove();
        refreshSerialNumbers();
        showEmptyRowIfNeeded();
        updateGrandTotals();
    });

    reportRows.appendChild(row);
    refreshSerialNumbers();
    updateRowCalculations(row);
}

function showDnPicker(baseData, deliveries) {
    dnSelect.innerHTML = '';
    deliveries.forEach((delivery, index) => {
        const option = document.createElement('option');
        const labelParts = [delivery.dn_number || `DN ${index + 1}`];
        if (delivery.mnf_weight) {
            labelParts.push(`MNF ${formatKg(delivery.mnf_weight)}`);
        }
        if (delivery.fix_anc_weight) {
            labelParts.push(`Fix ${formatKg(delivery.fix_anc_weight)}`);
        }
        option.value = String(index);
        option.textContent = labelParts.join(' - ');
        dnSelect.appendChild(option);
    });

    lookupMessage.textContent = `Multiple DNs found for ${baseData.wo_no}. Select one to add.`;
    dnPicker.hidden = false;
}

function hideDnPicker() {
    pendingLookup = null;
    if (dnPicker) {
        dnPicker.hidden = true;
    }
}

function mergeDelivery(baseData, delivery) {
    return {
        ...baseData,
        ...delivery,
        project_name: delivery.project_name || baseData.project_name,
        edd: delivery.edd || baseData.edd,
        wo_qty: delivery.wo_qty || baseData.wo_qty,
        duct_weight: delivery.duct_weight || baseData.duct_weight,
        mnf_weight: delivery.mnf_weight || baseData.mnf_weight,
        fix_anc_weight: delivery.fix_anc_weight || baseData.fix_anc_weight,
    };
}

function reportRowKey(data) {
    return `${(data.wo_no || data.delivery_note || '').toUpperCase()}|${(data.dn_number || '').toUpperCase()}`;
}

function isAlreadyAdded(data) {
    return addedWorkOrders.has(reportRowKey(data));
}

function refreshSerialNumbers() {
    reportRows.querySelectorAll('tr:not(.empty-row)').forEach((row, index) => {
        row.querySelector('.serial').textContent = index + 1;
    });
}

function showEmptyRowIfNeeded() {
    if (reportRows.querySelector('tr')) {
        updateGrandTotals();
        return;
    }

    const row = document.createElement('tr');
    row.className = 'empty-row';
    row.innerHTML = '<td colspan="17">No work orders added yet.</td>';
    reportRows.appendChild(row);
    updateGrandTotals();
}

function updateRowCalculations(row) {
    const woQty = numberValue(row.querySelector('.manual-wo-qty').value);
    row.dataset.woQty = woQty;
    const mnf = numberValue(row.querySelector('.manual-mnf').value);
    const fixAnc = numberValue(row.querySelector('.manual-fix').value);
    const previousPercent = numberValue(row.querySelector('.manual-prev').value);
    const shipmentTotal = mnf + fixAnc;
    const shipmentPercent = woQty > 0 ? (mnf / woQty) * 100 : 0;
    const totalDelivered = previousPercent + shipmentPercent;

    row.querySelector('.shipment-total').textContent = formatKg(shipmentTotal);
    row.querySelector('.shipment-percent').textContent = formatPercent(shipmentPercent);
    row.querySelector('.delivered-total').textContent = formatPercent(totalDelivered);

    updateGrandTotals();
}

function updateGrandTotals() {
    const rows = [...reportRows.querySelectorAll('tr:not(.empty-row)')];
    const totals = rows.reduce((carry, row) => {
        carry.woQty += numberValue(row.dataset.woQty);
        carry.mnf += numberValue(row.querySelector('.manual-mnf')?.value);
        carry.fixAnc += numberValue(row.querySelector('.manual-fix')?.value);
        carry.mnfQty += numberValue(row.dataset.mnfQty);
        return carry;
    }, { woQty: 0, mnf: 0, fixAnc: 0, mnfQty: 0 });

    totalWoQty.textContent = formatKg(totals.woQty);
    totalMnf.textContent = formatKg(totals.mnf);
    totalFixAnc.textContent = formatKg(totals.fixAnc);
    totalShipment.textContent = formatKg(totals.mnf + totals.fixAnc);
    totalMnfQty.textContent = `${formatNumber(totals.mnfQty)} PCs`;
}

function collectReportItems() {
    return [...reportRows.querySelectorAll('tr:not(.empty-row)')].map((row) => ({
        customer_name: cellValue(row, 1),
        project_name: cellValue(row, 2),
        delivery_note: cellValue(row, 3),
        dn_number: cellValue(row, 4),
        destination: cellValue(row, 5),
        added_to_delivery: cellValue(row, 6),
        wo_qty: numberValue(cellValue(row, 7)),
        mnf_weight: numberValue(cellValue(row, 8)),
        fix_anc_weight: numberValue(cellValue(row, 9)),
        mnf_qty: numberValue(cellValue(row, 11)),
        previous_delivered_percent: numberValue(cellValue(row, 13)),
        remark: cellValue(row, 15),
    }));
}

async function loadReportList() {
    if (!savedReportRows) {
        return;
    }

    try {
        const response = await fetch('list_reports.php');
        const payload = await response.json();

        if (!response.ok || !payload.success) {
            savedReportMessage.textContent = payload.message || 'Could not load saved reports.';
            return;
        }

        renderSavedReports(payload.reports || []);
    } catch (error) {
        savedReportMessage.textContent = 'Could not load saved reports.';
    }
}

function renderSavedReports(reports) {
    savedReportRows.innerHTML = '';

    if (!reports.length) {
        savedReportRows.innerHTML = '<tr><td colspan="4">No saved delivery reports yet.</td></tr>';
        return;
    }

    reports.forEach((report) => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${escapeHtml(report.report_name || '')}</td>
            <td>${escapeHtml(report.report_date || '')}</td>
            <td>${escapeHtml(report.updated_at || '')}</td>
            <td><button class="load-report" type="button">Open</button></td>
        `;
        row.querySelector('.load-report').addEventListener('click', () => loadSavedReport(report.id));
        savedReportRows.appendChild(row);
    });
}

async function loadSavedReport(reportId) {
    try {
        const response = await fetch(`get_report.php?id=${encodeURIComponent(reportId)}`);
        const payload = await response.json();

        if (!response.ok || !payload.success) {
            savedReportMessage.textContent = payload.message || 'Could not open report.';
            return;
        }

        clearReportRows();
        currentReportId.value = payload.report.id;
        document.querySelector('#report_date').value = payload.report.report_date;
        saveReport.textContent = 'Modify Report';

        (payload.items || []).forEach((item) => {
            addReportRow({
                customer_name: item.customer_name,
                project_name: item.project_name,
                wo_no: item.delivery_note,
                delivery_note: item.delivery_note,
                dn_number: item.dn_number,
                destination: item.destination,
                added_to_delivery: item.added_to_delivery || 'Yes',
                duct_weight: item.wo_qty,
                wo_qty: item.mnf_qty,
                mnf_weight: item.mnf_weight,
                fix_anc_weight: item.fix_anc_weight,
                previous_delivered_percent: item.previous_delivered_percent,
                remark: item.remark,
            });
        });

        lookupMessage.textContent = `${payload.report.report_name} loaded for modification.`;
        window.location.hash = '#report';
    } catch (error) {
        savedReportMessage.textContent = 'Could not open report.';
    }
}

function clearReportRows() {
    reportRows.innerHTML = '<tr class="empty-row"><td colspan="17">No work orders added yet.</td></tr>';
    addedWorkOrders.clear();
    updateGrandTotals();
}

function exportPdf(rows) {
    const headers = [
        '#',
        'Customer',
        'Project',
        'Delivery Note#',
        'DN #',
        'Destination',
        'Added to Delivery',
        'WOs Qty',
        'MNF',
        'Fix Anc.',
        'Total',
        'MNF Qty',
        '%',
        'Previously Delivered %',
        'Total Delivered %',
        'Remark',
    ];

    const data = rows.map((row) => [
        cellValue(row, 0),
        cellValue(row, 1),
        cellValue(row, 2),
        cellValue(row, 3),
        cellValue(row, 4),
        cellValue(row, 5),
        cellValue(row, 6),
        cellValue(row, 7),
        cellValue(row, 8),
        cellValue(row, 9),
        cellValue(row, 10),
        cellValue(row, 11),
        cellValue(row, 12),
        cellValue(row, 13),
        cellValue(row, 14),
        cellValue(row, 15),
    ]);

    const reportDate = document.querySelector('#report_date')?.value || new Date().toISOString().slice(0, 10);
    const printWindow = window.open('', '_blank');

    if (!printWindow) {
        lookupMessage.textContent = 'Please allow popups to export the PDF.';
        return;
    }

    const bodyRows = data.map((row) => `
        <tr>${row.map((value) => `<td>${escapeHtml(value)}</td>`).join('')}</tr>
    `).join('');

    printWindow.document.write(`
        <!doctype html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>Delivery Report-${escapeHtml(reportDate)}</title>
            <style>
                @page { size: A4 landscape; margin: 8mm; }
                * { box-sizing: border-box; }
                body { font-family: Arial, Helvetica, sans-serif; margin: 0; color: #000; }
                .title {
                    background: #14213d;
                    color: #fff;
                    font-size: 14px;
                    font-weight: 700;
                    padding: 5px 8px;
                    text-align: center;
                }
                .meta {
                    background: #2f61ad;
                    color: #fff;
                    display: flex;
                    font-size: 10px;
                    font-weight: 700;
                    justify-content: space-between;
                    padding: 5px 8px;
                }
                table { border-collapse: collapse; table-layout: fixed; width: 100%; }
                th, td {
                    border: 1px solid #111;
                    font-size: 7px;
                    font-weight: 700;
                    padding: 3px 2px;
                    text-align: center;
                    vertical-align: middle;
                    word-break: break-word;
                }
                th { background: #14213d; color: #fff; text-transform: uppercase; }
                tbody tr:nth-child(odd) td { background: #d7e6f8; }
                tfoot td { background: #14213d; color: #fbbf24; font-weight: 800; }
                .customer { width: 15%; }
                .project { width: 11%; }
                .remark { width: 8%; }
                .small { width: 5%; }
            </style>
        </head>
        <body>
            <div class="title">Duct &amp; Fittings - Daily Delivery Report</div>
            <div class="meta">
                <span>Duct &amp; Fittings Delivered WOs (Metal Ducts)</span>
                <span>Report Date: ${escapeHtml(reportDate)}</span>
            </div>
            <table>
                <thead>
                    <tr>
                        ${headers.map((header) => `<th>${escapeHtml(header)}</th>`).join('')}
                    </tr>
                </thead>
                <tbody>${bodyRows}</tbody>
                <tfoot>
                    <tr>
                        <td colspan="7">Grand Totals</td>
                        <td>${escapeHtml(totalWoQty.textContent)}</td>
                        <td>${escapeHtml(totalMnf.textContent)}</td>
                        <td>${escapeHtml(totalFixAnc.textContent)}</td>
                        <td>${escapeHtml(totalShipment.textContent)}</td>
                        <td>${escapeHtml(totalMnfQty.textContent)}</td>
                        <td colspan="4"></td>
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

function cellValue(row, index) {
    const cell = row.children[index];
    if (!cell) {
        return '';
    }

    const field = cell.querySelector('input, select, textarea');
    if (field) {
        return field.value.trim();
    }

    return cell.textContent.trim().replace(/\s+/g, ' ');
}

function numberValue(value) {
    const parsed = Number.parseFloat(String(value || '').replace(/,/g, ''));
    return Number.isFinite(parsed) ? parsed : 0;
}

function formatKg(value) {
    return `${formatNumber(numberValue(value))} KGs`;
}

function formatPercent(value) {
    const percent = numberValue(value);
    const decimals = Number.isInteger(percent) ? 0 : 1;
    return `${percent.toFixed(decimals)}%`;
}

function formatPcs(value) {
    return `${formatNumber(numberValue(value))} PCs`;
}

function formatNumber(value) {
    return numberValue(value).toLocaleString('en-US', {
        maximumFractionDigits: 2,
    });
}

function formatRawNumber(value) {
    return Number.isInteger(numberValue(value)) ? String(numberValue(value)) : String(Number(numberValue(value).toFixed(2)));
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
