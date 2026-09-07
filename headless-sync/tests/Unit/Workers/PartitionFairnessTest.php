<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Workers;

use HSP\Core\Contracts\EventInterface;
use HSP\Core\Contracts\QueueProviderInterface;
use HSP\Core\Events\EventRegistry;
use HSP\Core\Workers\Strategies\EventWorkerStrategy;
use HSP\Core\Workers\WorkerExecutionContext;
use HSP\Tests\Support\ContentProjections;
use HSP\Tests\Unit\Content\Adapters\FakeDbConnection;
use PHPUnit\Framework\TestCase;

/**
 * P2-S1 acceptance — one busy domain must not starve another (DECISION AG AG-4).
 *
 * The projection stage shares a SINGLE `processing.projection_batch_size` budget across
 * every active partition; the budget is explicitly NOT multiplied per domain. Without
 * rotation, whichever partition is probed first would consume that entire shared budget
 * whenever it has a backlog, and the other domain would never advance — a Commerce import
 * would stall Content indefinitely, and vice versa.
 *
 * These assertions are about CLAIM ORDER, which is where fairness is decided. Processing
 * each claimed job is exercised elsewhere; here the jobs deliberately fail to load so the
 * test stays focused on rotation.
 */
final class PartitionFairnessTest extends TestCase
{
    private function strategy(PartitionSpyQueue $queue): EventWorkerStrategy
    {
        return new EventWorkerStrategy(
            $queue,
            new EventRegistry(),
            new FakeDbConnection(),
            ContentProjections::router(['commerce' => 'commerce']),
            retryLimit: 10,
        );
    }

    private function ctx(): WorkerExecutionContext
    {
        return new WorkerExecutionContext(
            workerId:      'test-worker-id',
            tickStartedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    /** Run execute() n times, swallowing the per-job processing failure. */
    private function drive(EventWorkerStrategy $strategy, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            try {
                $strategy->execute($this->ctx());
            } catch (\Throwable) {
                // The claim is the subject; the job body is intentionally unprocessable.
            }
        }
    }

    public function testClaimsAlternateBetweenBusyPartitions(): void
    {
        $queue = new PartitionSpyQueue(['content' => 10, 'commerce' => 10]);

        $this->drive($this->strategy($queue), 4);

        self::assertSame(
            ['content', 'commerce', 'content', 'commerce'],
            $queue->successfulClaims,
            'with both domains backlogged, successive claims must rotate — not drain one first',
        );
    }

    public function testABusyDomainDoesNotStarveAQuietOne(): void
    {
        // Content has a deep backlog; Commerce has a single job. Commerce must still be
        // served on the second call rather than waiting for Content to empty.
        $queue = new PartitionSpyQueue(['content' => 100, 'commerce' => 1]);

        $this->drive($this->strategy($queue), 3);

        self::assertContains('commerce', $queue->successfulClaims);
        self::assertSame(['content', 'commerce', 'content'], $queue->successfulClaims);
    }

    public function testAnEmptyPartitionIsSkippedAndTheOtherStillDrains(): void
    {
        $queue = new PartitionSpyQueue(['content' => 0, 'commerce' => 3]);

        $this->drive($this->strategy($queue), 2);

        self::assertSame(['commerce', 'commerce'], $queue->successfulClaims);
    }

    public function testReturnsFalseOnlyWhenEveryPartitionIsEmpty(): void
    {
        $queue    = new PartitionSpyQueue(['content' => 0, 'commerce' => 0]);
        $strategy = $this->strategy($queue);

        self::assertFalse($strategy->execute($this->ctx()));
        self::assertSame(
            ['content', 'commerce'],
            $queue->probedPartitions,
            'every active partition must be probed before reporting the queue empty',
        );
    }

    public function testQueueNamesReflectTheActivePartitions(): void
    {
        $strategy = $this->strategy(new PartitionSpyQueue([]));

        self::assertSame(['content', 'commerce'], $strategy->getQueueNames());
    }
}

/**
 * Queue double with per-partition depth, recording which partitions were probed and which
 * actually yielded a job.
 */
final class PartitionSpyQueue implements QueueProviderInterface
{
    /** @var list<string> */
    public array $probedPartitions = [];

    /** @var list<string> */
    public array $successfulClaims = [];

    /** @param array<string,int> $depths partition => number of jobs available */
    public function __construct(private array $depths = []) {}

    public function claim(string $queueName, string $workerId): ?array
    {
        $this->probedPartitions[] = $queueName;

        if (($this->depths[$queueName] ?? 0) < 1) {
            return null;
        }

        $this->depths[$queueName]--;
        $this->successfulClaims[] = $queueName;

        // Deliberately missing event_id: the strategy raises during load, which is fine —
        // the claim has already happened and that is what this test measures.
        return ['id' => 'job-' . count($this->successfulClaims), 'attempts' => 0];
    }

    public function enqueue(EventInterface $event, string $queueName): string { return 'job'; }
    public function complete(string $jobId, string $workerId): bool { return true; }
    public function release(string $jobId, string $workerId, int $delaySeconds = 0): bool { return true; }
    /** @param array<string,mixed> $failureContext */
    public function deadLetter(string $jobId, string $workerId, array $failureContext): bool { return true; }
    public function requeueTimedOut(string $queueName): int { return 0; }
}
