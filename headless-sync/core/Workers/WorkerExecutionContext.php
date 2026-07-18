<?php

declare(strict_types=1);

namespace HSP\Core\Workers;

/**
 * Immutable execution context passed to a stage strategy during a Processing Engine cycle.
 *
 * Carries the per-cycle processing-component identity and per-cycle metadata strategies need
 * to claim jobs, publish heartbeats, or propagate tracing context — without strategies
 * holding mutable state themselves (ADR-044).
 *
 * Created by WorkerEngine at the start of each cycle (ADR-054 / Doc 8 v2.0 §24); discarded
 * when the cycle exits.
 */
final class WorkerExecutionContext
{
    public function __construct(
        /** Per-cycle UUIDv7 (fresh per cycle — DECISION X ruling (1)); OPEN-3 v1.1 type canon. */
        public readonly string $workerId,
        /** Wall-clock time this cycle was initiated (UTC). */
        public readonly \DateTimeImmutable $tickStartedAt,
    ) {}
}
