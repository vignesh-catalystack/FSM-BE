<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/api.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../jobs/helpers.php';

enforce_method('GET');

$user = authenticate($pdo);
ensureDeletedJobsTable($pdo);

// ─────────────────────────────────────────────
// INPUTS
// ─────────────────────────────────────────────
$requestedTechnicianId = query_int_value('technician_id');
$requestedJobId = query_int_value('job_id');

// ─────────────────────────────────────────────
// QUERY BUILD
// ─────────────────────────────────────────────
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

// LIVE CONDITIONS
$where[] = "j.status = 'in_progress'";
$where[] = "s.status = 'active'";

// 🔥 FRESH WINDOW (aligned with Flutter refresh)
$where[] = "l.created_at >= (NOW() - INTERVAL 40 SECOND)";

$whereSql = implode(' AND ', $where);

// ─────────────────────────────────────────────
// EXECUTION
// ─────────────────────────────────────────────
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
            j.status AS status,
            u.name AS technician_name,
            COALESCE(s.status, 'stopped') AS tracking_status
        FROM job_locations l
        INNER JOIN (
            SELECT user_id, job_id, MAX(id) AS max_id
            FROM job_locations
            GROUP BY user_id, job_id
        ) latest ON latest.max_id = l.id
        INNER JOIN jobs j ON j.id = l.job_id
        LEFT JOIN users u ON u.id = l.user_id
        LEFT JOIN deleted_jobs dj ON dj.job_id = j.id
        LEFT JOIN job_tracking_sessions s ON s.id = l.session_id
        WHERE {$whereSql}
        ORDER BY l.id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ─────────────────────────────────────────────
    // CLEAN + NORMALIZE
    // ─────────────────────────────────────────────
    $final = [];

    foreach ($rows as $row) {

        $lat = isset($row['latitude']) ? (float)$row['latitude'] : null;
        $lng = isset($row['longitude']) ? (float)$row['longitude'] : null;

        // ❌ REMOVE INVALID / GHOST COORDINATES
        if (
            $lat === null || $lng === null ||
            $lat < -90 || $lat > 90 ||
            $lng < -180 || $lng > 180 ||
            ($lat == 0.0 && $lng == 0.0)
        ) {
            continue;
        }

        $row['latitude'] = $lat;
        $row['longitude'] = $lng;

        // NORMALIZATION
        $row['accuracy'] = isset($row['accuracy']) ? (float)$row['accuracy'] : null;
        $row['speed'] = isset($row['speed']) ? (float)$row['speed'] : null;

        $row['battery'] = is_numeric($row['battery'] ?? null)
            ? max(0, min(100, (int)$row['battery']))
            : null;

        $row['is_charging'] = (bool)($row['is_charging'] ?? false);

        // NOTE:
        // ❌ DO NOT ADD "is_live"
        // Flutter handles freshness using updated_at

        $final[] = $row;
    }

    // ─────────────────────────────────────────────
    // RESPONSE
    // ─────────────────────────────────────────────
    respond_with_json([
        'success' => true,
        'count' => count($final),
        'data' => $final,
    ]);

} catch (Throwable $exception) {
    respond_with_json([
        'success' => false,
        'message' => 'Unable to fetch technician live status',
        'error' => $exception->getMessage(),
    ], 500);
}