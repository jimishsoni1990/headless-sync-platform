<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Providers;

use HSP\Core\Contracts\Operations\MetricSample;
use HSP\Core\Operations\Diagnostics\OperationsQueryReader;
use HSP\Core\Operations\Providers\MetricsProvider;
use HSP\Tests\Unit\Operations\Fakes\ScriptedReaderConnection;
use PHPUnit\Framework\TestCase;

final class MetricsProviderTest extends TestCase
{
    public function test_key_is_metrics(): void
    {
        $provider = new MetricsProvider(new OperationsQueryReader(new ScriptedReaderConnection()), 300);

        self::assertSame('metrics', $provider->key());
    }

    /**
     * ADR-054 §6 makes worker_count a WINDOWED reading. The regression it guards: an unwindowed
     * count is a tally of cycle executions (each cycle mints a fresh UUIDv7 — DECISION X (1)), so
     * it climbs for the life of the install; a single-site console reported "22 workers" after 22
     * cycles. Reading it off workerHeartbeats() would be worse still — that is the capped display
     * read, so it would report the cap.
     */
    public function test_worker_count_is_the_windowed_heartbeat_count_not_the_display_read(): void
    {
        $displayRows = array_fill(0, 25, $this->workerRow());

        $conn = (new ScriptedReaderConnection())
            ->on('recent_heartbeats', [['recent_heartbeats' => '3']])
            ->on('GROUP BY worker_type', [['worker_type' => 'processing', 'c' => '6']])
            ->on('AVG(EXTRACT', [['avg_secs' => '1.5']])
            ->on('worker_heartbeats', $displayRows);

        $by = $this->byName((new MetricsProvider(new OperationsQueryReader($conn), 300, 60))->samples());

        self::assertSame(3, $by['worker_count']->value, 'the windowed count, not the 25 display rows');
    }

    public function test_samples_are_derived_point_in_time(): void
    {
        $conn = (new ScriptedReaderConnection())
            ->on("FROM system.queue_jobs WHERE status = 'available'", [['c' => '5']])
            ->on('FROM system.dead_letter_jobs WHERE replayed_at IS NULL', [['c' => '2']])
            ->on('FROM system.dead_letter_jobs WHERE replayed_at IS NOT NULL', [['c' => '3']])
            ->on('FROM system.dead_letter_jobs', [['c' => '5']])
            // Cycle metrics (matched before the plain worker_heartbeats needle):
            ->on('recent_heartbeats', [['recent_heartbeats' => '2']])
            ->on('GROUP BY worker_type', [['worker_type' => 'processing', 'c' => '6']])
            ->on('AVG(EXTRACT', [['avg_secs' => '1.5']])
            ->on('worker_heartbeats', [$this->workerRow(), $this->workerRow()])
            ->on("status = 'completed'", [['c' => '10']])
            ->on('MIN(available_at)', [['age' => '12.0']])
            ->on('HAVING MAX', [['c' => '1']]);

        $samples = (new MetricsProvider(new OperationsQueryReader($conn), 300))->samples();
        $by = $this->byName($samples);

        self::assertContainsOnlyInstancesOf(MetricSample::class, $samples);
        self::assertSame(5, $by['queue_depth']->value);
        self::assertSame(5, $by['dlq_depth']->value);
        // ADR-054 §6: rows that heartbeated within the FRESHNESS window, counted in the database.
        // Deliberately not derived from workerHeartbeats() — that is the console's display read
        // (newest-first, capped), so its length would report the cap rather than the population.
        self::assertSame(2, $by['worker_count']->value);
        self::assertSame('cycles', $by['worker_count']->unit);
        self::assertSame(2, $by['replay_pending']->value);
        self::assertSame(3, $by['replay_completed']->value);
        self::assertSame(1, $by['reconciliation_backlog']->value);
        // processing_rate = 10 completed over a 300s window → 2.0/min.
        self::assertEqualsWithDelta(2.0, $by['processing_rate']->value, 0.001);
        self::assertSame('per_minute', $by['processing_rate']->unit);
        // oldest_pending_age present because the queue is non-empty.
        self::assertArrayHasKey('oldest_pending_age', $by);
        self::assertEqualsWithDelta(12.0, $by['oldest_pending_age']->value, 0.001);

        // ADR-054 §17/§27 cycle metrics, derived from the per-cycle heartbeat rows.
        self::assertSame(6, $by['cycles_completed']->value);
        self::assertSame('cycles', $by['cycles_completed']->unit);
        self::assertEqualsWithDelta(1.5, $by['avg_cycle_duration']->value, 0.001);
        self::assertSame('seconds', $by['avg_cycle_duration']->unit);
        // per_stage_throughput.processing = 6 cycles over 300s (=5 min) → 1.2/min.
        self::assertArrayHasKey('per_stage_throughput.processing', $by);
        self::assertEqualsWithDelta(1.2, $by['per_stage_throughput.processing']->value, 0.001);
        self::assertSame('per_minute', $by['per_stage_throughput.processing']->unit);
        // Daemon metrics never appear (ADR-054 §6).
        self::assertArrayNotHasKey('worker_uptime', $by);
        self::assertArrayNotHasKey('restart_count', $by);
    }

    public function test_oldest_pending_age_sample_is_omitted_when_queue_empty(): void
    {
        $conn = (new ScriptedReaderConnection())
            ->on("FROM system.queue_jobs WHERE status = 'available'", [['c' => '0']])
            ->on('FROM system.dead_letter_jobs WHERE replayed_at IS NULL', [['c' => '0']])
            ->on('FROM system.dead_letter_jobs WHERE replayed_at IS NOT NULL', [['c' => '0']])
            ->on('FROM system.dead_letter_jobs', [['c' => '0']])
            ->on('worker_heartbeats', [])
            ->on("status = 'completed'", [['c' => '0']])
            ->on('MIN(available_at)', [['age' => null]])
            ->on('HAVING MAX', [['c' => '0']]);

        $by = $this->byName((new MetricsProvider(new OperationsQueryReader($conn), 300))->samples());

        self::assertArrayNotHasKey('oldest_pending_age', $by);
        self::assertSame(0, $by['queue_depth']->value);
    }

    public function test_zero_window_yields_zero_processing_rate(): void
    {
        $conn = (new ScriptedReaderConnection())
            ->on('queue_jobs', [['c' => '0', 'age' => null]])
            ->on('dead_letter_jobs', [['c' => '0']])
            ->on('worker_heartbeats', [])
            ->on('HAVING MAX', [['c' => '0']]);

        $by = $this->byName((new MetricsProvider(new OperationsQueryReader($conn), 0))->samples());

        self::assertEqualsWithDelta(0.0, $by['processing_rate']->value, 0.0001);
        // A zero window yields zero cycles and no avg/per-stage samples (nothing to average).
        self::assertSame(0, $by['cycles_completed']->value);
        self::assertArrayNotHasKey('avg_cycle_duration', $by);
    }

    /** @return array<string,string> a full worker_heartbeats row as the reader SELECTs it. */
    private function workerRow(): array
    {
        return [
            'worker_id'         => '01900000-0000-7000-8000-0000000000c1',
            'worker_type'       => 'event',
            'status'            => 'idle',
            'last_heartbeat_at' => '2026-07-15 10:00:00+00',
            'age_seconds'       => '1',
        ];
    }

    /**
     * @param MetricSample[] $samples
     * @return array<string,MetricSample>
     */
    private function byName(array $samples): array
    {
        $out = [];
        foreach ($samples as $s) {
            $out[$s->name] = $s;
        }

        return $out;
    }
}
