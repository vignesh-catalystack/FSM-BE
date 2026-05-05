<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/api.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/helpers.php';

enforce_method('GET');

$user = authenticate($pdo);
ensure_user_role($user, ['admin', 'manager'], 'Only admin/manager can view dashboard summary');

ensureDeletedJobsTable($pdo);

try {
    $summaryStmt = $pdo->query("
        SELECT
            COUNT(*) AS total_users,
            SUM(role = 'manager') AS total_managers,
            SUM(role = 'technician') AS total_technicians
        FROM users
    ");
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

    $jobsStmt = $pdo->query("
        SELECT
            COUNT(*) AS total_jobs,
            SUM(j.status = 'completed') AS completed_jobs
        FROM jobs j
        LEFT JOIN deleted_jobs dj ON dj.job_id = j.id
        WHERE dj.job_id IS NULL
    ");
    $jobs = $jobsStmt->fetch(PDO::FETCH_ASSOC);

    $activeStmt = $pdo->query("
        SELECT COUNT(*) AS active_sessions
        FROM job_tracking_sessions s
        INNER JOIN jobs j ON j.id = s.job_id
        LEFT JOIN deleted_jobs dj ON dj.job_id = j.id
        WHERE s.status = 'active'
          AND dj.job_id IS NULL
    ");
    $active = $activeStmt->fetch(PDO::FETCH_ASSOC);

    // 🔥 IMPORTANT: CLEAN JSON OUTPUT
    echo json_encode([
        'success' => true,
        'data' => [
            'total_users' => (int)($summary['total_users'] ?? 0),
            'total_managers' => (int)($summary['total_managers'] ?? 0),
            'total_technicians' => (int)($summary['total_technicians'] ?? 0),
            'total_jobs' => (int)($jobs['total_jobs'] ?? 0),
            'completed_jobs' => (int)($jobs['completed_jobs'] ?? 0),
            'active_sessions' => (int)($active['active_sessions'] ?? 0),
        ]
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