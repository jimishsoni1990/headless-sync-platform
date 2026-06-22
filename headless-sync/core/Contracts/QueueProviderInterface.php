<?php

declare(strict_types=1);

namespace HSP\Core\Contracts;

/**
 * Abstracts job queueing and claiming over system.queue_jobs.
 *
 * Claiming protocol (OPEN-4): SELECT … FOR UPDATE SKIP LOCKED.
 * Visibility timeout: config-driven; a recovery process requeues jobs whose
 * visibility_timeout_at has expired without completion — OPEN-4.
 * worker_id: UUIDv7 self-assigned at worker startup — v1.1 canon.
 */
interface QueueProviderInterface
{
    /**
     * Enqueue a job for the given event on the named queue partition.
     *
     * @param string $queueName e.g. 'content', 'commerce', 'system'
     */
    public function enqueue(EventInterface $event, string $queueName): string;

    /**
     * Claim the next available job using SELECT … FOR UPDATE SKIP LOCKED (OPEN-4).
     * Sets worker_id and visibility_timeout_at on the claimed row.
     *
     * @return array<string, mixed>|null Null when no job is available
     */
    public function claim(string $queueName, string $workerId): ?array;

    /**
     * Mark a claimed job as completed.
     *
     * The caller must pass the worker_id it received from claim(). The UPDATE is
     * fenced on AND worker_id = $workerId AND status = 'claimed'; if affected_rows
     * is 0 the lease was lost (timeout recovery reassigned it) — the caller must
     * abandon, not throw, because the new owner is responsible for the job.
     *
     * Returns true if the completion was recorded, false if the lease was lost.
     */
    public function complete(string $jobId, string $workerId): bool;

    /**
     * Return a claimed job to available state (e.g. after a transient failure).
     *
     * Fenced on AND worker_id = $workerId AND status = 'claimed'.
     * Returns true if the release was recorded, false if the lease was lost.
     */
    public function release(string $jobId, string $workerId, int $delaySeconds = 0): bool;

    /**
     * Move a terminally-failed job to system.dead_letter_jobs with full failure context.
     *
     * Ownership-fenced on worker_id + status='claimed'. Returns true if the DLQ entry
     * was written, false if the lease was lost (caller must abandon, not retry).
     *
     * @param array<string, mixed> $failureContext keys: failure_reason, stack_trace, attempt_count, payload_snapshot
     */
    public function deadLetter(string $jobId, string $workerId, array $failureContext): bool;

    /**
     * Requeue jobs whose visibility_timeout_at has expired (recovery path — OPEN-4).
     * Returns the count of jobs requeued.
     */
    public function requeueTimedOut(string $queueName): int;
}
