<?php

declare(strict_types=1);

namespace HSP\Core\Workers\Strategies;

use HSP\Core\Replay\ReplayResult;
use HSP\Core\Replay\ReplayService;
use HSP\Core\Workers\WorkerExecutionContext;
use HSP\Core\Workers\WorkerStrategyInterface;

/**
 * Owns entity and date-range replay (DECISION T).
 *
 * Replay in these modes is projection repair via synthetic re-emission, triggered
 * operationally (WP-CLI `hsp replay entity|range`), not driven by a `system`-queue job.
 * The producer-side work is delegated to ReplayService: read current WordPress state per
 * aggregate → emit ONE synthetic event through the outbox with a fresh aggregate_version
 * (DECISION 2) → the event flows relay → dispatch → worker and passes the DECISION J stale
 * guard naturally. Historical system.events rows are never mutated or re-enqueued.
 *
 * The WorkerStrategyInterface surface is retained so the class stays a first-class
 * worker strategy in the engine/registry. execute() is a deliberate no-op (returns
 * false): entity/date-range replay is a producer-side operation, so there is no
 * `system`-queue job for this strategy to consume — the engine idles gracefully if this
 * strategy is ever run on a tick loop. Single-event DLQ replay is a separate path
 * (DECISION S, DeadLetterRepository); it is unchanged by DECISION T.
 */
final class ReplayWorkerStrategy implements WorkerStrategyInterface
{
    public function __construct(
        private readonly ReplayService $replayService,
    ) {}

    /**
     * Entity replay — reproject a single aggregate to current WordPress state.
     */
    public function replayEntity(string $aggregateType, string $aggregateId): ReplayResult
    {
        return $this->replayService->replayEntity($aggregateType, $aggregateId);
    }

    /**
     * Date-range replay — reproject every aggregate with an event in [from, to).
     */
    public function replayRange(\DateTimeImmutable $from, \DateTimeImmutable $to): ReplayResult
    {
        return $this->replayService->replayRange($from, $to);
    }

    /**
     * Intentional no-op (DECISION T). Entity/date-range replay is a producer-side
     * operation invoked via ReplayService / the WP-CLI `hsp replay` surface — it is NOT
     * driven by claiming a `system`-queue job. If this strategy is ever launched under a
     * WorkerEngine, execute() must idle cleanly: it claims no job, performs no I/O, throws
     * nothing, and returns false so the engine publishes an 'idle' heartbeat and applies its
     * idle back-off (no busy-spin). All replay work happens in replayEntity()/replayRange().
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
