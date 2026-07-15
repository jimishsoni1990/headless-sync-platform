<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Providers;

use HSP\Core\Contracts\Operations\WorkerStatus;
use HSP\Core\Operations\Diagnostics\OperationsQueryReader;
use HSP\Core\Operations\Providers\WorkerStatusProvider;
use HSP\Tests\Unit\Operations\Fakes\ScriptedReaderConnection;
use PHPUnit\Framework\TestCase;

final class WorkerStatusProviderTest extends TestCase
{
    public function test_key_is_workers(): void
    {
        $provider = new WorkerStatusProvider(new OperationsQueryReader(new ScriptedReaderConnection()), 60);

        self::assertSame('workers', $provider->key());
    }

    public function test_offline_is_a_heartbeat_age_comparison_against_the_threshold(): void
    {
        $conn = (new ScriptedReaderConnection())->on('FROM   system.worker_heartbeats', [
            [
                'worker_id'         => '01900000-0000-7000-8000-0000000000a1',
                'worker_type'       => 'event',
                'status'            => 'idle',
                'last_heartbeat_at' => '2026-07-15 10:00:00+00',
                'age_seconds'       => '10',   // within 60s → online
            ],
            [
                'worker_id'         => '01900000-0000-7000-8000-0000000000a2',
                'worker_type'       => 'maintenance',
                'status'            => 'running',
                'last_heartbeat_at' => '2026-07-15 09:00:00+00',
                'age_seconds'       => '3600', // older than 60s → offline
            ],
        ]);

        $statuses = (new WorkerStatusProvider(new OperationsQueryReader($conn), 60))->statuses();

        self::assertCount(2, $statuses);
        self::assertContainsOnlyInstancesOf(WorkerStatus::class, $statuses);

        self::assertSame('event', $statuses[0]->workerType);
        self::assertTrue($statuses[0]->online, 'fresh heartbeat → online');
        self::assertInstanceOf(\DateTimeImmutable::class, $statuses[0]->lastHeartbeatAt);

        self::assertSame('maintenance', $statuses[1]->workerType);
        self::assertFalse($statuses[1]->online, 'stale heartbeat → offline');
    }

    public function test_boundary_age_equal_to_threshold_is_online(): void
    {
        $conn = (new ScriptedReaderConnection())->on('worker_heartbeats', [[
            'worker_id'         => '01900000-0000-7000-8000-0000000000a3',
            'worker_type'       => 'event',
            'status'            => 'idle',
            'last_heartbeat_at' => '2026-07-15 10:00:00+00',
            'age_seconds'       => '60', // exactly at threshold → online (<=)
        ]]);

        $statuses = (new WorkerStatusProvider(new OperationsQueryReader($conn), 60))->statuses();

        self::assertTrue($statuses[0]->online);
    }

    public function test_no_workers_yields_empty_list(): void
    {
        $conn = (new ScriptedReaderConnection())->on('worker_heartbeats', []);

        self::assertSame([], (new WorkerStatusProvider(new OperationsQueryReader($conn), 60))->statuses());
    }

    public function test_provider_never_writes(): void
    {
        $conn = (new ScriptedReaderConnection())->on('worker_heartbeats', []);

        (new WorkerStatusProvider(new OperationsQueryReader($conn), 60))->statuses();

        self::assertSame(0, $conn->writeAttempts);
    }
}
