<?php

declare(strict_types=1);

namespace HSP\Core\Workers\Strategies;

use HSP\Core\Contracts\QueueProviderInterface;
use HSP\Core\Workers\WorkerExecutionContext;
use HSP\Core\Workers\WorkerStrategyInterface;

/**
 * Maintenance stage: requeues jobs whose visibility lease expired without completion
 * (OPEN-4 recovery process; DECISION R).
 *
 * ADR-054: this is the MAINTENANCE STAGE of the WP-Cron Processing Engine cycle — invoked
 * once per cycle by WorkerEngine::runCycle() (Doc 8 v2.0 §9 "maintenance (requeue
 * visibility-timeout expiries — DECISION R — as scheduled)"). The sweep runs once per
 * cycle: under the cron model the WP-Cron cadence IS the maintenance cadence (a cycle
 * fires at most once per cron tick), so the sweep runs at the intended interval across
 * independent, per-cycle-exiting processes with NO in-process throttle state and NO new
 * persistence (DECISION Q / ADR-054 §9).
 *
 * Cadence-safety note (T3): the prior in-memory `$lastSweepAt` throttle assumed a
 * continuously-ticking daemon and does NOT survive a per-cycle process exit — every fresh
 * cron cycle would see `$lastSweepAt=null` and always sweep. It is REMOVED. The cadence is
 * now the cron schedule that fires cycles: each cycle sweeps exactly once. `requeueTimedOut()`
 * is idempotent and cheap when nothing is expired, so a per-cycle sweep is safe.
 *
 * Authority:
 *   OPEN-4          — a recovery process requeues jobs whose visibility_timeout_at
 *                     has expired without completion; timeout duration is config-driven.
 *   DECISION R (v1.16) — MaintenanceWorkerStrategy is the RUNTIME DRIVER for
 *                     QueueProviderInterface::requeueTimedOut(); uses the worker-runtime
 *                     connection (the same handle the queue provider already holds).
 *   ADR-054         — one bounded cycle per cron tick; no daemon, no in-process cadence state.
 *   ADR-012         — dependencies injected via constructor.
 *
 * Partitions: sweeps every partition the queue serves ('content', 'commerce', 'system')
 *   so no partition's crashed jobs are left stranded. Returns true when a sweep ran
 *   (regardless of how many rows were requeued) so the cycle records the maintenance stage.
 */
final class MaintenanceWorkerStrategy implements WorkerStrategyInterface
{
    /** @var list<string> */
    private array $partitions;

    /** @var array<string,int> last observed requeue counts per partition (for observability). */
    private array $lastRequeuedByPartition = [];

    /**
     * @param array<string,mixed> $config keys (under the maintenance sub-array): partitions
     */
    public function __construct(
        private readonly QueueProviderInterface $queue,
        array $config = [],
    ) {
        /** @var list<string> $partitions */
        $partitions       = $config['partitions'] ?? ['content', 'commerce', 'system'];
        $this->partitions = array_values($partitions);
    }

    public function execute(WorkerExecutionContext $context): bool
    {
        $this->lastRequeuedByPartition = [];

        foreach ($this->partitions as $partition) {
            $this->lastRequeuedByPartition[$partition] = $this->queue->requeueTimedOut($partition);
        }

        return true;
    }

    public function getQueueNames(): array
    {
        return ['system'];
    }

    /**
     * Requeue counts from the most recent sweep, keyed by partition.
     * Exposed for observability / structured metric emission (DECISION Q).
     *
     * @return array<string,int>
     */
    public function getLastRequeuedByPartition(): array
    {
        return $this->lastRequeuedByPartition;
    }
}
