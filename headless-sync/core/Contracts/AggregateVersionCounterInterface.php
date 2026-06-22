<?php

declare(strict_types=1);

namespace HSP\Core\Contracts;

/**
 * Atomically increments and returns the per-aggregate version counter.
 *
 * DECISION 2 v1.1: counter lives in wp_hsp_aggregate_counters; increment is a single
 * SQL round-trip via INSERT … ON DUPLICATE KEY UPDATE version = LAST_INSERT_ID(version + 1).
 * Application-layer read-modify-write is prohibited — it cannot guarantee uniqueness.
 */
interface AggregateVersionCounterInterface
{
    /**
     * Increment and return the next version number for the given aggregate.
     *
     * Returns 1 on first call for a new aggregate, N+1 on subsequent calls.
     *
     * @throws \HSP\Core\Events\Outbox\Exception\OutboxWriteException
     */
    public function next(string $aggregateType, string $aggregateId): int;
}
