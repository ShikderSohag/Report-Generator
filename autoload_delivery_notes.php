<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/vehicle_schema.php';

header('Content-Type: application/json; charset=utf-8');

$reportDate = trim((string) ($_GET['report_date'] ?? ''));

if ($reportDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Report date is required.']);
    exit;
}

try {
    $pdo = db();
    ensureSchema($pdo);
    ensureWorkOrderVehicleSchema($pdo);

    $statement = $pdo->query("
        SELECT
            d.wo_no,
            d.dn_number,
            d.project_name AS delivery_project_name,
            d.edd,
            d.wo_qty AS delivery_wo_qty,
            d.duct_weight AS delivery_duct_weight,
            d.mnf_weight,
            d.mnf_date,
            d.fix_anc_weight,
            d.fix_anc_date,
            d.duct_system,
            d.pid_area,
            d.pid_supp_rod,
            d.pid_mnf_qty,
            d.pid_material,
            d.raw_data AS delivery_raw_data,
            w.customer_name,
            w.project_name AS work_order_project_name,
            w.destination,
            w.duct_weight AS work_order_duct_weight,
            w.raw_data AS work_order_raw_data,
            v.vehicle_type AS scheduled_vehicle_type
        FROM work_order_deliveries d
        LEFT JOIN work_orders w ON w.wo_no = d.wo_no
        LEFT JOIN work_order_vehicle_types v ON v.wo_no = d.wo_no
        ORDER BY d.wo_no ASC, d.dn_number ASC, d.id ASC
    ");

    $groups = [];
    foreach ($statement->fetchAll() as $row) {
        $groups[(string) $row['wo_no']][] = $row;
    }

    $deliveries = [];
    foreach ($groups as $rows) {
        usort($rows, static function (array $left, array $right): int {
            $leftOrder = deliverySortValue($left['dn_number'] ?? '');
            $rightOrder = deliverySortValue($right['dn_number'] ?? '');

            if ($leftOrder === $rightOrder) {
                return strnatcasecmp((string) ($left['dn_number'] ?? ''), (string) ($right['dn_number'] ?? ''));
            }

            return $leftOrder <=> $rightOrder;
        });

        $previousMnfWeight = 0.0;
        $previousPidArea = 0.0;

        foreach ($rows as $row) {
            $row['previous_mnf_weight'] = $previousMnfWeight;
            $row['previous_pid_area'] = $previousPidArea;

            if (deliveryMatchesReportDate($row, $reportDate)) {
                $deliveries[] = deliveryPayload($row);
            }

            $previousMnfWeight += is_numeric($row['mnf_weight'] ?? null) ? (float) $row['mnf_weight'] : 0.0;
            $previousPidArea += is_numeric($row['pid_area'] ?? null) ? (float) $row['pid_area'] : 0.0;
        }
    }

    echo json_encode([
        'success' => true,
        'report_date' => $reportDate,
        'deliveries' => $deliveries,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
}

function deliveryPayload(array $row): array
{
    $workOrderRaw = json_decode((string) ($row['work_order_raw_data'] ?? ''), true);
    $deliveryRaw = json_decode((string) ($row['delivery_raw_data'] ?? ''), true);
    $workOrderRaw = is_array($workOrderRaw) ? $workOrderRaw : [];
    $deliveryRaw = is_array($deliveryRaw) ? $deliveryRaw : [];
    $raw = array_replace($workOrderRaw, $deliveryRaw);
    $ductSystem = strtolower(trim((string) ($row['duct_system'] ?? ''))) === 'pid' ? 'pid' : 'metal';
    $woNo = (string) ($row['wo_no'] ?? '');
    $displayDeliveryNote = preg_replace('/^W/i', '', $woNo);

    if ($ductSystem === 'pid') {
        return [
            'wo_no' => $displayDeliveryNote,
            'delivery_note' => $displayDeliveryNote,
            'customer_name' => $row['customer_name'] ?? null,
            'project_name' => $row['delivery_project_name'] ?: ($row['work_order_project_name'] ?? null),
            'dn_number' => $row['dn_number'] ?? null,
            'destination' => $row['destination'] ?? null,
            'vehicle_type' => $row['scheduled_vehicle_type'] ?: deliveryVehicleType($raw),
            'added_to_delivery' => 'Yes',
            'duct_system' => 'pid',
            'pid_area' => nullablePayloadNumber($row['pid_area'] ?? $row['mnf_weight'] ?? null),
            'pid_supp_rod' => nullablePayloadNumber($row['pid_supp_rod'] ?? null),
            'pid_mnf_qty' => nullablePayloadNumber($row['pid_mnf_qty'] ?? $row['delivery_wo_qty'] ?? null),
            'pid_material' => $row['pid_material'] ?? null,
            'previous_pid_area' => nullablePayloadNumber($row['previous_pid_area'] ?? 0),
        ];
    }

    $workOrderWeight = nullablePayloadNumber($row['work_order_duct_weight'] ?? null);
    if (($workOrderWeight ?? 0) <= 0 && isset($raw['ductweight'])) {
        $workOrderWeight = nullablePayloadNumber($raw['ductweight']);
    }
    if (($workOrderWeight ?? 0) <= 0) {
        $workOrderWeight = nullablePayloadNumber($row['delivery_duct_weight'] ?? $row['mnf_weight'] ?? null);
    }

    return [
        'wo_no' => $displayDeliveryNote,
        'delivery_note' => $displayDeliveryNote,
        'customer_name' => $row['customer_name'] ?? null,
        'project_name' => $row['delivery_project_name'] ?: ($row['work_order_project_name'] ?? null),
        'dn_number' => $row['dn_number'] ?? null,
        'destination' => $row['destination'] ?? null,
        'vehicle_type' => $row['scheduled_vehicle_type'] ?: deliveryVehicleType($raw),
        'added_to_delivery' => 'Yes',
        'duct_system' => 'metal',
        'duct_weight' => $workOrderWeight,
        'wo_qty' => nullablePayloadNumber($row['delivery_wo_qty'] ?? null),
        'mnf_weight' => nullablePayloadNumber($row['mnf_weight'] ?? null),
        'fix_anc_weight' => nullablePayloadNumber($row['fix_anc_weight'] ?? null),
        'previous_mnf_weight' => nullablePayloadNumber($row['previous_mnf_weight'] ?? 0),
        'material' => deliveryMetalMaterial($raw),
    ];
}

function deliveryMetadataText(array $raw, array $keys): ?string
{
    foreach ($keys as $key) {
        $value = trim((string) ($raw[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return null;
}

function deliveryVehicleType(array $raw): ?string
{
    $value = deliveryMetadataText($raw, ['vehicletype', 'vehicle_type', 'vehicle']);
    if ($value !== null) {
        return $value;
    }

    $text = (string) ($raw['raw_text'] ?? '');
    if (preg_match('/^(?:Vehicle\s+Type|Truck\s+Type|Vehicle)\s*:\s*(.+)$/mi', $text, $matches)) {
        return trim(preg_replace('/\s+/', ' ', $matches[1]));
    }
    return null;
}

function deliveryMetalMaterial(array $raw): ?string
{
    $value = deliveryMetadataText($raw, ['metalmaterial', 'material', 'ducttype']);
    if ($value !== null) {
        return shortMetalMaterial($value);
    }
    return shortMetalMaterial(deliveryMetadataText($raw, ['raw_text']), false);
}

function shortMetalMaterial(?string $value, bool $allowOriginal = true): ?string
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

function deliveryMatchesReportDate(array $row, string $reportDate): bool
{
    foreach (['edd', 'mnf_date', 'fix_anc_date'] as $field) {
        if (normalizeDeliveryDate($row[$field] ?? null) === $reportDate) {
            return true;
        }
    }

    return false;
}

function normalizeDeliveryDate(mixed $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    $formats = ['!n/j/Y', '!m/d/Y', '!d/m/Y', '!j/n/Y', '!d-M-Y', '!j-M-Y'];
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $value);
        if ($date instanceof DateTime) {
            return $date->format('Y-m-d');
        }
    }

    $timestamp = strtotime($value);
    return $timestamp === false ? null : date('Y-m-d', $timestamp);
}

function deliverySortValue(mixed $dnNumber): int
{
    $value = strtoupper(trim((string) $dnNumber));
    if (preg_match('/(?:DN|D|DELIVERY)\s*-?\s*(\d+)/i', $value, $matches)) {
        return (int) $matches[1];
    }

    return PHP_INT_MAX;
}

function nullablePayloadNumber(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    $number = str_replace(',', '', trim((string) $value));
    return is_numeric($number) ? (float) $number : null;
}
