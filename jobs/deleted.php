<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/api.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/helpers.php';

enforce_method('GET');

$user = authenticate($pdo);
ensure_user_role($user, ['admin', 'manager'], 'Only admin/manager can view deleted jobs');

ensureDeletedJobsTable($pdo);

try {
    $sql = "
        SELECT
            dj.job_id,
            COALESCE(NULLIF(j.title, ''), NULLIF(dj.title, ''), CONCAT('Job #', dj.job_id)) AS job_title,
            dj.assigned_to AS technician_id,
            COALESCE(
                NULLIF(SUBSTRING_INDEX(u.email, '@', 1), ''),
                NULLIF(u.email, ''),
                '-'
            ) AS technician_name,
            dj.deleted_at,
            COALESCE(NULLIF(j.status, ''), NULLIF(dj.original_status, ''), 'deleted') AS status,
            dj.reason,
            dj.deleted_by
        FROM deleted_jobs dj
        LEFT JOIN jobs j ON j.id = dj.job_id
        LEFT JOIN users u ON u.id = dj.assigned_to
        ORDER BY dj.deleted_at DESC, dj.id DESC
    ";

    $stmt = $pdo->query($sql);
    respond_with_json([
        'success' => true,
        'jobs' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
} catch (Throwable $exception) {
    respond_with_json([
        'success' => false,
        'message' => 'Unable to fetch deleted jobs',
        'error' => $exception->getMessage(),
    ], 500);
}