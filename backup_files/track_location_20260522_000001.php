<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/api.php';
require_once __DIR__ . '/../middleware/auth.php';

enforce_method('POST');

$user = authenticate($pdo);

if (($user['role'] ?? '') !== 'technician') {
    respond_with_json([
        'success' => false,
        'message' => 'Only technicians can track location'
    ], 403);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    $input = [];
}

$jobId = isset($input['job_id'])
    ? (int)$input['job_id']
    : 0;

$sessionId = isset($input['session_id'])
    ? (int)$input['session_id']
    : 0;

$latitude = isset($input['latitude'])
    ? (float)$input['latitude']
    : null;

$longitude = isset($input['longitude'])
    ? (float)$input['longitude']
    : null;

$accuracy = isset($input['accuracy'])
    ? (float)$input['accuracy']
    : null;

$speed = isset($input['speed'])
    ? (float)$input['speed']
    : null;

$heading = isset($input['heading'])
    ? (float)$input['heading']
    : null;

$battery = isset($input['battery']) && is_numeric($input['battery'])
    ? max(0, min(100, (int)$input['battery']))
    : null;

$isCharging = isset($input['is_charging'])
    ? (int)((bool)$input['is_charging'])
    : 0;

$technicianId = (int)$user['id'];

if (
    $jobId <= 0 ||
    $latitude === null ||
    $longitude === null
) {
    respond_with_json([
        'success' => false,
        'message' => 'Invalid tracking payload'
    ], 400);
}

if (
    $latitude < -90 ||
    $latitude > 90 ||
    $longitude < -180 ||
    $longitude > 180
) {
    respond_with_json([
        'success' => false,
        'message' => 'Invalid coordinates'
    ], 400);
}

try {

    $pdo->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | VALIDATE JOB
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            id,
            assigned_to,
            status
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

    if ((int)$job['assigned_to'] !== $technicianId) {

        $pdo->rollBack();

        respond_with_json([
            'success' => false,
            'message' => 'Job is not assigned to this technician'
        ], 403);
    }

    /*
    |--------------------------------------------------------------------------
    | BLOCK TRACKING FOR COMPLETED/CANCELLED JOBS
    |--------------------------------------------------------------------------
    */

    $jobStatus = strtolower(
        trim((string)($job['status'] ?? ''))
    );

    if (
        in_array(
            $jobStatus,
            ['completed', 'cancelled', 'deleted'],
            true
        )
    ) {

        $pdo->rollBack();

        respond_with_json([
            'success' => false,
            'message' => 'Tracking is not allowed for this job status',
            'status' => $jobStatus
        ], 409);
    }

    /*
    |--------------------------------------------------------------------------
    | CLEANUP STALE SESSIONS
    |--------------------------------------------------------------------------
    */

    $cleanupStmt = $pdo->prepare("
        UPDATE job_tracking_sessions
        SET
            status = 'stale',
            ended_at = UTC_TIMESTAMP()
        WHERE technician_id = ?
          AND status = 'active'
          AND ended_at IS NULL
          AND start_time < (
                UTC_TIMESTAMP() - INTERVAL 1 DAY
          )
    ");

    $cleanupStmt->execute([
        $technicianId
    ]);

    /*
    |--------------------------------------------------------------------------
    | SESSION RESOLUTION
    |--------------------------------------------------------------------------
    */

    if ($sessionId <= 0) {

        $stmt = $pdo->prepare("
            SELECT id
            FROM job_tracking_sessions
            WHERE job_id = ?
              AND technician_id = ?
              AND status = 'active'
            ORDER BY start_time DESC, id DESC
            LIMIT 1
        ");

        $stmt->execute([
            $jobId,
            $technicianId
        ]);

        $sessionId = (int)$stmt->fetchColumn();
    }

    /*
    |--------------------------------------------------------------------------
    | AUTO CREATE SESSION
    |--------------------------------------------------------------------------
    */

    if ($sessionId <= 0) {

        $stmt = $pdo->prepare("
            INSERT INTO job_tracking_sessions (
                job_id,
                technician_id,
                status,
                start_time
            ) VALUES (
                ?, ?, 'active', UTC_TIMESTAMP()
            )
        ");

        $stmt->execute([
            $jobId,
            $technicianId
        ]);

        $sessionId = (int)$pdo->lastInsertId();
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDATE SESSION
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT id
        FROM job_tracking_sessions
        WHERE id = ?
          AND job_id = ?
          AND technician_id = ?
          AND status = 'active'
        LIMIT 1
    ");

    $stmt->execute([
        $sessionId,
        $jobId,
        $technicianId
    ]);

    if (!$stmt->fetch()) {

        $pdo->rollBack();

        respond_with_json([
            'success' => false,
            'message' => 'Invalid tracking session'
        ], 400);
    }

    /*
    |--------------------------------------------------------------------------
    | THROTTLE HISTORY INSERTS
    |--------------------------------------------------------------------------
    |
    | Prevent duplicate inserts and API spam.
    | Live snapshot still updates normally.
    |--------------------------------------------------------------------------
    */

    $skipHistoryInsert = false;

    $throttleStmt = $pdo->prepare("
        SELECT updated_at
        FROM technician_last_locations
        WHERE technician_id = ?
        LIMIT 1
    ");

    $throttleStmt->execute([
        $technicianId
    ]);

    $lastRow = $throttleStmt->fetch(PDO::FETCH_ASSOC);

    if (
        $lastRow &&
        !empty($lastRow['updated_at'])
    ) {

        $lastUpdateTs = strtotime($lastRow['updated_at']);
        $currentTs = time();

        if (($currentTs - $lastUpdateTs) < 10) {
            $skipHistoryInsert = true;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | REJECT VERY BAD GPS POINTS
    |--------------------------------------------------------------------------
    */

    if (
        $accuracy !== null &&
        $accuracy > 100
    ) {
        $skipHistoryInsert = true;
    }

    /*
    |--------------------------------------------------------------------------
    | INSERT HISTORY LOCATION
    |--------------------------------------------------------------------------
    */

    if (!$skipHistoryInsert) {

        $stmt = $pdo->prepare("
            INSERT INTO technician_locations (
                session_id,
                job_id,
                technician_id,
                latitude,
                longitude,
                accuracy,
                speed,
                heading,
                battery,
                is_charging,
                created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP()
            )
        ");

        $stmt->execute([
            $sessionId,
            $jobId,
            $technicianId,
            $latitude,
            $longitude,
            $accuracy,
            $speed,
            $heading,
            $battery,
            $isCharging
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | UPSERT LIVE SNAPSHOT
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        INSERT INTO technician_last_locations (
            technician_id,
            job_id,
            session_id,
            latitude,
            longitude,
            accuracy,
            speed,
            heading,
            battery,
            is_charging,
            updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP()
        )
        ON DUPLICATE KEY UPDATE
            job_id = VALUES(job_id),
            session_id = VALUES(session_id),
            latitude = VALUES(latitude),
            longitude = VALUES(longitude),
            accuracy = VALUES(accuracy),
            speed = VALUES(speed),
            heading = VALUES(heading),
            battery = VALUES(battery),
            is_charging = VALUES(is_charging),
            updated_at = UTC_TIMESTAMP()
    ");

    $stmt->execute([
        $technicianId,
        $jobId,
        $sessionId,
        $latitude,
        $longitude,
        $accuracy,
        $speed,
        $heading,
        $battery,
        $isCharging
    ]);

    /*
    |--------------------------------------------------------------------------
    | UPDATE USER BATTERY
    |--------------------------------------------------------------------------
    */

    if ($battery !== null) {

        $stmt = $pdo->prepare("
            UPDATE users
            SET
                battery = ?,
                is_charging = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $battery,
            $isCharging,
            $technicianId
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | COMMIT
    |--------------------------------------------------------------------------
    */

    $pdo->commit();

    respond_with_json([
        'success' => true,
        'message' => 'Location tracked successfully',
        'job_id' => $jobId,
        'session_id' => $sessionId,
        'history_saved' => !$skipHistoryInsert
    ]);

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log($e->getMessage());

    respond_with_json([
        'success' => false,
        'message' => 'Unable to track location'
    ], 500);
}