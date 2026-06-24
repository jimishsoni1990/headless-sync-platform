<?php

declare(strict_types=1);

namespace HSP\Core\Events\Dispatcher;

/**
 * Value object representing one batch of undispatched system.events rows
 * selected during a single Dispatcher tick.
 *
 * Each element is an associative array with at minimum:
 *   id         string  — system.events.id (UUID) used as event_id in queue_jobs
 *   event_type string  — fully-qualified <domain>.<aggregate>.<action> (OPEN-1)
 *
 * Authority: DECISION L (v1.12, 2026-06-25)
 */
final class DispatchBatch
{
    /** @param array<int, array<string, mixed>> $rows */
    public function __construct(private readonly array $rows) {}

    /** @return array<int, array<string, mixed>> */
    public function getRows(): array
    {
        return $this->rows;
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    public function count(): int
    {
        return count($this->rows);
    }
}
