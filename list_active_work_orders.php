<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = db();
    ensureSchema($pdo);

    $rows = $pdo->query("
        SELECT wo_no, customer_name, edd, prod_started_date, status, finish, prod_sup_note,
               destination, duct_area, duct_weight, wo_qty
        FROM active_work_orders
        WHERE UPPER(TRIM(REPLACE(REPLACE(COALESCE(status, ''), CHAR(13), ' '), CHAR(10), ' '))) NOT IN ('PRODUCTION FINISHED', 'PACKING FINISHED')
        ORDER BY STR_TO_DATE(edd, '%d/%b/%Y') ASC,
                 STR_TO_DATE(edd, '%d-%b-%Y') ASC,
                 wo_no ASC
    ")->fetchAll();

    echo json_encode(['success' => true, 'items' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
}
