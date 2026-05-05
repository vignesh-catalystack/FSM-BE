<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/api.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../notifications/helpers.php';
require_once __DIR__ . '/helpers.php';

enforce_method('POST');

$user = authenticate($pdo);
ensure_user_role($user, ['technician'], 'Only technicians can finish jobs');

ensureDeletedJobsTable($pdo);

$sources = request_sources();
$jobId = request_int_value($sources, ['job_id', 'id']);
if ($jobId <= 0) {
    respond_with_json(['message' => 'job_id is required'], 400);
}

$latitude = request_float_value($sources, ['latitude', 'lat', 'location_lat']);
$longitude = request_float_value($sources, ['longitude', 'lng', 'long', 'location_lng']);
$accuracy = request_float_value($sources, ['accuracy']);
$speed = request_float_value($sources, ['speed']);
$battery = request_int_value($sources, ['battery']);
$isCharging = request_int_value($sources, ['is_charging']);

try {
    ensureNotificationsTable($pdo);
} catch (Throwable $exception) {
    // Notification table creation failure should not block finish flow.
}

try {
    $pdo->beginTransaction();

    $jobStmt = $pdo->prepare(
        "
        SELECT j.id, j.title, j.assigned_to, j.status
        FROM jobs j
        LEFT JOIN deleted_jobs dj ON dj.job_id = j.id
        WHERE j.id = ? AND dj.job_id IS NULL
        LIMIT 1
        "
    );
    $jobStmt->execute([$jobId]);
    $jobRow = $jobStmt->fetch(PDO::FETCH_ASSOC);

    if (!$jobRow || (int)$jobRow['assigned_to'] !== (int)$user['id']) {
        $pdo->rollBack();
        respond_with_json(['message' => 'Job not found or not assigned to this technician'], 404);
    }

    $currentStatus = strtolower(trim((string)($jobRow['status'] ?? '')));
    if ($currentStatus === 'cancelled') {
        $pdo->rollBack();
        respond_with_json([
            'message' => 'Cancelled jobs cannot be finished',
            'current_status' => $currentStatus,
        ], 409);
    }

    if (!in_array($currentStatus, ['accepted', 'in_progress', 'completed'], true)) {
        $pdo->rollBack();
        respond_with_json([
            'message' => 'Only accepted/in-progress jobs can be finished',
            'current_status' => $currentStatus,
        ], 409);
    }

    if ($currentStatus !== 'completed') {
        $updateJob = $pdo->prepare(
            "
            UPDATE jobs
            SET status = 'completed'
            WHERE id = ?
            "
        );
        $updateJob->execute([$jobId]);
    }

    $sessionStmt = $pdo->prepare(
        "
        SELECT id
        FROM job_tracking_sessions
        WHERE job_id = ? AND user_id = ?
        ORDER BY id DESC
        LIMIT 1
        "
    );
    $sessionStmt->execute([$jobId, (int)$user['id']]);
    $sessionId = $sessionStmt->fetchColumn();

    if ($sessionId) {
        $endSession = $pdo->prepare(
            "
            UPDATE job_tracking_sessions
            SET status = 'stopped', ended_at = NOW()
            WHERE id = ?
            "
        );
        $endSession->execute([(int)$sessionId]);
    }

    // ✅ UPSERT for finish location
    if ($latitude !== null && $longitude !== null) {
        $insertLocation = $pdo->prepare(
            "
            INSERT INTO job_locations (session_id, job_id, user_id, latitude, longitude, accuracy, speed, battery, is_charging, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                session_id = VALUES(session_id),
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                accuracy = VALUES(accuracy),
                speed = VALUES(speed),
                battery = VALUES(battery),
                is_charging = VALUES(is_charging)
            "
        );
        $insertLocation->execute([
            $sessionId ? (int)$sessionId : null,
            $jobId,
            (int)$user['id'],
            $latitude,
            $longitude,
            $accuracy,
            $speed,
            $battery,
            $isCharging ?? 0,
        ]);
    }

    $pdo->commit();

    try {
        $jobTitle = trim((string)($jobRow['title'] ?? "Job #{$jobId}"));
        notifyUsersByRole(
            $pdo,
            ['admin', 'manager'],
            'Job Completed',
            "{$jobTitle} completed by technician #{$user['id']}",
            'job_completed',
            $jobId
        );
    } catch (Throwable $exception) {
        // Notification failure should not fail API response.
    }

    respond_with_json([
        'success' => true,
        'message' => 'Job completed',
        'job_id' => $jobId,
        'tracking_session_id' => $sessionId ? (int)$sessionId : null,
        'tracking_status' => 'stopped',
        'status' => 'completed',
        'location_saved' => ($latitude !== null && $longitude !== null),
    ]);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond_with_json([
        'success' => false,
        'message' => 'Unable to finish job right now',
        'error' => $exception->getMessage(),
    ], 500);
}