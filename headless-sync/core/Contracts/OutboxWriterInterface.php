<?php

declare(strict_types=1);

namespace HSP\Core\Contracts;

/**
 * Writes a domain event to wp_hsp_outbox immediately after the WordPress commit.
 *
 * DECISION 1: post-commit write only — never inside a WordPress transaction.
 * The event_id (UUIDv7) is born here and preserved unchanged through relay.
 */
interface OutboxWriterInterface
{
    /**
     * Build and persist a new outbox row.
     *
     * @param array<string, mixed> $payload
     * @throws \HSP\Core\Events\Outbox\Exception\OutboxWriteException
     */
    public function write(
        string $eventType,
        int    $eventVersion,
        string $aggregateType,
        string $aggregateId,
        array  $payload,
        string $correlationId,
        ?string $causationId,
        \DateTimeImmutable $sourceUpdatedAt,
    ): EventInterface;
}
