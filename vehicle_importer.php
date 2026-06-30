<?php
declare(strict_types=1);

require_once __DIR__ . '/xlsx_reader.php';
require_once __DIR__ . '/vehicle_schema.php';

function extractVehicleMappingsFromWorkbook(string $path): array
{
    foreach (readAllXlsxWorksheets($path) as $sheetName => $rows) {
        $header = findVehicleHeader($rows);
        if ($header === null) {
            continue;
        }

        $mappings = [];
        $lastVehicle = '';
        for ($index = $header['row'] + 1, $count = count($rows); $index < $count; $index++) {
            $row = $rows[$index];
            $workOrders = extractVehicleWorkOrders($row[$header['work_orders']] ?? null);
            if (!$workOrders) {
                continue;
            }

            $vehicle = normalizeVehicleType($row[$header['trucks']] ?? null);
            if ($vehicle !== '') {
                $lastVehicle = $vehicle;
            } else {
                $vehicle = $lastVehicle;
            }

            if ($vehicle === '') {
                continue;
            }

            foreach ($workOrders as $workOrder) {
                $mappings[$workOrder] = $vehicle;
            }
        }

        if ($mappings) {
            return [
                'sheet' => $sheetName,
                'mappings' => $mappings,
            ];
        }
    }

    throw new RuntimeException('No worksheet with Work Orders and Trucks columns was found.');
}

function importVehicleWorkbook(PDO $pdo, string $path, string $sourceFile): array
{
    $extracted = extractVehicleMappingsFromWorkbook($path);
    ensureWorkOrderVehicleSchema($pdo);

    $exists = $pdo->prepare('SELECT wo_no FROM work_order_vehicle_types WHERE wo_no = ? LIMIT 1');
    $upsert = $pdo->prepare('
        INSERT INTO work_order_vehicle_types (wo_no, vehicle_type, source_file)
        VALUES (:wo_no, :vehicle_type, :source_file)
        ON DUPLICATE KEY UPDATE
            vehicle_type = VALUES(vehicle_type),
            source_file = VALUES(source_file)
    ');

    $inserted = 0;
    $updated = 0;
    foreach ($extracted['mappings'] as $workOrder => $vehicle) {
        $exists->execute([$workOrder]);
        $alreadyExists = (bool) $exists->fetchColumn();
        $upsert->execute([
            ':wo_no' => $workOrder,
            ':vehicle_type' => $vehicle,
            ':source_file' => $sourceFile,
        ]);
        $alreadyExists ? $updated++ : $inserted++;
    }

    return ['inserted' => $inserted, 'skipped' => $updated];
}

function findVehicleHeader(array $rows): ?array
{
    $limit = min(count($rows), 60);
    for ($rowIndex = 0; $rowIndex < $limit; $rowIndex++) {
        $headers = [];
        foreach ($rows[$rowIndex] as $columnIndex => $value) {
            $headers[normalizeVehicleHeader($value)] = $columnIndex;
        }

        $workOrderColumn = firstVehicleHeaderColumn($headers, ['workorders', 'workorder', 'wos']);
        $truckColumn = firstVehicleHeaderColumn($headers, ['trucks', 'truck', 'vehicletype', 'vehicle']);
        if ($workOrderColumn !== null && $truckColumn !== null) {
            return [
                'row' => $rowIndex,
                'work_orders' => $workOrderColumn,
                'trucks' => $truckColumn,
            ];
        }
    }

    return null;
}

function firstVehicleHeaderColumn(array $headers, array $names): ?int
{
    foreach ($names as $name) {
        if (array_key_exists($name, $headers)) {
            return $headers[$name];
        }
    }
    return null;
}

function normalizeVehicleHeader(mixed $value): string
{
    return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', trim((string) $value)));
}

function normalizeVehicleType(mixed $value): string
{
    return trim((string) preg_replace('/\s+/', ' ', (string) $value));
}

function extractVehicleWorkOrders(mixed $value): array
{
    $text = strtoupper(str_ireplace('_x000D_', "\n", (string) $value));
    if (!preg_match_all('/\bW?\d{9,}\b/', $text, $matches)) {
        return [];
    }

    $workOrders = [];
    foreach ($matches[0] as $match) {
        $workOrder = preg_replace('/\s+/', '', trim((string) $match));
        if (!str_starts_with($workOrder, 'W')) {
            $workOrder = 'W' . $workOrder;
        }
        $workOrders[] = $workOrder;
    }

    return array_values(array_unique($workOrders));
}
