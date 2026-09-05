<?php

declare(strict_types=1);

namespace HSP\Core\Operations\Providers;

use HSP\Core\Contracts\Operations\MetricSample;
use HSP\Core\Contracts\Operations\MetricsProviderInterface;
use HSP\Core\Operations\Diagnostics\OperationsQueryReader;

/**
 * Derived-on-demand metrics provider (OPSC-S2; DECISION Q / DECISION V (c)).
 *
 * Every sample is computed at read time from existing operational data via the delivery-handle
 * reader (DECISION V (g)). ZERO new persistence: no metrics table, no rollups, no time-series
 * store. Doc 12 §12's "Processing Rate / Replay Progress / Reconciliation Status" tiles are
 * point-in-time derivations here, not persisted progress surfaces (DECISION V (c)).
 *
 * Sample set (all point-in-time):
 *   queue_depth              — available/claimable jobs now.
 *   dlq_depth                — dead-lettered rows now (permanent audit rows — DECISION S).
 *   worker_count             — distinct processing STAGES that heartbeated in the trailing window
 *                              (ADR-054 §6: components that are live now, NOT daemons and NOT a
 *                              tally of cycles — DECISION P/X). Cycles run is cycles_completed.
 *   oldest_pending_age       — age of the oldest available job now (seconds); omitted when empty.
 *   processing_rate          — jobs completed in the trailing window, per minute.
 *   replay_pending           — DLQ rows not yet replayed (replayed_at IS NULL) now.
 *   replay_completed         — DLQ rows already replayed now.
 *   reconciliation_backlog   — aggregates captured/relayed but not yet projected now.
 *
 * Cycle metrics (ADR-054 §17/§27; derived on demand from the fresh-UUID-per-cycle heartbeat
 * rows — DECISION X ruling (1) cardinality; ZERO persistence — DECISION Q):
 *   cycles_completed              — processing cycles that heartbeated in the trailing window.
 *   avg_cycle_duration            — mean last_heartbeat_at−started_at over those cycles (seconds);
 *                                   omitted when no cycle ran in the window.
 *   per_stage_throughput.<type>   — cycles-per-minute for each stage (worker_type) that ran in
 *                                   the window (one sample per stage; MetricSample values are
 *                                   scalar, so a stage map is emitted as one sample per stage).
 *                                   These SUPERSEDE the removed daemon metrics
 *                                   worker_uptime/restart_count (ADR-054 §6 — already absent).
 */
final class MetricsProvider implements MetricsProviderInterface
{
    public const KEY = 'metrics';

    public function __construct(
        private readonly OperationsQueryReader $reader,
        private readonly int $processingRateWindowSeconds,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    /** @return MetricSample[] */
    public function samples(): array
    {
        $samples = [
            new MetricSample('queue_depth', $this->reader->queueDepth(), 'jobs'),
            new MetricSample('dlq_depth', $this->reader->deadLetterDepth(), 'jobs'),
            // Distinct processing STAGES that heartbeated in the window — not a row count.
            // workerHeartbeats() is the console's display read: it is ordered newest-first and
            // capped, so counting it would report the cap, and before that cap it counted every
            // cycle ever run (one row per cycle under ADR-054 — DECISION X (1)), which is how a
            // single-site install came to report "22 workers". cyclesCompletedByType() is already
            // windowed and keyed by worker_type, so its key count is the live-component count with
            // no extra query. How many cycles ran stays the separate cycles_completed sample.
            new MetricSample(
                'worker_count',
                count($this->reader->cyclesCompletedByType($this->processingRateWindowSeconds)),
                'stages',
            ),
            new MetricSample(
                'processing_rate',
                round($this->reader->processingRatePerMinute($this->processingRateWindowSeconds), 3),
                'per_minute',
            ),
            new MetricSample('replay_pending', $this->reader->pendingReplayCount(), 'jobs'),
            new MetricSample('replay_completed', $this->reader->replayedCount(), 'jobs'),
            new MetricSample('reconciliation_backlog', $this->reader->unprocessedAggregateCount(), 'aggregates'),
        ];

        // Oldest-pending age is only meaningful when the queue holds an available job.
        $oldest = $this->reader->oldestPendingAgeSeconds();
        if ($oldest !== null) {
            $samples[] = new MetricSample('oldest_pending_age', round($oldest, 3), 'seconds');
        }

        // ADR-054 §17/§27 cycle metrics — derived on demand from the per-cycle heartbeat rows
        // (fresh UUID per cycle → each recent row is one cycle execution; DECISION X / Q).
        foreach ($this->cycleMetrics() as $sample) {
            $samples[] = $sample;
        }

        return $samples;
    }

    /**
     * The ADR-054 §17/§27 cycle metrics, all derived on demand from system.worker_heartbeats
     * over the same trailing window as processing_rate (config-driven; nothing hardcoded).
     *
     * @return list<MetricSample>
     */
    private function cycleMetrics(): array
    {
        $window        = $this->processingRateWindowSeconds;
        $windowMinutes = $window > 0 ? $window / 60.0 : 0.0;

        $byType         = $this->reader->cyclesCompletedByType($window);
        $cyclesTotal    = array_sum($byType);

        $out = [
            new MetricSample('cycles_completed', $cyclesTotal, 'cycles'),
        ];

        // avg_cycle_duration is only meaningful when at least one cycle ran in the window.
        $avg = $this->reader->averageCycleDurationSeconds($window);
        if ($avg !== null) {
            $out[] = new MetricSample('avg_cycle_duration', round($avg, 3), 'seconds');
        }

        // per_stage_throughput.<worker_type> = cycles-per-minute for that stage. One scalar
        // sample per stage (MetricSample holds a scalar, not a map).
        if ($windowMinutes > 0.0) {
            foreach ($byType as $type => $count) {
                $out[] = new MetricSample(
                    'per_stage_throughput.' . $type,
                    round($count / $windowMinutes, 3),
                    'per_minute',
                );
            }
        }

        return $out;
    }
}
