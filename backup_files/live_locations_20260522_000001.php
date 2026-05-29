<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/api.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../jobs/helpers.php';

enforce_method('GET');

$user = authenticate($pdo);
ensureDeletedJobsTable($pdo);

$requestedTechnicianId = query_int_value('technician_id');
$requestedJobId        = query_int_value('job_id');

$params = [];
$where  = ['dj.job_id IS NULL'];

/*
|--------------------------------------------------------------------------
| ROLE FILTER
|--------------------------------------------------------------------------
*/
if (in_array($user['role'], ['admin', 'manager'], true)) {
    if ($requestedTechnicianId !== null && $requestedTechnicianId > 0) {
        $where[] = 't.technician_id = ?';
        $params[] = $requestedTechnicianId;
    }
} else {
    $where[] = 't.technician_id = ?';
    $params[] = (int)$user['id'];
}

/*
|--------------------------------------------------------------------------
| JOB FILTER
|--------------------------------------------------------------------------
*/
if ($requestedJobId !== null && $requestedJobId > 0) {
    $where[] = 't.job_id = ?';
    $params[] = $requestedJobId;
}

/*
|--------------------------------------------------------------------------
| JOB STATUS
|--------------------------------------------------------------------------
*/
$where[] = "j.status != 'deleted'";

$whereSql = implode(' AND ', $where);

/*
|--------------------------------------------------------------------------
| CORE QUERY (ANTI-JOIN — FAST + REAL-TIME)
|--------------------------------------------------------------------------
*/
try {

$sql = "
SELECT
    t.technician_id,
    t.job_id,
    t.session_id,

    t.latitude,
    t.longitude,
    t.accuracy,
    t.speed,
    t.heading,
    t.battery,
    t.is_charging,
    t.updated_at,

    u.name AS technician_name,

    j.title AS job_title,
    j.status AS job_status,

    COALESCE(s.status, 'stopped') AS tracking_status

FROM technician_last_locations t

LEFT JOIN users u
    ON u.id = t.technician_id

LEFT JOIN jobs j
    ON j.id = t.job_id

LEFT JOIN job_tracking_sessions s
    ON s.id = t.session_id

WHERE {$whereSql}
AND s.status = 'active'
AND t.updated_at >= (NOW() - INTERVAL 5 MINUTE)

ORDER BY t.updated_at DESC
";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /*
    |--------------------------------------------------------------------------
    | NORMALIZATION (NO DEDUPE, NO FILTERING)
    |--------------------------------------------------------------------------
    */

    $final = [];

    foreach ($rows as $row) {

        $lat = isset($row['latitude']) ? (float)$row['latitude'] : null;
        $lng = isset($row['longitude']) ? (float)$row['longitude'] : null;

        // Only hard invalid rejection
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

        $row['battery'] = is_numeric($row['battery'] ?? null)
            ? max(0, min(100, (int)$row['battery']))
            : null;

        $row['is_charging'] = (bool)($row['is_charging'] ?? false);

        // ISO 8601
        $row['updated_at'] = gmdate('c', strtotime($row['updated_at']));

        $final[] = $row;
    }

    respond_with_json([
        'success' => true,
        'data'    => $final,
    ]);

} catch (Throwable $exception) {
    respond_with_json([
        'success' => false,
        'message' => 'Unable to fetch technician live status',
        error_log($exception->getMessage());
    ], 500);
}