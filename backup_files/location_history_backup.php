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

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
$limit = max(10, min($limit, 1000));

$onlyActiveSession = isset($_GET['active_only']) ? (bool)$_GET['active_only'] : false;

$params = [];
$where = ['dj.job_id IS NULL'];

if (in_array($user['role'], ['admin', 'manager'], true)) {
    if ($requestedTechnicianId !== null && $requestedTechnicianId > 0) {
        $where[] = 'l.user_id = ?';
        $params[] = $requestedTechnicianId;
    }
} else {
    $where[] = 'l.user_id = ?';
    $params[] = (int)$user['id'];
}

if ($requestedJobId !== null && $requestedJobId > 0) {
    $where[] = 'l.job_id = ?';
    $params[] = $requestedJobId;
}

if ($onlyActiveSession) {
    $where[] = "s.status = 'active'";
}

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
            l.created_at AS captured_at,
            j.status AS job_status,
            COALESCE(s.status, 'stopped') AS tracking_status
        FROM job_locations l
        INNER JOIN jobs j ON j.id = l.job_id
        LEFT JOIN deleted_jobs dj ON dj.job_id = j.id
        LEFT JOIN job_tracking_sessions s ON s.id = l.session_id
        WHERE {$whereSql}
        ORDER BY l.created_at ASC, l.id ASC
        LIMIT {$limit}
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($history as &$row) {
        $lat = isset($row['latitude']) ? (float)$row['latitude'] : null;
        $lng = isset($row['longitude']) ? (float)$row['longitude'] : null;

        if (
            $lat === null || $lng === null ||
            $lat < -90 || $lat > 90 ||
            $lng < -180 || $lng > 180 ||
            ($lat == 0.0 && $lng == 0.0)
        ) {
            $row['latitude'] = null;
            $row['longitude'] = null;
        } else {
            $row['latitude'] = $lat;
            $row['longitude'] = $lng;
        }

        $row['accuracy'] = isset($row['accuracy']) ? (float)$row['accuracy'] : null;
        $row['speed'] = isset($row['speed']) ? (float)$row['speed'] : null;

        $row['battery'] = is_numeric($row['battery'] ?? null)
            ? max(0, min(100, (int)$row['battery']))
            : null;

        $row['is_charging'] = (bool)($row['is_charging'] ?? false);

        $row['tracking_status'] = $row['tracking_status'] ?? 'stopped';
        $row['job_status'] = $row['job_status'] ?? '';
    }

    respond_with_json([
        'success' => true,
        'count' => count($history),
        'history' => $history,
    ]);

} catch (Throwable $exception) {
    respond_with_json([
        'success' => false,
        'message' => 'Unable to fetch location history',
        'error' => $exception->getMessage(),
    ], 500);
}