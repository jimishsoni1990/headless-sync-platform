<?php

declare(strict_types=1);

namespace HSP\Core\Workers;

/**
 * Immutable description of one completed Processing Engine cycle (ADR-054 / Doc 8 v2.0 §9).
 *
 * Returned by WorkerInterface::runCycle() (DECISION X, v1.24 — ruling (3)). Carries the
 * per-cycle identity (fresh UUIDv7 — ruling (1)), the per-stage batch counts, whether the
 * execution-time budget stopped the cycle mid-backlog, and the wall-clock duration. This is
 * the inspectable outcome the cron trigger, tests, and derived-metrics surface read; it holds
 * no infrastructure handle and is plain-JSON friendly (DECISION Q — no persistence).
 *
 * "didWork" is true if any stage advanced at least one row this cycle; a cycle that found an
 * empty pipeline did no work (its heartbeat status is 'idle', §16).
 */
final class ProcessingCycleResult
{
    public function __construct(
        /** The fresh per-cycle UUIDv7 processing-component identity (Doc 8 v2.0 §24). */
        public readonly string $workerId,
        /** Rows relayed wp_hsp_outbox → system.events this cycle. */
        public readonly int $relayed,
        /** Rows dispatched system.events → system.queue_jobs this cycle. */
        public readonly int $dispatched,
        /** Jobs projected (claimed + processed via EventWorkerStrategy) this cycle. */
        public readonly int $projected,
        /** True if the maintenance (visibility-timeout requeue) sweep ran this cycle. */
        public readonly bool $maintenanceSwept,
        /** True if the execution-time budget stopped the cycle before the pipeline drained. */
        public readonly bool $budgetExhausted,
        /** Wall-clock seconds the cycle ran. */
        public readonly float $elapsedSeconds,
    ) {}

    /** True if any stage advanced work this cycle (drives the 'running' vs 'idle' heartbeat). */
    public function didWork(): bool
    {
        return $this->relayed > 0 || $this->dispatched > 0 || $this->projected > 0;
    }

    /** @return array<string,mixed> Plain JSON-friendly snapshot (DECISION Q structured-log surface). */
    public function toArray(): array
    {
        return [
            'worker_id'         => $this->workerId,
            'relayed'           => $this->relayed,
            'dispatched'        => $this->dispatched,
            'projected'         => $this->projected,
            'maintenance_swept' => $this->maintenanceSwept,
            'budget_exhausted'  => $this->budgetExhausted,
            'elapsed_seconds'   => $this->elapsedSeconds,
            'did_work'          => $this->didWork(),
        ];
    }
}
