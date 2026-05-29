<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/api.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/helpers.php';

enforce_method('GET');

$user = authenticate($pdo);
ensureDeletedJobsTable($pdo);

try {
    if (in_array($user['role'], ['admin', 'manager'], true)) {

$sql = "
    SELECT
        j.id AS job_id,
        j.title AS job_title,
        j.status,
        j.assigned_to AS technician_id,

        u.email AS technician_email,
        COALESCE(NULLIF(SUBSTRING_INDEX(u.email, '@', 1), ''), CONCAT('Technician #', u.id)) AS technician_name,

        COALESCE(s.status, 'stopped') AS tracking_status,

        l.latitude,
        l.longitude,
        l.accuracy,
        l.speed,

        COALESCE(l.battery, u.battery) AS battery,
        COALESCE(l.is_charging, u.is_charging) AS is_charging,

        COALESCE(l.updated_at, s.start_time, j.created_at) AS updated_at

    FROM jobs j

    LEFT JOIN deleted_jobs dj ON dj.job_id = j.id
    LEFT JOIN users u ON u.id = j.assigned_to

    LEFT JOIN job_tracking_sessions s 
    ON s.job_id = j.id

    -- ✅ CORRECT TABLE
    LEFT JOIN technician_last_locations l 
    ON l.job_id = j.id

    WHERE dj.job_id IS NULL
    AND j.status != 'deleted'

    ORDER BY j.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute(); // ⚠️ NO PARAMS NEEDED
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $jobs
    ]);
    exit;
    }
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage()
    ]);
    exit;
}