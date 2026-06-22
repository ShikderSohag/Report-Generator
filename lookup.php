<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$woNo = canonicalWorkOrderNumber($_GET['wo_no'] ?? '');

if ($woNo === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'WO number is required.']);
    exit;
}

try {
    $pdo = db();
    ensureSchema($pdo);

    $lookupValues = workOrderLookupValues($woNo);
    $statement = $pdo->prepare('
        SELECT wo_no, customer_name, project_name, dn_number, destination, edd, wo_qty, duct_weight, mnf_weight, fix_anc_weight
        FROM work_orders
        WHERE wo_no IN (' . implode(',', array_fill(0, count($lookupValues), '?')) . ')
        ORDER BY CASE WHEN wo_no = ? THEN 0 ELSE 1 END
        LIMIT 1
    ');
    $statement->execute([...$lookupValues, $woNo]);
    $row = $statement->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'WO number was not found.']);
        exit;
    }

    $deliveriesStatement = $pdo->prepare('
        SELECT dn_number, project_name, edd, wo_qty, duct_weight, mnf_weight, fix_anc_weight
        FROM work_order_deliveries
        WHERE wo_no IN (' . implode(',', array_fill(0, count($lookupValues), '?')) . ')
        ORDER BY dn_number
    ');
    $deliveriesStatement->execute($lookupValues);
    $deliveries = $deliveriesStatement->fetchAll();

    if (!$deliveries && !empty($row['dn_number'])) {
        $deliveries[] = [
            'dn_number' => $row['dn_number'],
            'project_name' => $row['project_name'],
            'edd' => $row['edd'],
            'wo_qty' => $row['wo_qty'],
            'duct_weight' => $row['duct_weight'],
            'mnf_weight' => $row['mnf_weight'],
            'fix_anc_weight' => $row['fix_anc_weight'],
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => $row,
        'deliveries' => $deliveries,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
}
