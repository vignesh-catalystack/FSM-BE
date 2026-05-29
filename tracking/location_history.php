<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/api.php';
require_once __DIR__ . '/../middleware/auth.php';

// FSM UPGRADE START
function location_history_has_valid_coordinates(?float $latitude, ?float $longitude): bool
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

function location_history_nullable_metric($value, bool $allowNegative = false, ?float $maxValue = null): ?float
{
    if (!is_numeric($value)) {
        return null;
    }

    $normalized = (float)$value;

    if (!is_finite($normalized)) {
        return null;
    }

    if (!$allowNegative && $normalized < 0) {
        return null;
    }

    if ($maxValue !== null && $normalized > $maxValue) {
        return null;
    }

    return $normalized;
}

function location_history_heading($value): ?float
{
    $heading = location_history_nullable_metric($value, true);

    if ($heading === null) {
        return null;
    }

    $heading = fmod($heading, 360.0);

    if ($heading < 0) {
        $heading += 360.0;
    }

    return round($heading, 2);
}

function location_history_iso8601(?string $value): ?string
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        return null;
    }

    return gmdate('c', $timestamp);
}
// FSM UPGRADE END

enforce_method('GET');

$user = authenticate($pdo);
$jobId = query_int_value('job_id');

$params = [];
$where = [];

// Optional job filter
if ($jobId !== null && $jobId > 0) {
    $where[] = 'l.job_id = ?';
    $params[] = $jobId;
}

// Restrict technicians to their own history
if (!in_array($user['role'], ['admin', 'manager'], true)) {
    $where[] = 'l.technician_id = ?';
    $params[] = (int)$user['id'];
}

try {
$sql = "
    SELECT
        l.technician_id,
        l.job_id,
        l.session_id,
        l.latitude,
        l.longitude,
        l.accuracy,
        l.speed,
        l.heading,
        l.battery,
        l.is_charging,
        COALESCE(l.device_time, l.created_at) AS captured_at,
        l.created_at AS received_at,
        l.device_time
    FROM technician_locations l
    " . (!empty($where)
        ? 'WHERE ' . implode(' AND ', $where)
        : '') . "
    ORDER BY COALESCE(l.device_time, l.created_at) ASC, l.id ASC
    LIMIT 5000
";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $final = [];

    foreach ($rows as $row) {
        $lat = isset($row['latitude']) ? (float)$row['latitude'] : null;
        $lng = isset($row['longitude']) ? (float)$row['longitude'] : null;

        if (!location_history_has_valid_coordinates($lat, $lng)) {
            continue;
        }

        $row['latitude'] = $lat;
        $row['longitude'] = $lng;
        $row['accuracy'] = location_history_nullable_metric($row['accuracy'] ?? null, false, 60);
        $row['speed'] = location_history_nullable_metric($row['speed'] ?? null, false, 60);
        $row['heading'] = location_history_heading($row['heading'] ?? null);
        $row['battery'] = is_numeric($row['battery'] ?? null)
            ? max(0, min(100, (int)$row['battery']))
            : null;
        $row['is_charging'] = (bool)($row['is_charging'] ?? false);
        $row['captured_at'] = location_history_iso8601(
            isset($row['captured_at']) ? (string)$row['captured_at'] : null
        );
        $row['received_at'] = location_history_iso8601(
            isset($row['received_at']) ? (string)$row['received_at'] : null
        );
        $row['device_time'] = !empty($row['device_time'])
            ? location_history_iso8601((string)$row['device_time'])
            : null;

        $final[] = $row;
    }

    respond_with_json([
        'success' => true,
        'data' => $final
    ]);
} catch (Throwable $exception) {
    error_log($exception->getMessage());

    respond_with_json([
        'success' => false,
        'message' => 'Unable to fetch location history'
    ], 500);
}
