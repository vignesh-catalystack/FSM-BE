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

// ROLE FILTER
if (in_array($user['role'], ['admin', 'manager'], true)) {
    if ($requestedTechnicianId !== null && $requestedTechnicianId > 0) {
        $where[] = 'l.user_id = ?';
        $params[] = $requestedTechnicianId;
    }
} else {
    $where[] = 'l.user_id = ?';
    $params[] = (int)$user['id'];
}

// JOB FILTER
if ($requestedJobId !== null && $requestedJobId > 0) {
    $where[] = 'l.job_id = ?';
    $params[] = $requestedJobId;
}

// 🔥 IMPORTANT: only active live data
$where[] = "j.status = 'in_progress'";
$where[] = "s.status = 'active'";

$whereSql = implode(' AND ', $where);

try {
    $sql = "
        SELECT
            l.*,
            j.title AS job_title,
            j.status AS job_status,
            u.name AS technician_name,
            COALESCE(s.status, 'stopped') AS tracking_status
        FROM job_locations l
        INNER JOIN jobs j ON j.id = l.job_id
        LEFT JOIN users u ON u.id = l.user_id
        LEFT JOIN deleted_jobs dj ON dj.job_id = j.id
        LEFT JOIN job_tracking_sessions s ON s.id = l.session_id
        WHERE {$whereSql}
        ORDER BY l.created_at DESC, l.id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $latestMap = [];
    $final = [];

    foreach ($rows as $row) {
        $key = $row['user_id'] . '_' . $row['job_id'];

        if (!isset($latestMap[$key])) {
            $latestMap[$key] = true;

            $row['latitude'] = isset($row['latitude']) ? (float)$row['latitude'] : null;
            $row['longitude'] = isset($row['longitude']) ? (float)$row['longitude'] : null;

            $row['battery'] = is_numeric($row['battery'] ?? null)
                ? max(0, min(100, (int)$row['battery']))
                : null;

            $row['is_charging'] = (bool)($row['is_charging'] ?? false);

            $final[] = $row;
        }
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