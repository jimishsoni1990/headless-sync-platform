<?php

declare(strict_types=1);

namespace HSP\Core\Workers\Strategies;

use HSP\Core\Workers\WorkerExecutionContext;
use HSP\Core\Workers\WorkerStrategyInterface;

/**
 * Detects and repairs drift between WordPress (source of truth) and PostgreSQL
 * delivery projections.
 *
 * STUB — P0-S6. Domain logic (hourly drift detection, incremental validation,
 * full reconciliation, WordPress-wins repair — ADR-026, ADR-027, ADR-045) is
 * implemented in OPS-S1 / Phase 3 Operational Hardening.
 *
 * WordPress always wins divergence (ADR-045, CLAUDE.md Rule 1). The stub
 * returns false (nothing to process) so the engine idles gracefully.
 */
final class ReconciliationWorkerStrategy implements WorkerStrategyInterface
{
    public function execute(WorkerExecutionContext $context): bool
    {
        // STUB — OPS-S1: implement drift detection and WordPress-wins repair.
        return false;
    }

    public function getQueueNames(): array
    {
        return ['system'];
    }
}
