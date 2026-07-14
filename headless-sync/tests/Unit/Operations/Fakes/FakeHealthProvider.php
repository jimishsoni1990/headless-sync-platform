<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Fakes;

use HSP\Core\Contracts\Operations\HealthProviderInterface;
use HSP\Core\Contracts\Operations\HealthReport;
use HSP\Core\Contracts\Operations\Severity;

/**
 * In-memory HealthProvider fake. Counts reports() calls so tests can prove the Refresh
 * Coordinator invokes a provider exactly once per refresh (Doc 12 §8). No DB, no WordPress.
 */
final class FakeHealthProvider implements HealthProviderInterface
{
    public int $calls = 0;

    /** @param HealthReport[] $reports */
    public function __construct(
        private readonly string $key = 'health',
        private readonly array $reports = [],
    ) {}

    public function key(): string { return $this->key; }

    public function reports(): array
    {
        $this->calls++;

        return $this->reports === []
            ? [new HealthReport('database', Severity::OK, 'reachable')]
            : $this->reports;
    }
}
