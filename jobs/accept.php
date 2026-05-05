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

$jobId = request_int_value($sources, ['job_id', 'id']);
$batteryRaw = request_string_value($sources, ['battery'], '');
$chargingRaw = request_string_value($sources, ['is_charging'], '');
$latitude = request_float_value($sources, ['latitude', 'lat', 'location_lat']);
$longitude = request_float_value($sources, ['longitude', 'lng', 'location_lng']);
$accuracy = request_float_value($sources, ['accuracy']);
$speed = request_float_value($sources, ['speed']);
$battery = is_numeric($batteryRaw) ? (int)$batteryRaw : null;
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
            WHERE job_id = ? AND user_id = ? AND status = 'active'
            ORDER BY start_time DESC, id DESC
            LIMIT 1
        ");
        $sessionStmt->execute([$jobId, (int)$user['id']]);
        $sessionId = (int)($sessionStmt->fetchColumn() ?: 0);

        if ($sessionId <= 0) {
            $sessionStmt = $pdo->prepare("
                INSERT INTO job_tracking_sessions (job_id, user_id, start_time, status)
                VALUES (?, ?, NOW(), 'active')
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

        if (
            $latitude !== null && $longitude !== null &&
            $latitude >= -90 && $latitude <= 90 &&
            $longitude >= -180 && $longitude <= 180 &&
            !($latitude == 0.0 && $longitude == 0.0)
        ) {
            $locationStmt = $pdo->prepare("
                INSERT INTO job_locations (
                    session_id, job_id, user_id, latitude, longitude,
                    accuracy, speed, battery, is_charging, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $locationStmt->execute([
                $sessionId,
                $jobId,
                (int)$user['id'],
                $latitude,
                $longitude,
                $accuracy,
                $speed,
                $battery === null ? null : max(0, min(100, $battery)),
                $isCharging
            ]);
        }

        $pdo->commit();

        respond_with_json([
            'success' => true,
            'message' => 'Job already in progress; live tracking resumed',
            'job_id' => $jobId,
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
        SET status = 'stopped', ended_at = NOW()
        WHERE job_id = ? AND user_id = ? AND status = 'active'
    ")->execute([$jobId, (int)$user['id']]);

    // ---------- START NEW SESSION ----------
    $sessionStmt = $pdo->prepare("
        INSERT INTO job_tracking_sessions (job_id, user_id, start_time, status)
        VALUES (?, ?, NOW(), 'active')
    ");
    $sessionStmt->execute([$jobId, (int)$user['id']]);

    $sessionId = (int)$pdo->lastInsertId();

    if (
        $latitude !== null && $longitude !== null &&
        $latitude >= -90 && $latitude <= 90 &&
        $longitude >= -180 && $longitude <= 180 &&
        !($latitude == 0.0 && $longitude == 0.0)
    ) {
        $locationStmt = $pdo->prepare("
            INSERT INTO job_locations (
                session_id, job_id, user_id, latitude, longitude,
                accuracy, speed, battery, is_charging, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $locationStmt->execute([
            $sessionId,
            $jobId,
            (int)$user['id'],
            $latitude,
            $longitude,
            $accuracy,
            $speed,
            $battery === null ? null : max(0, min(100, $battery)),
            $isCharging
        ]);
    }

    // ---------- 🔥 BATTERY UPDATE (ADDED FIX) ----------
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

        // Notify technician
        createNotification(
            $pdo,
            (int)$user['id'],
            'Job Accepted',
            "You accepted job: {$job['title']}",
            'job_accepted',
            $jobId
        );

        // Notify admin + manager
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
        'success' => true,
        'message' => 'Job accepted successfully',
        'job_id' => $jobId,
        'session_id' => $sessionId
    ]);

} catch (Throwable $exception) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond_with_json([
        'success' => false,
        'message' => 'Unable to accept job',
        'error' => $exception->getMessage()
    ], 500);
}
