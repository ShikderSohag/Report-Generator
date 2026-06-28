<?php
declare(strict_types=1);

require __DIR__ . '/excel_exporter.php';

$targetPath = null;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Invalid export request.');
    }

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid report payload.');
    }

    $reportDate = trim((string) ($payload['report_date'] ?? ''));
    $tmpDir = __DIR__ . '/tmp';
    if (!is_dir($tmpDir) && !mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
        throw new RuntimeException('Could not create the export directory.');
    }

    $targetPath = $tmpDir . '/delivery-report-' . bin2hex(random_bytes(8)) . '.xlsx';
    createDailyReportWorkbook($payload, $targetPath);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="Delivery Report-' . $reportDate . '.xlsx"');
    header('Content-Length: ' . filesize($targetPath));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    readfile($targetPath);
    unlink($targetPath);
} catch (Throwable $exception) {
    if (is_string($targetPath) && is_file($targetPath)) {
        unlink($targetPath);
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
}
