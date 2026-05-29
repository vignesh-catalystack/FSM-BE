<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/api.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../notifications/helpers.php';
require_once __DIR__ . '/helpers.php';

// FSM UPGRADE START
function finish_has_valid_coordinates(?float $latitude, ?float $longitude): bool
{
    if ($latitude === null || $longitude === null) {
        return false;
    }

    if (!is_finite($latitude) || !is_finite($longitude)) {
        return false;
    }

    if (
        $latitude < -90 ||
        $latitude > 90 ||
        $longitude < -180 ||
        $longitude > 180
    ) {
        return false;
    }

    return !($latitude == 0.0 && $longitude == 0.0);
}

function finish_normalize_accuracy(?float $accuracy): ?float
{
    if (
        $accuracy === null ||
        !is_finite($accuracy) ||
        $accuracy < 0 ||
        $accuracy > 5000
    ) {
        return null;
    }

    return round($accuracy, 2);
}

function finish_normalize_speed(?float $speed): ?float
{
    if (
        $speed === null ||
        !is_finite($speed) ||
        $speed < 0 ||
        $speed > 1000
    ) {
        return null;
    }

    return round($speed, 3);
}

function finish_normalize_battery(?int $battery): ?int
{
    if ($battery === null) {
        return null;
    }

    return max(0, min(100, $battery));
}

function finish_normalize_charging(?int $isCharging): int
{
    return ($isCharging ?? 0) > 0 ? 1 : 0;
}
// FSM UPGRADE END

enforce_method('POST');

$user = authenticate($pdo);
ensure_user_role($user, ['technician'], 'Only technicians can finish jobs');

ensureDeletedJobsTable($pdo);

$sources = request_sources();

$jobId = request_int_value($sources, ['job_id', 'id']);

if ($jobId <= 0) {
    respond_with_json([
        'message' => 'job_id is required'
    ], 400);
}

$latitude = request_float_value($sources, ['latitude', 'lat', 'location_lat']);
$longitude = request_float_value($sources, ['longitude', 'lng', 'long', 'location_lng']);
$accuracy = request_float_value($sources, ['accuracy']);
$speed = request_float_value($sources, ['speed']);
$batteryRaw = request_string_value($sources, ['battery'], '');
$isChargingRaw = request_string_value($sources, ['is_charging'], '');
$battery = is_numeric($batteryRaw) ? (int)$batteryRaw : null;
$isCharging = is_numeric($isChargingRaw) ? (int)$isChargingRaw : null;
// FSM UPGRADE START
$accuracy = finish_normalize_accuracy($accuracy);
$speed = finish_normalize_speed($speed);
$battery = finish_normalize_battery($battery);
$isCharging = finish_normalize_charging($isCharging);
$hasValidFinalLocation = finish_has_valid_coordinates($latitude, $longitude);
$locationSaved = false;
// FSM UPGRADE END

try {
    ensureNotificationsTable($pdo);
} catch (Throwable $exception) {
    // Notification table creation failure should not block finish flow.
}

try {

    /*
    |--------------------------------------------------------------------------
    | TRANSACTION
    |--------------------------------------------------------------------------
    */

    $pdo->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | FETCH JOB
    |--------------------------------------------------------------------------
    */

    $jobStmt = $pdo->prepare(
        "
        SELECT
            j.id,
            j.title,
            j.assigned_to,
            j.status
        FROM jobs j

        LEFT JOIN deleted_jobs dj
            ON dj.job_id = j.id

        WHERE j.id = ?
        AND dj.job_id IS NULL

        LIMIT 1
        FOR UPDATE
        "
    );

    $jobStmt->execute([$jobId]);

    $jobRow = $jobStmt->fetch(PDO::FETCH_ASSOC);

    if (
        !$jobRow ||
        (int)$jobRow['assigned_to'] !== (int)$user['id']
    ) {

        $pdo->rollBack();

        respond_with_json([
            'message' => 'Job not found or not assigned to this technician'
        ], 404);
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDATE STATUS
    |--------------------------------------------------------------------------
    */

    $currentStatus = strtolower(
        trim((string)($jobRow['status'] ?? ''))
    );

    if ($currentStatus === 'cancelled') {

        $pdo->rollBack();

        respond_with_json([
            'message' => 'Cancelled jobs cannot be finished',
            'current_status' => $currentStatus,
        ], 409);
    }

    if (
        !in_array(
            $currentStatus,
            ['accepted', 'in_progress', 'completed'],
            true
        )
    ) {

        $pdo->rollBack();

        respond_with_json([
            'message' => 'Only accepted/in-progress jobs can be finished',
            'current_status' => $currentStatus,
        ], 409);
    }

    /*
    |--------------------------------------------------------------------------
    | COMPLETE JOB
    |--------------------------------------------------------------------------
    */

    if ($currentStatus !== 'completed') {

        $updateJob = $pdo->prepare(
            "
            UPDATE jobs
            SET
                status = 'completed',
                completed_at = UTC_TIMESTAMP()
            WHERE id = ?
            "
        );

        $updateJob->execute([$jobId]);
    }

    /*
    |--------------------------------------------------------------------------
    | FETCH ACTIVE TRACKING SESSION
    |--------------------------------------------------------------------------
    */

    $sessionStmt = $pdo->prepare(
        "
        SELECT id
        FROM job_tracking_sessions
        WHERE job_id = ?
        AND technician_id = ?
        AND status = 'active'
        ORDER BY id DESC
        LIMIT 1
        FOR UPDATE
        "
    );

    $sessionStmt->execute([
        $jobId,
        (int)$user['id']
    ]);

    $sessionId = $sessionStmt->fetchColumn();

    /*
    |--------------------------------------------------------------------------
    | COMPLETE TRACKING SESSION
    |--------------------------------------------------------------------------
    */

    if ($sessionId) {

        $endSession = $pdo->prepare(
            "
            UPDATE job_tracking_sessions
            SET
                status = 'completed',
                ended_at = UTC_TIMESTAMP()
            WHERE id = ?
            "
        );

        $endSession->execute([
            (int)$sessionId
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | SAVE FINAL TRACKING POINT
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    | We now save final location into technician_locations
    | instead of old job_locations architecture.
    |--------------------------------------------------------------------------
    */

    if ($hasValidFinalLocation) {

        /*
         |--------------------------------------------------------------------------
         | INSERT FINAL HISTORY POINT
        |--------------------------------------------------------------------------
        */

        $insertHistory = $pdo->prepare(
            "
            INSERT INTO technician_locations (
                technician_id,
                job_id,
                session_id,
                latitude,
                longitude,
                accuracy,
                speed,
                battery,
                is_charging,
                created_at
            )
            VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP()
            )
            "
        );

        $insertHistory->execute([
            (int)$user['id'],
            $jobId,
            $sessionId ? (int)$sessionId : null,
            $latitude,
            $longitude,
            $accuracy,
            $speed,
            $battery,
            $isCharging,
        ]);

        $locationSaved = true;
    }

    // FSM UPGRADE START
    if ($battery !== null) {
        $updateBattery = $pdo->prepare(
            "
            UPDATE users
            SET battery = ?, is_charging = ?
            WHERE id = ?
            "
        );

        $updateBattery->execute([
            $battery,
            $isCharging,
            (int)$user['id'],
        ]);
    }
    // FSM UPGRADE END

    /*
    |--------------------------------------------------------------------------
    | REMOVE LIVE SNAPSHOT
    |--------------------------------------------------------------------------
    |
    | CRITICAL FIX:
    | Prevents ghost live technicians after finishing job.
    |--------------------------------------------------------------------------
    */

    $removeLiveSnapshot = $pdo->prepare(
        "
        DELETE FROM technician_last_locations
        WHERE job_id = ?
        AND technician_id = ?
        "
    );

    $removeLiveSnapshot->execute([
        $jobId,
        (int)$user['id']
    ]);

    /*
    |--------------------------------------------------------------------------
    | COMMIT
    |--------------------------------------------------------------------------
    */

    $pdo->commit();

    /*
    |--------------------------------------------------------------------------
    | NOTIFICATIONS
    |--------------------------------------------------------------------------
    */

    try {

        $jobTitle = trim(
            (string)($jobRow['title'] ?? "Job #{$jobId}")
        );

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

    /*
    |--------------------------------------------------------------------------
    | RESPONSE
    |--------------------------------------------------------------------------
    */

    respond_with_json([
        'success' => true,
        'message' => 'Job completed successfully',

        'job_id' => $jobId,

        'tracking_session_id' => $sessionId
            ? (int)$sessionId
            : null,

        'tracking_status' => 'completed',

        'status' => 'completed',

        'location_saved' => $locationSaved,
    ]);

} catch (Throwable $exception) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log($exception->getMessage());

    respond_with_json([
        'success' => false,
        'message' => 'Unable to finish job right now',
    ], 500);
}
