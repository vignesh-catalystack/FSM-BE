<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/api.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../notifications/helpers.php';
require_once __DIR__ . '/helpers.php';

enforce_method('POST');

$user = authenticate($pdo);
ensure_user_role($user, ['admin', 'manager'], 'Only admin/manager can delete jobs');

ensureDeletedJobsTable($pdo);

$sources = request_sources();
$jobId = request_int_value($sources, ['job_id', 'id']);
if ($jobId <= 0) {
    respond_with_json(['message' => 'job_id is required'], 400);
}

$reason = request_string_value($sources, ['reason'], 'deleted_by_admin');

try {
    ensureNotificationsTable($pdo);
} catch (Throwable $exception) {
    // Notification table creation failure should not block delete flow.
}

try {
    $pdo->beginTransaction();

    $job = softDeleteJob($pdo, $jobId, (int)$user['id'], $reason);
    if (!$job) {
        $pdo->rollBack();
        respond_with_json(['message' => 'Job not found'], 404);
    }

    $stopSessions = $pdo->prepare(
        "
        UPDATE job_tracking_sessions
        SET status = 'stopped', ended_at = NOW()
        WHERE job_id = ? AND status = 'active'
    "
    );
    $stopSessions->execute([$jobId]);

    $pdo->commit();

    try {
        if (!empty($job['assigned_to'])) {
            createNotification(
                $pdo,
                (int)$job['assigned_to'],
                'Job Removed',
                'Assigned job has been moved to deleted jobs by admin.',
                'job_deleted',
                $jobId
            );
        }
    } catch (Throwable $exception) {
        // Notification failure should not fail API response.
    }

    respond_with_json([
        'success' => true,
        'message' => 'Job moved to deleted list',
        'job_id' => $jobId,
    ]);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond_with_json([
        'success' => false,
        'message' => 'Unable to delete job right now',
        'error' => $exception->getMessage(),
    ], 500);
}