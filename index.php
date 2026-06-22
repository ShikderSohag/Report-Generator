<?php
declare(strict_types=1);

$message = $_GET['message'] ?? '';
$error = $_GET['error'] ?? '';
$defaultReportDate = date('Y-m-d', strtotime('-1 day'));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Daily Report Generator</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
    <main class="shell">
        <header class="topbar">
            <div>
                <h1>Daily Report Generator</h1>
                <p>Import work orders from Excel and retrieve report details by WO number.</p>
            </div>
            <nav>
                <a href="#upload">Upload</a>
                <a href="#report">Report</a>
                <a href="#savedReports">Delivery Reports</a>
            </nav>
        </header>

        <?php if ($message): ?>
            <div class="notice success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <section id="upload" class="panel">
            <div class="section-head">
                <h2>Upload Source File</h2>
                <p>Upload Excel/CSV work orders or a manufactured delivery-note PDF.</p>
            </div>

            <form class="upload-form" action="upload.php" method="post" enctype="multipart/form-data">
                <label class="drop-zone" for="excel_file">
                    <input id="excel_file" name="excel_file[]" type="file" accept=".xlsx,.csv,.pdf" multiple required>
                    <span class="drop-title">Drop Excel, CSV, or PDF files here</span>
                    <span class="drop-subtitle">Supported formats: .xlsx, .csv, .pdf</span>
                    <span id="fileName" class="file-name"></span>
                </label>
                <button type="submit">Upload and Import</button>
            </form>
        </section>

        <section id="report" class="panel">
            <div class="report-title">
                <h2>Duct &amp; Fittings - Daily Delivery Report</h2>
                <div class="report-date">
                    <label for="report_date">Report Date</label>
                    <input id="report_date" type="date" value="<?= htmlspecialchars($defaultReportDate, ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>

            <div class="section-head compact">
                <p>Enter a WO number to add it to the delivery report, then complete the manual fields in the row.</p>
            </div>

            <div class="report-actions">
                <input id="currentReportId" type="hidden" value="">
                <button id="saveReport" type="button">Save Report</button>
                <button id="exportReport" type="button">Export PDF</button>
            </div>

            <form id="reportForm" class="lookup-form">
                <label for="wo_no">WO Number</label>
                <div class="lookup-row">
                    <input id="wo_no" name="wo_no" type="text" placeholder="Example: W2032604220" required>
                    <button type="submit">Add to List</button>
                </div>
            </form>

            <div id="lookupMessage" class="inline-message"></div>
            <div id="dnPicker" class="dn-picker" hidden>
                <label for="dnSelect">DN Number</label>
                <div class="lookup-row">
                    <select id="dnSelect"></select>
                    <button id="addSelectedDn" type="button">Add Selected DN</button>
                </div>
            </div>

            <div class="table-wrap" aria-live="polite">
                <table class="report-table">
                    <colgroup>
                        <col class="col-serial">
                        <col class="col-customer">
                        <col class="col-project">
                        <col class="col-delivery">
                        <col class="col-dn">
                        <col class="col-destination">
                        <col class="col-added">
                        <col class="col-qty">
                        <col class="col-qty">
                        <col class="col-qty">
                        <col class="col-qty">
                        <col class="col-qty">
                        <col class="col-percent">
                        <col class="col-percent">
                        <col class="col-percent">
                        <col class="col-remark">
                        <col class="col-action">
                    </colgroup>
                    <thead>
                        <tr>
                            <th rowspan="2">#</th>
                            <th rowspan="2">Customer</th>
                            <th rowspan="2">Project</th>
                            <th rowspan="2">Delivery Note#</th>
                            <th rowspan="2">DN #</th>
                            <th rowspan="2">Destination</th>
                            <th rowspan="2">Added to Delivery</th>
                            <th rowspan="2">WOs Qty</th>
                            <th colspan="5">Shipment QTYs</th>
                            <th rowspan="2">Previously Delivered %</th>
                            <th rowspan="2">Total Delivered %</th>
                            <th rowspan="2">Remark</th>
                            <th rowspan="2">Action</th>
                        </tr>
                        <tr>
                            <th>MNF</th>
                            <th>Fix Anc.</th>
                            <th>Total</th>
                            <th>MNF Qty</th>
                            <th>%</th>
                        </tr>
                    </thead>
                    <tbody id="reportRows">
                        <tr class="empty-row">
                            <td colspan="17">No work orders added yet.</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="5" class="footer-blank"></td>
                            <td colspan="2" class="grand-label">Grand Totals:</td>
                            <td id="totalWoQty">0 KGs</td>
                            <td id="totalMnf">0 KGs</td>
                            <td id="totalFixAnc">0 KGs</td>
                            <td id="totalShipment">0 KGs</td>
                            <td id="totalMnfQty">0 PCs</td>
                            <td colspan="5" class="footer-blank"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <section id="savedReports" class="panel">
            <div class="section-head">
                <h2>Delivery Reports</h2>
                <p>Saved reports are named from the report date, for example <strong>Delivery Report-<?= htmlspecialchars($defaultReportDate, ENT_QUOTES, 'UTF-8') ?></strong>.</p>
            </div>

            <div class="report-actions">
                <button id="refreshReports" type="button">Refresh List</button>
            </div>

            <div id="savedReportMessage" class="inline-message"></div>

            <div class="saved-list-wrap">
                <table class="saved-report-table">
                    <thead>
                        <tr>
                            <th>Report Name</th>
                            <th>Report Date</th>
                            <th>Last Modified</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="savedReportRows">
                        <tr>
                            <td colspan="4">No saved reports loaded.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <script src="assets/app.js"></script>
</body>
</html>
