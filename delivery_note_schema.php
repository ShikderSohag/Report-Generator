<?php
declare(strict_types=1);

function ensureDeliveryNoteQuantitySchema(PDO $pdo): void
{
    $column = $pdo->query("SHOW COLUMNS FROM work_order_deliveries LIKE 'mnf_qty'")->fetch();
    if (!$column) {
        $pdo->exec('ALTER TABLE work_order_deliveries ADD COLUMN mnf_qty DECIMAL(14,3) NULL AFTER wo_qty');
    }

    $pdo->exec('UPDATE work_order_deliveries SET mnf_qty = wo_qty WHERE mnf_qty IS NULL AND wo_qty IS NOT NULL');
}
