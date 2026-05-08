<?php
declare(strict_types=1);

/**
 * Ensure deleted_jobs table exists (safe, idempotent)
 */
function ensureDeletedJobsTable(PDO $pdo): bool
{
    static $ensured = false;

    if ($ensured) return true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS deleted_jobs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            job_id BIGINT NOT NULL,
            title VARCHAR(255),
            assigned_to INT,
            original_status VARCHAR(50),
            deleted_by INT,
            reason VARCHAR(255),
            deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_deleted_jobs_job_id (job_id),
            INDEX idx_deleted_jobs_assigned_to (assigned_to),
            INDEX idx_deleted_jobs_deleted_at (deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $ensured = true;
    return true;
}


/**
 * Soft delete a job (production-grade)
 */
function softDeleteJob(PDO $pdo, int $jobId, int $deletedBy, ?string $reason = null): ?array
{
    if ($jobId <= 0) {
        throw new InvalidArgumentException('Invalid jobId');
    }

    if ($deletedBy <= 0) {
        throw new InvalidArgumentException('Invalid deletedBy');
    }

    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT id, title, assigned_to, status
            FROM jobs
            WHERE id = ?
            FOR UPDATE
        ");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$job) {
            $pdo->rollBack();
            return null;
        }

        $alreadyDeleted = ($job['status'] ?? '') === 'deleted';

        $title = $job['title'] ?? null;
        $assignedTo = isset($job['assigned_to']) ? (int)$job['assigned_to'] : null;
        $originalStatus = $job['status'] ?? null;

        $stmt = $pdo->prepare("
            INSERT INTO deleted_jobs (
                job_id, title, assigned_to, original_status,
                deleted_by, reason, deleted_at
            )
            VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                assigned_to = VALUES(assigned_to),
                original_status = VALUES(original_status),
                deleted_by = VALUES(deleted_by),
                reason = VALUES(reason),
                deleted_at = VALUES(deleted_at)
        ");

        $stmt->execute([
            (int)$job['id'],
            $title,
            $assignedTo,
            $originalStatus,
            $deletedBy,
            $reason
        ]);

        // Stop sessions
        $pdo->prepare("
            UPDATE job_tracking_sessions
            SET status = 'stopped'
            WHERE job_id = ?
        ")->execute([$jobId]);

        if (!$alreadyDeleted) {
            $pdo->prepare("
                UPDATE jobs
                SET status = 'deleted'
                WHERE id = ?
            ")->execute([$jobId]);
        }

        $pdo->commit();

        return $job;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}