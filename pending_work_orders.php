<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Riyadh');

$defaultReportDate = date('Y-m-d');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pending Work Order Exporter</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body
    data-list-endpoint="list_pending_work_orders.php"
    data-save-endpoint="save_pending_report.php"
    data-list-reports-endpoint="list_pending_reports.php"
    data-get-report-endpoint="get_pending_report.php"
    data-delete-report-endpoint="delete_pending_report.php"
    data-export-title="Pending Work Order List"
    data-empty-text="No pending work orders loaded."
    data-no-rows-text="No pending work orders are available."
    data-saved-empty-text="No saved pending reports yet."
    data-loaded-hash="#pendingExporter"
    data-highlight-edd="age"
>
    <main class="shell">
        <header class="topbar">
            <div>
                <h1>Pending Work Order Exporter</h1>
                <p>Export work orders with EDD up to today from imported Excel data.</p>
            </div>
            <nav>
                <a href="index.php">Home</a>
                <a href="active_work_orders.php">Active Work Orders</a>
                <a href="#pendingExporter">Exporter</a>
                <a href="#savedActiveReports">Saved Pending Reports</a>
            </nav>
        </header>

        <section id="pendingExporter" class="panel">
            <div class="report-title">
                <h2>Pending Work Order List</h2>
                <div class="report-date">
                    <label for="activeReportDate">Report Date</label>
                    <input id="activeReportDate" type="date" value="<?= htmlspecialchars($defaultReportDate, ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>

            <div class="section-head compact">
                <p>Only rows with EDD up to today are shown. Finished production and packing rows are excluded.</p>
            </div>

            <div class="report-actions">
                <input id="currentActiveReportId" type="hidden" value="">
                <button id="refreshActiveOrders" type="button">Refresh Data</button>
                <button id="saveActiveReport" type="button">Save Report</button>
                <button id="exportActiveReport" type="button">Export PDF</button>
            </div>

            <div id="activeMessage" class="inline-message"></div>

            <div class="table-wrap" aria-live="polite">
                <table class="report-table active-work-order-table">
                    <colgroup>
                        <col class="active-col-serial">
                        <col class="active-col-wo">
                        <col class="active-col-customer">
                        <col class="active-col-date">
                        <col class="active-col-date">
                        <col class="active-col-status">
                        <col class="active-col-finish">
                        <col class="active-col-note">
                        <col class="active-col-destination">
                        <col class="active-col-number">
                        <col class="active-col-number">
                        <col class="active-col-number">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>SLNO</th>
                            <th>WONO</th>
                            <th>CUSTOMER</th>
                            <th>EDD</th>
                            <th>PRODSTARTEDDATE</th>
                            <th>STATUS</th>
                            <th>FINISH</th>
                            <th>ProdSupNote</th>
                            <th>Destination</th>
                            <th>DuctArea</th>
                            <th>DuctWeight</th>
                            <th>WOQTY</th>
                        </tr>
                    </thead>
                    <tbody id="activeRows">
                        <tr class="empty-row">
                            <td colspan="12">No pending work orders loaded.</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="8" class="footer-blank"></td>
                            <td class="grand-label">Totals:</td>
                            <td id="activeTotalDuctArea">0</td>
                            <td id="activeTotalDuctWeight">0</td>
                            <td id="activeTotalWoQty">0</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <section id="savedActiveReports" class="panel">
            <div class="section-head">
                <h2>Pending Work Order Reports</h2>
                <p>Saved pending work order snapshots can be reopened, modified, exported, or deleted.</p>
            </div>

            <div class="report-actions">
                <button id="refreshActiveReports" type="button">Refresh List</button>
            </div>

            <div id="savedActiveMessage" class="inline-message"></div>

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
                    <tbody id="savedActiveRows">
                        <tr>
                            <td colspan="4">No saved pending reports loaded.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <script src="assets/active_work_orders.js"></script>
</body>
</html>
