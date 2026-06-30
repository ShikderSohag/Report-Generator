<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/report_schema.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid report payload.');
    }

    $reportDate = trim((string) ($payload['report_date'] ?? ''));
    $items = $payload['items'] ?? [];
    $pidItems = $payload['pid_items'] ?? [];
    $ancillaryItems = $payload['ancillary_items'] ?? [];

    if ($reportDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
        throw new RuntimeException('Report date is required.');
    }

    if ((!is_array($items) || count($items) === 0) && (!is_array($pidItems) || count($pidItems) === 0)) {
        throw new RuntimeException('Add at least one report row before saving.');
    }

    $reportId = isset($payload['report_id']) && $payload['report_id'] !== ''
        ? (int) $payload['report_id']
        : null;
    $reportName = 'Delivery Report-' . $reportDate;

    $pdo = db();
    ensureSchema($pdo);
    ensureExtendedDeliveryReportSchema($pdo);
    $pdo->beginTransaction();

    if ($reportId) {
        $statement = $pdo->prepare('
            UPDATE delivery_reports
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
            INSERT INTO delivery_reports (report_name, report_date)
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

    $pdo->prepare('DELETE FROM delivery_report_items WHERE report_id = ?')->execute([$reportId]);
    $pdo->prepare('DELETE FROM delivery_report_pid_items WHERE report_id = ?')->execute([$reportId]);
    $pdo->prepare('DELETE FROM delivery_report_ancillary_items WHERE report_id = ?')->execute([$reportId]);

    $insertItem = $pdo->prepare('
        INSERT INTO delivery_report_items
            (report_id, row_order, customer_name, project_name, delivery_note, dn_number, destination,
             vehicle_type, added_to_delivery, wo_qty, mnf_weight, fix_anc_weight, mnf_qty, previous_delivered_percent, material, remark)
        VALUES
            (:report_id, :row_order, :customer_name, :project_name, :delivery_note, :dn_number, :destination,
             :vehicle_type, :added_to_delivery, :wo_qty, :mnf_weight, :fix_anc_weight, :mnf_qty, :previous_delivered_percent, :material, :remark)
    ');

    foreach (array_values($items) as $index => $item) {
        if (!is_array($item)) {
            continue;
        }

        $insertItem->execute([
            ':report_id' => $reportId,
            ':row_order' => $index + 1,
            ':customer_name' => nullableReportText($item['customer_name'] ?? null),
            ':project_name' => nullableReportText($item['project_name'] ?? null),
            ':delivery_note' => nullableReportText($item['delivery_note'] ?? null),
            ':dn_number' => nullableReportText($item['dn_number'] ?? null),
            ':destination' => nullableReportText($item['destination'] ?? null),
            ':vehicle_type' => nullableReportText($item['vehicle_type'] ?? null),
            ':added_to_delivery' => nullableReportText($item['added_to_delivery'] ?? null),
            ':wo_qty' => nullableReportNumber($item['wo_qty'] ?? null),
            ':mnf_weight' => nullableReportNumber($item['mnf_weight'] ?? null),
            ':fix_anc_weight' => nullableReportNumber($item['fix_anc_weight'] ?? null),
            ':mnf_qty' => nullableReportNumber($item['mnf_qty'] ?? null),
            ':previous_delivered_percent' => nullableReportNumber($item['previous_delivered_percent'] ?? null),
            ':material' => nullableReportText($item['material'] ?? null),
            ':remark' => nullableReportText($item['remark'] ?? null),
        ]);
    }

    $insertPidItem = $pdo->prepare('
        INSERT INTO delivery_report_pid_items
            (report_id, row_order, customer_name, project_name, delivery_note, dn_number, destination, vehicle_type,
             added_to_delivery, wo_qty, mnf_area, supp_rod, mnf_qty, previous_delivered_percent, material, remark)
        VALUES
            (:report_id, :row_order, :customer_name, :project_name, :delivery_note, :dn_number, :destination, :vehicle_type,
             :added_to_delivery, :wo_qty, :mnf_area, :supp_rod, :mnf_qty, :previous_delivered_percent, :material, :remark)
    ');

    if (is_array($pidItems)) {
        foreach (array_values($pidItems) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $insertPidItem->execute([
                ':report_id' => $reportId,
                ':row_order' => $index + 1,
                ':customer_name' => nullableReportText($item['customer_name'] ?? null),
                ':project_name' => nullableReportText($item['project_name'] ?? null),
                ':delivery_note' => nullableReportText($item['delivery_note'] ?? null),
                ':dn_number' => nullableReportText($item['dn_number'] ?? null),
                ':destination' => nullableReportText($item['destination'] ?? null),
                ':vehicle_type' => nullableReportText($item['vehicle_type'] ?? null),
                ':added_to_delivery' => nullableReportText($item['added_to_delivery'] ?? null),
                ':wo_qty' => nullableReportNumber($item['wo_qty'] ?? null),
                ':mnf_area' => nullableReportNumber($item['mnf_area'] ?? null),
                ':supp_rod' => nullableReportNumber($item['supp_rod'] ?? null),
                ':mnf_qty' => nullableReportNumber($item['mnf_qty'] ?? null),
                ':previous_delivered_percent' => nullableReportNumber($item['previous_delivered_percent'] ?? null),
                ':material' => nullableReportText($item['material'] ?? null),
                ':remark' => nullableReportText($item['remark'] ?? null),
            ]);
        }
    }

    $insertAncillaryItem = $pdo->prepare('
        INSERT INTO delivery_report_ancillary_items
            (report_id, row_order, customer_name, project_name, delivery_note, dn_number, destination, vehicle_type,
             item_no, item_name, qty, previous_delivered_percent, total_delivered_percent, remark)
        VALUES
            (:report_id, :row_order, :customer_name, :project_name, :delivery_note, :dn_number, :destination, :vehicle_type,
             :item_no, :item_name, :qty, :previous_delivered_percent, :total_delivered_percent, :remark)
    ');

    if (is_array($ancillaryItems)) {
        foreach (array_values($ancillaryItems) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $insertAncillaryItem->execute([
                ':report_id' => $reportId,
                ':row_order' => $index + 1,
                ':customer_name' => nullableReportText($item['customer_name'] ?? null),
                ':project_name' => nullableReportText($item['project_name'] ?? null),
                ':delivery_note' => nullableReportText($item['delivery_note'] ?? null),
                ':dn_number' => nullableReportText($item['dn_number'] ?? null),
                ':destination' => nullableReportText($item['destination'] ?? null),
                ':vehicle_type' => nullableReportText($item['vehicle_type'] ?? null),
                ':item_no' => nullableReportText($item['item_no'] ?? null),
                ':item_name' => nullableReportText($item['item_name'] ?? null),
                ':qty' => nullableReportNumber($item['qty'] ?? null),
                ':previous_delivered_percent' => nullableReportNumber($item['previous_delivered_percent'] ?? null),
                ':total_delivered_percent' => nullableReportNumber($item['total_delivered_percent'] ?? null),
                ':remark' => nullableReportText($item['remark'] ?? null),
            ]);
        }
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

function nullableReportText(mixed $value): ?string
{
    $value = trim((string) $value);
    return $value === '' ? null : $value;
}

function nullableReportNumber(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    $number = str_replace([',', 'KGs', 'KG', 'PCs', 'PC', 'm²', 'm2', ' M', 'm', '%'], '', trim((string) $value));
    return is_numeric($number) ? $number : null;
}
