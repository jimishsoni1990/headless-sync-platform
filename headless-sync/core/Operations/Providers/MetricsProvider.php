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
 *   worker_count             — current-state heartbeat rows now (DECISION P).
 *   oldest_pending_age       — age of the oldest available job now (seconds); omitted when empty.
 *   processing_rate          — jobs completed in the trailing window, per minute.
 *   replay_pending           — DLQ rows not yet replayed (replayed_at IS NULL) now.
 *   replay_completed         — DLQ rows already replayed now.
 *   reconciliation_backlog   — aggregates captured/relayed but not yet projected now.
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
            new MetricSample('worker_count', count($this->reader->workerHeartbeats()), 'workers'),
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

        return $samples;
    }
}
