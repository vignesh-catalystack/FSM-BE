<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/api.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../jobs/helpers.php';

enforce_method('POST');

$user = authenticate($pdo);

// ─────────────────────────────────────────────
// INPUT
// ─────────────────────────────────────────────
$data = get_json_input();

$jobId = isset($data['job_id']) ? (int)$data['job_id'] : 0;
$lat = isset($data['latitude']) ? (float)$data['latitude'] : null;
$lng = isset($data['longitude']) ? (float)$data['longitude'] : null;
$accuracy = isset($data['accuracy']) ? (float)$data['accuracy'] : null;
$speed = isset($data['speed']) ? (float)$data['speed'] : 0.0;
$battery = isset($data['battery']) ? (int)$data['battery'] : null;
$isCharging = isset($data['is_charging']) ? (bool)$data['is_charging'] : false;
$sessionId = isset($data['session_id']) ? (int)$data['session_id'] : null;

// ─────────────────────────────────────────────
// BASIC VALIDATION
// ─────────────────────────────────────────────
if ($jobId <= 0 || $lat === null || $lng === null) {
    respond_with_json(['success' => false, 'message' => 'Missing required fields'], 422);
}

// ─────────────────────────────────────────────
// JOB VALIDATION
// ─────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT id, status FROM jobs WHERE id = ?");
$stmt->execute([$jobId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    respond_with_json(['success' => false, 'message' => 'Job not found'], 404);
}

if ($job['status'] !== 'in_progress') {
    respond_with_json(['success' => true, 'message' => 'Skipped (job not active)']);
    return;
}

// ─────────────────────────────────────────────
// ❌ BLOCK DELETED JOBS
// ─────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT 1 FROM deleted_jobs WHERE job_id = ? LIMIT 1");
$stmt->execute([$jobId]);

if ($stmt->fetchColumn()) {
    respond_with_json(['success' => true, 'message' => 'Skipped (job deleted)']);
    return;
}

// ─────────────────────────────────────────────
// SESSION VALIDATION
// ─────────────────────────────────────────────
if ($sessionId) {
    $stmt = $pdo->prepare("SELECT id, status FROM job_tracking_sessions WHERE id = ?");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session || $session['status'] !== 'active') {
        respond_with_json(['success' => true, 'message' => 'Skipped (inactive session)']);
        return;
    }
}

// ─────────────────────────────────────────────
// GPS VALIDATION
// ─────────────────────────────────────────────

// invalid coords
if ($lat == 0.0 && $lng == 0.0) {
    respond_with_json(['success' => true, 'message' => 'Skipped (invalid coords)']);
    return;
}

// out of bounds
if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    respond_with_json(['success' => true, 'message' => 'Skipped (out of range)']);
    return;
}

// strict accuracy
if ($accuracy !== null && $accuracy > 25) {
    respond_with_json(['success' => true, 'message' => 'Skipped (low accuracy)']);
    return;
}

// ─────────────────────────────────────────────
// PREVIOUS POINT (TIME-BASED)
// ─────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT latitude, longitude, created_at
    FROM job_locations
    WHERE user_id = ? AND job_id = ?
    ORDER BY created_at DESC
    LIMIT 1
");
$stmt->execute([$user['id'], $jobId]);
$prev = $stmt->fetch(PDO::FETCH_ASSOC);

if ($prev) {
    $prevLat = (float)$prev['latitude'];
    $prevLng = (float)$prev['longitude'];

    $distance = haversine($prevLat, $prevLng, $lat, $lng);

    $prevTime = strtotime($prev['created_at']);
    $now = time();
    $timeDiff = max(1, $now - $prevTime);

    $calcSpeed = $distance / $timeDiff;

    // jitter filter
    if ($distance < 8 && $speed < 0.5) {
        respond_with_json(['success' => true, 'message' => 'Skipped (jitter)']);
        return;
    }

    // teleport filter
    if ($distance > 300) {
        respond_with_json(['success' => true, 'message' => 'Skipped (teleport)']);
        return;
    }

    // unrealistic speed (>180 km/h)
    if ($calcSpeed > 50) {
        respond_with_json(['success' => true, 'message' => 'Skipped (invalid speed)']);
        return;
    }

    // spam protection
    if ($timeDiff < 2) {
        respond_with_json(['success' => true, 'message' => 'Skipped (too frequent)']);
        return;
    }
}

// ─────────────────────────────────────────────
// NORMALIZATION
// ─────────────────────────────────────────────
if ($battery !== null) {
    $battery = max(0, min(100, $battery));
}

// ─────────────────────────────────────────────
// INSERT
// ─────────────────────────────────────────────
$stmt = $pdo->prepare("
    INSERT INTO job_locations (
        job_id,
        user_id,
        latitude,
        longitude,
        accuracy,
        speed,
        battery,
        is_charging,
        session_id,
        created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
");

$stmt->execute([
    $jobId,
    $user['id'],
    $lat,
    $lng,
    $accuracy,
    $speed,
    $battery,
    $isCharging ? 1 : 0,
    $sessionId
]);

// ─────────────────────────────────────────────
// RESPONSE
// ─────────────────────────────────────────────
respond_with_json([
    'success' => true,
    'message' => 'Location stored'
]);

// ─────────────────────────────────────────────
// HELPER
// ─────────────────────────────────────────────
function haversine($lat1, $lon1, $lat2, $lon2): float {
    $R = 6371000;

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat/2)**2 +
         cos(deg2rad($lat1)) *
         cos(deg2rad($lat2)) *
         sin($dLon/2)**2;

    return 2 * $R * atan2(sqrt($a), sqrt(1 - $a));
}