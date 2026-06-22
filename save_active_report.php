<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid active report payload.');
    }

    $reportDate = trim((string) ($payload['report_date'] ?? ''));
    $items = $payload['items'] ?? [];

    if ($reportDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
        throw new RuntimeException('Report date is required.');
    }

    if (!is_array($items) || count($items) === 0) {
        throw new RuntimeException('No active work orders are available to save.');
    }

    $reportId = isset($payload['report_id']) && $payload['report_id'] !== ''
        ? (int) $payload['report_id']
        : null;
    $reportName = 'Active Work Order List-' . $reportDate;

    $pdo = db();
    ensureSchema($pdo);
    $pdo->beginTransaction();

    if ($reportId) {
        $statement = $pdo->prepare('
            UPDATE active_work_order_reports
            SET report_name = :report_name,
                report_date = :report_date
            WHERE id = :id
        ');
        $statement->execute([
            ':report_name' => $reportName,
            ':report_date' => $reportDate,
            ':id' => $reportId,
        ]);
    } else {
        $statement = $pdo->prepare('
            INSERT INTO active_work_order_reports (report_name, report_date)
            VALUES (:report_name, :report_date)
            ON DUPLICATE KEY UPDATE
                report_date = VALUES(report_date),
                id = LAST_INSERT_ID(id)
        ');
        $statement->execute([
            ':report_name' => $reportName,
            ':report_date' => $reportDate,
        ]);
        $reportId = (int) $pdo->lastInsertId();
    }

    $pdo->prepare('DELETE FROM active_work_order_report_items WHERE report_id = ?')->execute([$reportId]);

    $insertItem = $pdo->prepare('
        INSERT INTO active_work_order_report_items
            (report_id, row_order, wo_no, customer_name, edd, prod_started_date, status,
             finish, prod_sup_note, destination, duct_area, duct_weight, wo_qty)
        VALUES
            (:report_id, :row_order, :wo_no, :customer_name, :edd, :prod_started_date, :status,
             :finish, :prod_sup_note, :destination, :duct_area, :duct_weight, :wo_qty)
    ');

    foreach (array_values($items) as $index => $item) {
        if (!is_array($item) || trim((string) ($item['wo_no'] ?? '')) === '') {
            continue;
        }

        $insertItem->execute([
            ':report_id' => $reportId,
            ':row_order' => $index + 1,
            ':wo_no' => trim((string) ($item['wo_no'] ?? '')),
            ':customer_name' => nullableActiveText($item['customer_name'] ?? null),
            ':edd' => nullableActiveText($item['edd'] ?? null),
            ':prod_started_date' => nullableActiveText($item['prod_started_date'] ?? null),
            ':status' => nullableActiveText($item['status'] ?? null),
            ':finish' => nullableActiveText($item['finish'] ?? null),
            ':prod_sup_note' => nullableActiveText($item['prod_sup_note'] ?? null),
            ':destination' => nullableActiveText($item['destination'] ?? null),
            ':duct_area' => nullableActiveNumber($item['duct_area'] ?? null),
            ':duct_weight' => nullableActiveNumber($item['duct_weight'] ?? null),
            ':wo_qty' => nullableActiveNumber($item['wo_qty'] ?? null),
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'report_id' => $reportId,
        'report_name' => $reportName,
        'message' => $reportName . ' saved.',
    ]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
}

function nullableActiveText(mixed $value): ?string
{
    $value = trim((string) $value);
    return $value === '' ? null : $value;
}

function nullableActiveNumber(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    $number = str_replace(',', '', trim((string) $value));
    return is_numeric($number) ? $number : null;
}
