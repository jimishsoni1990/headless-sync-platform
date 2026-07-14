<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Fakes;

use HSP\Core\Contracts\Operations\QueueStatus;
use HSP\Core\Contracts\Operations\QueueStatusProviderInterface;

/**
 * In-memory QueueStatusProvider fake (current-state depths — DECISION Q). No persistence.
 */
final class FakeQueueStatusProvider implements QueueStatusProviderInterface
{
    public int $calls = 0;

    public function __construct(private readonly string $key = 'queue') {}

    public function key(): string { return $this->key; }

    public function status(): QueueStatus
    {
        $this->calls++;

        return new QueueStatus(3, 1, null);
    }
}
