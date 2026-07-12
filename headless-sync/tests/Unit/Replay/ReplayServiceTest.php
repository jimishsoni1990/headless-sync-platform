<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Replay;

use HSP\Core\Replay\ReplayService;
use HSP\Tests\Unit\Content\Adapters\FakeDbConnection;
use PHPUnit\Framework\TestCase;

/**
 * ReplayService — DECISION T orchestration.
 *
 * Verifies entity-mode single emit, date-range DISTINCT discovery over system.events,
 * per-aggregate emit fan-out, correlation/causation assignment, half-open window bounds,
 * and unknown-aggregate rejection. Uses a fake connection (no PG) and a fake emitter.
 */
final class ReplayServiceTest extends TestCase
{
    private FakeDbConnection $conn;
    private FakeReplayEmitter $emitter;
    private ReplayService $service;

    protected function setUp(): void
    {
        $this->conn    = new FakeDbConnection();
        $this->emitter = new FakeReplayEmitter();
        $this->service = new ReplayService($this->conn, [$this->emitter]);
    }

    public function testEntityReplayEmitsExactlyOnce(): void
    {
        $result = $this->service->replayEntity('post', '42');

        self::assertCount(1, $this->emitter->calls);
        self::assertSame('post', $this->emitter->calls[0]['type']);
        self::assertSame('42', $this->emitter->calls[0]['id']);
        self::assertSame(1, $result->count());
        self::assertSame('content.post.updated', $result->emitted[0]['event_type']);
        self::assertSame(1, $result->emitted[0]['aggregate_version']);
    }

    public function testEntityReplayAssignsCorrelationAndCausation(): void
    {
        $result = $this->service->replayEntity('post', '42');

        self::assertNotSame('', $result->correlationId);
        self::assertNotSame('', $result->causationId);
        self::assertSame($result->correlationId, $this->emitter->calls[0]['correlationId']);
        self::assertSame($result->causationId, $this->emitter->calls[0]['causationId']);
    }

    public function testDateRangeDiscoversDistinctAggregatesAndEmitsOnePerAggregate(): void
    {
        $this->conn->willReturnRows([
            ['aggregate_type' => 'post', 'aggregate_id' => '1'],
            ['aggregate_type' => 'post', 'aggregate_id' => '2'],
            ['aggregate_type' => 'category', 'aggregate_id' => '5'],
        ]);

        $result = $this->service->replayRange(
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-02T00:00:00Z'),
        );

        self::assertSame(3, $result->count());
        self::assertCount(3, $this->emitter->calls);
        self::assertSame(['post', 'post', 'category'], array_column($this->emitter->calls, 'type'));
        self::assertSame(['1', '2', '5'], array_column($this->emitter->calls, 'id'));
    }

    public function testDateRangeSharesOneCorrelationIdAcrossAllEmits(): void
    {
        $this->conn->willReturnRows([
            ['aggregate_type' => 'post', 'aggregate_id' => '1'],
            ['aggregate_type' => 'post', 'aggregate_id' => '2'],
        ]);

        $result = $this->service->replayRange(
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-02T00:00:00Z'),
        );

        $correlationIds = array_unique(array_column($this->emitter->calls, 'correlationId'));
        self::assertCount(1, $correlationIds);
        self::assertSame($result->correlationId, $correlationIds[0]);
    }

    public function testDateRangeUsesDistinctSelectWithHalfOpenBounds(): void
    {
        $this->conn->willReturnRows([]);

        $this->service->replayRange(
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-02T00:00:00Z'),
        );

        $q = $this->conn->log[0];
        self::assertSame('query', $q['method']);
        self::assertStringContainsString('SELECT DISTINCT', $q['sql']);
        self::assertStringContainsString('system.events', $q['sql']);
        // Half-open window: >= from, < to.
        self::assertStringContainsString('>= $1', $q['sql']);
        self::assertStringContainsString('<  $2', $q['sql']);
    }

    public function testDateRangeWithNoAggregatesEmitsNothing(): void
    {
        $this->conn->willReturnRows([]);

        $result = $this->service->replayRange(
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-02T00:00:00Z'),
        );

        self::assertSame(0, $result->count());
        self::assertCount(0, $this->emitter->calls);
    }

    public function testDateRangeReadDoesNotWriteOrMutate(): void
    {
        // Replay is repair-by-emit; the service itself performs NO DML on system.events.
        $this->conn->willReturnRows([['aggregate_type' => 'post', 'aggregate_id' => '1']]);

        $this->service->replayRange(
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-02T00:00:00Z'),
        );

        self::assertSame(['query'], $this->conn->loggedMethods());
    }

    public function testUnknownAggregateTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("No replay emitter registered for aggregate type 'widget'");
        $this->service->replayEntity('widget', '1');
    }
}
