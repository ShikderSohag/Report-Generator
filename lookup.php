<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/vehicle_schema.php';

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
    ensureWorkOrderVehicleSchema($pdo);

    $lookupValues = workOrderLookupValues($woNo);
    $scheduledVehicle = lookupScheduledVehicle($pdo, $lookupValues, $woNo);
    $statement = $pdo->prepare('
        SELECT wo_no, customer_name, project_name, dn_number, destination, edd, wo_qty, duct_weight, mnf_weight,
               fix_anc_weight, duct_system, pid_area, pid_supp_rod, pid_mnf_qty, pid_material, raw_data
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

    $workOrderRaw = json_decode((string) ($row['raw_data'] ?? ''), true);
    $workOrderRaw = is_array($workOrderRaw) ? $workOrderRaw : [];
    if ((float) ($row['duct_weight'] ?? 0) <= 0) {
        if (isset($workOrderRaw['ductweight']) && is_numeric($workOrderRaw['ductweight'])) {
            $row['duct_weight'] = $workOrderRaw['ductweight'];
        }
    }
    $row['vehicle_type'] = $scheduledVehicle ?: lookupVehicleType($workOrderRaw);
    $row['material'] = lookupMetalMaterial($workOrderRaw);
    unset($row['raw_data']);

    $deliveriesStatement = $pdo->prepare('
        SELECT dn_number, project_name, edd, wo_qty, duct_weight, mnf_weight, mnf_date, fix_anc_weight, fix_anc_date,
               duct_system, pid_area, pid_supp_rod, pid_mnf_qty, pid_material, raw_data
        FROM work_order_deliveries
        WHERE wo_no IN (' . implode(',', array_fill(0, count($lookupValues), '?')) . ')
    ');
    $deliveriesStatement->execute($lookupValues);
    $deliveries = $deliveriesStatement->fetchAll();
    foreach ($deliveries as &$delivery) {
        $deliveryRaw = json_decode((string) ($delivery['raw_data'] ?? ''), true);
        $deliveryRaw = is_array($deliveryRaw) ? $deliveryRaw : [];
        $metadata = array_replace($workOrderRaw, $deliveryRaw);
        $delivery['vehicle_type'] = $scheduledVehicle ?: lookupVehicleType($metadata);
        $delivery['material'] = lookupMetalMaterial($metadata);
        unset($delivery['raw_data']);
    }
    unset($delivery);
    $deliveries = withPreviousDeliveryTotals($deliveries);

    if (!$deliveries && !empty($row['dn_number'])) {
        $deliveries[] = [
            'dn_number' => $row['dn_number'],
            'project_name' => $row['project_name'],
            'edd' => $row['edd'],
            'wo_qty' => $row['wo_qty'],
            'duct_weight' => $row['duct_weight'],
            'mnf_weight' => $row['mnf_weight'],
            'mnf_date' => null,
            'fix_anc_weight' => $row['fix_anc_weight'],
            'fix_anc_date' => null,
            'duct_system' => $row['duct_system'] ?: 'metal',
            'pid_area' => $row['pid_area'],
            'pid_supp_rod' => $row['pid_supp_rod'],
            'pid_mnf_qty' => $row['pid_mnf_qty'],
            'pid_material' => $row['pid_material'],
            'vehicle_type' => $row['vehicle_type'],
            'material' => $row['material'],
            'previous_mnf_weight' => 0,
            'previous_pid_area' => 0,
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

function lookupScheduledVehicle(PDO $pdo, array $lookupValues, string $canonicalWorkOrder): ?string
{
    if (!$lookupValues) {
        return null;
    }

    $statement = $pdo->prepare('
        SELECT vehicle_type
        FROM work_order_vehicle_types
        WHERE wo_no IN (' . implode(',', array_fill(0, count($lookupValues), '?')) . ')
        ORDER BY CASE WHEN wo_no = ? THEN 0 ELSE 1 END
        LIMIT 1
    ');
    $statement->execute([...$lookupValues, $canonicalWorkOrder]);
    $vehicle = trim((string) ($statement->fetchColumn() ?: ''));
    return $vehicle === '' ? null : $vehicle;
}

function withPreviousDeliveryTotals(array $deliveries): array
{
    usort($deliveries, static function (array $left, array $right): int {
        $leftOrder = deliverySortValue($left['dn_number'] ?? '');
        $rightOrder = deliverySortValue($right['dn_number'] ?? '');

        if ($leftOrder === $rightOrder) {
            return strnatcasecmp((string) ($left['dn_number'] ?? ''), (string) ($right['dn_number'] ?? ''));
        }

        return $leftOrder <=> $rightOrder;
    });

    $previousMnfWeight = 0.0;
    $previousPidArea = 0.0;
    foreach ($deliveries as &$delivery) {
        $delivery['previous_mnf_weight'] = $previousMnfWeight;
        $delivery['previous_pid_area'] = $previousPidArea;
        $previousMnfWeight += is_numeric($delivery['mnf_weight'] ?? null) ? (float) $delivery['mnf_weight'] : 0.0;
        $previousPidArea += is_numeric($delivery['pid_area'] ?? null) ? (float) $delivery['pid_area'] : 0.0;
    }
    unset($delivery);

    return $deliveries;
}

function lookupMetadataText(array $raw, array $keys): ?string
{
    foreach ($keys as $key) {
        $value = trim((string) ($raw[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return null;
}

function lookupVehicleType(array $raw): ?string
{
    $value = lookupMetadataText($raw, ['vehicletype', 'vehicle_type', 'vehicle']);
    if ($value !== null) {
        return $value;
    }

    $text = (string) ($raw['raw_text'] ?? '');
    if (preg_match('/^(?:Vehicle\s+Type|Truck\s+Type|Vehicle)\s*:\s*(.+)$/mi', $text, $matches)) {
        return trim(preg_replace('/\s+/', ' ', $matches[1]));
    }
    return null;
}

function lookupMetalMaterial(array $raw): ?string
{
    $value = lookupMetadataText($raw, ['metalmaterial', 'material', 'ducttype']);
    if ($value !== null) {
        return shortLookupMetalMaterial($value);
    }
    return shortLookupMetalMaterial(lookupMetadataText($raw, ['raw_text']), false);
}

function shortLookupMetalMaterial(?string $value, bool $allowOriginal = true): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $ductType = preg_match('/\bDOUBLE\s*WALL\b|\bDW\b/i', $value) ? 'DW' : 'SW';
    $materials = [];
    $patterns = [
        'SS 304' => '/(?:STAINLESS\s*STEEL|\bSS\b).*\b304\b|\b304\b.*(?:STAINLESS\s*STEEL|\bSS\b)/i',
        'SS 316' => '/(?:STAINLESS\s*STEEL|\bSS\b).*\b316\b|\b316\b.*(?:STAINLESS\s*STEEL|\bSS\b)/i',
        'GI' => '/\bGALVANI[ZS]ED\b|\bGI\b/i',
        'AL' => '/\bALUMINI?UM\b/i',
        'BS' => '/\bBLACK\s*STEEL\b/i',
        'MS' => '/\bMILD\s*STEEL\b/i',
    ];
    foreach ($patterns as $code => $pattern) {
        if (preg_match($pattern, $value)) {
            $materials[] = $code;
        }
    }

    if ($materials) {
        return $ductType . '/' . implode('+', $materials);
    }
    if (!$allowOriginal) {
        return $ductType === 'DW' ? 'DW' : null;
    }

    if (preg_match('/^(SW|DW)\s*\/\s*(.+)$/i', $value, $matches)) {
        return strtoupper($matches[1]) . '/' . trim($matches[2]);
    }
    if (preg_match('/^(SW|DW)$/i', $value, $matches)) {
        return strtoupper($matches[1]);
    }
    return $ductType . '/' . $value;
}

function deliverySortValue(mixed $dnNumber): int
{
    $value = strtoupper(trim((string) $dnNumber));
    if (preg_match('/(?:DN|D|DELIVERY)\s*-?\s*(\d+)/i', $value, $matches)) {
        return (int) $matches[1];
    }

    return PHP_INT_MAX;
}
