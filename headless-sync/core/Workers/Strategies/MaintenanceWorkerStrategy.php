<?php

declare(strict_types=1);

namespace HSP\Core\Workers\Strategies;

use HSP\Core\Workers\WorkerExecutionContext;
use HSP\Core\Workers\WorkerStrategyInterface;

/**
 * Performs housekeeping tasks: purging expired jobs, compacting audit logs,
 * emitting operational metrics, and running schema maintenance.
 *
 * STUB — P0-S6. Concrete housekeeping tasks are scoped to OPS-S1 and
 * Phase 3 Operational Hardening (Doc 8 §27, Doc 11 §12).
 *
 * The stub returns false (nothing to process) so the engine idles gracefully.
 */
final class MaintenanceWorkerStrategy implements WorkerStrategyInterface
{
    public function execute(WorkerExecutionContext $context): bool
    {
        // STUB — OPS-S1 / Phase 3: implement job purging, metric emission,
        // audit-log compaction, and schema maintenance tasks.
        return false;
    }

    public function getQueueNames(): array
    {
        return ['system'];
    }
}
