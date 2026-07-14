<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Fakes;

use HSP\Core\Contracts\Operations\WorkerStatus;
use HSP\Core\Contracts\Operations\WorkerStatusProviderInterface;

/**
 * In-memory WorkerStatusProvider fake (current-state, read-only — DECISION P / V (f)).
 */
final class FakeWorkerStatusProvider implements WorkerStatusProviderInterface
{
    public int $calls = 0;

    public function __construct(private readonly string $key = 'workers') {}

    public function key(): string { return $this->key; }

    public function statuses(): array
    {
        $this->calls++;

        return [new WorkerStatus('worker-uuid', 'event', true, new \DateTimeImmutable('2026-07-15T00:00:00Z'))];
    }
}
