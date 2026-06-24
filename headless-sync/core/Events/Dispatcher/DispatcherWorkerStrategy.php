<?php

declare(strict_types=1);

namespace HSP\Core\Events\Dispatcher;

use HSP\Core\Workers\WorkerExecutionContext;
use HSP\Core\Workers\WorkerStrategyInterface;

/**
 * WorkerStrategyInterface implementation for the Dispatcher stage.
 *
 * Each tick delegates to EventDispatcher::dispatchBatch(), which:
 *   1. Claims a batch of undispatched system.events rows (anti-join + FOR UPDATE SKIP LOCKED).
 *   2. Enqueues each into system.queue_jobs via DatabaseQueueProvider::enqueueIdempotent().
 *
 * Returns true if the batch was non-empty (work was done), false if there were no
 * undispatched events (engine idles and backs off).
 *
 * Queue partition: 'content' (derived from event_type first segment by EventDispatcher).
 *
 * Authority: DECISION L (v1.12, 2026-06-25); WorkerStrategyInterface contract;
 *            CLAUDE.md Rule 7 (constructor injection only — no service-locator calls).
 */
final class DispatcherWorkerStrategy implements WorkerStrategyInterface
{
    public function __construct(private readonly EventDispatcher $dispatcher) {}

    public function execute(WorkerExecutionContext $context): bool
    {
        $batch = $this->dispatcher->dispatchBatch();
        return ! $batch->isEmpty();
    }

    /** @return string[] */
    public function getQueueNames(): array
    {
        return ['content'];
    }
}
