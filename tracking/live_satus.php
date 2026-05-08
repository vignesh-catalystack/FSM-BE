<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/api.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../jobs/helpers.php';

enforce_method('GET');

$user = authenticate($pdo);
ensureDeletedJobsTable($pdo);

$requestedTechnicianId = query_int_value('technician_id');
$requestedJobId = query_int_value('job_id');

$params = [];
$where = ['dj.job_id IS NULL'];

// ---------- ROLE FILTER ----------
if (in_array($user['role'], ['admin', 'manager'], true)) {
    if ($requestedTechnicianId !== null && $requestedTechnicianId > 0) {
        $where[] = 'l.user_id = ?';
        $params[] = $requestedTechnicianId;
    }
} else {
    $where[] = 'l.user_id = ?';
    $params[] = (int)$user['id'];
}

// ---------- JOB FILTER ----------
if ($requestedJobId !== null && $requestedJobId > 0) {
    $where[] = 'l.job_id = ?';
    $params[] = $requestedJobId;
}

// ---------- JOB STATUS ----------
$where[] = "j.status != 'deleted'";

$whereSql = implode(' AND ', $where);

try {

$sql = "
SELECT
    l.id,
    l.job_id,
    l.user_id AS technician_id,
    l.latitude,
    l.longitude,
    l.accuracy,
    l.speed,
    l.battery,
    l.is_charging,
    l.created_at AS updated_at,

    j.title AS job_title,
    j.status AS job_status,
    u.name AS technician_name,
    COALESCE(s.status, 'stopped') AS tracking_status,

    CASE 
        WHEN l.created_at >= (UTC_TIMESTAMP() - INTERVAL 30 SECOND)
             AND j.status = 'in_progress'
             AND s.status = 'active'
        THEN 1 ELSE 0
    END AS is_live

FROM job_locations l

INNER JOIN (
    SELECT user_id, job_id, MAX(created_at) AS max_time
    FROM job_locations
    GROUP BY user_id, job_id
) latest 
    ON latest.user_id = l.user_id
    AND latest.job_id = l.job_id
    AND latest.max_time = l.created_at

INNER JOIN jobs j ON j.id = l.job_id
LEFT JOIN users u ON u.id = l.user_id
LEFT JOIN deleted_jobs dj ON dj.job_id = j.id
LEFT JOIN job_tracking_sessions s ON s.id = l.session_id

WHERE {$whereSql}
AND (l.accuracy IS NULL OR l.accuracy <= 25)
";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $final = [];
    $unique = [];

    foreach ($rows as $row) {

        $lat = isset($row['latitude']) ? (float)$row['latitude'] : null;
        $lng = isset($row['longitude']) ? (float)$row['longitude'] : null;

        // ---------- HARD GPS VALIDATION ----------
        if (
            $lat === null || $lng === null ||
            $lat < -90 || $lat > 90 ||
            $lng < -180 || $lng > 180 ||
            ($lat == 0.0 && $lng == 0.0)
        ) {
            continue;
        }

        $key = $row['technician_id'] . '_' . $row['job_id'];

        // ---------- DEDUPE ----------
        if (isset($unique[$key])) {
            continue;
        }
        $unique[$key] = true;

        // ---------- NORMALIZATION ----------
        $row['latitude'] = $lat;
        $row['longitude'] = $lng;
        $row['accuracy'] = isset($row['accuracy']) ? (float)$row['accuracy'] : null;
        $row['speed'] = isset($row['speed']) ? (float)$row['speed'] : null;

        $row['battery'] = is_numeric($row['battery'] ?? null)
            ? max(0, min(100, (int)$row['battery']))
            : null;

        $row['is_charging'] = (bool)($row['is_charging'] ?? false);
        $row['is_live'] = (bool)($row['is_live'] ?? false);

        // ISO 8601 (UTC)
        $row['updated_at'] = gmdate('c', strtotime($row['updated_at']));

        $final[] = $row;
    }

    respond_with_json([
        'success' => true,
        'data' => $final,
    ]);

} catch (Throwable $exception) {
    respond_with_json([
        'success' => false,
        'message' => 'Unable to fetch technician live status',
        'error' => $exception->getMessage(),
    ], 500);
}