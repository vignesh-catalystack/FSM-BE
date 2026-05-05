<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/api.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/helpers.php';

enforce_method('GET');

$user = authenticate($pdo);
ensureNotificationsTable($pdo);

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$limit = max(1, min(100, $limit));
$afterId = isset($_GET['after_id']) ? (int)$_GET['after_id'] : 0;

try {
    if ($afterId > 0) {
        $stmt = $pdo->prepare(
            "
            SELECT id, title, body, type, reference_id, is_read, created_at
            FROM notifications
            WHERE user_id = ? AND id > ?
            ORDER BY id DESC
            LIMIT {$limit}
        "
        );
        $stmt->execute([(int)$user['id'], $afterId]);
    } else {
        $stmt = $pdo->prepare(
            "
            SELECT id, title, body, type, reference_id, is_read, created_at
            FROM notifications
            WHERE user_id = ?
            ORDER BY id DESC
            LIMIT {$limit}
        "
        );
        $stmt->execute([(int)$user['id']]);
    }

    respond_with_json([
        'notifications' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
} catch (Throwable $exception) {
    respond_with_exception('Unable to fetch notifications', $exception);
}