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
