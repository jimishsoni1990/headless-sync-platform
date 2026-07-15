<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Diagnostics;

use HSP\Core\Operations\Diagnostics\OperationsQueryReader;
use HSP\Tests\Unit\Operations\Fakes\ScriptedReaderConnection;
use PHPUnit\Framework\TestCase;

final class OperationsQueryReaderTest extends TestCase
{
    public function test_queue_dlq_and_oldest_pending_reads(): void
    {
        $conn = (new ScriptedReaderConnection())
            ->on("FROM system.queue_jobs WHERE status = 'available'", [['c' => '9']])
            ->on('FROM system.dead_letter_jobs', [['c' => '3']])
            ->on('MIN(available_at)', [['age' => '42.5']]);

        $reader = new OperationsQueryReader($conn);

        self::assertSame(9, $reader->queueDepth());
        self::assertSame(3, $reader->deadLetterDepth());
        self::assertSame(42.5, $reader->oldestPendingAgeSeconds());
    }

    public function test_oldest_pending_null_when_empty(): void
    {
        $conn = (new ScriptedReaderConnection())->on('MIN(available_at)', [['age' => null]]);

        self::assertNull((new OperationsQueryReader($conn))->oldestPendingAgeSeconds());
    }

    public function test_worker_heartbeats_normalises_rows(): void
    {
        $conn = (new ScriptedReaderConnection())->on('worker_heartbeats', [[
            'worker_id'         => 'w1',
            'worker_type'       => 'event',
            'status'            => 'idle',
            'last_heartbeat_at' => '2026-07-15 10:00:00+00',
            'age_seconds'       => '7.0',
        ]]);

        $rows = (new OperationsQueryReader($conn))->workerHeartbeats();

        self::assertCount(1, $rows);
        self::assertSame('event', $rows[0]['worker_type']);
        self::assertSame(7.0, $rows[0]['age_seconds']);
    }

    public function test_processing_rate_zero_for_non_positive_window(): void
    {
        $reader = new OperationsQueryReader(new ScriptedReaderConnection());

        self::assertSame(0.0, $reader->processingRatePerMinute(0));
        self::assertSame(0.0, $reader->processingRatePerMinute(-5));
    }

    public function test_processing_rate_is_completed_over_window_per_minute(): void
    {
        $conn = (new ScriptedReaderConnection())->on("status = 'completed'", [['c' => '30']]);

        // 30 completed over 600s (10 min) → 3.0/min.
        self::assertEqualsWithDelta(3.0, (new OperationsQueryReader($conn))->processingRatePerMinute(600), 0.0001);
    }

    public function test_module_versions_map_and_migration_state(): void
    {
        $conn = (new ScriptedReaderConnection())
            ->on('FROM   system.module_versions', [
                ['module_name' => 'content', 'schema_version' => '1.0.0'],
            ])
            ->on('COUNT(*) AS c FROM system.schema_versions', [['c' => '13']])
            ->on('SELECT migration_name', [['migration_name' => '0013_add_replayed_at_to_dead_letter_jobs']]);

        $reader = new OperationsQueryReader($conn);

        self::assertSame(['content' => '1.0.0'], $reader->moduleVersions());
        $migration = $reader->migrationState();
        self::assertSame(13, $migration['applied_count']);
        self::assertSame('0013_add_replayed_at_to_dead_letter_jobs', $migration['latest']);
    }

    public function test_reader_issues_no_writes(): void
    {
        $conn = (new ScriptedReaderConnection())->on('anything', []);
        $reader = new OperationsQueryReader($conn);

        $reader->queueDepth();
        $reader->deadLetterDepth();
        $reader->workerHeartbeats();
        $reader->replayedCount();
        $reader->pendingReplayCount();
        $reader->unprocessedAggregateCount();
        $reader->moduleVersions();
        $reader->migrationState();

        self::assertSame(0, $conn->writeAttempts, 'reader is read-only (no DML)');
    }
}
