<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Workers;

use HSP\Core\Contracts\EventInterface;
use HSP\Core\Contracts\QueueProviderInterface;
use HSP\Core\Workers\Strategies\MaintenanceWorkerStrategy;
use HSP\Core\Workers\WorkerExecutionContext;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MaintenanceWorkerStrategy (DECISION R).
 *
 * Proves: drives requeueTimedOut() per partition on the first tick; enforces the
 * config-driven cadence (no second sweep within the interval); no hardcoded timing.
 */
final class MaintenanceWorkerStrategyTest extends TestCase
{
    public function test_first_tick_sweeps_every_partition(): void
    {
        $queue    = new RecordingRequeueProvider();
        $strategy = new MaintenanceWorkerStrategy($queue, [
            'recovery_interval_seconds' => 30,
            'partitions'                => ['content', 'commerce', 'system'],
        ]);

        $did = $strategy->execute($this->ctx());

        self::assertTrue($did, 'a sweep counts as work');
        self::assertSame(['content', 'commerce', 'system'], $queue->requeuedPartitions);
    }

    public function test_second_tick_within_interval_does_not_sweep(): void
    {
        $queue    = new RecordingRequeueProvider();
        $strategy = new MaintenanceWorkerStrategy($queue, [
            'recovery_interval_seconds' => 3600, // large window
            'partitions'                => ['content'],
        ]);

        $strategy->execute($this->ctx());          // sweeps
        $queue->requeuedPartitions = [];            // reset observation
        $second = $strategy->execute($this->ctx()); // within interval → no sweep

        self::assertFalse($second, 'no sweep within cadence window → idle');
        self::assertSame([], $queue->requeuedPartitions, 'requeueTimedOut not called again');
    }

    public function test_zero_interval_sweeps_every_tick(): void
    {
        $queue    = new RecordingRequeueProvider();
        $strategy = new MaintenanceWorkerStrategy($queue, [
            'recovery_interval_seconds' => 0,
            'partitions'                => ['content'],
        ]);

        self::assertTrue($strategy->execute($this->ctx()));
        self::assertTrue($strategy->execute($this->ctx()));
        self::assertSame(['content', 'content'], $queue->requeuedPartitions);
    }

    public function test_default_interval_is_used_when_config_absent(): void
    {
        // No hardcoded timing at the call site; the default lives in the strategy.
        $queue    = new RecordingRequeueProvider();
        $strategy = new MaintenanceWorkerStrategy($queue); // no config

        $strategy->execute($this->ctx());
        $queue->requeuedPartitions = [];
        $second = $strategy->execute($this->ctx());

        self::assertFalse($second, 'default 30s interval blocks an immediate re-sweep');
    }

    public function test_last_requeued_counts_exposed_for_observability(): void
    {
        $queue                = new RecordingRequeueProvider();
        $queue->requeueReturn = 4;
        $strategy             = new MaintenanceWorkerStrategy($queue, [
            'recovery_interval_seconds' => 0,
            'partitions'                => ['content', 'system'],
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
