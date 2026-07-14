<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Operations;

/**
 * Immutable, derived-on-demand metric sample from a MetricsProviderInterface.
 *
 * DECISION Q / DECISION V (c): all console metrics are derived on-demand from existing
 * operational data — ZERO new persistence. A sample is a point-in-time value computed at
 * read time (e.g. queue depth, worker count, processing rate over a rolling window). It is
 * NOT a stored time-series point; there is no metrics table, no rollup, no history.
 *
 * Fields:
 *   $name   — metric identifier (e.g. 'queue_depth', 'worker_count', 'processing_rate').
 *   $value  — the derived numeric value at read time.
 *   $unit   — optional display unit (e.g. 'jobs', 'per_minute'); null when dimensionless.
 *
 * @psalm-immutable
 */
final class MetricSample
{
    public function __construct(
        public readonly string $name,
        public readonly int|float $value,
        public readonly ?string $unit = null,
    ) {}
}
