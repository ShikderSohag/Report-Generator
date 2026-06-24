<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Riyadh');

/*
 * Put your database credentials here.
 * The app will create the `work_orders` table automatically if it does not exist.
 */

$dbHost = 'localhost';
$dbName = 'aqdfxhnuxo_dreports';
$dbUser = 'aqdfxhnuxo_dreports';
$dbPass = "ysG4c'6x6!cK:&S";
$dbCharset = 'utf8mb4';
$pythonPath = '/home/aqdfxhnuxo/virtualenv/app.ducty.shop/Report-Generator/3.9/bin/python';

function db(): PDO
{
    global $dbHost, $dbName, $dbUser, $dbPass, $dbCharset;

    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = "mysql:host={$dbHost};dbname={$dbName};charset={$dbCharset}";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+03:00'");

    return $pdo;
}

function canonicalWorkOrderNumber(mixed $value): string
{
    $workOrder = strtoupper(trim((string) $value));
    $workOrder = preg_replace('/\s+/', '', $workOrder);

    if ($workOrder === '') {
        return '';
    }

    return str_starts_with($workOrder, 'W') ? $workOrder : 'W' . $workOrder;
}

function workOrderLookupValues(mixed $value): array
{
    $canonical = canonicalWorkOrderNumber($value);
    if ($canonical === '') {
        return [];
    }

    $withoutPrefix = ltrim($canonical, 'W');
    return array_values(array_unique([$canonical, $withoutPrefix]));
}

function ensureSchema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS work_orders (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            wo_no VARCHAR(80) NOT NULL,
            customer_name VARCHAR(255) NULL,
            project_name VARCHAR(255) NULL,
            dn_number VARCHAR(40) NULL,
            destination VARCHAR(255) NULL,
            edd VARCHAR(80) NULL,
            wo_qty DECIMAL(14,3) NULL,
            duct_weight DECIMAL(14,3) NULL,
            mnf_weight DECIMAL(14,3) NULL,
            mnf_date VARCHAR(40) NULL,
            fix_anc_weight DECIMAL(14,3) NULL,
            fix_anc_date VARCHAR(40) NULL,
            duct_system VARCHAR(20) NULL,
            pid_area DECIMAL(14,3) NULL,
            pid_supp_rod DECIMAL(14,3) NULL,
            pid_mnf_qty DECIMAL(14,3) NULL,
            pid_material VARCHAR(120) NULL,
            raw_data LONGTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_work_orders_wo_no (wo_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS work_order_deliveries (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            wo_no VARCHAR(80) NOT NULL,
            dn_number VARCHAR(40) NOT NULL,
            project_name VARCHAR(255) NULL,
            edd VARCHAR(80) NULL,
            wo_qty DECIMAL(14,3) NULL,
            duct_weight DECIMAL(14,3) NULL,
            mnf_weight DECIMAL(14,3) NULL,
            fix_anc_weight DECIMAL(14,3) NULL,
            duct_system VARCHAR(20) NULL,
            pid_area DECIMAL(14,3) NULL,
            pid_supp_rod DECIMAL(14,3) NULL,
            pid_mnf_qty DECIMAL(14,3) NULL,
            pid_material VARCHAR(120) NULL,
            raw_data LONGTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_work_order_deliveries_wo_dn (wo_no, dn_number),
            KEY idx_work_order_deliveries_wo_no (wo_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS delivery_reports (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            report_name VARCHAR(255) NOT NULL,
            report_date DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_delivery_reports_name (report_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS delivery_report_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            report_id INT UNSIGNED NOT NULL,
            row_order INT UNSIGNED NOT NULL,
            customer_name VARCHAR(255) NULL,
            project_name VARCHAR(255) NULL,
            delivery_note VARCHAR(80) NULL,
            dn_number VARCHAR(40) NULL,
            destination VARCHAR(255) NULL,
            added_to_delivery VARCHAR(10) NULL,
            wo_qty DECIMAL(14,3) NULL,
            mnf_weight DECIMAL(14,3) NULL,
            fix_anc_weight DECIMAL(14,3) NULL,
            mnf_qty DECIMAL(14,3) NULL,
            previous_delivered_percent DECIMAL(8,2) NULL,
            remark TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_delivery_report_items_report_id (report_id),
            CONSTRAINT fk_delivery_report_items_report
                FOREIGN KEY (report_id) REFERENCES delivery_reports(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS delivery_report_ancillary_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            report_id INT UNSIGNED NOT NULL,
            row_order INT UNSIGNED NOT NULL,
            customer_name VARCHAR(255) NULL,
            project_name VARCHAR(255) NULL,
            delivery_note VARCHAR(80) NULL,
            dn_number VARCHAR(40) NULL,
            item_no VARCHAR(80) NULL,
            item_name VARCHAR(255) NULL,
            qty DECIMAL(14,3) NULL,
            previous_delivered_percent DECIMAL(8,2) NULL,
            total_delivered_percent DECIMAL(8,2) NULL,
            remark TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_delivery_report_ancillary_items_report_id (report_id),
            CONSTRAINT fk_delivery_report_ancillary_items_report
                FOREIGN KEY (report_id) REFERENCES delivery_reports(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS delivery_report_pid_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            report_id INT UNSIGNED NOT NULL,
            row_order INT UNSIGNED NOT NULL,
            customer_name VARCHAR(255) NULL,
            project_name VARCHAR(255) NULL,
            delivery_note VARCHAR(80) NULL,
            dn_number VARCHAR(40) NULL,
            added_to_delivery VARCHAR(10) NULL,
            wo_qty DECIMAL(14,3) NULL,
            mnf_area DECIMAL(14,3) NULL,
            supp_rod DECIMAL(14,3) NULL,
            mnf_qty DECIMAL(14,3) NULL,
            previous_delivered_percent DECIMAL(8,2) NULL,
            material VARCHAR(120) NULL,
            remark TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_delivery_report_pid_items_report_id (report_id),
            CONSTRAINT fk_delivery_report_pid_items_report
                FOREIGN KEY (report_id) REFERENCES delivery_reports(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS active_work_orders (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            wo_no VARCHAR(80) NOT NULL,
            customer_name VARCHAR(255) NULL,
            edd VARCHAR(80) NULL,
            prod_started_date VARCHAR(80) NULL,
            status VARCHAR(80) NULL,
            finish VARCHAR(120) NULL,
            prod_sup_note VARCHAR(255) NULL,
            destination VARCHAR(255) NULL,
            duct_area DECIMAL(14,3) NULL,
            duct_weight DECIMAL(14,3) NULL,
            wo_qty DECIMAL(14,3) NULL,
            raw_data LONGTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_active_work_orders_wo_no (wo_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS active_work_order_reports (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            report_name VARCHAR(255) NOT NULL,
            report_date DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_active_work_order_reports_name (report_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS active_work_order_report_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            report_id INT UNSIGNED NOT NULL,
            row_order INT UNSIGNED NOT NULL,
            wo_no VARCHAR(80) NOT NULL,
            customer_name VARCHAR(255) NULL,
            edd VARCHAR(80) NULL,
            prod_started_date VARCHAR(80) NULL,
            status VARCHAR(80) NULL,
            finish VARCHAR(120) NULL,
            prod_sup_note VARCHAR(255) NULL,
            destination VARCHAR(255) NULL,
            duct_area DECIMAL(14,3) NULL,
            duct_weight DECIMAL(14,3) NULL,
            wo_qty DECIMAL(14,3) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_active_work_order_report_items_report_id (report_id),
            CONSTRAINT fk_active_work_order_report_items_report
                FOREIGN KEY (report_id) REFERENCES active_work_order_reports(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pending_work_order_reports (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            report_name VARCHAR(255) NOT NULL,
            report_date DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_pending_work_order_reports_name (report_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pending_work_order_report_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            report_id INT UNSIGNED NOT NULL,
            row_order INT UNSIGNED NOT NULL,
            wo_no VARCHAR(80) NOT NULL,
            customer_name VARCHAR(255) NULL,
            edd VARCHAR(80) NULL,
            prod_started_date VARCHAR(80) NULL,
            status VARCHAR(80) NULL,
            finish VARCHAR(120) NULL,
            prod_sup_note VARCHAR(255) NULL,
            destination VARCHAR(255) NULL,
            duct_area DECIMAL(14,3) NULL,
            duct_weight DECIMAL(14,3) NULL,
            wo_qty DECIMAL(14,3) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_pending_work_order_report_items_report_id (report_id),
            CONSTRAINT fk_pending_work_order_report_items_report
                FOREIGN KEY (report_id) REFERENCES pending_work_order_reports(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $destinationColumn = $pdo->query("SHOW COLUMNS FROM work_orders LIKE 'destination'")->fetch();
    if (!$destinationColumn) {
        $projectColumn = $pdo->query("SHOW COLUMNS FROM work_orders LIKE 'project_name'")->fetch();
        $position = $projectColumn ? 'AFTER project_name' : 'AFTER customer_name';
        $pdo->exec("ALTER TABLE work_orders ADD COLUMN destination VARCHAR(255) NULL {$position}");
    }

    $projectColumn = $pdo->query("SHOW COLUMNS FROM work_orders LIKE 'project_name'")->fetch();
    if (!$projectColumn) {
        $pdo->exec('ALTER TABLE work_orders ADD COLUMN project_name VARCHAR(255) NULL AFTER customer_name');
    }

    $dnNumberColumn = $pdo->query("SHOW COLUMNS FROM work_orders LIKE 'dn_number'")->fetch();
    if (!$dnNumberColumn) {
        $pdo->exec('ALTER TABLE work_orders ADD COLUMN dn_number VARCHAR(40) NULL AFTER project_name');
    }

    $woQtyColumn = $pdo->query("SHOW COLUMNS FROM work_orders LIKE 'wo_qty'")->fetch();
    if (!$woQtyColumn) {
        $pdo->exec('ALTER TABLE work_orders ADD COLUMN wo_qty DECIMAL(14,3) NULL AFTER edd');
    }

    $mnfWeightColumn = $pdo->query("SHOW COLUMNS FROM work_orders LIKE 'mnf_weight'")->fetch();
    if (!$mnfWeightColumn) {
        $pdo->exec('ALTER TABLE work_orders ADD COLUMN mnf_weight DECIMAL(14,3) NULL AFTER duct_weight');
    }

    $fixAncWeightColumn = $pdo->query("SHOW COLUMNS FROM work_orders LIKE 'fix_anc_weight'")->fetch();
    if (!$fixAncWeightColumn) {
        $pdo->exec('ALTER TABLE work_orders ADD COLUMN fix_anc_weight DECIMAL(14,3) NULL AFTER mnf_weight');
    }

    foreach ([
        'duct_system' => "ALTER TABLE work_orders ADD COLUMN duct_system VARCHAR(20) NULL AFTER fix_anc_weight",
        'pid_area' => "ALTER TABLE work_orders ADD COLUMN pid_area DECIMAL(14,3) NULL AFTER duct_system",
        'pid_supp_rod' => "ALTER TABLE work_orders ADD COLUMN pid_supp_rod DECIMAL(14,3) NULL AFTER pid_area",
        'pid_mnf_qty' => "ALTER TABLE work_orders ADD COLUMN pid_mnf_qty DECIMAL(14,3) NULL AFTER pid_supp_rod",
        'pid_material' => "ALTER TABLE work_orders ADD COLUMN pid_material VARCHAR(120) NULL AFTER pid_mnf_qty",
    ] as $column => $sql) {
        if (!$pdo->query("SHOW COLUMNS FROM work_orders LIKE '{$column}'")->fetch()) {
            $pdo->exec($sql);
        }
    }

    $mnfDateColumn = $pdo->query("SHOW COLUMNS FROM work_order_deliveries LIKE 'mnf_date'")->fetch();
    if (!$mnfDateColumn) {
        $pdo->exec('ALTER TABLE work_order_deliveries ADD COLUMN mnf_date VARCHAR(40) NULL AFTER mnf_weight');
    }

    $fixAncDateColumn = $pdo->query("SHOW COLUMNS FROM work_order_deliveries LIKE 'fix_anc_date'")->fetch();
    if (!$fixAncDateColumn) {
        $pdo->exec('ALTER TABLE work_order_deliveries ADD COLUMN fix_anc_date VARCHAR(40) NULL AFTER fix_anc_weight');
    }

    foreach ([
        'duct_system' => "ALTER TABLE work_order_deliveries ADD COLUMN duct_system VARCHAR(20) NULL AFTER fix_anc_date",
        'pid_area' => "ALTER TABLE work_order_deliveries ADD COLUMN pid_area DECIMAL(14,3) NULL AFTER duct_system",
        'pid_supp_rod' => "ALTER TABLE work_order_deliveries ADD COLUMN pid_supp_rod DECIMAL(14,3) NULL AFTER pid_area",
        'pid_mnf_qty' => "ALTER TABLE work_order_deliveries ADD COLUMN pid_mnf_qty DECIMAL(14,3) NULL AFTER pid_supp_rod",
        'pid_material' => "ALTER TABLE work_order_deliveries ADD COLUMN pid_material VARCHAR(120) NULL AFTER pid_mnf_qty",
    ] as $column => $sql) {
        if (!$pdo->query("SHOW COLUMNS FROM work_order_deliveries LIKE '{$column}'")->fetch()) {
            $pdo->exec($sql);
        }
    }

    normalizeStoredWorkOrderNumbers($pdo);
    repairImportedRawData($pdo);
    syncActiveWorkOrdersFromRawData($pdo);
}

function normalizeStoredWorkOrderNumbers(PDO $pdo): void
{
    $rows = $pdo->query("
        SELECT id, wo_no
        FROM work_orders
        WHERE UPPER(wo_no) NOT LIKE 'W%'
    ")->fetchAll();

    $find = $pdo->prepare('SELECT id FROM work_orders WHERE wo_no = ? LIMIT 1');
    $update = $pdo->prepare('UPDATE work_orders SET wo_no = ? WHERE id = ?');

    foreach ($rows as $row) {
        $canonical = canonicalWorkOrderNumber($row['wo_no']);
        if ($canonical === '') {
            continue;
        }

        $find->execute([$canonical]);
        if ($find->fetch()) {
            continue;
        }

        $update->execute([$canonical, $row['id']]);
    }
}

function repairImportedRawData(PDO $pdo): void
{
    $rows = $pdo->query("
        SELECT id, project_name, dn_number, destination, wo_qty, duct_weight, mnf_weight, fix_anc_weight, raw_data
        FROM work_orders
        WHERE raw_data IS NOT NULL
    ")->fetchAll();

    $update = $pdo->prepare('
        UPDATE work_orders
        SET project_name = COALESCE(:project_name, project_name),
            dn_number = COALESCE(:dn_number, dn_number),
            destination = COALESCE(:destination, destination),
            wo_qty = COALESCE(:wo_qty, wo_qty),
            duct_weight = COALESCE(:duct_weight, duct_weight),
            mnf_weight = COALESCE(:mnf_weight, mnf_weight),
            fix_anc_weight = COALESCE(:fix_anc_weight, fix_anc_weight)
        WHERE id = :id
    ');

    foreach ($rows as $row) {
        $raw = json_decode((string) $row['raw_data'], true);
        if (!is_array($raw)) {
            continue;
        }

        $projectName = nullableSchemaText($raw['projectname'] ?? $raw['project'] ?? null);
        $dnNumber = nullableSchemaText($raw['dnnumber'] ?? null);
        $destination = nullableSchemaText($raw['destination'] ?? null);
        $woQty = nullableSchemaNumber($raw['woqty'] ?? null);
        $ductWeight = ($raw['source'] ?? null) === 'pdf' ? null : nullableSchemaNumber($raw['ductweight'] ?? null);
        $mnfWeight = nullableSchemaNumber($raw['mnfweight'] ?? null);
        $fixAncWeight = nullableSchemaNumber($raw['fixancweight'] ?? null);

        if (
            $projectName === nullableSchemaText($row['project_name'])
            && $dnNumber === nullableSchemaText($row['dn_number'])
            && $destination === nullableSchemaText($row['destination'])
            && $woQty === nullableSchemaNumber($row['wo_qty'])
            && $ductWeight === nullableSchemaNumber($row['duct_weight'])
            && $mnfWeight === nullableSchemaNumber($row['mnf_weight'])
            && $fixAncWeight === nullableSchemaNumber($row['fix_anc_weight'])
        ) {
            continue;
        }

        $update->execute([
            ':project_name' => $projectName,
            ':dn_number' => $dnNumber,
            ':destination' => $destination,
            ':wo_qty' => $woQty,
            ':duct_weight' => $ductWeight,
            ':mnf_weight' => $mnfWeight,
            ':fix_anc_weight' => $fixAncWeight,
            ':id' => $row['id'],
        ]);
    }
}

function syncActiveWorkOrdersFromRawData(PDO $pdo): void
{
    $rows = $pdo->query("
        SELECT wo_no, raw_data
        FROM work_orders
        WHERE raw_data IS NOT NULL
    ")->fetchAll();

    $upsert = $pdo->prepare('
        INSERT INTO active_work_orders
            (wo_no, customer_name, edd, prod_started_date, status, finish, prod_sup_note,
             destination, duct_area, duct_weight, wo_qty, raw_data)
        VALUES
            (:wo_no, :customer_name, :edd, :prod_started_date, :status, :finish, :prod_sup_note,
             :destination, :duct_area, :duct_weight, :wo_qty, :raw_data)
        ON DUPLICATE KEY UPDATE
            customer_name = COALESCE(customer_name, VALUES(customer_name)),
            edd = COALESCE(edd, VALUES(edd)),
            prod_started_date = COALESCE(prod_started_date, VALUES(prod_started_date)),
            status = COALESCE(status, VALUES(status)),
            finish = COALESCE(finish, VALUES(finish)),
            prod_sup_note = COALESCE(prod_sup_note, VALUES(prod_sup_note)),
            destination = COALESCE(destination, VALUES(destination)),
            duct_area = COALESCE(duct_area, VALUES(duct_area)),
            duct_weight = COALESCE(duct_weight, VALUES(duct_weight)),
            wo_qty = COALESCE(wo_qty, VALUES(wo_qty)),
            raw_data = COALESCE(raw_data, VALUES(raw_data))
    ');

    foreach ($rows as $row) {
        $raw = json_decode((string) $row['raw_data'], true);
        if (!is_array($raw) || !array_key_exists('status', $raw)) {
            continue;
        }

        $woNo = canonicalWorkOrderNumber($row['wo_no']);
        if ($woNo === '') {
            continue;
        }

        $upsert->execute([
            ':wo_no' => $woNo,
            ':customer_name' => nullableSchemaText($raw['customer'] ?? null),
            ':edd' => nullableSchemaText($raw['edd'] ?? null),
            ':prod_started_date' => nullableSchemaText($raw['prodstarteddate'] ?? null),
            ':status' => nullableSchemaText($raw['status'] ?? null),
            ':finish' => nullableSchemaText($raw['finish'] ?? null),
            ':prod_sup_note' => nullableSchemaText($raw['prodsupnote'] ?? null),
            ':destination' => nullableSchemaText($raw['destination'] ?? null),
            ':duct_area' => nullableSchemaNumber($raw['ductarea'] ?? null),
            ':duct_weight' => nullableSchemaNumber($raw['ductweight'] ?? null),
            ':wo_qty' => nullableSchemaNumber($raw['woqty'] ?? null),
            ':raw_data' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}

function nullableSchemaText(mixed $value): ?string
{
    $value = trim((string) $value);
    return $value === '' ? null : $value;
}

function nullableSchemaNumber(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    $number = str_replace(',', '', trim((string) $value));
    return is_numeric($number) ? $number : null;
}
