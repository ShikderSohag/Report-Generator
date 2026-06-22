<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = db();
    ensureSchema($pdo);

    $reports = $pdo->query('
        SELECT id, report_name, report_date, updated_at
        FROM delivery_reports
        ORDER BY report_date DESC, id DESC
    ')->fetchAll();

    echo json_encode(['success' => true, 'reports' => $reports]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
}
