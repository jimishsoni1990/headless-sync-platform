<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Fakes;

use HSP\Core\Contracts\Operations\MetricSample;
use HSP\Core\Contracts\Operations\MetricsProviderInterface;

/**
 * In-memory MetricsProvider fake (derived-on-demand samples — DECISION Q). No persistence.
 */
final class FakeMetricsProvider implements MetricsProviderInterface
{
    public int $calls = 0;

    public function __construct(private readonly string $key = 'metrics') {}

    public function key(): string { return $this->key; }

    public function samples(): array
    {
        $this->calls++;

        return [new MetricSample('queue_depth', 7, 'jobs')];
    }
}
