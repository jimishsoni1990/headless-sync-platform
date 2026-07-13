<?php

declare(strict_types=1);

namespace HSP\Core\Cli;

use HSP\Core\Observability\StructuredLogger;
use HSP\Core\Reconciliation\ReconciliationResult;
use HSP\Core\Reconciliation\ReconciliationService;
use HSP\Core\Workers\Strategies\ReconciliationWorkerStrategy;

/**
 * WP-CLI surface for reconciliation: `hsp reconcile drift|incremental|full [--dry-run]`
 * (DECISION U v1.19). WP-CLI is the only operational surface (consistent with DECISION S/T:
 * no admin UI, sidestepping the TBD WPCS decision at the WP admin boundary).
 *
 * Design (ADR-012, minimal WP coupling): depends only on ReconciliationWorkerStrategy (which
 * owns the ReconciliationService delegation) and a StructuredLogger. Each subcommand returns
 * a plain ReconciliationResult; the WP-CLI shim formats it. Testable without a WP-CLI runtime.
 *
 * On a non-dry-run pass the `reconcile` runtime counter is emitted as a structured log event
 * (DECISION Q clause 2), count = number of aggregates repaired via re-emission.
 */
final class ReconcileCommand
{
    public function __construct(
        private readonly ReconciliationWorkerStrategy $strategy,
        private readonly StructuredLogger             $logger,
    ) {}

    /**
     * Run a reconciliation pass for the given mode.
     *
     * @param string $mode   'drift' | 'incremental' | 'full'
     * @param bool   $dryRun Detect and report only; do not re-emit.
     *
     * @throws \InvalidArgumentException on unknown mode.
     */
    public function run(string $mode, bool $dryRun = false): ReconciliationResult
    {
        $result = match ($mode) {
            ReconciliationService::MODE_DRIFT       => $this->strategy->reconcileDrift($dryRun),
            ReconciliationService::MODE_INCREMENTAL => $this->strategy->reconcileIncremental($dryRun),
            ReconciliationService::MODE_FULL        => $this->strategy->reconcileFull($dryRun),
            default => throw new \InvalidArgumentException(
                "Unknown reconcile mode '{$mode}'. Use drift|incremental|full."
            ),
        };

        if (! $dryRun) {
            // DECISION Q: runtime counter as a structured log event.
            $this->logger->metric('reconcile', [
                'mode'       => $result->mode,
                'scanned'    => $result->scanned,
                'suppressed' => $result->suppressed,
                'reconcile'  => $result->repairedCount(),
            ]);
        }

        return $result;
    }
}
