<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Reconciliation;

use HSP\Core\Reconciliation\ReconciliationService;
use HSP\Core\Replay\ReplayService;
use HSP\Core\Workers\Strategies\ReconciliationWorkerStrategy;
use HSP\Core\Workers\WorkerExecutionContext;
use HSP\Tests\Unit\Content\Adapters\FakeDbConnection;
use HSP\Tests\Unit\Replay\FakeReplayEmitter;
use PHPUnit\Framework\TestCase;

/**
 * ReconciliationWorkerStrategy — DECISION U façade (executor = B1).
 *
 * Verifies the three mode methods delegate to ReconciliationService with the right mode, and
 * that execute() is a producer-side no-op (returns false, claims nothing, does no I/O).
 */
final class ReconciliationWorkerStrategyTest extends TestCase
{
    private FakeReconConnection $conn;
    private FakeReconciliationSource $source;
    private FakeReplayEmitter $emitter;
    private ReconciliationWorkerStrategy $strategy;

    protected function setUp(): void
    {
        $this->conn    = new FakeReconConnection();
        $this->source  = new FakeReconciliationSource();
        $this->emitter = new FakeReplayEmitter();
        $replay        = new ReplayService(new FakeDbConnection(), [$this->emitter]);
        $service       = new ReconciliationService($this->conn, $this->source, $replay, 500);
        $this->strategy = new ReconciliationWorkerStrategy($service);
    }

    public function testDriftModeDelegates(): void
    {
        $this->source->withType('post');
        $this->source->addLive('post', '1', true, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->conn->projectionRows['post:1'] = null; // missed create

        $result = $this->strategy->reconcileDrift();
        self::assertSame('drift', $result->mode);
        self::assertSame(1, $result->repairedCount());
    }

    public function testIncrementalModeDelegates(): void
    {
        $this->source->withType('post');
        $result = $this->strategy->reconcileIncremental();
        self::assertSame('incremental', $result->mode);
    }

    public function testFullModeDelegates(): void
    {
        $this->source->withType('post');
        $result = $this->strategy->reconcileFull();
        self::assertSame('full', $result->mode);
    }

    public function testExecuteIsProducerSideNoOp(): void
    {
        $context = new WorkerExecutionContext(
            'worker-uuid-1',
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
        self::assertFalse($this->strategy->execute($context));
        // No repair, no re-emission, no PG execute.
        self::assertCount(0, $this->emitter->calls);
        self::assertNotContains('execute', $this->conn->loggedMethods());
    }

    public function testQueueNames(): void
    {
        self::assertSame(['system'], $this->strategy->getQueueNames());
    }
}
