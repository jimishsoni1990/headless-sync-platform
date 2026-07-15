<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Providers;

use HSP\Core\Contracts\Operations\QueueStatus;
use HSP\Core\Contracts\Operations\QueueStatusProviderInterface;
use HSP\Core\Operations\Diagnostics\OperationsQueryReader;
use HSP\Core\Operations\Providers\QueueStatusProvider;
use HSP\Tests\Unit\Operations\Fakes\ScriptedReaderConnection;
use PHPUnit\Framework\TestCase;

final class QueueStatusProviderTest extends TestCase
{
    public function test_key_is_queue(): void
    {
        $provider = new QueueStatusProvider(new OperationsQueryReader(new ScriptedReaderConnection()));

        self::assertSame('queue', $provider->key());
        self::assertInstanceOf(QueueStatusProviderInterface::class, $provider);
    }

    public function test_status_reports_depths_and_oldest_pending_age(): void
    {
        $conn = (new ScriptedReaderConnection())
            ->on("FROM system.queue_jobs WHERE status = 'available'", [['c' => '4']])
            ->on('FROM system.dead_letter_jobs', [['c' => '2']])
            ->on('MIN(available_at)', [['age' => '90.4']]);

        $status = (new QueueStatusProvider(new OperationsQueryReader($conn)))->status();

        self::assertInstanceOf(QueueStatus::class, $status);
        self::assertSame(4, $status->depth);
        self::assertSame(2, $status->deadLetterDepth);
        self::assertNotNull($status->oldestPendingAge);
        // 90.4s rounds to 90 whole seconds (PT1M30S).
        self::assertSame(90, $this->intervalSeconds($status->oldestPendingAge));
    }

    public function test_empty_queue_yields_null_oldest_pending_age(): void
    {
        $conn = (new ScriptedReaderConnection())
            ->on("FROM system.queue_jobs WHERE status = 'available'", [['c' => '0']])
            ->on('FROM system.dead_letter_jobs', [['c' => '0']])
            ->on('MIN(available_at)', [['age' => null]]);

        $status = (new QueueStatusProvider(new OperationsQueryReader($conn)))->status();

        self::assertSame(0, $status->depth);
        self::assertSame(0, $status->deadLetterDepth);
        self::assertNull($status->oldestPendingAge);
    }

    public function test_provider_never_writes(): void
    {
        $conn = (new ScriptedReaderConnection())
            ->on('queue_jobs', [['c' => '0', 'age' => null]])
            ->on('dead_letter_jobs', [['c' => '0']]);

        (new QueueStatusProvider(new OperationsQueryReader($conn)))->status();

        self::assertSame(0, $conn->writeAttempts, 'read-only provider issued no DML');
    }

    private function intervalSeconds(\DateInterval $i): int
    {
        return ($i->days ? $i->days * 86400 : 0)
            + $i->h * 3600 + $i->i * 60 + $i->s;
    }
}
