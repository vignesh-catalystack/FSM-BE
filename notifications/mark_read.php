<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/api.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/helpers.php';

enforce_method('POST');

$user = authenticate($pdo);
ensureNotificationsTable($pdo);

$id = request_int_value(request_sources(), ['id']);

try {
    if ($id > 0) {
        $stmt = $pdo->prepare(
            "
            UPDATE notifications
            SET is_read = 1
            WHERE user_id = ? AND id = ?
        "
        );
        $stmt->execute([(int)$user['id'], $id]);
    } else {
        $stmt = $pdo->prepare(
            "
            UPDATE notifications
            SET is_read = 1
            WHERE user_id = ? AND is_read = 0
        "
        );
        $stmt->execute([(int)$user['id']]);
    }

    respond_with_json(['message' => 'Notifications marked as read']);
} catch (Throwable $exception) {
    respond_with_exception('Unable to mark notifications as read', $exception);
}