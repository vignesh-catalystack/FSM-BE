<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/api.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/helpers.php';

enforce_method('GET');

$user = authenticate($pdo);
ensureNotificationsTable($pdo);

try {
    $stmt = $pdo->prepare(
        "
        SELECT COUNT(*) AS unread_count
        FROM notifications
        WHERE user_id = ? AND is_read = 0
    "
    );
    $stmt->execute([(int)$user['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    respond_with_json([
        'unread_count' => (int)($row['unread_count'] ?? 0),
    ]);
} catch (Throwable $exception) {
    respond_with_exception('Unable to fetch unread count', $exception);
}