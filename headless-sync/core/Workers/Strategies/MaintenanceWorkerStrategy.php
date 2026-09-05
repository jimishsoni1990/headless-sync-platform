<?php

declare(strict_types=1);

namespace HSP\Core\Workers\Strategies;

use HSP\Core\Contracts\QueueProviderInterface;
use HSP\Core\Database\DatabaseConnectionInterface;
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

    /** Seconds of per-cycle heartbeat history kept by the sweep. */
    private readonly int $heartbeatRetentionSeconds;

    /** Rows removed by the most recent heartbeat prune (for observability). */
    private int $lastHeartbeatsPruned = 0;

    /**
     * @param array<string,mixed> $config keys (under the maintenance sub-array): partitions,
     *                                    heartbeat_retention_seconds
     */
    public function __construct(
        private readonly QueueProviderInterface $queue,
        array $config = [],
        private readonly ?DatabaseConnectionInterface $conn = null,
    ) {
        /** @var list<string> $partitions */
        $partitions       = $config['partitions'] ?? ['content', 'commerce', 'system'];
        $this->partitions = array_values($partitions);

        $retention = $config['heartbeat_retention_seconds'] ?? 86400;
        $this->heartbeatRetentionSeconds = is_numeric($retention) ? (int) $retention : 86400;
    }

    public function execute(WorkerExecutionContext $context): bool
    {
        $this->lastRequeuedByPartition = [];

        foreach ($this->partitions as $partition) {
            $this->lastRequeuedByPartition[$partition] = $this->queue->requeueTimedOut($partition);
        }

        $this->lastHeartbeatsPruned = $this->pruneHeartbeats();

        return true;
    }

    /**
     * Drop per-cycle heartbeat rows older than the retention window.
     *
     * `system.worker_heartbeats` is append-mostly under ADR-054: the upsert keys on worker_id and
     * every cycle mints a fresh UUIDv7 (DECISION X (1)), so each cycle leaves a row behind rather
     * than updating one. Nothing removed them, so at the default 60s cadence the table grew ~1,440
     * rows/day without bound — unbounded growth in the table the console reads on every page load
     * and every 15s poll. Retention belongs to the maintenance stage for the same reason the
     * visibility-timeout requeue does: it is periodic table upkeep on the cycle's own cadence, with
     * no new persistence and no new schedule (DECISION Q / ADR-054 §9).
     *
     * The window must stay comfortably wider than the console's freshness threshold and its
     * cycles-completed window, so pruning never erases a row an operator is still reading.
     * A no-op when no connection is wired (unit contexts) — this is upkeep, never correctness.
     */
    private function pruneHeartbeats(): int
    {
        if ($this->conn === null || $this->heartbeatRetentionSeconds <= 0) {
            return 0;
        }

        return $this->conn->execute(
            'DELETE FROM system.worker_heartbeats
             WHERE  last_heartbeat_at < NOW() - make_interval(secs => $1)',
            [$this->heartbeatRetentionSeconds],
        );
    }

    /** Rows removed by the most recent heartbeat prune (DECISION Q observability). */
    public function lastHeartbeatsPruned(): int
    {
        return $this->lastHeartbeatsPruned;
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
