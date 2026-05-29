<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/api.php';
require_once __DIR__ . '/../middleware/auth.php';

// FSM UPGRADE START
function last_locations_has_valid_coordinates(?float $latitude, ?float $longitude): bool
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

function last_locations_nullable_metric($value, bool $allowNegative = false, ?float $maxValue = null): ?float
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

function last_locations_heading($value): ?float
{
    $heading = last_locations_nullable_metric($value, true);

    if ($heading === null) {
        return null;
    }

    $heading = fmod($heading, 360.0);

    if ($heading < 0) {
        $heading += 360.0;
    }

    return round($heading, 2);
}

function last_locations_iso8601(?string $value): ?string
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

/*
|--------------------------------------------------------------------------
| INPUT
|--------------------------------------------------------------------------
*/

$requestedTechnicianId = isset($_GET['technician_id'])
    ? (int)$_GET['technician_id']
    : null;

/*
|--------------------------------------------------------------------------
| ROLE FILTER
|--------------------------------------------------------------------------
*/

$params = [];
$where  = [];

if (in_array($user['role'], ['admin', 'manager'], true)) {

    if ($requestedTechnicianId !== null && $requestedTechnicianId > 0) {
        $where[] = 't.technician_id = ?';
        $params[] = $requestedTechnicianId;
    }

} else {

    $where[] = 't.technician_id = ?';
    $params[] = (int)$user['id'];
}

$whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

/*
|--------------------------------------------------------------------------
| QUERY (FAST SNAPSHOT)
|--------------------------------------------------------------------------
*/

try {

$sql = "
SELECT
    t.technician_id,
    t.job_id,
    t.latitude,
    t.longitude,
    t.accuracy,
    t.speed,
    t.heading,
    t.battery,
    t.is_charging,
    t.updated_at,

    u.name AS technician_name

FROM technician_last_locations t

LEFT JOIN users u ON u.id = t.technician_id

{$whereSql}
";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /*
    |--------------------------------------------------------------------------
    | NORMALIZATION
    |--------------------------------------------------------------------------
    */

    $final = [];

    foreach ($rows as $row) {

        $lat = isset($row['latitude']) ? (float)$row['latitude'] : null;
        $lng = isset($row['longitude']) ? (float)$row['longitude'] : null;

        // Only reject invalid GPS
        if (!last_locations_has_valid_coordinates($lat, $lng)) {
            continue;
        }

        $row['latitude']  = $lat;
        $row['longitude'] = $lng;
        $row['accuracy']  = last_locations_nullable_metric($row['accuracy'] ?? null, false, 75);
        $row['speed']     = last_locations_nullable_metric($row['speed'] ?? null, false, 60);
        $row['heading']   = last_locations_heading($row['heading'] ?? null);

        $row['battery'] = is_numeric($row['battery'] ?? null)
            ? max(0, min(100, (int)$row['battery']))
            : null;

        $row['is_charging'] = (bool)($row['is_charging'] ?? false);

        // ISO 8601 format
        $row['updated_at'] = last_locations_iso8601(
            isset($row['updated_at']) ? (string)$row['updated_at'] : null
        );

        $final[] = $row;
    }

    respond_with_json([
        'success' => true,
        'data'    => $final
    ]);

} catch (Throwable $exception) {
    // FSM UPGRADE START
    error_log($exception->getMessage());
    // FSM UPGRADE END

    respond_with_json([
        'success' => false,
        'message' => 'Unable to fetch last locations',
        'error'   => $exception->getMessage()
    ], 500);
}
