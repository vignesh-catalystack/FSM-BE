<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/api.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../notifications/helpers.php';

$user = authenticate($pdo);
ensure_user_role($user, ['admin'], 'Forbidden');

$payload = read_json_input();
$title = trim((string)($payload['title'] ?? ''));
$assignedTo = (int)($payload['assigned_to'] ?? 0);

if ($title === '' || $assignedTo <= 0) {
    respond_with_json(['message' => 'Invalid input'], 400);
}

$techStmt = $pdo->prepare(
    "
    SELECT id
    FROM users
    WHERE id = ? AND role = 'technician' AND status = 1
    LIMIT 1
"
);
$techStmt->execute([$assignedTo]);
if (!$techStmt->fetch(PDO::FETCH_ASSOC)) {
    respond_with_json(['message' => 'Assigned technician is invalid or inactive'], 400);
}

try {
    $stmt = $pdo->prepare(
        "
        INSERT INTO jobs (title, assigned_to, status)
        VALUES (?, ?, 'assigned')
    "
    );
    $stmt->execute([$title, $assignedTo]);
    $jobId = (int)$pdo->lastInsertId();

    try {
        ensureNotificationsTable($pdo);

        createNotification(
            $pdo,
            $assignedTo,
            'Work Assigned',
            "Admin assigned: {$title}",
            'job_assigned',
            $jobId
        );

        notifyUsersByRole(
            $pdo,
            ['admin', 'manager'],
            'Job Created',
            "{$title} assigned to technician #{$assignedTo}",
            'job_created',
            $jobId,
            (int)$user['id']
        );
    } catch (Throwable $exception) {
        // Notification issues should not fail job creation.
    }

    respond_with_json([
        'message' => 'Job created successfully',
        'job_id' => $jobId,
    ]);
} catch (Throwable $exception) {
    respond_with_exception('Database error', $exception);
}