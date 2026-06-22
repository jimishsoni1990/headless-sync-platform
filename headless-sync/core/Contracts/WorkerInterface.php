<?php

declare(strict_types=1);

namespace HSP\Core\Contracts;

/**
 * Defines the execution contract for all worker strategies.
 *
 * Workers are stateless (ADR-044): they reload current WordPress state on each event.
 * Worker identity: UUIDv7 self-assigned at startup (v1.1 canon).
 *
 * Standard pipeline (Doc 8): Claim → Load → Context → Validate → Resolve → Execute → Commit → Ack
 */
interface WorkerInterface
{
    /**
     * Run one processing cycle: claim a job, execute it, ack or dead-letter.
     *
     * @return bool True if a job was processed, false if the queue was empty
     */
    public function tick(): bool;

    /** Start the worker loop (blocks until shutdown signal). */
    public function run(): void;

    /** Signal a graceful shutdown after the current job completes. */
    public function shutdown(): void;

    /** Returns the UUIDv7 identity assigned to this worker at startup. */
    public function getWorkerId(): string;

    /** Returns the queue partition(s) this worker consumes. */
    public function getQueueNames(): array;
}
