<?php

function ensureDeletedJobsTable($pdo) {
    static $ensured = false;

    if ($ensured) {
        return true;
    }

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS deleted_jobs (\n            id BIGINT AUTO_INCREMENT PRIMARY KEY,\n            job_id BIGINT NOT NULL,\n            title VARCHAR(255) NULL,\n            assigned_to INT NULL,\n            original_status VARCHAR(50) NULL,\n            deleted_by INT NULL,\n            reason VARCHAR(255) NULL,\n            deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            UNIQUE KEY uq_deleted_jobs_job_id (job_id),\n            INDEX idx_deleted_jobs_assigned_to (assigned_to),\n            INDEX idx_deleted_jobs_deleted_at (deleted_at)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n    ");

    $ensured = true;
    return true;
}

function softDeleteJob($pdo, $jobId, $deletedBy, $reason = null) {
    ensureDeletedJobsTable($pdo);

    $stmt = $pdo->prepare("\n        SELECT id, title, assigned_to, status\n        FROM jobs\n        WHERE id = ?\n        LIMIT 1\n    ");
    $stmt->execute([(int)$jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        return null;
    }

    $insert = $pdo->prepare("\n        INSERT INTO deleted_jobs (job_id, title, assigned_to, original_status, deleted_by, reason, deleted_at)\n        VALUES (?, ?, ?, ?, ?, ?, NOW())\n        ON DUPLICATE KEY UPDATE\n            title = VALUES(title),\n            assigned_to = VALUES(assigned_to),\n            original_status = VALUES(original_status),\n            deleted_by = VALUES(deleted_by),\n            reason = VALUES(reason),\n            deleted_at = VALUES(deleted_at)\n    ");

    $insert->execute([
        (int)$job['id'],
        $job['title'],
        isset($job['assigned_to']) ? (int)$job['assigned_to'] : null,
        $job['status'],
        (int)$deletedBy,
        $reason !== null ? (string)$reason : null,
    ]);

    return $job;
}