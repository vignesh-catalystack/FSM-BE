<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/api.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/helpers.php';
$user = authenticate($pdo);
ensureDeletedJobsTable($pdo);

$stmt = $pdo->prepare(
    "
    SELECT j.id, j.title, j.status, j.created_at
    FROM jobs j
    LEFT JOIN deleted_jobs dj ON dj.job_id = j.id
    WHERE j.assigned_to = ?
      AND dj.job_id IS NULL
    ORDER BY j.id DESC
"
);
$stmt->execute([(int)$user['id']]);

respond_with_json($stmt->fetchAll(PDO::FETCH_ASSOC));