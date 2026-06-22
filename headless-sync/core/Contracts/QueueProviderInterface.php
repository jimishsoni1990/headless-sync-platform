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

    /** Mark a claimed job as completed. Clears visibility_timeout_at. */
    public function complete(string $jobId): void;

    /** Return a claimed job to available state (e.g. after a transient failure). */
    public function release(string $jobId, int $delaySeconds = 0): void;

    /**
     * Move a terminally-failed job to system.dead_letter_jobs with full failure context.
     *
     * @param array<string, mixed> $failureContext keys: failure_reason, stack_trace, attempt_count, payload_snapshot
     */
    public function deadLetter(string $jobId, string $workerId, array $failureContext): void;

    /**
     * Requeue jobs whose visibility_timeout_at has expired (recovery path — OPEN-4).
     * Returns the count of jobs requeued.
     */
    public function requeueTimedOut(string $queueName): int;
}
