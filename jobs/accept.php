<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/api.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../jobs/helpers.php';
require_once __DIR__ . '/../notifications/helpers.php';

enforce_method('POST');

// ---------- AUTH ----------
$user = authenticate($pdo);
ensure_user_role($user, ['technician'], 'Only technician can accept jobs');

// ---------- ENSURE TABLE ----------
ensureDeletedJobsTable($pdo);

// ---------- INPUT ----------
$sources = request_sources();

$jobId      = request_int_value($sources, ['job_id', 'id']);
$batteryRaw = request_string_value($sources, ['battery'], '');
$chargingRaw = request_string_value($sources, ['is_charging'], '');
$latitude   = request_float_value($sources, ['latitude', 'lat', 'location_lat']);
$longitude  = request_float_value($sources, ['longitude', 'lng', 'location_lng']);
$accuracy   = request_float_value($sources, ['accuracy']);
$speed      = request_float_value($sources, ['speed']);
$battery    = is_numeric($batteryRaw) ? (int)$batteryRaw : null;
$isCharging = is_numeric($chargingRaw) ? (int)$chargingRaw : 0;

if ($jobId <= 0) {
    respond_with_json([
        'success' => false,
        'message' => 'job_id is required'
    ], 400);
}

try {
    $pdo->beginTransaction();

    // ---------- GET JOB ----------
    $stmt = $pdo->prepare("
        SELECT id, title, status, assigned_to
        FROM jobs
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        $pdo->rollBack();
        respond_with_json([
            'success' => false,
            'message' => 'Job not found'
        ], 404);
    }

    // ---------- OWNERSHIP CHECK ----------
    if ((int)$job['assigned_to'] !== (int)$user['id']) {
        $pdo->rollBack();
        respond_with_json([
            'success' => false,
            'message' => 'This job is not assigned to you'
        ], 403);
    }

    // ---------- IDEMPOTENT RESUME ----------
    if ($job['status'] === 'in_progress') {
        $sessionStmt = $pdo->prepare("
            SELECT id
            FROM job_tracking_sessions
            WHERE job_id = ? AND technician_id = ? AND status = 'active'
            ORDER BY start_time DESC, id DESC
            LIMIT 1
            FOR UPDATE
        ");
        $sessionStmt->execute([$jobId, (int)$user['id']]);
        $sessionId = (int)($sessionStmt->fetchColumn() ?: 0);

        if ($sessionId <= 0) {
            $sessionStmt = $pdo->prepare("
                INSERT INTO job_tracking_sessions (job_id, technician_id, start_time, status)
                VALUES (?, ?, UTC_TIMESTAMP(), 'active')
            ");
            $sessionStmt->execute([$jobId, (int)$user['id']]);
            $sessionId = (int)$pdo->lastInsertId();
        }

        if ($battery !== null) {
            $pdo->prepare("
                UPDATE users
                SET battery = ?, is_charging = ?
                WHERE id = ?
            ")->execute([
                max(0, min(100, $battery)),
                $isCharging,
                (int)$user['id']
            ]);
        }

        $pdo->commit();

        respond_with_json([
            'success'    => true,
            'message'    => 'Job already in progress; live tracking resumed',
            'job_id'     => $jobId,
            'session_id' => $sessionId
        ]);
    }

    // ---------- STATUS CHECK ----------
    if ($job['status'] !== 'assigned') {
        $pdo->rollBack();
        respond_with_json([
            'success' => false,
            'message' => 'Job must be in assigned state'
        ], 400);
    }

    // ---------- UPDATE JOB ----------
    $update = $pdo->prepare("
        UPDATE jobs
        SET status = 'in_progress'
        WHERE id = ? AND assigned_to = ?
    ");
    $update->execute([$jobId, (int)$user['id']]);

    if ($update->rowCount() === 0) {
        $pdo->rollBack();
        respond_with_json([
            'success' => false,
            'message' => 'Failed to update job status'
        ], 400);
    }

    // ---------- STOP OLD SESSION ----------
    $pdo->prepare("
        UPDATE job_tracking_sessions
        SET status = 'stopped', ended_at = COALESCE(ended_at, UTC_TIMESTAMP())
        WHERE job_id = ? AND technician_id = ? AND status = 'active'
    ")->execute([$jobId, (int)$user['id']]);

    // ---------- START NEW SESSION ----------
    $sessionStmt = $pdo->prepare("
        INSERT INTO job_tracking_sessions (job_id, technician_id, start_time, status)
        VALUES (?, ?, UTC_TIMESTAMP(), 'active')
    ");
    $sessionStmt->execute([$jobId, (int)$user['id']]);

    $sessionId = (int)$pdo->lastInsertId();

    // ---------- BATTERY UPDATE ----------
    if ($battery !== null) {
        $pdo->prepare("
            UPDATE users
            SET battery = ?, is_charging = ?
            WHERE id = ?
        ")->execute([
            max(0, min(100, $battery)),
            $isCharging,
            (int)$user['id']
        ]);
    }

    // ---------- NOTIFICATIONS ----------
    try {
        ensureNotificationsTable($pdo);

        createNotification(
            $pdo,
            (int)$user['id'],
            'Job Accepted',
            "You accepted job: {$job['title']}",
            'job_accepted',
            $jobId
        );

        notifyUsersByRole(
            $pdo,
            ['admin', 'manager'],
            'Job Accepted',
            "Technician accepted: {$job['title']}",
            'job_accepted',
            $jobId,
            (int)$user['id']
        );

    } catch (Throwable $e) {
        // do not break flow
    }

    // ---------- COMMIT ----------
    $pdo->commit();

    // ---------- RESPONSE ----------
    respond_with_json([
        'success'    => true,
        'message'    => 'Job accepted successfully',
        'job_id'     => $jobId,
        'session_id' => $sessionId
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond_with_json([
        'success' => false,
        'message' => 'Unable to accept job',
        'error'   => $e->getMessage()
    ], 500);
}
