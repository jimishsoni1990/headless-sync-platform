<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Workers;

use HSP\Core\Workers\DatabaseHeartbeatPublisher;
use HSP\Core\Workers\HeartbeatRecord;
use HSP\Tests\Unit\Queue\FakeQueueConnection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DatabaseHeartbeatPublisher (DECISION P).
 *
 * Proves the per-tick upsert SQL shape and bindings against a fake connection —
 * INSERT … ON CONFLICT (worker_id) DO UPDATE with worker_type/status/last_heartbeat_at.
 */
final class DatabaseHeartbeatPublisherTest extends TestCase
{
    public function test_publish_issues_upsert_with_all_five_columns(): void
    {
        $conn      = new FakeQueueConnection();
        $publisher = new DatabaseHeartbeatPublisher($conn);

        $started = new \DateTimeImmutable('2026-07-11T10:00:00+00:00');
        $beat    = new \DateTimeImmutable('2026-07-11T10:00:05+00:00');

        $publisher->publish(new HeartbeatRecord(
            workerId:        '01900000-0000-7000-8000-0000000000aa',
            status:          'processing',
            lastHeartbeatAt: $beat,
            workerType:      'event',
            startedAt:       $started,
        ));

        self::assertCount(1, $conn->executeCalls);
        $call = $conn->executeCalls[0];

        self::assertStringContainsString('INSERT INTO system.worker_heartbeats', $call['sql']);
        self::assertStringContainsString('ON CONFLICT (worker_id) DO UPDATE', $call['sql']);
        self::assertStringContainsString('last_heartbeat_at  = EXCLUDED.last_heartbeat_at', $call['sql']);

        $tsFormat = 'Y-m-d\TH:i:s.uP';
        self::assertSame('01900000-0000-7000-8000-0000000000aa', $call['params'][0]);
        self::assertSame('event', $call['params'][1]);
        self::assertSame('processing', $call['params'][2]);
        self::assertSame($beat->format($tsFormat), $call['params'][3]);
        self::assertSame($started->format($tsFormat), $call['params'][4]);
    }

    public function test_missing_started_at_falls_back_to_last_heartbeat(): void
    {
        $conn      = new FakeQueueConnection();
        $publisher = new DatabaseHeartbeatPublisher($conn);

        $beat = new \DateTimeImmutable('2026-07-11T10:00:05+00:00');

        $publisher->publish(new HeartbeatRecord(
            workerId:        '01900000-0000-7000-8000-0000000000bb',
            status:          'idle',
            lastHeartbeatAt: $beat,
            // no startedAt
        ));

        $call = $conn->executeCalls[0];
        self::assertSame($beat->format('Y-m-d\TH:i:s.uP'), $call['params'][4], 'started_at falls back to last_heartbeat_at');
        self::assertSame('worker', $call['params'][1], 'worker_type defaults to "worker"');
    }
}
