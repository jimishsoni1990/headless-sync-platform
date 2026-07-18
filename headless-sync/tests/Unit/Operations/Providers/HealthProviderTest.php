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

/**
 * HealthProvider under the ADR-054 cycle model: the processing-freshness signal is
 * cycle-freshness, NOT daemon liveness. A stale heartbeat is only escalated to ERROR
 * ("processing stalled") WHILE the queue is non-empty; with an empty queue it is benign.
 */
final class HealthProviderTest extends TestCase
{
    public function test_key_is_health(): void
    {
        $provider = new HealthProvider(new OperationsQueryReader(new ScriptedReaderConnection()), 60);

        self::assertSame('health', $provider->key());
    }

    public function test_all_ok_when_cycles_fresh_and_queues_clear(): void
    {
        $conn = (new ScriptedReaderConnection())
            ->on('worker_heartbeats', [$this->cycleRow('processing', '5')])
            ->on('FROM system.dead_letter_jobs', [['c' => '0']])
            ->on("FROM system.queue_jobs WHERE status = 'available'", [['c' => '0']]);

        $by = $this->byComponent((new HealthProvider(new OperationsQueryReader($conn), 60))->reports());

        self::assertSame(Severity::OK, $by['database']->severity);
        self::assertSame(Severity::OK, $by['processing']->severity);
        self::assertSame(Severity::OK, $by['dead_letter_queue']->severity);
        self::assertSame(Severity::OK, $by['queue']->severity);
    }

    public function test_stale_cycle_is_error_only_while_queue_non_empty(): void
    {
        // A recent cycle plus a stale stage row, WITH a non-empty queue → stalled → ERROR.
        $conn = (new ScriptedReaderConnection())
            ->on('worker_heartbeats', [
                $this->cycleRow('processing', '5'),
                $this->cycleRow('maintenance', '5000'),
            ])
            ->on('FROM system.dead_letter_jobs', [['c' => '4']])
            ->on("FROM system.queue_jobs WHERE status = 'available'", [['c' => '7']]);

        $by = $this->byComponent((new HealthProvider(new OperationsQueryReader($conn), 60))->reports());

        self::assertSame(Severity::ERROR, $by['processing']->severity);
        self::assertSame(1, $by['processing']->details['stale']);
        self::assertSame(7, $by['processing']->details['queue_depth']);
        self::assertStringContainsStringIgnoringCase('stalled', $by['processing']->summary);
        self::assertSame(Severity::WARNING, $by['dead_letter_queue']->severity);
    }

    public function test_stale_cycle_is_ok_when_queue_is_empty(): void
    {
        // Same stale stage row, but the queue is empty → benign (nothing to process) → OK.
        $conn = (new ScriptedReaderConnection())
            ->on('worker_heartbeats', [
                $this->cycleRow('processing', '5'),
                $this->cycleRow('maintenance', '5000'),
            ])
            ->on('FROM system.dead_letter_jobs', [['c' => '0']])
            ->on("FROM system.queue_jobs WHERE status = 'available'", [['c' => '0']]);

        $by = $this->byComponent((new HealthProvider(new OperationsQueryReader($conn), 60))->reports());

        self::assertSame(Severity::OK, $by['processing']->severity);
    }

    public function test_no_recent_cycle_is_warning_when_idle_and_error_when_backlogged(): void
    {
        // No recent cycle + empty queue → WARNING (idle, nothing waiting).
        $idle = (new ScriptedReaderConnection())
            ->on('worker_heartbeats', [])
            ->on('FROM system.dead_letter_jobs', [['c' => '0']])
            ->on("FROM system.queue_jobs WHERE status = 'available'", [['c' => '0']]);

        $byIdle = $this->byComponent((new HealthProvider(new OperationsQueryReader($idle), 60))->reports());
        self::assertSame(Severity::WARNING, $byIdle['processing']->severity);

        // No recent cycle + non-empty queue → ERROR (stalled, work waiting).
        $backlog = (new ScriptedReaderConnection())
            ->on('worker_heartbeats', [])
            ->on('FROM system.dead_letter_jobs', [['c' => '0']])
            ->on("FROM system.queue_jobs WHERE status = 'available'", [['c' => '3']]);

        $byBacklog = $this->byComponent((new HealthProvider(new OperationsQueryReader($backlog), 60))->reports());
        self::assertSame(Severity::ERROR, $byBacklog['processing']->severity);
        self::assertStringContainsStringIgnoringCase('stalled', $byBacklog['processing']->summary);
    }

    public function test_health_never_uses_daemon_offline_wording(): void
    {
        $conn = (new ScriptedReaderConnection())
            ->on('worker_heartbeats', [$this->cycleRow('processing', '5000')])
            ->on('FROM system.dead_letter_jobs', [['c' => '0']])
            ->on("FROM system.queue_jobs WHERE status = 'available'", [['c' => '9']]);

        $by = $this->byComponent((new HealthProvider(new OperationsQueryReader($conn), 60))->reports());

        self::assertStringNotContainsStringIgnoringCase('offline', $by['processing']->summary);
        self::assertStringNotContainsStringIgnoringCase('worker', $by['processing']->summary);
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
    private function cycleRow(string $type, string $age): array
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
