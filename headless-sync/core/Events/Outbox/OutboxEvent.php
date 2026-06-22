<?php

declare(strict_types=1);

namespace HSP\Core\Events\Outbox;

use HSP\Core\Contracts\EventInterface;

/**
 * Immutable event envelope written to wp_hsp_outbox and relayed to system.events.
 *
 * The event_id (UUIDv7) is assigned at outbox write time and preserved unchanged
 * through relay to system.events (OPEN-6 v1.3 relay fidelity rules).
 */
final class OutboxEvent implements EventInterface
{
    public function __construct(
        private readonly string             $id,
        private readonly string             $eventType,
        private readonly int                $eventVersion,
        private readonly string             $aggregateType,
        private readonly string             $aggregateId,
        private readonly int                $aggregateVersion,
        private readonly array              $payload,
        private readonly string             $checksum,
        private readonly \DateTimeImmutable $sourceUpdatedAt,
        private readonly \DateTimeImmutable $createdAt,
        private readonly string             $correlationId,
        private readonly ?string            $causationId,
    ) {}

    public function getId(): string                        { return $this->id; }
    public function getEventType(): string                 { return $this->eventType; }
    public function getEventVersion(): int                 { return $this->eventVersion; }
    public function getAggregateType(): string             { return $this->aggregateType; }
    public function getAggregateId(): string               { return $this->aggregateId; }
    public function getAggregateVersion(): int             { return $this->aggregateVersion; }
    public function getPayload(): array                    { return $this->payload; }
    public function getChecksum(): string                  { return $this->checksum; }
    public function getSourceUpdatedAt(): \DateTimeImmutable { return $this->sourceUpdatedAt; }
    public function getCreatedAt(): \DateTimeImmutable      { return $this->createdAt; }
    public function getCorrelationId(): string             { return $this->correlationId; }
    public function getCausationId(): ?string              { return $this->causationId; }
}
