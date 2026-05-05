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
                COALESCE(l.created_at, s.start_time, j.created_at) AS updated_at
            FROM jobs j
            LEFT JOIN deleted_jobs dj ON dj.job_id = j.id
            LEFT JOIN users u ON u.id = j.assigned_to
            LEFT JOIN job_tracking_sessions s ON s.id = (
                SELECT s2.id
                FROM job_tracking_sessions s2
                WHERE s2.job_id = j.id
                ORDER BY s2.id DESC
                LIMIT 1
            )
            LEFT JOIN job_locations l ON l.id = (
                SELECT l2.id
                FROM job_locations l2
                WHERE l2.job_id = j.id
                ORDER BY l2.id DESC
                LIMIT 1
            )
            WHERE dj.job_id IS NULL
            ORDER BY j.id DESC
        ";

        $stmt = $pdo->query($sql);
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => $jobs
        ]);
        exit;
    }

    // technician flow
    $stmt = $pdo->prepare("
        SELECT 
            j.id AS job_id,
            j.title AS job_title,
            j.status,
            j.created_at
        FROM jobs j
        LEFT JOIN deleted_jobs dj ON dj.job_id = j.id
        WHERE j.assigned_to = ?
          AND dj.job_id IS NULL
        ORDER BY j.id DESC
    ");

    $stmt->execute([(int)$user['id']]);
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $jobs
    ]);
    exit;

} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage()
    ]);
    exit;
}