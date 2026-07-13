<?php

declare(strict_types=1);

namespace HSP\Core\Workers\Strategies;

use HSP\Core\Reconciliation\ReconciliationResult;
use HSP\Core\Reconciliation\ReconciliationService;
use HSP\Core\Workers\WorkerExecutionContext;
use HSP\Core\Workers\WorkerStrategyInterface;

/**
 * Detects and repairs drift between WordPress (source of truth) and the content.*
 * PostgreSQL delivery projections (DECISION U v1.19).
 *
 * Repair is EXCLUSIVELY re-emission through the DECISION T primitive (ReconciliationService
 * → ReplayService::replayEntity → outbox → relay → dispatch → worker). No direct PG
 * projection writes; WordPress-wins holds by construction (ADR-026/027/045, CLAUDE.md Rule 1).
 *
 * Façade shape mirrors ReplayWorkerStrategy (DECISION U D5 / executor = B1): the three
 * mode methods are invoked by the WP-CLI `hsp reconcile …` surface and the WP-Cron triggers;
 * they run on the worker-bootstrapped process, NOT by claiming a `system`-queue job.
 *
 * execute() is a deliberate producer-side no-op (returns false): reconciliation is a
 * triggered producer-side operation, so there is no `system`-queue job for this strategy to
 * consume. If ever launched under a WorkerEngine it idles cleanly (no claim, no I/O, no
 * exception → 'idle' heartbeat + engine idle back-off).
 */
final class ReconciliationWorkerStrategy implements WorkerStrategyInterface
{
    public function __construct(
        private readonly ReconciliationService $reconciliation,
    ) {}

    /** Hourly drift detection — timestamp/existence, WP→PG (DECISION U D1). */
    public function reconcileDrift(bool $dryRun = false): ReconciliationResult
    {
        return $this->reconciliation->reconcile(ReconciliationService::MODE_DRIFT, $dryRun);
    }

    /** Nightly incremental validation — window + checksum recompute, WP→PG (DECISION U D1). */
    public function reconcileIncremental(bool $dryRun = false): ReconciliationResult
    {
        return $this->reconciliation->reconcile(ReconciliationService::MODE_INCREMENTAL, $dryRun);
    }

    /** Weekly full reconciliation — whole corpus + checksum + orphan sweep (DECISION U D1/D3). */
    public function reconcileFull(bool $dryRun = false): ReconciliationResult
    {
        return $this->reconciliation->reconcile(ReconciliationService::MODE_FULL, $dryRun);
    }

    /**
     * Intentional no-op (DECISION U D5 — executor B1). Reconciliation is triggered by
     * WP-CLI / WP-Cron, not by claiming a `system`-queue job. Idles cleanly if run under a
     * WorkerEngine: no claim, no I/O, no exception; returns false so the engine publishes an
     * 'idle' heartbeat and applies its idle back-off.
     */
    public function execute(WorkerExecutionContext $context): bool
    {
        return false;
    }

    /** @return string[] */
    public function getQueueNames(): array
    {
        return ['system'];
    }
}
