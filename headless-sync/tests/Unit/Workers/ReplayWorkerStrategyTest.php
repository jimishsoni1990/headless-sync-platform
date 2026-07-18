<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Workers;

use HSP\Core\Replay\ReplayService;
use HSP\Core\Workers\Strategies\ReplayWorkerStrategy;
use HSP\Core\Workers\WorkerExecutionContext;
use HSP\Tests\Unit\Content\Adapters\FakeDbConnection;
use HSP\Tests\Unit\Replay\FakeReplayEmitter;
use PHPUnit\Framework\TestCase;

/**
 * ReplayWorkerStrategy (DECISION T) — delegation to ReplayService and the deliberate
 * execute() no-op (entity/date-range replay is producer-side, not a `system`-queue consumer).
 */
final class ReplayWorkerStrategyTest extends TestCase
{
    private FakeDbConnection $conn;
    private FakeReplayEmitter $emitter;
    private ReplayWorkerStrategy $strategy;

    protected function setUp(): void
    {
        $this->conn     = new FakeDbConnection();
        $this->emitter  = new FakeReplayEmitter();
        $this->strategy = new ReplayWorkerStrategy(new ReplayService($this->conn, [$this->emitter]));
    }

    public function testReplayEntityDelegatesToService(): void
    {
        $result = $this->strategy->replayEntity('post', '42');

        self::assertSame(1, $result->count());
        self::assertSame('42', $this->emitter->calls[0]['id']);
    }

    public function testReplayRangeDelegatesToService(): void
    {
        $this->conn->willReturnRows([
            ['aggregate_type' => 'post', 'aggregate_id' => '1'],
        ]);

        $result = $this->strategy->replayRange(
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-02T00:00:00Z'),
        );

        self::assertSame(1, $result->count());
    }

    public function testExecuteIsNoOpReturningFalse(): void
    {
        $context = new WorkerExecutionContext(
            'worker-uuid',
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );

        self::assertFalse($this->strategy->execute($context));
    }

    public function testConsumesSystemQueue(): void
    {
        self::assertSame(['system'], $this->strategy->getQueueNames());
    }

    /**
     * DECISION T (as preserved under ADR-054): the replay strategy is producer-side — its
     * execute() is a deliberate no-op and is NOT a stage of the WP-Cron Processing Engine
     * cycle (the cycle composes relay/dispatch/projection/maintenance only). Even if execute()
     * is called repeatedly, it claims no job, touches no connection, and never throws — it
     * reports no work every time. (Under ADR-054 there is no daemon engine wrapping arbitrary
     * strategies; the replay path is CLI/cron-triggered via replayEntity()/replayRange().)
     */
    public function testExecuteIsAClaimlessNoOpAcrossRepeatedCalls(): void
    {
        $context = new WorkerExecutionContext(
            'worker-uuid',
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );

        for ($i = 0; $i < 5; $i++) {
            self::assertFalse($this->strategy->execute($context), 'replay execute() reports no work');
        }

        // No queue claim / no connection I/O whatsoever occurred during execute().
        self::assertSame([], $this->conn->log, 'replay execute() performs no connection I/O — claims no job');
    }
}
