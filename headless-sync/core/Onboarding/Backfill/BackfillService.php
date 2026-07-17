<?php

declare(strict_types=1);

namespace HSP\Core\Onboarding\Backfill;

use HSP\Core\Reconciliation\ReconciliationResult;
use HSP\Core\Reconciliation\ReconciliationService;

/**
 * First-run content backfill — a THIN DELEGATOR to ReconciliationService (ONB-S2; DECISION W (b)).
 *
 * The initial population of the delivery projections from existing WordPress content is a
 * full reconciliation ({@see ReconciliationService::reconcileFull()}, DECISION U) that re-emits
 * every in-scope aggregate through the normal outbox → relay → dispatch → worker pipeline via the
 * DECISION T primitive. There is NO direct WP→PG copy path and NO second repair path (DECISION W
 * (b) / DECISION U point 2). This class holds ONLY the gate + the reconciliation service — it
 * reaches no DatabaseConnectionInterface / adapter / projection writer, so the write-spy proof
 * (zero direct `content.*` / `system.*` writes on the backfill path) holds BY CONSTRUCTION: no
 * write primitive is reachable from here. Projections are written only by the worker pipeline, as
 * as for organic edits. WordPress-wins holds by construction (DECISION U point 3).
 *
 * A live worker heartbeat + applied migrations are HARD PREREQUISITES ({@see BackfillGate}): if the
 * gate is not ready, start() refuses without emitting anything (there is no in-request tick drain
 * — DECISION W (c); the wp-admin request only enqueues via re-emission and then reports derived
 * progress). Completion (flip hsp_onboarding_state → complete) is NOT done here — it is a separate
 * guarded transition driven by the progress poll once convergence is reached (DECISION W (d)),
 * because the re-emitted events drain asynchronously on the worker, not inline.
 */
final class BackfillService
{
    public function __construct(
        private readonly BackfillGate $gate,
        private readonly ReconciliationService $reconciliation,
    ) {}

    /**
     * Trigger the backfill: re-emit every in-scope aggregate via reconcileFull().
     *
     * @throws BackfillBlockedException when a hard prerequisite (live worker / applied migrations)
     *         is unmet — the caller surfaces the gate summary as remediation, never starts backfill.
     */
    public function start(): ReconciliationResult
    {
        if (! $this->gate->isReady()) {
            throw new BackfillBlockedException($this->gate->summary());
        }

        // Full reconciliation = re-emit the whole in-scope corpus to current WP state. The worker
        // pipeline projects it; this call writes no projection itself.
        return $this->reconciliation->reconcile(ReconciliationService::MODE_FULL);
    }

    /**
     * The current backfill-gate readiness summary (worker heartbeat + applied migrations), for the
     * progress/status surface. Read-only; triggers nothing.
     *
     * @return array{ready:bool,gates:list<array{key:string,label:string,passed:bool,detail:string,remediation:string}>}
     */
    public function gateSummary(): array
    {
        return $this->gate->summary();
    }

    /** True when both hard prerequisites pass (live worker heartbeat + applied migrations). */
    public function isReady(): bool
    {
        return $this->gate->isReady();
    }
}
