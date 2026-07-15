<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Providers;

use HSP\Core\Contracts\Operations\HealthReport;
use HSP\Core\Contracts\Operations\Severity;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Database\Exception\DatabaseException;
use HSP\Core\Operations\Diagnostics\OperationsQueryReader;
use HSP\Core\Operations\Providers\HealthProvider;
use HSP\Tests\Unit\Operations\Fakes\ScriptedReaderConnection;
use PHPUnit\Framework\TestCase;

final class HealthProviderTest extends TestCase
{
    public function test_key_is_health(): void
    {
        $provider = new HealthProvider(new OperationsQueryReader(new ScriptedReaderConnection()), 60);

        self::assertSame('health', $provider->key());
    }

    public function test_all_ok_when_workers_fresh_and_queues_clear(): void
    {
        $conn = (new ScriptedReaderConnection())
            ->on('worker_heartbeats', [$this->workerRow('event', '5')])
            ->on('FROM system.dead_letter_jobs', [['c' => '0']])
            ->on("FROM system.queue_jobs WHERE status = 'available'", [['c' => '0']]);

        $by = $this->byComponent((new HealthProvider(new OperationsQueryReader($conn), 60))->reports());

        self::assertSame(Severity::OK, $by['database']->severity);
        self::assertSame(Severity::OK, $by['workers']->severity);
        self::assertSame(Severity::OK, $by['dead_letter_queue']->severity);
        self::assertSame(Severity::OK, $by['queue']->severity);
    }

    public function test_offline_worker_is_error_and_dlq_backlog_is_warning(): void
    {
        $conn = (new ScriptedReaderConnection())
            ->on('worker_heartbeats', [
                $this->workerRow('event', '5'),
                $this->workerRow('maintenance', '5000'),
            ])
            ->on('FROM system.dead_letter_jobs', [['c' => '4']])
            ->on("FROM system.queue_jobs WHERE status = 'available'", [['c' => '7']]);

        $by = $this->byComponent((new HealthProvider(new OperationsQueryReader($conn), 60))->reports());

        self::assertSame(Severity::ERROR, $by['workers']->severity);
        self::assertSame(1, $by['workers']->details['offline']);
        self::assertSame(Severity::WARNING, $by['dead_letter_queue']->severity);
        self::assertSame(4, $by['dead_letter_queue']->details['depth']);
    }

    public function test_no_workers_is_warning(): void
    {
        $conn = (new ScriptedReaderConnection())
            ->on('worker_heartbeats', [])
            ->on('FROM system.dead_letter_jobs', [['c' => '0']])
            ->on("FROM system.queue_jobs WHERE status = 'available'", [['c' => '0']]);

        $by = $this->byComponent((new HealthProvider(new OperationsQueryReader($conn), 60))->reports());

        self::assertSame(Severity::WARNING, $by['workers']->severity);
    }

    public function test_unreachable_database_yields_single_critical_report(): void
    {
        $conn = new class implements DatabaseConnectionInterface {
            public function query(string $sql, array $params = []): array
            {
                throw new DatabaseException('connection refused');
            }
            public function execute(string $sql, array $params = []): int { return 0; }
            public function beginTransaction(): void {}
            public function commit(): void {}
            public function rollback(): void {}
        };

        $reports = (new HealthProvider(new OperationsQueryReader($conn), 60))->reports();

        self::assertCount(1, $reports);
        self::assertInstanceOf(HealthReport::class, $reports[0]);
        self::assertSame('database', $reports[0]->component);
        self::assertSame(Severity::CRITICAL, $reports[0]->severity);
    }

    /** @return array<string,string> a full worker_heartbeats row as the reader SELECTs it. */
    private function workerRow(string $type, string $age): array
    {
        return [
            'worker_id'         => '01900000-0000-7000-8000-0000000000b1',
            'worker_type'       => $type,
            'status'            => 'idle',
            'last_heartbeat_at' => '2026-07-15 10:00:00+00',
            'age_seconds'       => $age,
        ];
    }

    /**
     * @param HealthReport[] $reports
     * @return array<string,HealthReport>
     */
    private function byComponent(array $reports): array
    {
        $out = [];
        foreach ($reports as $r) {
            $out[$r->component] = $r;
        }

        return $out;
    }
}
