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

    if (!in_array($extension, ['xlsx', 'csv', 'pdf', 'zip'], true)) {
        throw new RuntimeException('Only .xlsx, .csv, .pdf, and .zip files are supported.');
    }

    $safeName = preg_replace('/[^A-Za-z0-9_.-]/', '_', $originalName);
    $targetPath = $uploadDir . '/' . date('Ymd_His') . '_' . uniqid('', true) . '_' . $safeName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new RuntimeException('Could not save uploaded file.');
    }

    if ($extension === 'zip') {
        return importZip($targetPath, $uploadDir);
    }

    if ($extension === 'pdf') {
        return importPdf($targetPath);
    }

    $rows = $extension === 'csv' ? readCsvRows($targetPath) : readXlsxRows($targetPath);
    return importRows($rows);
}

function importZip(string $path, string $uploadDir): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Could not open ZIP file.');
    }

    $summary = ['inserted' => 0, 'skipped' => 0];
    $extractDir = $uploadDir . '/' . pathinfo($path, PATHINFO_FILENAME);
    $entries = zipImportEntries($zip);
    $errors = [];

    if (!is_dir($extractDir)) {
        mkdir($extractDir, 0775, true);
    }

    foreach ($entries as $entry) {
        $stream = $zip->getStream($entry['entry']);
        if (!$stream) {
            continue;
        }

        $safeName = preg_replace('/[^A-Za-z0-9_.-]/', '_', $entry['base']);
        $targetPath = $extractDir . '/' . uniqid('', true) . '_' . $safeName;
        $target = fopen($targetPath, 'wb');

        if (!$target) {
            fclose($stream);
            continue;
        }

        stream_copy_to_stream($stream, $target);
        fclose($stream);
        fclose($target);

        try {
            if ($entry['extension'] === 'pdf') {
                $result = importPdf($targetPath);
            } else {
                $rows = $entry['extension'] === 'csv' ? readCsvRows($targetPath) : readXlsxRows($targetPath);
                $result = importRows($rows);
            }

            $summary['inserted'] += $result['inserted'];
            $summary['skipped'] += $result['skipped'];
        } catch (Throwable $exception) {
            $summary['skipped']++;
            $errors[] = $entry['base'] . ': ' . $exception->getMessage();
        }
    }

    $zip->close();

    if ($summary['inserted'] === 0 && $summary['skipped'] === 0) {
        throw new RuntimeException('No supported Excel, CSV, MNF PDF, or FIX PDF files were found in the ZIP.');
    }

    if ($summary['inserted'] === 0 && $errors) {
        throw new RuntimeException('Could not import selected ZIP files. Details: ' . implode(' | ', $errors));
    }

    return $summary;
}

function zipImportEntries(ZipArchive $zip): array
{
    $entries = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entryName = $zip->getNameIndex($i);
        if ($entryName === false || str_ends_with($entryName, '/')) {
            continue;
        }

        $baseName = basename($entryName);
        if ($baseName === '' || str_starts_with($baseName, '.')) {
            continue;
        }

        $extension = strtolower(pathinfo($baseName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['xlsx', 'csv', 'pdf'], true)) {
            continue;
        }

        $entries[] = [
            'entry' => $entryName,
            'base' => $baseName,
            'extension' => $extension,
            'is_delivery_pdf' => $extension === 'pdf' && isDeliveryNotePdfName($baseName),
        ];
    }

    $deliveryPdfEntries = array_values(array_filter(
        $entries,
        static fn (array $entry): bool => $entry['is_delivery_pdf']
    ));

    if ($deliveryPdfEntries) {
        return array_values(array_filter(
            $entries,
            static fn (array $entry): bool => $entry['extension'] !== 'pdf' || $entry['is_delivery_pdf']
        ));
    }

    return $entries;
}

function isDeliveryNotePdfName(string $fileName): bool
{
    $name = strtolower(pathinfo($fileName, PATHINFO_FILENAME));
    $name = preg_replace('/[^a-z0-9]+/', ' ', $name);

    return preg_match('/\b(mnf|manufactured|fix|fixed|anc|ancillary|ancillaries)\b/', $name) === 1;
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
        INSERT INTO work_orders
            (wo_no, customer_name, project_name, dn_number, destination, edd, wo_qty, duct_weight,
             mnf_weight, fix_anc_weight, duct_system, pid_area, pid_supp_rod, pid_mnf_qty, pid_material, raw_data)
        VALUES
            (:wo_no, :customer_name, :project_name, :dn_number, :destination, :edd, :wo_qty, :duct_weight,
             :mnf_weight, :fix_anc_weight, :duct_system, :pid_area, :pid_supp_rod, :pid_mnf_qty, :pid_material, :raw_data)
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
            duct_system = COALESCE(:duct_system, duct_system),
            pid_area = COALESCE(:pid_area, pid_area),
            pid_supp_rod = COALESCE(:pid_supp_rod, pid_supp_rod),
            pid_mnf_qty = COALESCE(:pid_mnf_qty, pid_mnf_qty),
            pid_material = COALESCE(:pid_material, pid_material),
            raw_data = COALESCE(raw_data, :raw_data)
        WHERE id = :id
    ');

    $inserted = 0;
    $skipped = 0;
    $importedActiveWorkOrders = [];
    $hasActiveWorkOrderColumns = isset($columnMap['status'], $columnMap['ductarea'], $columnMap['ductweight']);

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
            ':duct_system' => nullableText(getByHeader($raw, ['ductsystem'])) ?? 'metal',
            ':pid_area' => nullableNumber(getByHeader($raw, ['pidarea'])),
            ':pid_supp_rod' => nullableNumber(getByHeader($raw, ['pidsupprod'])),
            ':pid_mnf_qty' => nullableNumber(getByHeader($raw, ['pidmnfqty'])),
            ':pid_material' => nullableText(getByHeader($raw, ['pidmaterial'])),
            ':raw_data' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        upsertActiveWorkOrder($pdo, $woNo, $raw);
        if ($hasActiveWorkOrderColumns) {
            $importedActiveWorkOrders[] = $woNo;
        }

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

    if ($hasActiveWorkOrderColumns) {
        markMissingActiveWorkOrdersFinished($pdo, $importedActiveWorkOrders);
    }

    return ['inserted' => $inserted, 'skipped' => $skipped];
}

function markMissingActiveWorkOrdersFinished(PDO $pdo, array $importedWorkOrders): void
{
    $importedWorkOrders = array_values(array_unique(array_filter($importedWorkOrders)));

    if (!$importedWorkOrders) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($importedWorkOrders), '?'));
    $statement = $pdo->prepare("
        UPDATE active_work_orders
        SET status = 'Production Finished'
        WHERE wo_no NOT IN ({$placeholders})
          AND UPPER(TRIM(REPLACE(REPLACE(COALESCE(status, ''), CHAR(13), ' '), CHAR(10), ' '))) NOT IN ('PRODUCTION FINISHED', 'PACKING FINISHED')
    ");
    $statement->execute($importedWorkOrders);
}

function upsertActiveWorkOrder(PDO $pdo, string $woNo, array $raw): void
{
    $status = nullableText(getByHeader($raw, ['status']));

    $statement = $pdo->prepare('
        INSERT INTO active_work_orders
            (wo_no, customer_name, edd, prod_started_date, status, finish, prod_sup_note,
             destination, duct_area, duct_weight, wo_qty, raw_data)
        VALUES
            (:wo_no, :customer_name, :edd, :prod_started_date, :status, :finish, :prod_sup_note,
             :destination, :duct_area, :duct_weight, :wo_qty, :raw_data)
        ON DUPLICATE KEY UPDATE
            customer_name = VALUES(customer_name),
            edd = VALUES(edd),
            prod_started_date = VALUES(prod_started_date),
            status = VALUES(status),
            finish = VALUES(finish),
            prod_sup_note = VALUES(prod_sup_note),
            destination = VALUES(destination),
            duct_area = VALUES(duct_area),
            duct_weight = VALUES(duct_weight),
            wo_qty = VALUES(wo_qty),
            raw_data = VALUES(raw_data)
    ');

    $statement->execute([
        ':wo_no' => $woNo,
        ':customer_name' => nullableText(getByHeader($raw, ['customer'])),
        ':edd' => nullableText(getByHeader($raw, ['edd'])),
        ':prod_started_date' => nullableText(getByHeader($raw, ['prodstarteddate'])),
        ':status' => $status,
        ':finish' => nullableText(getByHeader($raw, ['finish'])),
        ':prod_sup_note' => nullableText(getByHeader($raw, ['prodsupnote'])),
        ':destination' => nullableText(getByHeader($raw, ['destination'])),
        ':duct_area' => nullableNumber(getByHeader($raw, ['ductarea'])),
        ':duct_weight' => nullableNumber(getByHeader($raw, ['ductweight'])),
        ':wo_qty' => nullableNumber(getByHeader($raw, ['woqty'])),
        ':raw_data' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
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
        INSERT INTO work_orders
            (wo_no, customer_name, project_name, dn_number, destination, edd, wo_qty, duct_weight,
             mnf_weight, fix_anc_weight, duct_system, pid_area, pid_supp_rod, pid_mnf_qty, pid_material, raw_data)
        VALUES
            (:wo_no, :customer_name, :project_name, :dn_number, :destination, :edd, :wo_qty, :duct_weight,
             :mnf_weight, :fix_anc_weight, :duct_system, :pid_area, :pid_supp_rod, :pid_mnf_qty, :pid_material, :raw_data)
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
            duct_system = COALESCE(:duct_system, duct_system),
            pid_area = COALESCE(:pid_area, pid_area),
            pid_supp_rod = COALESCE(:pid_supp_rod, pid_supp_rod),
            pid_mnf_qty = COALESCE(:pid_mnf_qty, pid_mnf_qty),
            pid_material = COALESCE(:pid_material, pid_material),
            raw_data = :raw_data
        WHERE id = :id
    ');

    $isPid = ($raw['duct_system'] ?? null) === 'pid';

    $data = [
        ':customer_name' => nullableText($raw['customer'] ?? null),
        ':project_name' => nullableText($raw['projectname'] ?? null),
        ':dn_number' => nullableText($raw['dnnumber'] ?? null),
        ':destination' => nullableText($raw['destination'] ?? null),
        ':edd' => nullableText($raw['pdfdate'] ?? null),
        ':wo_qty' => nullableNumber($raw['woqty'] ?? null),
        ':duct_weight' => $isPid ? nullableNumber($raw['pidarea'] ?? $raw['ductarea'] ?? null) : null,
        ':mnf_weight' => nullableNumber($raw['mnfweight'] ?? null),
        ':fix_anc_weight' => nullableNumber($raw['fixancweight'] ?? null),
        ':duct_system' => nullableText($raw['duct_system'] ?? 'metal'),
        ':pid_area' => nullableNumber($raw['pidarea'] ?? null),
        ':pid_supp_rod' => nullableNumber($raw['pidsupprod'] ?? null),
        ':pid_mnf_qty' => nullableNumber($raw['pidmnfqty'] ?? null),
        ':pid_material' => nullableText($raw['pidmaterial'] ?? null),
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
            (wo_no, dn_number, project_name, edd, wo_qty, duct_weight, mnf_weight, mnf_date, fix_anc_weight, fix_anc_date,
             duct_system, pid_area, pid_supp_rod, pid_mnf_qty, pid_material, raw_data)
        VALUES
            (:wo_no, :dn_number, :project_name, :edd, :wo_qty, :duct_weight, :mnf_weight, :mnf_date, :fix_anc_weight, :fix_anc_date,
             :duct_system, :pid_area, :pid_supp_rod, :pid_mnf_qty, :pid_material, :raw_data)
        ON DUPLICATE KEY UPDATE
            project_name = COALESCE(VALUES(project_name), project_name),
            edd = COALESCE(VALUES(edd), edd),
            wo_qty = COALESCE(VALUES(wo_qty), wo_qty),
            duct_weight = COALESCE(VALUES(duct_weight), duct_weight),
            mnf_weight = COALESCE(VALUES(mnf_weight), mnf_weight),
            mnf_date = COALESCE(VALUES(mnf_date), mnf_date),
            fix_anc_weight = COALESCE(VALUES(fix_anc_weight), fix_anc_weight),
            fix_anc_date = COALESCE(VALUES(fix_anc_date), fix_anc_date),
            duct_system = COALESCE(VALUES(duct_system), duct_system),
            pid_area = COALESCE(VALUES(pid_area), pid_area),
            pid_supp_rod = COALESCE(VALUES(pid_supp_rod), pid_supp_rod),
            pid_mnf_qty = COALESCE(VALUES(pid_mnf_qty), pid_mnf_qty),
            pid_material = COALESCE(VALUES(pid_material), pid_material),
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
        ':mnf_date' => nullableText(($raw['pdf_type'] ?? null) === 'manufactured' ? ($raw['pdfdate'] ?? null) : null),
        ':fix_anc_weight' => nullableNumber($raw['fixancweight'] ?? null),
        ':fix_anc_date' => nullableText(($raw['pdf_type'] ?? null) === 'fixed' ? ($raw['pdfdate'] ?? null) : null),
        ':duct_system' => nullableText($raw['duct_system'] ?? 'metal'),
        ':pid_area' => nullableNumber($raw['pidarea'] ?? null),
        ':pid_supp_rod' => nullableNumber($raw['pidsupprod'] ?? null),
        ':pid_mnf_qty' => nullableNumber($raw['pidmnfqty'] ?? null),
        ':pid_material' => nullableText($raw['pidmaterial'] ?? null),
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
