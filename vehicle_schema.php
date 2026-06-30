<?php
declare(strict_types=1);

function ensureWorkOrderVehicleSchema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS work_order_vehicle_types (
            wo_no VARCHAR(80) NOT NULL PRIMARY KEY,
            vehicle_type VARCHAR(120) NOT NULL,
            source_file VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}
