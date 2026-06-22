<?php
declare(strict_types=1);

$defaultReportDate = date('Y-m-d');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Active Work Order Exporter</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
    <main class="shell">
        <header class="topbar">
            <div>
                <h1>Active Work Order Exporter</h1>
                <p>Export active work orders from imported Excel data.</p>
            </div>
            <nav>
                <a href="index.php">Home</a>
                <a href="#activeExporter">Exporter</a>
                <a href="#savedActiveReports">Saved Active Reports</a>
            </nav>
        </header>

        <section id="activeExporter" class="panel">
            <div class="report-title">
                <h2>Active Work Order List</h2>
                <div class="report-date">
                    <label for="activeReportDate">Report Date</label>
                    <input id="activeReportDate" type="date" value="<?= htmlspecialchars($defaultReportDate, ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>

            <div class="section-head compact">
                <p>Rows with status Production Finished or Packing Finished are excluded.</p>
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
                            <td colspan="12">No active work orders loaded.</td>
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
                <h2>Active Work Order Reports</h2>
                <p>Saved active work order snapshots can be reopened, modified, exported, or deleted.</p>
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
                            <td colspan="4">No saved active reports loaded.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <script src="assets/active_work_orders.js"></script>
</body>
</html>
