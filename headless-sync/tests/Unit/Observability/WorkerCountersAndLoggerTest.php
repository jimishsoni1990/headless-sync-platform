<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Observability;

use HSP\Core\Observability\StructuredLogger;
use HSP\Core\Observability\WorkerCounters;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WorkerCounters + StructuredLogger (DECISION Q clause 2).
 */
final class WorkerCountersAndLoggerTest extends TestCase
{
    public function test_counters_accumulate_and_snapshot(): void
    {
        $c = new WorkerCounters();
        $c->incrementProcessed();
        $c->incrementProcessed();
        $c->incrementRetry();
        $c->incrementFailure();
        $c->incrementReplay();

        self::assertSame(
            ['processed' => 2, 'retry' => 1, 'failure' => 1, 'replay' => 1],
            $c->snapshot(),
        );
    }

    public function test_structured_logger_emits_json_with_hsp_metric_envelope(): void
    {
        $captured = [];
        $logger   = new StructuredLogger(static function (string $line) use (&$captured): void {
            $captured[] = $line;
        });

        $logger->metric('worker.counters', ['processed' => 5, 'worker_type' => 'event']);

        self::assertCount(1, $captured);
        $decoded = json_decode($captured[0], true);

        self::assertSame('metric', $decoded['hsp']);
        self::assertSame('worker.counters', $decoded['event']);
        self::assertSame(5, $decoded['processed']);
        self::assertSame('event', $decoded['worker_type']);
        self::assertArrayHasKey('ts', $decoded);
    }
}
