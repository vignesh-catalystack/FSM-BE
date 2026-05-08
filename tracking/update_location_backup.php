<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/api.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../jobs/helpers.php';

enforce_method('POST');

// ---------- AUTH ----------
$user = authenticate($pdo);

// ---------- INPUT (JSON + FORM SUPPORT) ----------
$sources = request_sources();

$jobId = request_int_value($sources, ['job_id']);
$sessionId = request_int_value($sources, ['session_id']);

$latitude = request_float_value($sources, ['latitude']);
$longitude = request_float_value($sources, ['longitude']);

$accuracy = request_float_value($sources, ['accuracy']);
$speed = request_float_value($sources, ['speed']);

$batteryRaw = request_string_value($sources, ['battery'], '');
$chargingRaw = request_string_value($sources, ['is_charging'], '');
$battery = is_numeric($batteryRaw) ? (int)$batteryRaw : null;
$isCharging = is_numeric($chargingRaw) ? (int)$chargingRaw : 0;

// ---------- VALIDATION ----------
if ($jobId <= 0 || $latitude === null || $longitude === null) {
    respond_with_json([
        'success' => false,
        'message' => 'Invalid location payload'
    ], 400);
}

// ---------- COORDINATE VALIDATION ----------
if (
    $latitude < -90 || $latitude > 90 ||
    $longitude < -180 || $longitude > 180 ||
    ($latitude == 0.0 && $longitude == 0.0)
) {
    respond_with_json([
        'success' => true,
        'skipped' => 'invalid_coordinates'
    ]);
}

// ---------- JOB + SESSION VALIDATION ----------
$stmt = $pdo->prepare("
    SELECT id, status, assigned_to
    FROM jobs
    WHERE id = ? AND assigned_to = ?
    LIMIT 1
");
$stmt->execute([$jobId, (int)$user['id']]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    respond_with_json([
        'success' => false,
        'message' => 'Job not found for technician'
    ], 404);
}

$terminalStatuses = ['completed', 'finished', 'closed', 'deleted'];
$isTerminalJob = in_array($job['status'], $terminalStatuses, true);

$session = null;
if ($sessionId > 0) {
    $stmt = $pdo->prepare("
        SELECT id, status
        FROM job_tracking_sessions
        WHERE id = ? AND job_id = ? AND user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$sessionId, $jobId, (int)$user['id']]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$session || (!$isTerminalJob && $session['status'] !== 'active')) {
    $stmt = $pdo->prepare("
        SELECT id, status
        FROM job_tracking_sessions
        WHERE job_id = ? AND user_id = ? AND status = 'active'
        ORDER BY start_time DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$jobId, (int)$user['id']]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$session) {
    if ($isTerminalJob) {
        $stmt = $pdo->prepare("
            SELECT id, status
            FROM job_tracking_sessions
            WHERE job_id = ? AND user_id = ?
            ORDER BY start_time DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$jobId, (int)$user['id']]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

if (!$session) {
    if ($job['status'] !== 'in_progress') {
        respond_with_json([
            'success' => false,
            'message' => 'Accept job before live tracking'
        ], 409);
    }

    $stmt = $pdo->prepare("
        INSERT INTO job_tracking_sessions (job_id, user_id, start_time, status)
        VALUES (?, ?, NOW(), 'active')
    ");
    $stmt->execute([$jobId, (int)$user['id']]);
    $session = [
        'id' => (int)$pdo->lastInsertId(),
        'status' => 'active',
    ];
}

// ---------- ONLY ACTIVE SESSION ----------
if (!$isTerminalJob && $session['status'] !== 'active') {
    respond_with_json([
        'success' => false,
        'message' => 'Tracking not active'
    ], 409);
}

$sessionId = (int)$session['id'];

// ---------- INSERT LOCATION ----------
$stmt = $pdo->prepare("
    INSERT INTO job_locations (
        session_id,
        job_id,
        user_id,
        latitude,
        longitude,
        accuracy,
        speed,
        battery,
        is_charging,
        created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
");

$stmt->execute([
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

// ---------- UPDATE USER DEVICE STATE ----------
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

// ---------- RESPONSE ----------
respond_with_json([
    'success' => true,
    'message' => 'Location updated'
]);
