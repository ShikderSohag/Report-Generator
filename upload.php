<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/xlsx_reader.php';
require __DIR__ . '/pdf_reader.php';

function redirectWith(string $key, string $value): never
{
    header('Location: index.php?' . http_build_query([$key => $value]));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectWith('error', 'Invalid upload request.');
}

$uploadDir = __DIR__ . '/uploads';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0775, true);
}

try {
    $files = uploadedFiles('excel_file');
    if (!$files) {
        redirectWith('error', 'Please choose at least one valid source file.');
    }

    $summary = [
        'files' => 0,
        'inserted' => 0,
        'skipped' => 0,
        'failed' => 0,
        'errors' => [],
    ];

    foreach ($files as $file) {
        $summary['files']++;

        try {
            $result = importUploadedFile($file, $uploadDir);
            $summary['inserted'] += $result['inserted'];
            $summary['skipped'] += $result['skipped'];
        } catch (Throwable $exception) {
            $summary['failed']++;
            $summary['errors'][] = $file['name'] . ': ' . $exception->getMessage();
        }
    }

    if ($summary['failed'] > 0 && $summary['inserted'] === 0 && $summary['skipped'] === 0) {
        redirectWith('error', 'Import failed: ' . implode(' | ', $summary['errors']));
    }

    $message = "Import completed. Files {$summary['files']}, inserted {$summary['inserted']} new row(s), skipped/updated {$summary['skipped']} existing/invalid row(s)";
    if ($summary['failed'] > 0) {
        $message .= ", failed {$summary['failed']} file(s): " . implode(' | ', $summary['errors']);
    }

    redirectWith(
        'message',
        $message . '.'
    );
} catch (Throwable $exception) {
    redirectWith('error', 'Import failed: ' . $exception->getMessage());
}

function uploadedFiles(string $field): array
{
    if (!isset($_FILES[$field])) {
        return [];
    }

    $files = $_FILES[$field];
    $normalized = [];

    if (is_array($files['name'])) {
        foreach ($files['name'] as $index => $name) {
            if ($files['error'][$index] === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'type' => $files['type'][$index],
                'tmp_name' => $files['tmp_name'][$index],
                'error' => $files['error'][$index],
                'size' => $files['size'][$index],
            ];
        }

        return $normalized;
    }

    if ($files['error'] === UPLOAD_ERR_NO_FILE) {
        return [];
    }

    return [$files];
}

function importUploadedFile(array $file, string $uploadDir): array
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed with error code ' . $file['error']);
    }

    $originalName = $file['name'];
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($extension, ['xlsx', 'csv', 'pdf'], true)) {
        throw new RuntimeException('Only .xlsx, .csv, and .pdf files are supported.');
    }

    $safeName = preg_replace('/[^A-Za-z0-9_.-]/', '_', $originalName);
    $targetPath = $uploadDir . '/' . date('Ymd_His') . '_' . uniqid('', true) . '_' . $safeName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new RuntimeException('Could not save uploaded file.');
    }

    if ($extension === 'pdf') {
        return importPdf($targetPath);
    }

    $rows = $extension === 'csv' ? readCsvRows($targetPath) : readXlsxRows($targetPath);
    return importRows($rows);
}

function importRows(array $rows): array
{
    if (count($rows) < 2) {
        throw new RuntimeException('The uploaded file does not contain data rows.');
    }

    $headers = array_map('normalizeHeader', array_shift($rows));
    $columnMap = array_flip($headers);

    if (!isset($columnMap['wono'])) {
        throw new RuntimeException('Required column WONO was not found.');
    }

    $pdo = db();
    ensureSchema($pdo);

    $exists = $pdo->prepare('SELECT id FROM work_orders WHERE wo_no = ? LIMIT 1');
    $insert = $pdo->prepare('
        INSERT INTO work_orders (wo_no, customer_name, project_name, dn_number, destination, edd, wo_qty, duct_weight, mnf_weight, fix_anc_weight, raw_data)
        VALUES (:wo_no, :customer_name, :project_name, :dn_number, :destination, :edd, :wo_qty, :duct_weight, :mnf_weight, :fix_anc_weight, :raw_data)
    ');
    $update = $pdo->prepare('
        UPDATE work_orders
        SET wo_no = :wo_no,
            customer_name = :customer_name,
            project_name = COALESCE(:project_name, project_name),
            dn_number = COALESCE(:dn_number, dn_number),
            destination = COALESCE(:destination, destination),
            edd = COALESCE(:edd, edd),
            wo_qty = COALESCE(:wo_qty, wo_qty),
            duct_weight = COALESCE(:duct_weight, duct_weight),
            mnf_weight = COALESCE(:mnf_weight, mnf_weight),
            fix_anc_weight = COALESCE(:fix_anc_weight, fix_anc_weight),
            raw_data = :raw_data
        WHERE id = :id
    ');

    $inserted = 0;
    $skipped = 0;

    foreach ($rows as $row) {
        $raw = rowToAssoc($headers, $row);
        $woNo = canonicalWorkOrderNumber(getByHeader($raw, ['wono']));

        if ($woNo === '') {
            $skipped++;
            continue;
        }

        $data = [
            ':customer_name' => nullableText(getByHeader($raw, ['customer'])),
            ':project_name' => nullableText(getByHeader($raw, ['projectname', 'project'])),
            ':dn_number' => nullableText(getByHeader($raw, ['dnnumber', 'dn'])),
            ':destination' => nullableText(getByHeader($raw, ['destination'])),
            ':edd' => nullableText(getByHeader($raw, ['edd'])),
            ':wo_qty' => nullableNumber(getByHeader($raw, ['woqty'])),
            ':duct_weight' => nullableNumber(getByHeader($raw, ['ductweight'])),
            ':mnf_weight' => null,
            ':fix_anc_weight' => null,
            ':raw_data' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        $lookupValues = workOrderLookupValues($woNo);
        $exists = $pdo->prepare(
            'SELECT id FROM work_orders WHERE wo_no IN (' . implode(',', array_fill(0, count($lookupValues), '?')) . ')
            ORDER BY CASE WHEN wo_no = ? THEN 0 ELSE 1 END LIMIT 1'
        );
        $exists->execute([...$lookupValues, $woNo]);
        $existing = $exists->fetch();
        if ($existing) {
            $update->execute($data + [':wo_no' => $woNo, ':id' => $existing['id']]);
            $skipped++;
            continue;
        }

        $insert->execute($data + [':wo_no' => $woNo]);

        $inserted++;
    }

    return ['inserted' => $inserted, 'skipped' => $skipped];
}

function importPdf(string $path): array
{
    $raw = readPdfDeliveryNote($path);
    $woNo = canonicalWorkOrderNumber($raw['wono'] ?? '');

    if ($woNo === '') {
        throw new RuntimeException('Required delivery note / WO number was not found in the PDF.');
    }

    $pdo = db();
    ensureSchema($pdo);

    $exists = $pdo->prepare('SELECT id FROM work_orders WHERE wo_no = ? LIMIT 1');
    $insert = $pdo->prepare('
        INSERT INTO work_orders (wo_no, customer_name, project_name, dn_number, destination, edd, wo_qty, duct_weight, mnf_weight, fix_anc_weight, raw_data)
        VALUES (:wo_no, :customer_name, :project_name, :dn_number, :destination, :edd, :wo_qty, :duct_weight, :mnf_weight, :fix_anc_weight, :raw_data)
    ');
    $update = $pdo->prepare('
        UPDATE work_orders
        SET wo_no = :wo_no,
            customer_name = COALESCE(:customer_name, customer_name),
            project_name = COALESCE(:project_name, project_name),
            dn_number = COALESCE(:dn_number, dn_number),
            destination = COALESCE(:destination, destination),
            edd = COALESCE(:edd, edd),
            wo_qty = COALESCE(:wo_qty, wo_qty),
            duct_weight = COALESCE(:duct_weight, duct_weight),
            mnf_weight = COALESCE(:mnf_weight, mnf_weight),
            fix_anc_weight = COALESCE(:fix_anc_weight, fix_anc_weight),
            raw_data = :raw_data
        WHERE id = :id
    ');

    $data = [
        ':customer_name' => nullableText($raw['customer'] ?? null),
        ':project_name' => nullableText($raw['projectname'] ?? null),
        ':dn_number' => nullableText($raw['dnnumber'] ?? null),
        ':destination' => nullableText($raw['destination'] ?? null),
        ':edd' => nullableText($raw['pdfdate'] ?? null),
        ':wo_qty' => nullableNumber($raw['woqty'] ?? null),
        ':duct_weight' => nullableNumber($raw['ductweight'] ?? null),
        ':mnf_weight' => nullableNumber($raw['mnfweight'] ?? null),
        ':fix_anc_weight' => nullableNumber($raw['fixancweight'] ?? null),
        ':raw_data' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];

    $lookupValues = workOrderLookupValues($woNo);
    $exists = $pdo->prepare(
        'SELECT id FROM work_orders WHERE wo_no IN (' . implode(',', array_fill(0, count($lookupValues), '?')) . ')
        ORDER BY CASE WHEN wo_no = ? THEN 0 ELSE 1 END LIMIT 1'
    );
    $exists->execute([...$lookupValues, $woNo]);
    $existing = $exists->fetch();

    if ($existing) {
        $update->execute($data + [':wo_no' => $woNo, ':id' => $existing['id']]);
        upsertDelivery($pdo, $woNo, $raw);
        return ['inserted' => 0, 'skipped' => 1];
    }

    $insert->execute($data + [':wo_no' => $woNo]);
    upsertDelivery($pdo, $woNo, $raw);
    return ['inserted' => 1, 'skipped' => 0];
}

function upsertDelivery(PDO $pdo, string $woNo, array $raw): void
{
    $dnNumber = nullableText($raw['dnnumber'] ?? null);
    if ($dnNumber === null) {
        $dnNumber = 'D1';
    }

    $insert = $pdo->prepare('
        INSERT INTO work_order_deliveries
            (wo_no, dn_number, project_name, edd, wo_qty, duct_weight, mnf_weight, fix_anc_weight, raw_data)
        VALUES
            (:wo_no, :dn_number, :project_name, :edd, :wo_qty, :duct_weight, :mnf_weight, :fix_anc_weight, :raw_data)
        ON DUPLICATE KEY UPDATE
            project_name = COALESCE(VALUES(project_name), project_name),
            edd = COALESCE(VALUES(edd), edd),
            wo_qty = COALESCE(VALUES(wo_qty), wo_qty),
            duct_weight = COALESCE(VALUES(duct_weight), duct_weight),
            mnf_weight = COALESCE(VALUES(mnf_weight), mnf_weight),
            fix_anc_weight = COALESCE(VALUES(fix_anc_weight), fix_anc_weight),
            raw_data = VALUES(raw_data)
    ');

    $insert->execute([
        ':wo_no' => $woNo,
        ':dn_number' => $dnNumber,
        ':project_name' => nullableText($raw['projectname'] ?? null),
        ':edd' => nullableText($raw['pdfdate'] ?? null),
        ':wo_qty' => nullableNumber($raw['woqty'] ?? null),
        ':duct_weight' => nullableNumber($raw['ductweight'] ?? null),
        ':mnf_weight' => nullableNumber($raw['mnfweight'] ?? null),
        ':fix_anc_weight' => nullableNumber($raw['fixancweight'] ?? null),
        ':raw_data' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function rowToAssoc(array $headers, array $row): array
{
    $assoc = [];

    foreach ($headers as $index => $header) {
        if ($header === '') {
            continue;
        }

        $assoc[$header] = $row[$index] ?? null;
    }

    return $assoc;
}

function getByHeader(array $row, array $keys): mixed
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row)) {
            return $row[$key];
        }
    }

    return null;
}

function normalizeHeader(mixed $header): string
{
    return strtolower(preg_replace('/[^A-Za-z0-9]/', '', trim((string) $header)));
}

function nullableText(mixed $value): ?string
{
    $value = trim((string) $value);
    return $value === '' ? null : $value;
}

function nullableNumber(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    $number = str_replace(',', '', trim((string) $value));
    return is_numeric($number) ? $number : null;
}
