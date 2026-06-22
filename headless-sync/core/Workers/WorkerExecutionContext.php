<?php

declare(strict_types=1);

namespace HSP\Core\Workers;

/**
 * Immutable execution context passed to a WorkerStrategyInterface on each tick.
 *
 * Carries the worker's identity and any per-tick metadata strategies need to
 * claim jobs, publish heartbeats, or propagate tracing context — without
 * strategies holding mutable state themselves (ADR-044).
 *
 * Created by WorkerEngine at the start of each tick; discarded after.
 */
final class WorkerExecutionContext
{
    public function __construct(
        /** UUIDv7 self-assigned at worker startup — OPEN-3 v1.1 canon. */
        public readonly string $workerId,
        /** Wall-clock time this tick was initiated (UTC). */
        public readonly \DateTimeImmutable $tickStartedAt,
    ) {}
}
