<?php

declare(strict_types=1);

namespace HSP\Core\Workers\Strategies;

use HSP\Core\Workers\WorkerExecutionContext;
use HSP\Core\Workers\WorkerStrategyInterface;

/**
 * Processes replay jobs from the 'system' queue.
 *
 * STUB — P0-S6. Domain logic (re-enqueuing archived events, driving the
 * replay pipeline) is implemented in OPS-S1 (Doc 4 §24).
 *
 * The stub satisfies the WorkerStrategyInterface contract so the engine can
 * be wired and tested with any strategy type. It always returns false (nothing
 * to process) so the engine idles gracefully when this strategy is used alone.
 */
final class ReplayWorkerStrategy implements WorkerStrategyInterface
{
    public function execute(WorkerExecutionContext $context): bool
    {
        // STUB — OPS-S1: implement single-event and entity replay modes.
        return false;
    }

    public function getQueueNames(): array
    {
        return ['system'];
    }
}
