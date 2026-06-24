<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid delete request.');
    }

    $reportId = (int) ($payload['id'] ?? 0);
    if ($reportId <= 0) {
        throw new RuntimeException('Report id is required.');
    }

    $pdo = db();
    ensureSchema($pdo);

    $statement = $pdo->prepare('DELETE FROM pending_work_order_reports WHERE id = ?');
    $statement->execute([$reportId]);

    echo json_encode([
        'success' => true,
        'message' => $statement->rowCount() > 0 ? 'Pending report deleted.' : 'Pending report was not found.',
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
}
