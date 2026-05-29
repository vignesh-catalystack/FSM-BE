<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/api.php';
require_once __DIR__ . '/../middleware/auth.php';

enforce_method('GET');

$user = authenticate($pdo);

/*
|--------------------------------------------------------------------------
| INPUT
|--------------------------------------------------------------------------
*/

$jobId = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;

if ($jobId <= 0) {
    respond_with_json([
        'success' => false,
        'message' => 'job_id is required'
    ], 400);
}

/*
|--------------------------------------------------------------------------
| QUERY (NO FILTERING — FULL CONTINUOUS TRACK)
|--------------------------------------------------------------------------
*/

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
    l.created_at AS captured_at
FROM technician_locations l

INNER JOIN (
    SELECT
        id
    FROM job_tracking_sessions
    WHERE job_id = ?
    ORDER BY start_time DESC, id DESC
    LIMIT 1
) s
ON s.id = l.session_id

WHERE l.job_id = ?

ORDER BY l.created_at ASC

LIMIT 2000
";

    $stmt = $pdo->prepare($sql);
$stmt->execute([
    $jobId,
    $jobId
]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /*
    |--------------------------------------------------------------------------
    | NORMALIZATION (ONLY HARD VALIDATION)
    |--------------------------------------------------------------------------
    */

    $final = [];

    foreach ($rows as $row) {

        $lat = isset($row['latitude']) ? (float)$row['latitude'] : null;
        $lng = isset($row['longitude']) ? (float)$row['longitude'] : null;

        // ONLY reject invalid GPS (never filter good data)
        if (
            $lat === null || $lng === null ||
            $lat < -90 || $lat > 90 ||
            $lng < -180 || $lng > 180 ||
            ($lat == 0.0 && $lng == 0.0)
        ) {
            continue;
        }

        $row['latitude']  = $lat;
        $row['longitude'] = $lng;
        $row['accuracy']  = isset($row['accuracy']) ? (float)$row['accuracy'] : null;
        $row['speed']     = isset($row['speed']) ? (float)$row['speed'] : null;
        $row['heading']   = isset($row['heading']) ? (float)$row['heading'] : null;

        // ISO 8601 format
        $row['captured_at'] = gmdate('c', strtotime($row['captured_at']));

        $final[] = $row;
    }

    respond_with_json([
        'success' => true,
        'data' => $final
    ]);

} catch (Throwable $exception) {

    respond_with_json([
        'success' => false,
        'message' => 'Unable to fetch location history',
        'error' => $exception->getMessage()
    ], 500);
}