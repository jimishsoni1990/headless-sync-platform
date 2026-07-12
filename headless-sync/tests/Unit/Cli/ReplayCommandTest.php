<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Cli;

use HSP\Core\Cli\ReplayCommand;
use HSP\Core\Observability\StructuredLogger;
use HSP\Core\Replay\ReplayService;
use HSP\Core\Workers\Strategies\ReplayWorkerStrategy;
use HSP\Tests\Unit\Content\Adapters\FakeDbConnection;
use HSP\Tests\Unit\Replay\FakeReplayEmitter;
use PHPUnit\Framework\TestCase;

/**
 * ReplayCommand — WP-CLI-facing entity/range replay (DECISION T) plus the DECISION Q
 * `replay` structured-counter emission and range-bound validation.
 *
 * Uses the real ReplayWorkerStrategy → ReplayService with a fake connection + emitter,
 * and a StructuredLogger with a capturing sink (no WP-CLI runtime).
 */
final class ReplayCommandTest extends TestCase
{
    private FakeDbConnection $conn;
    private FakeReplayEmitter $emitter;
    /** @var list<string> */
    private array $logLines;
    private ReplayCommand $command;

    protected function setUp(): void
    {
        $this->conn     = new FakeDbConnection();
        $this->emitter  = new FakeReplayEmitter();
        $strategy       = new ReplayWorkerStrategy(new ReplayService($this->conn, [$this->emitter]));
        $this->logLines = [];
        $logger = new StructuredLogger(function (string $line): void {
            $this->logLines[] = $line;
        });

        $this->command = new ReplayCommand($strategy, $logger);
    }

    public function testEntityReplayReturnsResultAndEmitsReplayCounter(): void
    {
        $result = $this->command->entity('post', '42');

        self::assertSame(1, $result->count());

        self::assertCount(1, $this->logLines);
        $decoded = json_decode($this->logLines[0], true);
        self::assertSame('metric', $decoded['hsp']);
        self::assertSame('replay', $decoded['event']);
        self::assertSame('entity', $decoded['mode']);
        self::assertSame(1, $decoded['replay']);
        self::assertSame($result->correlationId, $decoded['correlation_id']);
    }

    public function testRangeReplayEmitsCounterEqualToAggregateCount(): void
    {
        $this->conn->willReturnRows([
            ['aggregate_type' => 'post', 'aggregate_id' => '1'],
            ['aggregate_type' => 'post', 'aggregate_id' => '2'],
            ['aggregate_type' => 'category', 'aggregate_id' => '5'],
        ]);

        $result = $this->command->range('2026-07-01T00:00:00Z', '2026-07-02T00:00:00Z');

        self::assertSame(3, $result->count());
        $decoded = json_decode($this->logLines[0], true);
        self::assertSame('range', $decoded['mode']);
        self::assertSame(3, $decoded['replay']);
    }

    public function testRangeRejectsInvertedBounds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be strictly before');
        $this->command->range('2026-07-02T00:00:00Z', '2026-07-01T00:00:00Z');
    }

    public function testRangeRejectsEqualBounds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->command->range('2026-07-01T00:00:00Z', '2026-07-01T00:00:00Z');
    }

    public function testRangeRejectsUnparseableDatetime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a valid datetime');
        $this->command->range('not-a-date', '2026-07-02T00:00:00Z');
    }
}
