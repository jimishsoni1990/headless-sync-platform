<?php

declare(strict_types=1);

namespace HSP\Core\Queue\Providers\Database;

use HSP\Core\Contracts\EventInterface;
use HSP\Core\Contracts\QueueProviderInterface;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Queue\Exception\QueueException;

/**
 * PostgreSQL-backed queue provider.
 *
 * Authority:
 *   OPEN-4 v1.1  — SELECT … FOR UPDATE SKIP LOCKED; worker_id UUID;
 *                  visibility_timeout_at TIMESTAMPTZ; config-driven timeout
 *   OPEN-3 v1.1  — dead_letter_jobs schema
 *   DECISION A   — payload_snapshot NOT NULL; fallback to {"raw":"…"} if unparseable
 *   ADR-022      — default retry limit 10; exponential backoff + jitter on scheduling
 *   ADR-023      — DB queue is the primary claim target (not outbox directly)
 *
 * Partitions: 'content', 'commerce', 'system' (validated on enqueue/claim).
 *
 * Job lifecycle:
 *   available → claimed (claim) → completed (complete)
 *                              → available (release — retry with backoff)
 *                              → dead_lettered (deadLetter)
 *
 * Backoff formula (ADR-022): base * 2^(attempt-1) + jitter
 *   base    = 30 s (config: queue.backoff_base_seconds)
 *   cap     = 3 600 s / 1 h (config: queue.backoff_cap_seconds)
 *   jitter  = random(0, min(cap, computed) * 0.25)
 *
 * Visibility-timeout race safety:
 *   requeueTimedOut() uses WHERE status='claimed' AND visibility_timeout_at < NOW()
 *   so two concurrent callers UPDATE disjoint or already-'available' rows — idempotent.
 *
 * Cross-partition safety:
 *   All claim/requeue queries filter on queue_name so workers on different partitions
 *   never touch each other's rows.
 */
final class DatabaseQueueProvider implements QueueProviderInterface
{
    private const VALID_PARTITIONS = ['content', 'commerce', 'system'];

    private const DEFAULT_RETRY_LIMIT          = 10;
    private const DEFAULT_VISIBILITY_TIMEOUT_S = 300;  // 5 minutes
    private const DEFAULT_BACKOFF_BASE_S       = 30;
    private const DEFAULT_BACKOFF_CAP_S        = 3600; // 1 hour

    private int $retryLimit;
    private int $visibilityTimeoutSeconds;
    private int $backoffBaseSeconds;
    private int $backoffCapSeconds;

    /**
     * @param array<string, mixed> $config  keys: retry_limit, visibility_timeout_seconds,
     *                                            backoff_base_seconds, backoff_cap_seconds
     */
    public function __construct(
        private readonly DatabaseConnectionInterface $conn,
        array $config = [],
    ) {
        $this->retryLimit               = (int) ($config['retry_limit']               ?? self::DEFAULT_RETRY_LIMIT);
        $this->visibilityTimeoutSeconds = (int) ($config['visibility_timeout_seconds'] ?? self::DEFAULT_VISIBILITY_TIMEOUT_S);
        $this->backoffBaseSeconds       = (int) ($config['backoff_base_seconds']       ?? self::DEFAULT_BACKOFF_BASE_S);
        $this->backoffCapSeconds        = (int) ($config['backoff_cap_seconds']        ?? self::DEFAULT_BACKOFF_CAP_S);
    }

    // -------------------------------------------------------------------------
    // QueueProviderInterface
    // -------------------------------------------------------------------------

    /**
     * Enqueue a job for the given event on the named queue partition.
     *
     * Returns the new job UUID.
     */
    public function enqueue(EventInterface $event, string $queueName): string
    {
        $this->assertValidPartition($queueName);

        $jobId = $this->uuidv7();

        $this->conn->execute(
            "INSERT INTO system.queue_jobs
                 (id, event_id, queue_name, status, attempts, available_at)
             VALUES ($1::uuid, $2::uuid, $3, 'available', 0, NOW())",
            [$jobId, $event->getId(), $queueName],
        );

        return $jobId;
    }

    /**
     * Claim the next available job using SELECT … FOR UPDATE SKIP LOCKED (OPEN-4).
     *
     * Sets worker_id and visibility_timeout_at on the claimed row.
     * All three operations (SELECT, UPDATE, COMMIT) execute in a single PG transaction
     * so the lock acquired by SKIP LOCKED is held until the claim is recorded.
     *
     * Returns null when the partition queue is empty or all rows are locked.
     *
     * @return array<string, mixed>|null
     */
    public function claim(string $queueName, string $workerId): ?array
    {
        $this->assertValidPartition($queueName);

        $this->conn->beginTransaction();

        try {
            $rows = $this->conn->query(
                "SELECT id, event_id, queue_name, status, attempts,
                        available_at, started_at, completed_at, last_error,
                        worker_id, visibility_timeout_at
                 FROM   system.queue_jobs
                 WHERE  queue_name = $1
                   AND  status     = 'available'
                   AND  available_at <= NOW()
                 ORDER BY available_at ASC
                 LIMIT  1
                 FOR UPDATE SKIP LOCKED",
                [$queueName],
            );

            if (empty($rows)) {
                $this->conn->rollback();
                return null;
            }

            $row     = $rows[0];
            $timeout = $this->visibilityTimeoutSeconds;

            $this->conn->execute(
                "UPDATE system.queue_jobs
                 SET    status                = 'claimed',
                        worker_id             = $1::uuid,
                        visibility_timeout_at = NOW() + ($2 * INTERVAL '1 second'),
                        started_at            = NOW(),
                        attempts              = attempts + 1
                 WHERE  id = $3::uuid",
                [$workerId, (string) $timeout, $row['id']],
            );

            $this->conn->commit();

        } catch (\Throwable $e) {
            $this->conn->rollback();
            throw new QueueException('Claim failed: ' . $e->getMessage(), previous: $e);
        }

        // Return the row as it was selected (pre-update); caller gets the job data.
        // Attempt count reflects the *new* value after increment.
        $row['attempts']   = (int) $row['attempts'] + 1;
        $row['status']     = 'claimed';
        $row['worker_id']  = $workerId;

        return $row;
    }

    /**
     * Mark a claimed job as completed.
     *
     * Fenced on AND worker_id = $workerId AND status = 'claimed' (ownership guard).
     * If affected_rows == 0 the lease was lost to timeout recovery — returns false
     * so the caller can abandon cleanly. The new owner is now responsible; throwing
     * here would be incorrect.
     */
    public function complete(string $jobId, string $workerId): bool
    {
        $affected = $this->conn->execute(
            "UPDATE system.queue_jobs
             SET    status                = 'completed',
                    completed_at          = NOW(),
                    visibility_timeout_at = NULL,
                    worker_id             = NULL
             WHERE  id        = $1::uuid
               AND  worker_id = $2::uuid
               AND  status    = 'claimed'",
            [$jobId, $workerId],
        );

        return $affected === 1;
    }

    /**
     * Return a claimed job to 'available' state for retry.
     *
     * Fenced on AND worker_id = $workerId AND status = 'claimed'.
     * Returns false (abandon) if the lease was lost; true if recorded.
     *
     * $delaySeconds: computed by the caller (e.g. computeBackoffSeconds()).
     */
    public function release(string $jobId, string $workerId, int $delaySeconds = 0): bool
    {
        $affected = $this->conn->execute(
            "UPDATE system.queue_jobs
             SET    status                = 'available',
                    available_at          = NOW() + ($1 * INTERVAL '1 second'),
                    visibility_timeout_at = NULL,
                    worker_id             = NULL
             WHERE  id        = $2::uuid
               AND  worker_id = $3::uuid
               AND  status    = 'claimed'",
            [(string) $delaySeconds, $jobId, $workerId],
        );

        return $affected === 1;
    }

    /**
     * Move a terminally-failed job to system.dead_letter_jobs.
     *
     * $failureContext keys:
     *   failure_reason  string  (required)
     *   stack_trace     string  (optional)
     *   attempt_count   int     (required)
     *   payload_snapshot mixed  (required; DECISION A: must not be NULL)
     *
     * payload_snapshot coercion (DECISION A):
     *   - If already a valid JSON string → store as-is.
     *   - If array/object → json_encode.
     *   - If unparseable → wrap as {"raw":"<escaped>"}.
     *   - Never NULL.
     *
     * Ownership-fenced: the queue UPDATE is guarded on AND worker_id = $workerId
     * AND status = 'claimed'. If affected_rows == 0 the lease was lost — returns
     * false and the transaction is rolled back (no DLQ entry written for a job
     * this worker no longer owns).
     *
     * The event_id lookup is inside the transaction so it is consistent with
     * the ownership check.
     */
    public function deadLetter(string $jobId, string $workerId, array $failureContext): bool
    {
        $failureReason = (string) ($failureContext['failure_reason'] ?? 'unknown');
        $stackTrace    = isset($failureContext['stack_trace']) ? (string) $failureContext['stack_trace'] : null;
        $attemptCount  = (int) ($failureContext['attempt_count'] ?? 0);
        $payloadJson   = $this->coercePayloadSnapshot($failureContext['payload_snapshot'] ?? null);
        $dlqId         = $this->uuidv7();

        $this->conn->beginTransaction();

        try {
            // Ownership-fenced queue UPDATE first. If the lease was lost, the
            // INSERT must not happen — roll back and abandon.
            $affected = $this->conn->execute(
                "UPDATE system.queue_jobs
                 SET    status     = 'dead_lettered',
                        last_error = $1,
                        worker_id  = $2::uuid
                 WHERE  id        = $3::uuid
                   AND  worker_id = $2::uuid
                   AND  status    = 'claimed'",
                [$failureReason, $workerId, $jobId],
            );

            if ($affected === 0) {
                $this->conn->rollback();
                return false;
            }

            // Fetch event_id inside the same transaction for consistency.
            $rows = $this->conn->query(
                "SELECT event_id FROM system.queue_jobs WHERE id = $1::uuid",
                [$jobId],
            );

            if (empty($rows)) {
                // Should never happen — the UPDATE above found the row — but guard anyway.
                $this->conn->rollback();
                throw new QueueException("deadLetter(): job {$jobId} vanished after ownership check.");
            }

            $this->conn->execute(
                "INSERT INTO system.dead_letter_jobs
                     (id, job_id, event_id, failure_reason, created_at,
                      stack_trace, attempt_count, worker_id, payload_snapshot)
                 VALUES ($1::uuid, $2::uuid, $3::uuid, $4, NOW(),
                         $5, $6::integer, $7::uuid, $8::jsonb)",
                [
                    $dlqId,
                    $jobId,
                    $rows[0]['event_id'],
                    $failureReason,
                    $stackTrace,
                    (string) $attemptCount,
                    $workerId,
                    $payloadJson,
                ],
            );

            $this->conn->commit();

        } catch (\Throwable $e) {
            $this->conn->rollback();
            throw new QueueException('deadLetter() failed: ' . $e->getMessage(), previous: $e);
        }

        return true;
    }

    /**
     * Requeue jobs whose visibility_timeout_at has expired without completion.
     *
     * Uses WHERE status='claimed' AND visibility_timeout_at < NOW() so concurrent
     * callers cannot double-requeue the same row — only one UPDATE matches a still-claimed
     * expired row.  Returns the count of rows requeued.
     *
     * partition filter is required: a worker for 'content' must not revive 'commerce' jobs.
     */
    public function requeueTimedOut(string $queueName): int
    {
        $this->assertValidPartition($queueName);

        return $this->conn->execute(
            "UPDATE system.queue_jobs
             SET    status                = 'available',
                    available_at          = NOW(),
                    visibility_timeout_at = NULL,
                    worker_id             = NULL
             WHERE  queue_name            = $1
               AND  status               = 'claimed'
               AND  visibility_timeout_at < NOW()",
            [$queueName],
        );
    }

    // -------------------------------------------------------------------------
    // Retry / backoff helpers (public so worker engine and tests can call them)
    // -------------------------------------------------------------------------

    /**
     * Compute exponential backoff delay in seconds for a given attempt number (ADR-022).
     *
     * Formula: min(cap, base * 2^(attempt-1)) + jitter(0..25% of that value)
     *
     * attempt=1 → base * 1 + jitter
     * attempt=2 → base * 2 + jitter
     * attempt=3 → base * 4 + jitter
     * …
     */
    public function computeBackoffSeconds(int $attempt): int
    {
        if ($attempt <= 0) {
            return 0;
        }

        $raw      = $this->backoffBaseSeconds * (2 ** ($attempt - 1));
        $capped   = min($this->backoffCapSeconds, $raw);
        $jitter   = (int) random_int(0, (int) ($capped * 0.25));

        return $capped + $jitter;
    }

    public function getRetryLimit(): int
    {
        return $this->retryLimit;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function assertValidPartition(string $queueName): void
    {
        if (! in_array($queueName, self::VALID_PARTITIONS, true)) {
            throw new QueueException(
                "Invalid queue partition '{$queueName}'. Valid: "
                . implode(', ', self::VALID_PARTITIONS)
            );
        }
    }

    /**
     * Coerce an arbitrary value to a JSONB-safe string — DECISION A.
     *
     * Never returns null; falls back to {"raw":"<escaped>"}.
     */
    private function coercePayloadSnapshot(mixed $value): string
    {
        if (is_string($value)) {
            // Test if already valid JSON.
            json_decode($value);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $value;
            }
            // Not valid JSON — wrap it.
            return json_encode(['raw' => $value], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        if (is_array($value) || is_object($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            return $encoded;
        }

        if ($value === null) {
            // Explicit null: wrap as {"raw":"null"} to satisfy NOT NULL column (DECISION A).
            return '{"raw":"null"}';
        }

        // scalar (int, float, bool) — wrap in raw key for JSONB object constraint.
        return json_encode(['raw' => (string) $value], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Generate a UUIDv7 for job and DLQ row identity (ADR-015, v1.1 canon).
     */
    private function uuidv7(): string
    {
        $ms    = (int) (microtime(true) * 1000);
        $bytes = random_bytes(10);

        $tsHex   = sprintf('%012x', $ms);
        $rand12  = (ord($bytes[0]) & 0x0f) << 8 | ord($bytes[1]);
        $b67hex  = sprintf('%04x', 0x7000 | $rand12);
        $rand14  = (ord($bytes[2]) & 0x3f) << 8 | ord($bytes[3]);
        $b89hex  = sprintf('%04x', 0x8000 | $rand14);
        $tailHex = bin2hex(substr($bytes, 4, 6));

        $hex = $tsHex . $b67hex . $b89hex . $tailHex;

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hex, 4));
    }
}
