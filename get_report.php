<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/report_schema.php';

header('Content-Type: application/json; charset=utf-8');

$reportId = (int) ($_GET['id'] ?? 0);

if ($reportId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Report id is required.']);
    exit;
}

try {
    $pdo = db();
    ensureSchema($pdo);
    ensureExtendedDeliveryReportSchema($pdo);

    $reportStatement = $pdo->prepare('
        SELECT id, report_name, report_date
        FROM delivery_reports
        WHERE id = ?
        LIMIT 1
    ');
    $reportStatement->execute([$reportId]);
    $report = $reportStatement->fetch();

    if (!$report) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Report was not found.']);
        exit;
    }

    $itemsStatement = $pdo->prepare('
        SELECT customer_name, project_name, delivery_note, dn_number, destination, vehicle_type, added_to_delivery,
               wo_qty, mnf_weight, fix_anc_weight, mnf_qty, previous_delivered_percent, material, remark
        FROM delivery_report_items
        WHERE report_id = ?
        ORDER BY row_order ASC, id ASC
    ');
    $itemsStatement->execute([$reportId]);
    $pidStatement = $pdo->prepare('
        SELECT customer_name, project_name, delivery_note, dn_number, destination, vehicle_type, added_to_delivery,
               wo_qty, mnf_area, supp_rod, mnf_qty, previous_delivered_percent, material, remark
        FROM delivery_report_pid_items
        WHERE report_id = ?
        ORDER BY row_order ASC, id ASC
    ');
    $pidStatement->execute([$reportId]);
    $ancillaryStatement = $pdo->prepare('
        SELECT customer_name, project_name, delivery_note, dn_number, destination, vehicle_type, item_no, item_name,
               qty, previous_delivered_percent, total_delivered_percent, remark
        FROM delivery_report_ancillary_items
        WHERE report_id = ?
        ORDER BY row_order ASC, id ASC
    ');
    $ancillaryStatement->execute([$reportId]);

    echo json_encode([
        'success' => true,
        'report' => $report,
        'items' => $itemsStatement->fetchAll(),
        'pid_items' => $pidStatement->fetchAll(),
        'ancillary_items' => $ancillaryStatement->fetchAll(),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
}
