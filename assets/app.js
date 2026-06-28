const fileInput = document.querySelector('#excel_file');
const fileName = document.querySelector('#fileName');
const dropZone = document.querySelector('.drop-zone');
const uploadForm = document.querySelector('#uploadForm');
const uploadResult = document.querySelector('#uploadResult');
const reportForm = document.querySelector('#reportForm');
const lookupMessage = document.querySelector('#lookupMessage');
const reportRows = document.querySelector('#reportRows');
const dnPicker = document.querySelector('#dnPicker');
const dnSelect = document.querySelector('#dnSelect');
const addSelectedDn = document.querySelector('#addSelectedDn');
const exportReport = document.querySelector('#exportReport');
const saveReport = document.querySelector('#saveReport');
const autoloadDeliveryNotes = document.querySelector('#autoloadDeliveryNotes');
const currentReportId = document.querySelector('#currentReportId');
const refreshReports = document.querySelector('#refreshReports');
const savedReportRows = document.querySelector('#savedReportRows');
const savedReportMessage = document.querySelector('#savedReportMessage');
const totalWoQty = document.querySelector('#totalWoQty');
const totalMnf = document.querySelector('#totalMnf');
const totalFixAnc = document.querySelector('#totalFixAnc');
const totalShipment = document.querySelector('#totalShipment');
const totalMnfQty = document.querySelector('#totalMnfQty');
const pidRows = document.querySelector('#pidRows');
const pidTotalWoQty = document.querySelector('#pidTotalWoQty');
const pidTotalMnf = document.querySelector('#pidTotalMnf');
const pidTotalSuppRod = document.querySelector('#pidTotalSuppRod');
const pidTotalShipment = document.querySelector('#pidTotalShipment');
const pidTotalMnfQty = document.querySelector('#pidTotalMnfQty');
const addAncillaryRow = document.querySelector('#addAncillaryRow');
const ancillaryRows = document.querySelector('#ancillaryRows');
const addedWorkOrders = new Set();
let pendingLookup = null;
let droppedUploadFiles = null;

if (fileInput && fileName && dropZone) {
    fileInput.addEventListener('change', () => {
        droppedUploadFiles = null;
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

    dropZone.addEventListener('drop', async (event) => {
        fileName.textContent = 'Scanning dropped folder...';
        const discoveredFiles = await filesFromDrop(event.dataTransfer);
        const files = requiredUploadFiles(discoveredFiles);

        if (!files.length) {
            droppedUploadFiles = null;
            fileName.textContent = 'No supported delivery-note or work-order files found.';
            return;
        }

        fileInput.value = '';
        droppedUploadFiles = files;
        fileName.textContent = selectedFileLabel(files);
    });
}

if (uploadForm && fileInput && fileName && uploadResult) {
    uploadForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        const files = droppedUploadFiles || Array.from(fileInput.files);
        if (!files.length) {
            return;
        }

        const submitButton = uploadForm.querySelector('button[type="submit"]');
        const failures = [];
        let completed = 0;

        submitButton.disabled = true;
        uploadResult.hidden = false;
        uploadResult.className = 'notice';

        for (let index = 0; index < files.length; index += 1) {
            const file = files[index];
            fileName.textContent = `Uploading ${index + 1}/${files.length}: ${file.name}`;
            uploadResult.textContent = `Importing ${file.name}...`;

            try {
                const formData = new FormData();
                formData.append('ajax', '1');
                formData.append('excel_file[]', file, file.name);

                const response = await fetch(uploadForm.action, {
                    method: 'POST',
                    headers: { Accept: 'application/json' },
                    body: formData,
                });
                const result = await response.json();

                if (!response.ok || !result.ok) {
                    throw new Error(result.message || 'Upload failed.');
                }

                completed += 1;
            } catch (error) {
                failures.push(`${file.name}: ${error.message || 'Upload failed.'}`);
            }
        }

        submitButton.disabled = false;
        fileInput.value = '';
        droppedUploadFiles = null;
        fileName.textContent = '';

        if (failures.length) {
            uploadResult.className = 'notice error';
            uploadResult.textContent = `Completed ${completed} of ${files.length} file(s). Failed: ${failures.join(' | ')}`;
        } else {
            uploadResult.className = 'notice success';
            uploadResult.textContent = `Import completed for all ${completed} file(s).`;
        }
    });
}

function selectedFileLabel(files) {
    if (files.length === 1) {
        return files[0].name;
    }

    return `${files.length} files selected`;
}

async function filesFromDrop(dataTransfer) {
    const items = Array.from(dataTransfer.items || []);
    const entries = items
        .map((item) => (typeof item.webkitGetAsEntry === 'function' ? item.webkitGetAsEntry() : null))
        .filter(Boolean);

    if (!entries.length) {
        return Array.from(dataTransfer.files || []);
    }

    const nestedFiles = await Promise.all(entries.map((entry) => filesFromEntry(entry)));
    return nestedFiles.flat();
}

async function filesFromEntry(entry) {
    if (entry.isFile) {
        return new Promise((resolve) => {
            entry.file((file) => resolve([file]), () => resolve([]));
        });
    }

    if (!entry.isDirectory) {
        return [];
    }

    const reader = entry.createReader();
    const entries = [];

    while (true) {
        const batch = await new Promise((resolve) => reader.readEntries(resolve, () => resolve([])));
        if (!batch.length) {
            break;
        }
        entries.push(...batch);
    }

    const nestedFiles = await Promise.all(entries.map((child) => filesFromEntry(child)));
    return nestedFiles.flat();
}

function requiredUploadFiles(files) {
    const supported = files.filter((file) => {
        const name = file.name.toLowerCase();
        return /\.(xlsx|csv|pdf|zip)$/.test(name) || /^\d{8,}$/.test(name);
    });
    const deliveryNotePdfs = supported.filter((file) => {
        return file.name.toLowerCase().endsWith('.pdf') && isDeliveryNoteFileName(file.name);
    });

    if (!deliveryNotePdfs.length) {
        return supported;
    }

    return supported.filter((file) => {
        return !file.name.toLowerCase().endsWith('.pdf') || isDeliveryNoteFileName(file.name);
    });
}

function isDeliveryNoteFileName(fileName) {
    const normalized = fileName
        .replace(/\.[^.]+$/, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, ' ');
    return /\b(mnf|manufactured|fix|fixed|anc|ancillary|ancillaries)\b/.test(normalized);
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

            addLookupRow(selected);
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

        addLookupRow(selected);
        reportForm.reset();
        hideDnPicker();
        lookupMessage.textContent = '';
    });
}

if (addAncillaryRow) {
    addAncillaryRow.addEventListener('click', () => {
        addAncillaryTableRow({});
    });
}

if (autoloadDeliveryNotes) {
    autoloadDeliveryNotes.addEventListener('click', () => {
        loadDeliveryNotesForReportDate(false);
    });
}

if (exportReport) {
    exportReport.addEventListener('click', () => {
        const rows = [...reportRows.querySelectorAll('tr:not(.empty-row)')];
        const pidReportRows = [...pidRows.querySelectorAll('tr:not(.empty-row)')];
        if (!rows.length && !pidReportRows.length) {
            lookupMessage.textContent = 'Add at least one row before exporting.';
            return;
        }

        exportPdf(rows, pidReportRows);
    });
}

if (saveReport) {
    saveReport.addEventListener('click', async () => {
        const items = collectReportItems();
        const pidItems = collectPidItems();
        if (!items.length && !pidItems.length) {
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
                    pid_items: pidItems,
                    ancillary_items: collectAncillaryItems(),
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

document.addEventListener('DOMContentLoaded', () => {
    loadReportList();
    loadDeliveryNotesForReportDate(true);
});

function addLookupRow(data) {
    if (String(data.duct_system || '').toLowerCase() === 'pid') {
        addPidReportRow(data);
        return;
    }

    addReportRow(data);
}

async function loadDeliveryNotesForReportDate(isAutomatic = false) {
    const reportDate = document.querySelector('#report_date')?.value;
    if (!reportDate) {
        return;
    }

    if (
        isAutomatic
        && (
            reportRows.querySelector('tr:not(.empty-row)')
            || pidRows.querySelector('tr:not(.empty-row)')
            || currentReportId.value
        )
    ) {
        return;
    }

    try {
        const response = await fetch(`autoload_delivery_notes.php?report_date=${encodeURIComponent(reportDate)}`);
        const payload = await response.json();

        if (!response.ok || !payload.success) {
            if (!isAutomatic) {
                lookupMessage.textContent = payload.message || 'Could not load delivery notes.';
            }
            return;
        }

        let added = 0;
        let skipped = 0;
        (payload.deliveries || []).forEach((delivery) => {
            if (isAlreadyAdded(delivery)) {
                skipped++;
                return;
            }

            addLookupRow(delivery);
            added++;
        });

        if (!isAutomatic || added > 0) {
            lookupMessage.textContent = added
                ? `Loaded ${added} delivery note(s) for ${reportDate}${skipped ? `, skipped ${skipped} already added.` : '.'}`
                : `No new delivery notes found for ${reportDate}.`;
        }
    } catch (error) {
        if (!isAutomatic) {
            lookupMessage.textContent = 'Could not load delivery notes.';
        }
    }
}

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
    const hasPreviousPercentOverride = data.previous_delivered_percent !== undefined
        && data.previous_delivered_percent !== null;
    row.dataset.woNo = normalizedWoNo;
    row.dataset.rowKey = rowKey;
    row.dataset.woQty = numberValue(data.duct_weight);
    row.dataset.mnfQty = numberValue(data.wo_qty);
    row.dataset.previousMnfWeight = numberValue(data.previous_mnf_weight);
    row.dataset.hasDeliveryHistory = data.previous_mnf_weight === undefined || data.previous_mnf_weight === null ? '0' : '1';
    row.dataset.previousPercentOverride = hasPreviousPercentOverride
        ? String(numberValue(data.previous_delivered_percent))
        : '';
    row.draggable = true;
    row.innerHTML = `
        <td class="serial drag-handle" title="Drag to reorder"></td>
        <td>
            <div class="main-value">${escapeHtml(data.customer_name || '')}</div>
        </td>
        <td><input class="cell-input" type="text" placeholder="Project" value="${escapeHtml(data.project_name || '')}"></td>
        <td><input class="cell-input short" type="text" placeholder="Delivery Note" value="${escapeHtml(data.wo_no || data.delivery_note || '')}"></td>
        <td><input class="cell-input short" type="text" placeholder="DN" value="${escapeHtml(data.dn_number || '')}"></td>
        <td><input class="cell-input" type="text" placeholder="Destination" value="${escapeHtml(data.destination || '')}"></td>
        <td>
            <select class="cell-input short">
                <option ${data.added_to_delivery === 'Yes' ? 'selected' : ''}>Yes</option>
                <option ${data.added_to_delivery === 'No' ? 'selected' : ''}>No</option>
            </select>
        </td>
        <td><input class="cell-input number manual-wo-qty" type="text" inputmode="decimal" value="${formatRawNumber(row.dataset.woQty)}"></td>
        <td><input class="cell-input number manual-mnf" type="text" inputmode="decimal" value="${formatRawNumber(mnfWeight)}"></td>
        <td><input class="cell-input number manual-fix" type="text" inputmode="decimal" value="${formatRawNumber(fixAncWeight)}"></td>
        <td class="shipment-total">0 KGs</td>
        <td class="mnf-qty">${formatPcs(data.wo_qty)}</td>
        <td class="shipment-percent">0%</td>
        <td><div class="percent-input"><input class="cell-input number manual-prev" type="text" inputmode="decimal" value="${hasPreviousPercentOverride ? roundedPercent(data.previous_delivered_percent) : '0'}"></div></td>
        <td class="delivered-total">0%</td>
        <td><textarea class="cell-input remark" rows="2" placeholder="Remark">${escapeHtml(data.remark || '')}</textarea></td>
        <td><button class="remove-row" type="button">Remove</button></td>
    `;

    row.querySelectorAll('.manual-wo-qty, .manual-mnf, .manual-fix').forEach((input) => {
        input.addEventListener('input', () => {
            if (input.classList.contains('manual-wo-qty')) {
                syncWoQtyAcrossRows(row);
            } else {
                recalculateAllRows();
            }
        });
    });

    const previousPercentInput = row.querySelector('.manual-prev');
    previousPercentInput.addEventListener('input', (event) => {
        const value = event.target.value.trim();
        row.dataset.previousPercentOverride = value === '' ? '' : String(numberValue(value));
        updateCumulativePercentages();
    });
    previousPercentInput.addEventListener('change', () => {
        roundPreviousPercentInput(row, previousPercentInput, updateCumulativePercentages);
    });

    row.querySelector('.remove-row').addEventListener('click', () => {
        addedWorkOrders.delete(row.dataset.rowKey);
        row.remove();
        refreshSerialNumbers();
        showEmptyRowIfNeeded();
        recalculateAllRows();
    });

    row.addEventListener('dragstart', () => {
        row.classList.add('dragging-row');
    });

    row.addEventListener('dragend', () => {
        row.classList.remove('dragging-row');
        refreshSerialNumbers();
        recalculateAllRows();
    });

    row.addEventListener('dragover', (event) => {
        event.preventDefault();
        const draggingRow = reportRows.querySelector('.dragging-row');
        if (!draggingRow || draggingRow === row) {
            return;
        }

        const rect = row.getBoundingClientRect();
        const shouldInsertAfter = event.clientY > rect.top + rect.height / 2;
        reportRows.insertBefore(draggingRow, shouldInsertAfter ? row.nextSibling : row);
    });

    reportRows.appendChild(row);
    refreshSerialNumbers();
    recalculateAllRows();
}

function addPidReportRow(data) {
    const emptyRow = pidRows.querySelector('.empty-row');
    if (emptyRow) {
        emptyRow.remove();
    }

    const normalizedWoNo = (data.wo_no || data.delivery_note || '').toUpperCase();
    const rowKey = reportRowKey(data);
    addedWorkOrders.add(rowKey);

    const row = document.createElement('tr');
    const woQty = numberValue(data.pid_area ?? data.duct_weight ?? data.mnf_weight);
    const mnfArea = numberValue(data.pid_area ?? data.mnf_weight);
    const suppRod = numberValue(data.pid_supp_rod);
    const mnfQty = numberValue(data.pid_mnf_qty ?? data.wo_qty);
    const previousPidArea = data.previous_pid_area !== undefined && data.previous_pid_area !== null
        ? numberValue(data.previous_pid_area)
        : (woQty * numberValue(data.previous_delivered_percent) / 100);
    const hasPreviousPercentOverride = data.previous_delivered_percent !== undefined
        && data.previous_delivered_percent !== null;

    row.dataset.woNo = normalizedWoNo;
    row.dataset.rowKey = rowKey;
    row.dataset.woQty = woQty;
    row.dataset.mnfQty = mnfQty;
    row.dataset.previousPidArea = previousPidArea;
    row.dataset.hasDeliveryHistory = previousPidArea > 0 || data.previous_pid_area !== undefined ? '1' : '0';
    row.dataset.previousPercentOverride = hasPreviousPercentOverride
        ? String(numberValue(data.previous_delivered_percent))
        : '';
    row.innerHTML = `
        <td class="pid-serial"></td>
        <td>
            <div class="main-value">${escapeHtml(data.customer_name || '')}</div>
        </td>
        <td><input class="cell-input" type="text" placeholder="Project" value="${escapeHtml(data.project_name || '')}"></td>
        <td><input class="cell-input short" type="text" placeholder="Delivery Note" value="${escapeHtml(data.wo_no || data.delivery_note || '')}"></td>
        <td><input class="cell-input short" type="text" placeholder="DN" value="${escapeHtml(data.dn_number || '')}"></td>
        <td>
            <select class="cell-input short">
                <option ${data.added_to_delivery === 'Yes' ? 'selected' : ''}>Yes</option>
                <option ${data.added_to_delivery === 'No' ? 'selected' : ''}>No</option>
            </select>
        </td>
        <td><input class="cell-input number pid-wo-qty" type="number" min="0" step="0.01" value="${formatRawNumber(woQty)}"></td>
        <td><input class="cell-input number pid-mnf" type="number" min="0" step="0.01" value="${formatRawNumber(mnfArea)}"></td>
        <td><input class="cell-input number pid-supp-rod" type="number" min="0" step="0.01" value="${formatRawNumber(suppRod)}"></td>
        <td class="pid-shipment-total">0 m²</td>
        <td class="pid-mnf-qty">${formatPcs(mnfQty)}</td>
        <td class="pid-shipment-percent">0%</td>
        <td><div class="percent-input"><input class="cell-input number pid-prev" type="text" inputmode="decimal" value="${hasPreviousPercentOverride ? roundedPercent(data.previous_delivered_percent) : '0'}"></div></td>
        <td class="pid-delivered-total">0%</td>
        <td><input class="cell-input short" type="text" placeholder="Material" value="${escapeHtml(data.pid_material || data.material || '')}"></td>
        <td><textarea class="cell-input remark" rows="2" placeholder="Remark">${escapeHtml(data.remark || '')}</textarea></td>
        <td><button class="remove-row" type="button">Remove</button></td>
    `;

    row.querySelectorAll('.pid-wo-qty, .pid-mnf, .pid-supp-rod').forEach((input) => {
        input.addEventListener('input', recalculatePidRows);
    });

    const previousPercentInput = row.querySelector('.pid-prev');
    previousPercentInput.addEventListener('input', (event) => {
        const value = event.target.value.trim();
        row.dataset.previousPercentOverride = value === '' ? '' : String(numberValue(value));
        updatePidCumulativePercentages();
    });
    previousPercentInput.addEventListener('change', () => {
        roundPreviousPercentInput(row, previousPercentInput, updatePidCumulativePercentages);
    });

    row.querySelector('.remove-row').addEventListener('click', () => {
        addedWorkOrders.delete(row.dataset.rowKey);
        row.remove();
        refreshPidSerialNumbers();
        showEmptyPidRowIfNeeded();
        recalculatePidRows();
    });

    pidRows.appendChild(row);
    refreshPidSerialNumbers();
    recalculatePidRows();
}

function showDnPicker(baseData, deliveries) {
    dnSelect.innerHTML = '';
    deliveries.forEach((delivery, index) => {
        const option = document.createElement('option');
        const labelParts = [delivery.dn_number || `DN ${index + 1}`];
        if (delivery.mnf_weight) {
            labelParts.push(`MNF ${formatKg(delivery.mnf_weight)}${delivery.mnf_date ? ` (${delivery.mnf_date})` : ''}`);
        }
        if (delivery.fix_anc_weight) {
            labelParts.push(`Fix ${formatKg(delivery.fix_anc_weight)}${delivery.fix_anc_date ? ` (${delivery.fix_anc_date})` : ''}`);
        }
        if (delivery.duct_system === 'pid') {
            labelParts.push(`PID ${formatSquareMeters(delivery.pid_area)} / ${formatPcs(delivery.pid_mnf_qty)}`);
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
        duct_weight: baseData.duct_weight,
        mnf_weight: delivery.mnf_weight || baseData.mnf_weight,
        fix_anc_weight: delivery.fix_anc_weight || baseData.fix_anc_weight,
        duct_system: delivery.duct_system || baseData.duct_system,
        pid_area: delivery.pid_area || baseData.pid_area,
        pid_supp_rod: delivery.pid_supp_rod || baseData.pid_supp_rod,
        pid_mnf_qty: delivery.pid_mnf_qty || baseData.pid_mnf_qty,
        pid_material: delivery.pid_material || baseData.pid_material,
        previous_mnf_weight: delivery.previous_mnf_weight,
        previous_pid_area: delivery.previous_pid_area,
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
        recalculateAllRows();
        return;
    }

    const row = document.createElement('tr');
    row.className = 'empty-row';
    row.innerHTML = '<td colspan="17">No work orders added yet.</td>';
    reportRows.appendChild(row);
    updateGrandTotals();
}

function recalculateAllRows() {
    reportRows.querySelectorAll('tr:not(.empty-row)').forEach((row) => {
        updateRowCalculations(row);
    });

    updateCumulativePercentages();
    updateGrandTotals();
}

function updateRowCalculations(row) {
    const woQty = numberValue(row.querySelector('.manual-wo-qty').value);
    row.dataset.woQty = woQty;
    const mnf = numberValue(row.querySelector('.manual-mnf').value);
    const fixAnc = numberValue(row.querySelector('.manual-fix').value);
    const shipmentTotal = mnf + fixAnc;
    const shipmentPercent = woQty > 0 ? (mnf / woQty) * 100 : 0;

    row.querySelector('.shipment-total').textContent = formatKg(shipmentTotal);
    row.querySelector('.shipment-percent').textContent = formatPercent(shipmentPercent);

    row.dataset.currentPercent = shipmentPercent;
}

function updateCumulativePercentages() {
    const cumulativeByWo = new Map();

    reportRows.querySelectorAll('tr:not(.empty-row)').forEach((row) => {
        const woNo = row.dataset.woNo;
        const woQty = numberValue(row.querySelector('.manual-wo-qty').value);
        const databasePreviousPercent = woQty > 0 ? (numberValue(row.dataset.previousMnfWeight) / woQty) * 100 : 0;
        const hasManualOverride = row.dataset.previousPercentOverride !== '';
        const previousPercent = roundedPercent(hasManualOverride
            ? numberValue(row.dataset.previousPercentOverride)
            : (row.dataset.hasDeliveryHistory === '1'
                ? databasePreviousPercent
                : (cumulativeByWo.has(woNo) ? cumulativeByWo.get(woNo) : 0)));
        const currentPercent = numberValue(row.dataset.currentPercent);
        const totalDelivered = previousPercent + currentPercent;

        if (!hasManualOverride) {
            row.querySelector('.manual-prev').value = previousPercent;
        }
        row.querySelector('.delivered-total').textContent = formatPercent(totalDelivered);
        cumulativeByWo.set(woNo, Math.max(cumulativeByWo.get(woNo) || 0, totalDelivered));
    });
}

function syncWoQtyAcrossRows(sourceRow) {
    const sourceWoNo = sourceRow.dataset.woNo;
    const value = sourceRow.querySelector('.manual-wo-qty').value;

    reportRows.querySelectorAll('tr:not(.empty-row)').forEach((row) => {
        if (row === sourceRow || row.dataset.woNo !== sourceWoNo) {
            return;
        }

        row.querySelector('.manual-wo-qty').value = value;
    });

    recalculateAllRows();
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

function recalculatePidRows() {
    pidRows.querySelectorAll('tr:not(.empty-row)').forEach((row) => {
        const woQty = numberValue(row.querySelector('.pid-wo-qty').value);
        const mnfArea = numberValue(row.querySelector('.pid-mnf').value);
        const shipmentPercent = woQty > 0 ? (mnfArea / woQty) * 100 : 0;

        row.dataset.woQty = woQty;
        row.dataset.currentPercent = shipmentPercent;
        row.querySelector('.pid-shipment-total').textContent = formatSquareMeters(mnfArea);
        row.querySelector('.pid-shipment-percent').textContent = formatPercent(shipmentPercent);
    });

    updatePidCumulativePercentages();
    updatePidGrandTotals();
}

function updatePidCumulativePercentages() {
    const cumulativeByWo = new Map();

    pidRows.querySelectorAll('tr:not(.empty-row)').forEach((row) => {
        const woNo = row.dataset.woNo;
        const woQty = numberValue(row.querySelector('.pid-wo-qty').value);
        const databasePreviousPercent = woQty > 0 ? (numberValue(row.dataset.previousPidArea) / woQty) * 100 : 0;
        const hasManualOverride = row.dataset.previousPercentOverride !== '';
        const previousPercent = roundedPercent(hasManualOverride
            ? numberValue(row.dataset.previousPercentOverride)
            : (row.dataset.hasDeliveryHistory === '1'
                ? databasePreviousPercent
                : (cumulativeByWo.has(woNo) ? cumulativeByWo.get(woNo) : 0)));
        const currentPercent = numberValue(row.dataset.currentPercent);
        const totalDelivered = previousPercent + currentPercent;

        if (!hasManualOverride) {
            row.querySelector('.pid-prev').value = previousPercent;
        }
        row.querySelector('.pid-delivered-total').textContent = formatPercent(totalDelivered);
        cumulativeByWo.set(woNo, Math.max(cumulativeByWo.get(woNo) || 0, totalDelivered));
    });
}

function updatePidGrandTotals() {
    const rows = [...pidRows.querySelectorAll('tr:not(.empty-row)')];
    const totals = rows.reduce((carry, row) => {
        carry.woQty += numberValue(row.querySelector('.pid-wo-qty')?.value);
        carry.mnf += numberValue(row.querySelector('.pid-mnf')?.value);
        carry.suppRod += numberValue(row.querySelector('.pid-supp-rod')?.value);
        carry.mnfQty += numberValue(row.dataset.mnfQty);
        return carry;
    }, { woQty: 0, mnf: 0, suppRod: 0, mnfQty: 0 });

    pidTotalWoQty.textContent = formatSquareMeters(totals.woQty);
    pidTotalMnf.textContent = formatSquareMeters(totals.mnf);
    pidTotalSuppRod.textContent = formatMeters(totals.suppRod);
    pidTotalShipment.textContent = formatSquareMeters(totals.mnf);
    pidTotalMnfQty.textContent = `${formatNumber(totals.mnfQty)} PCs`;
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

function collectPidItems() {
    return [...pidRows.querySelectorAll('tr:not(.empty-row)')].map((row) => ({
        customer_name: cellValue(row, 1),
        project_name: cellValue(row, 2),
        delivery_note: cellValue(row, 3),
        dn_number: cellValue(row, 4),
        added_to_delivery: cellValue(row, 5),
        wo_qty: numberValue(cellValue(row, 6)),
        mnf_area: numberValue(cellValue(row, 7)),
        supp_rod: numberValue(cellValue(row, 8)),
        mnf_qty: numberValue(cellValue(row, 10)),
        previous_delivered_percent: numberValue(cellValue(row, 12)),
        material: cellValue(row, 14),
        remark: cellValue(row, 15),
    }));
}

function refreshPidSerialNumbers() {
    pidRows.querySelectorAll('tr:not(.empty-row)').forEach((row, index) => {
        row.querySelector('.pid-serial').textContent = index + 1;
    });
}

function showEmptyPidRowIfNeeded() {
    if (pidRows.querySelector('tr')) {
        return;
    }

    pidRows.innerHTML = '<tr class="empty-row"><td colspan="17">No PID work orders added yet.</td></tr>';
    updatePidGrandTotals();
}

function addAncillaryTableRow(data) {
    const emptyRow = ancillaryRows.querySelector('.empty-row');
    if (emptyRow) {
        emptyRow.remove();
    }

    const row = document.createElement('tr');
    row.innerHTML = `
        <td class="ancillary-serial"></td>
        <td><input class="cell-input" type="text" placeholder="Customer" value="${escapeHtml(data.customer_name || '')}"></td>
        <td><input class="cell-input" type="text" placeholder="Project" value="${escapeHtml(data.project_name || '')}"></td>
        <td><input class="cell-input short" type="text" placeholder="Delivery Note" value="${escapeHtml(data.delivery_note || '')}"></td>
        <td><input class="cell-input short" type="text" placeholder="DN" value="${escapeHtml(data.dn_number || '')}"></td>
        <td><input class="cell-input short" type="text" placeholder="Item No" value="${escapeHtml(data.item_no || '')}"></td>
        <td><input class="cell-input" type="text" placeholder="ItemName" value="${escapeHtml(data.item_name || '')}"></td>
        <td><input class="cell-input number" type="number" min="0" step="0.01" value="${formatRawNumber(data.qty || 0)}"></td>
        <td><input class="cell-input number" type="number" min="0" step="0.01" value="${formatRawNumber(data.previous_delivered_percent || 0)}"></td>
        <td><input class="cell-input number" type="number" min="0" step="0.01" value="${formatRawNumber(data.total_delivered_percent || 100)}"></td>
        <td><textarea class="cell-input remark" rows="2" placeholder="Remark">${escapeHtml(data.remark || '')}</textarea></td>
        <td><button class="remove-row" type="button">Remove</button></td>
    `;

    row.querySelector('.remove-row').addEventListener('click', () => {
        row.remove();
        refreshAncillarySerialNumbers();
        showEmptyAncillaryRowIfNeeded();
    });

    ancillaryRows.appendChild(row);
    refreshAncillarySerialNumbers();
}

function collectAncillaryItems() {
    return [...ancillaryRows.querySelectorAll('tr:not(.empty-row)')]
        .map((row) => ({
            customer_name: cellValue(row, 1),
            project_name: cellValue(row, 2),
            delivery_note: cellValue(row, 3),
            dn_number: cellValue(row, 4),
            item_no: cellValue(row, 5),
            item_name: cellValue(row, 6),
            qty: numberValue(cellValue(row, 7)),
            previous_delivered_percent: numberValue(cellValue(row, 8)),
            total_delivered_percent: numberValue(cellValue(row, 9)),
            remark: cellValue(row, 10),
        }))
        .filter((item) => (
            item.customer_name
            || item.project_name
            || item.delivery_note
            || item.dn_number
            || item.item_no
            || item.item_name
            || item.remark
            || numberValue(item.qty) > 0
            || numberValue(item.previous_delivered_percent) > 0
        ));
}

function refreshAncillarySerialNumbers() {
    ancillaryRows.querySelectorAll('tr:not(.empty-row)').forEach((row, index) => {
        row.querySelector('.ancillary-serial').textContent = index + 1;
    });
}

function showEmptyAncillaryRowIfNeeded() {
    if (ancillaryRows.querySelector('tr')) {
        return;
    }

    ancillaryRows.innerHTML = '<tr class="empty-row"><td colspan="12">No ancillary rows added yet.</td></tr>';
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
            <td>
                <button class="load-report" type="button">Open</button>
                <button class="delete-report" type="button">Delete</button>
            </td>
        `;
        row.querySelector('.load-report').addEventListener('click', () => loadSavedReport(report.id));
        row.querySelector('.delete-report').addEventListener('click', () => deleteSavedReport(report.id, report.report_name));
        savedReportRows.appendChild(row);
    });
}

async function deleteSavedReport(reportId, reportName) {
    if (!confirm(`Delete ${reportName}?`)) {
        return;
    }

    try {
        const response = await fetch('delete_report.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: reportId }),
        });
        const payload = await response.json();

        if (!response.ok || !payload.success) {
            savedReportMessage.textContent = payload.message || 'Could not delete report.';
            return;
        }

        if (currentReportId.value === String(reportId)) {
            currentReportId.value = '';
            saveReport.textContent = 'Save Report';
        }

        savedReportMessage.textContent = payload.message;
        loadReportList();
    } catch (error) {
        savedReportMessage.textContent = 'Could not delete report.';
    }
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
        clearPidRows();
        clearAncillaryRows();
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

        (payload.pid_items || []).forEach((item) => {
            addPidReportRow({
                customer_name: item.customer_name,
                project_name: item.project_name,
                wo_no: item.delivery_note,
                delivery_note: item.delivery_note,
                dn_number: item.dn_number,
                added_to_delivery: item.added_to_delivery || 'Yes',
                pid_area: item.wo_qty,
                pid_supp_rod: item.supp_rod,
                pid_mnf_qty: item.mnf_qty,
                pid_material: item.material,
                mnf_weight: item.mnf_area,
                previous_delivered_percent: item.previous_delivered_percent,
                remark: item.remark,
            });
        });

        (payload.ancillary_items || []).forEach((item) => {
            addAncillaryTableRow(item);
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

function clearAncillaryRows() {
    ancillaryRows.innerHTML = '<tr class="empty-row"><td colspan="12">No ancillary rows added yet.</td></tr>';
}

function clearPidRows() {
    pidRows.innerHTML = '<tr class="empty-row"><td colspan="17">No PID work orders added yet.</td></tr>';
    updatePidGrandTotals();
}

function exportPdf(rows, pidReportRows) {
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
        formatKg(cellValue(row, 7)),
        formatKg(cellValue(row, 8)),
        formatKg(cellValue(row, 9)),
        formatKg(cellValue(row, 10)),
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

    const bodyRows = renderMergedExportRows(data, [1, 2, 3, 4], [0, 1, 2, 3, 4]);
    const metalSection = rows.length ? `
            <div class="title">Duct &amp; Fittings - Daily Delivery Report</div>
            <div class="meta">
                <span>Duct &amp; Fittings Delivered WOs (Metal Ducts)</span>
                <span>Report Date: ${escapeHtml(reportDate)}</span>
            </div>
            <table class="delivery-print-table">
                <colgroup>
                    <col class="delivery-col-serial">
                    <col class="delivery-col-customer">
                    <col class="delivery-col-project">
                    <col class="delivery-col-note">
                    <col class="delivery-col-dn">
                    <col class="delivery-col-destination">
                    <col class="delivery-col-added">
                    <col class="delivery-col-weight">
                    <col class="delivery-col-weight">
                    <col class="delivery-col-weight">
                    <col class="delivery-col-weight">
                    <col class="delivery-col-mnfqty">
                    <col class="delivery-col-percent">
                    <col class="delivery-col-percent-wide">
                    <col class="delivery-col-total-delivered">
                    <col class="delivery-col-remark">
                </colgroup>
                <thead>
                    <tr>
                        ${headers.map((header) => `<th>${escapeHtml(header)}</th>`).join('')}
                    </tr>
                </thead>
                <tbody>${bodyRows}</tbody>
                <tfoot>
                    <tr>
                        <td class="footer-empty"></td>
                        <td class="footer-empty"></td>
                        <td class="footer-empty"></td>
                        <td class="footer-empty"></td>
                        <td class="footer-empty"></td>
                        <td colspan="2" class="grand-total-label">Grand Totals:</td>
                        <td>${escapeHtml(totalWoQty.textContent)}</td>
                        <td>${escapeHtml(totalMnf.textContent)}</td>
                        <td>${escapeHtml(totalFixAnc.textContent)}</td>
                        <td>${escapeHtml(totalShipment.textContent)}</td>
                        <td>${escapeHtml(totalMnfQty.textContent)}</td>
                        <td class="footer-empty"></td>
                        <td class="footer-empty"></td>
                        <td class="footer-empty"></td>
                        <td class="footer-empty"></td>
                    </tr>
                </tfoot>
            </table>
    ` : '';
    const pidData = pidReportRows.map((row) => [
        cellValue(row, 0),
        cellValue(row, 1),
        cellValue(row, 2),
        cellValue(row, 3),
        cellValue(row, 4),
        cellValue(row, 5),
        formatSquareMeters(cellValue(row, 6)),
        formatSquareMeters(cellValue(row, 7)),
        formatMeters(cellValue(row, 8)),
        formatSquareMeters(cellValue(row, 9)),
        cellValue(row, 10),
        cellValue(row, 11),
        cellValue(row, 12),
        cellValue(row, 13),
        cellValue(row, 14),
        cellValue(row, 15),
    ]);
    const pidHeaders = [
        '#',
        'Customer',
        'Project',
        'Delivery Note#',
        'DN #',
        'Added to Delivery',
        'WOs Qty',
        'MNF',
        'Supp. Rod',
        'Total',
        'MNF Qty',
        '%',
        'Previously Delivered %',
        'Total Delivered %',
        'Duct Type/Material',
        'Remark',
    ];
    const pidBodyRows = renderMergedExportRows(pidData, [1, 2, 3, 4], [0, 1, 2, 3, 4]);
    const pidSection = pidReportRows.length ? `
            ${rows.length ? '' : '<div class="title">Duct &amp; Fittings - Daily Delivery Report</div>'}
            <div class="meta pid-meta">
                <span>Duct &amp; Fittings Delivered WOs (PID)</span>
                <span>Report Date: ${escapeHtml(displayReportDate(reportDate))}</span>
            </div>
            <table class="delivery-print-table pid-print-table">
                <colgroup>
                    <col class="delivery-col-serial">
                    <col class="delivery-col-customer">
                    <col class="delivery-col-project">
                    <col class="delivery-col-note">
                    <col class="delivery-col-dn">
                    <col class="delivery-col-added">
                    <col class="delivery-col-weight">
                    <col class="delivery-col-weight">
                    <col class="delivery-col-weight">
                    <col class="delivery-col-weight">
                    <col class="delivery-col-mnfqty">
                    <col class="delivery-col-percent">
                    <col class="delivery-col-percent-wide">
                    <col class="delivery-col-total-delivered">
                    <col class="delivery-col-note">
                    <col class="delivery-col-remark">
                </colgroup>
                <thead>
                    <tr>
                        ${pidHeaders.map((header) => `<th>${escapeHtml(header)}</th>`).join('')}
                    </tr>
                </thead>
                <tbody>${pidBodyRows}</tbody>
                <tfoot>
                    <tr>
                        <td class="footer-empty"></td>
                        <td class="footer-empty"></td>
                        <td class="footer-empty"></td>
                        <td class="footer-empty"></td>
                        <td colspan="2" class="grand-total-label">Grand Totals:</td>
                        <td>${escapeHtml(pidTotalWoQty.textContent)}</td>
                        <td>${escapeHtml(pidTotalMnf.textContent)}</td>
                        <td>${escapeHtml(pidTotalSuppRod.textContent)}</td>
                        <td>${escapeHtml(pidTotalShipment.textContent)}</td>
                        <td>${escapeHtml(pidTotalMnfQty.textContent)}</td>
                        <td class="footer-empty"></td>
                        <td class="footer-empty"></td>
                        <td class="footer-empty"></td>
                        <td class="footer-empty"></td>
                        <td class="footer-empty"></td>
                    </tr>
                </tfoot>
            </table>
    ` : '';
    const ancillaryItems = collectAncillaryItems();
    const ancillaryRowsForExport = ancillaryItems.map((item, index) => [
        index + 1,
        item.customer_name,
        item.project_name,
        item.delivery_note,
        item.dn_number,
        item.item_no,
        item.item_name,
        formatNumber(item.qty),
        formatPercent(item.previous_delivered_percent),
        formatPercent(item.total_delivered_percent),
        item.remark,
    ]);
    const ancillaryBodyRows = renderMergedExportRows(
        ancillaryRowsForExport,
        [1, 2, 3, 4],
        [0, 1, 2, 3, 4],
        { classByColumn: { 9: 'delivered-green' } }
    );
    const ancillarySection = ancillaryItems.length ? `
        <section class="ancillary-print-section">
            <div class="ancillary-title">
                <span>Ancillaries</span>
                <span>Report Date: <strong>${escapeHtml(displayReportDate(reportDate))}</strong></span>
            </div>
            <table class="ancillary-print-table">
                <colgroup>
                    <col style="width: 2%;">
                    <col style="width: 16%;">
                    <col style="width: 16%;">
                    <col style="width: 7%;">
                    <col style="width: 5%;">
                    <col style="width: 6%;">
                    <col style="width: 25%;">
                    <col style="width: 5%;">
                    <col style="width: 5%;">
                    <col style="width: 5%;">
                    <col style="width: 8%;">
                </colgroup>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Customer</th>
                        <th>Project</th>
                        <th>Delivery Note#</th>
                        <th>DN #</th>
                        <th>Item No</th>
                        <th>ItemName</th>
                        <th>Qty</th>
                        <th>Previously Delivered %</th>
                        <th>Total Delivered %</th>
                        <th>Remark</th>
                    </tr>
                </thead>
                <tbody>${ancillaryBodyRows}</tbody>
            </table>
        </section>
    ` : '';

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
                .pid-meta { margin-top: 8px; }
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
                td[rowspan] { vertical-align: middle; }
                th { background: #14213d; color: #fff; text-transform: uppercase; }
                tbody tr:nth-child(odd) td { background: #d7e6f8; }
                tfoot td { background: #14213d; color: #fbbf24; font-weight: 800; }
                .delivery-col-serial { width: 2.2%; }
                .delivery-col-customer { width: 15%; }
                .delivery-col-project { width: 13%; }
                .delivery-col-note { width: 6.2%; }
                .delivery-col-dn { width: 4.5%; }
                .delivery-col-destination { width: 5.5%; }
                .delivery-col-added { width: 5.5%; }
                .delivery-col-weight { width: 5.8%; }
                .delivery-col-mnfqty { width: 5%; }
                .delivery-col-percent { width: 4.1%; }
                .delivery-col-percent-wide { width: 5%; }
                .delivery-col-total-delivered { width: 5.2%; }
                .delivery-col-remark { width: 5.7%; }
                .delivery-print-table tfoot .footer-empty {
                    background: transparent;
                    border: 0;
                    color: transparent;
                }
                .delivery-print-table tfoot .grand-total-label {
                    text-align: center;
                }
                .ancillary-print-section { margin-top: 8px; page-break-inside: avoid; }
                .ancillary-title {
                    align-items: center;
                    background: #2f61ad;
                    border: 1px solid #111;
                    border-bottom: 3px solid #f5a400;
                    color: #fff;
                    display: flex;
                    font-size: 9px;
                    font-weight: 800;
                    justify-content: center;
                    padding: 3px 8px;
                    position: relative;
                    text-align: center;
                }
                .ancillary-title span:last-child {
                    position: absolute;
                    right: 8px;
                }
                .ancillary-title strong { color: #ffd966; }
                .ancillary-print-table th {
                    background: #14213d;
                    color: #fff;
                    font-size: 7px;
                    text-transform: none;
                }
                .ancillary-print-table tbody tr:nth-child(odd) td {
                    background: #d7e6f8;
                }
                .ancillary-print-table .delivered-green {
                    background: #c6efce !important;
                    color: #000;
                }
                .customer { width: 15%; }
                .project { width: 11%; }
                .remark { width: 8%; }
                .small { width: 5%; }
            </style>
        </head>
        <body>
            ${metalSection}
            ${pidSection}
            ${ancillarySection}
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

function renderMergedExportRows(rows, groupColumns, mergeColumns, options = {}) {
    const classByColumn = options.classByColumn || {};
    const groupSpans = rows.map(() => 1);
    const skipColumnsByRow = rows.map(() => new Set());

    for (let rowIndex = 0; rowIndex < rows.length;) {
        const key = groupColumns.map((column) => normalizeMergeValue(rows[rowIndex][column])).join('|');
        let endIndex = rowIndex + 1;

        while (
            endIndex < rows.length
            && groupColumns.map((column) => normalizeMergeValue(rows[endIndex][column])).join('|') === key
        ) {
            endIndex++;
        }

        const span = endIndex - rowIndex;
        if (span > 1 && key.replace(/\|/g, '') !== '') {
            groupSpans[rowIndex] = span;
            for (let index = rowIndex + 1; index < endIndex; index++) {
                mergeColumns.forEach((column) => skipColumnsByRow[index].add(column));
            }
        }

        rowIndex = endIndex;
    }

    return rows.map((row, rowIndex) => `
        <tr>
            ${row.map((value, columnIndex) => {
                if (skipColumnsByRow[rowIndex].has(columnIndex)) {
                    return '';
                }

                const rowspan = mergeColumns.includes(columnIndex) && groupSpans[rowIndex] > 1
                    ? ` rowspan="${groupSpans[rowIndex]}"`
                    : '';
                const className = classByColumn[columnIndex] ? ` class="${classByColumn[columnIndex]}"` : '';

                return `<td${rowspan}${className}>${escapeHtml(value)}</td>`;
            }).join('')}
        </tr>
    `).join('');
}

function normalizeMergeValue(value) {
    return String(value || '').trim().replace(/\s+/g, ' ').toUpperCase();
}

function numberValue(value) {
    const parsed = Number.parseFloat(String(value || '').replace(/,/g, ''));
    return Number.isFinite(parsed) ? parsed : 0;
}

function formatKg(value) {
    return `${formatNumber(numberValue(value))} KGs`;
}

function formatSquareMeters(value) {
    return `${formatNumber(numberValue(value))} m²`;
}

function formatMeters(value) {
    return `${formatNumber(numberValue(value))} m`;
}

function formatPercent(value) {
    return `${roundedPercent(value)}%`;
}

function roundedPercent(value) {
    return Math.round(numberValue(value));
}

function roundPreviousPercentInput(row, input, recalculate) {
    if (input.value.trim() === '') {
        row.dataset.previousPercentOverride = '';
    } else {
        const rounded = roundedPercent(input.value);
        input.value = rounded;
        row.dataset.previousPercentOverride = String(rounded);
    }
    recalculate();
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

function displayReportDate(value) {
    const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!match) {
        return value;
    }

    const month = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
        .toLocaleString('en-US', { month: 'short' });
    return `${match[3]}-${month}-${match[1]}`;
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
