<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

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

    $reportStatement = $pdo->prepare('
        SELECT id, report_name, report_date
        FROM pending_work_order_reports
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
        SELECT wo_no, customer_name, edd, prod_started_date, status, finish, prod_sup_note,
               destination, duct_area, duct_weight, wo_qty
        FROM pending_work_order_report_items
        WHERE report_id = ?
        ORDER BY row_order ASC, id ASC
    ');
    $itemsStatement->execute([$reportId]);

    echo json_encode([
        'success' => true,
        'report' => $report,
        'items' => $itemsStatement->fetchAll(),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
}
