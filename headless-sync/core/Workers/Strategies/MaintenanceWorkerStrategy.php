<?php

declare(strict_types=1);

namespace HSP\Core\Workers\Strategies;

use HSP\Core\Contracts\QueueProviderInterface;
use HSP\Core\Workers\WorkerExecutionContext;
use HSP\Core\Workers\WorkerStrategyInterface;

/**
 * Drives visibility-timeout recovery: requeues jobs whose lease expired without
 * completion (OPEN-4 recovery process; DECISION R).
 *
 * Authority:
 *   OPEN-4          — a recovery process requeues jobs whose visibility_timeout_at
 *                     has expired without completion; timeout duration is config-driven.
 *   DECISION R (v1.16) — MaintenanceWorkerStrategy is the RUNTIME DRIVER for
 *                     QueueProviderInterface::requeueTimedOut(). Cadence is
 *                     configuration-driven with a sensible default; NO hardcoded timing.
 *                     Uses the worker-runtime connection (the same handle the queue
 *                     provider already holds) — no new handle.
 *   ADR-012         — dependencies injected via constructor.
 *
 * Cadence semantics:
 *   The WorkerEngine ticks continuously. Running requeueTimedOut() on every tick
 *   would hammer the DB. Instead the strategy enforces a minimum interval between
 *   recovery sweeps (config: worker.maintenance.recovery_interval_seconds, default 30s).
 *   Between sweeps execute() returns false so the engine idles (back-off).
 *
 * Partitions: sweeps every partition the queue serves ('content', 'commerce', 'system')
 *   so no partition's crashed jobs are left stranded. Returns true when a sweep ran
 *   (regardless of how many rows were requeued) so the engine treats it as work.
 */
final class MaintenanceWorkerStrategy implements WorkerStrategyInterface
{
    private const DEFAULT_RECOVERY_INTERVAL_SECONDS = 30;

    /** @var list<string> */
    private array $partitions;

    private int    $recoveryIntervalSeconds;
    private ?float $lastSweepAt = null;

    /** @var array<string,int> last observed requeue counts per partition (for observability). */
    private array $lastRequeuedByPartition = [];

    /**
     * @param array<string,mixed> $config keys (under the maintenance sub-array):
     *                                     recovery_interval_seconds, partitions
     */
    public function __construct(
        private readonly QueueProviderInterface $queue,
        array $config = [],
    ) {
        $this->recoveryIntervalSeconds = (int) ($config['recovery_interval_seconds']
            ?? self::DEFAULT_RECOVERY_INTERVAL_SECONDS);

        /** @var list<string> $partitions */
        $partitions       = $config['partitions'] ?? ['content', 'commerce', 'system'];
        $this->partitions = array_values($partitions);
    }

    public function execute(WorkerExecutionContext $context): bool
    {
        $now = microtime(true);

        // Cadence gate — enforce a minimum interval between recovery sweeps (DECISION R:
        // config-driven, no hardcoded timing at the call site).
        if ($this->lastSweepAt !== null
            && ($now - $this->lastSweepAt) < $this->recoveryIntervalSeconds
        ) {
            return false;
        }

        $this->lastSweepAt             = $now;
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
