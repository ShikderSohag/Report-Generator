<?php
declare(strict_types=1);

function ensureExtendedDeliveryReportSchema(PDO $pdo): void
{
    $columns = [
        ['delivery_report_items', 'vehicle_type', "ALTER TABLE delivery_report_items ADD COLUMN vehicle_type VARCHAR(120) NULL AFTER destination"],
        ['delivery_report_items', 'material', "ALTER TABLE delivery_report_items ADD COLUMN material VARCHAR(120) NULL AFTER previous_delivered_percent"],
        ['delivery_report_pid_items', 'destination', "ALTER TABLE delivery_report_pid_items ADD COLUMN destination VARCHAR(255) NULL AFTER dn_number"],
        ['delivery_report_pid_items', 'vehicle_type', "ALTER TABLE delivery_report_pid_items ADD COLUMN vehicle_type VARCHAR(120) NULL AFTER destination"],
        ['delivery_report_ancillary_items', 'destination', "ALTER TABLE delivery_report_ancillary_items ADD COLUMN destination VARCHAR(255) NULL AFTER dn_number"],
        ['delivery_report_ancillary_items', 'vehicle_type', "ALTER TABLE delivery_report_ancillary_items ADD COLUMN vehicle_type VARCHAR(120) NULL AFTER destination"],
    ];

    $exists = $pdo->prepare('
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ');

    foreach ($columns as [$table, $column, $alterSql]) {
        $exists->execute([$table, $column]);
        if (!$exists->fetchColumn()) {
            $pdo->exec($alterSql);
        }
    }
}
