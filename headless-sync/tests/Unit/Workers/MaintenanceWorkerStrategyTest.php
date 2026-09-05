<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Workers;

use HSP\Core\Contracts\EventInterface;
use HSP\Core\Contracts\QueueProviderInterface;
use HSP\Core\Workers\Strategies\MaintenanceWorkerStrategy;
use HSP\Core\Workers\WorkerExecutionContext;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MaintenanceWorkerStrategy (DECISION R; ADR-054 cycle model).
 *
 * Proves: drives requeueTimedOut() per partition once per cycle (execute()); the cron
 * cadence IS the maintenance cadence (no in-process throttle state — T3), so every cycle
 * sweeps; requeue counts are exposed for observability.
 */
final class MaintenanceWorkerStrategyTest extends TestCase
{
    public function test_execute_sweeps_every_partition(): void
    {
        $queue    = new RecordingRequeueProvider();
        $strategy = new MaintenanceWorkerStrategy($queue, [
            'partitions' => ['content', 'commerce', 'system'],
        ]);

        $did = $strategy->execute($this->ctx());

        self::assertTrue($did, 'a sweep counts as work');
        self::assertSame(['content', 'commerce', 'system'], $queue->requeuedPartitions);
    }

    public function test_each_cycle_sweeps_no_in_process_throttle(): void
    {
        // ADR-054 (T3): the in-memory cadence gate is removed — a fresh cron cycle must
        // not silently skip the sweep. Every execute() sweeps exactly once.
        $queue    = new RecordingRequeueProvider();
        $strategy = new MaintenanceWorkerStrategy($queue, ['partitions' => ['content']]);

        self::assertTrue($strategy->execute($this->ctx()));
        self::assertTrue($strategy->execute($this->ctx()));
        self::assertSame(['content', 'content'], $queue->requeuedPartitions);
    }

    /**
     * `system.worker_heartbeats` is append-mostly under ADR-054 — each cycle mints a fresh
     * worker_id (DECISION X (1)), so a row accumulates per cycle and nothing removed them. At the
     * default 60s cadence that is ~1,440 rows/day, growing without bound in the table the console
     * reads on every page load and every poll. The sweep is where that retention belongs.
     */
    public function test_execute_prunes_heartbeats_older_than_the_retention_window(): void
    {
        $conn     = new RecordingSweepConnection();
        $conn->affected = 7;
        $strategy = new MaintenanceWorkerStrategy(
            new RecordingRequeueProvider(),
            ['partitions' => ['content'], 'heartbeat_retention_seconds' => 3600],
            $conn,
        );

        $strategy->execute($this->ctx());

        self::assertCount(1, $conn->executed);
        self::assertStringContainsString('DELETE FROM system.worker_heartbeats', $conn->executed[0]['sql']);
        self::assertSame([3600], $conn->executed[0]['params']);
        self::assertSame(7, $strategy->lastHeartbeatsPruned());
    }

    /** Retention of 0 disables the prune outright (opt-out, not an accidental full wipe). */
    public function test_zero_retention_disables_the_prune(): void
    {
        $conn     = new RecordingSweepConnection();
        $strategy = new MaintenanceWorkerStrategy(
            new RecordingRequeueProvider(),
            ['heartbeat_retention_seconds' => 0],
            $conn,
        );

        $strategy->execute($this->ctx());

        self::assertSame([], $conn->executed);
    }

    /** Upkeep is never a correctness dependency: with no connection wired the sweep still works. */
    public function test_sweep_still_succeeds_without_a_connection(): void
    {
        $queue    = new RecordingRequeueProvider();
        $strategy = new MaintenanceWorkerStrategy($queue, ['partitions' => ['content']]);

        self::assertTrue($strategy->execute($this->ctx()));
        self::assertSame(['content'], $queue->requeuedPartitions);
        self::assertSame(0, $strategy->lastHeartbeatsPruned());
    }

    public function test_default_partitions_used_when_config_absent(): void
    {
        $queue    = new RecordingRequeueProvider();
        $strategy = new MaintenanceWorkerStrategy($queue); // no config

        $strategy->execute($this->ctx());

        self::assertSame(['content', 'commerce', 'system'], $queue->requeuedPartitions);
    }

    public function test_last_requeued_counts_exposed_for_observability(): void
    {
        $queue                = new RecordingRequeueProvider();
        $queue->requeueReturn = 4;
        $strategy             = new MaintenanceWorkerStrategy($queue, [
            'partitions' => ['content', 'system'],
        ]);

        $strategy->execute($this->ctx());

        self::assertSame(['content' => 4, 'system' => 4], $strategy->getLastRequeuedByPartition());
    }

    private function ctx(): WorkerExecutionContext
    {
        return new WorkerExecutionContext(
            workerId:      '01900000-0000-7000-8000-000000000001',
            tickStartedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }
}

/**
 * QueueProviderInterface double that records the partitions requeueTimedOut() was
 * called for, in order, and returns a configurable requeue count.
 */
final class RecordingRequeueProvider implements QueueProviderInterface
{
    /** @var list<string> */
    public array $requeuedPartitions = [];
    public int   $requeueReturn      = 0;

    public function enqueue(EventInterface $event, string $queueName): string { return 'x'; }
    public function claim(string $queueName, string $workerId): ?array { return null; }
    public function complete(string $jobId, string $workerId): bool { return true; }
    public function release(string $jobId, string $workerId, int $delaySeconds = 0): bool { return true; }
    public function deadLetter(string $jobId, string $workerId, array $failureContext): bool { return true; }

    public function requeueTimedOut(string $queueName): int
    {
        $this->requeuedPartitions[] = $queueName;
        return $this->requeueReturn;
    }
}

/**
 * DatabaseConnectionInterface double that records the statements the sweep issues.
 */
final class RecordingSweepConnection implements \HSP\Core\Database\DatabaseConnectionInterface
{
    /** @var list<array{sql:string,params:array<int,mixed>}> */
    public array $executed = [];
    public int   $affected = 0;

    public function execute(string $sql, array $params = []): int
    {
        $this->executed[] = ['sql' => $sql, 'params' => $params];
        return $this->affected;
    }

    public function query(string $sql, array $params = []): array { return []; }
    public function beginTransaction(): void {}
    public function commit(): void {}
    public function rollback(): void {}
}
