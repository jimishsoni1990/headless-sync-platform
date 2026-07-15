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

    public function test_samples_are_derived_point_in_time(): void
    {
        $conn = (new ScriptedReaderConnection())
            ->on("FROM system.queue_jobs WHERE status = 'available'", [['c' => '5']])
            ->on('FROM system.dead_letter_jobs WHERE replayed_at IS NULL', [['c' => '2']])
            ->on('FROM system.dead_letter_jobs WHERE replayed_at IS NOT NULL', [['c' => '3']])
            ->on('FROM system.dead_letter_jobs', [['c' => '5']])
            ->on('worker_heartbeats', [$this->workerRow(), $this->workerRow()])
            ->on("status = 'completed'", [['c' => '10']])
            ->on('MIN(available_at)', [['age' => '12.0']])
            ->on('HAVING MAX', [['c' => '1']]);

        $samples = (new MetricsProvider(new OperationsQueryReader($conn), 300))->samples();
        $by = $this->byName($samples);

        self::assertContainsOnlyInstancesOf(MetricSample::class, $samples);
        self::assertSame(5, $by['queue_depth']->value);
        self::assertSame(5, $by['dlq_depth']->value);
        self::assertSame(2, $by['worker_count']->value);
        self::assertSame(2, $by['replay_pending']->value);
        self::assertSame(3, $by['replay_completed']->value);
        self::assertSame(1, $by['reconciliation_backlog']->value);
        // processing_rate = 10 completed over a 300s window → 2.0/min.
        self::assertEqualsWithDelta(2.0, $by['processing_rate']->value, 0.001);
        self::assertSame('per_minute', $by['processing_rate']->unit);
        // oldest_pending_age present because the queue is non-empty.
        self::assertArrayHasKey('oldest_pending_age', $by);
        self::assertEqualsWithDelta(12.0, $by['oldest_pending_age']->value, 0.001);
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
