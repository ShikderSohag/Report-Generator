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
        ORDER BY wo_no ASC
    ")->fetchAll();

    $today = new DateTimeImmutable('today');
    $pendingRows = array_values(array_filter($rows, static function (array $row) use ($today): bool {
        $edd = pendingWorkOrderDate($row['edd'] ?? null);
        return $edd instanceof DateTimeImmutable && $edd <= $today;
    }));

    usort($pendingRows, static function (array $left, array $right): int {
        $leftDate = pendingWorkOrderDate($left['edd'] ?? null);
        $rightDate = pendingWorkOrderDate($right['edd'] ?? null);

        if ($leftDate && $rightDate && $leftDate != $rightDate) {
            return $leftDate <=> $rightDate;
        }

        return strnatcasecmp((string) ($left['wo_no'] ?? ''), (string) ($right['wo_no'] ?? ''));
    });

    echo json_encode(['success' => true, 'items' => $pendingRows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
}

function pendingWorkOrderDate(mixed $value): ?DateTimeImmutable
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }

    $formats = ['!d/M/Y', '!d-M-Y', '!Y-m-d', '!d/m/Y'];
    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $raw);
        if ($date instanceof DateTimeImmutable) {
            return $date;
        }
    }

    if (is_numeric($raw)) {
        $serial = (int) $raw;
        if ($serial > 25569) {
            return (new DateTimeImmutable('1899-12-30'))->modify('+' . $serial . ' days');
        }
    }

    return null;
}
