<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Fakes;

use HSP\Core\Contracts\EventInterface;

/**
 * A minimal EventInterface for OPSC-S4 action-path tests — the shape RecordingReplayEmitter
 * returns from a synthetic re-emission. Only the fields ReplayService/OperationsActionService
 * read (id, event type, aggregate identity, aggregate version, correlation/causation) carry
 * meaningful values; the rest satisfy the contract with inert defaults. No WordPress, no DB.
 */
final class SyntheticReplayEvent implements EventInterface
{
    public function __construct(
        private readonly string $aggregateType,
        private readonly string $aggregateId,
        private readonly string $eventType,
        private readonly string $correlationId,
        private readonly string $causationId,
        private readonly string $id = 'synthetic-event-id',
        private readonly int $aggregateVersion = 99,
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getEventVersion(): int
    {
        return 1;
    }

    public function getAggregateType(): string
    {
        return $this->aggregateType;
    }

    public function getAggregateId(): string
    {
        return $this->aggregateId;
    }

    public function getAggregateVersion(): int
    {
        return $this->aggregateVersion;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return ['replay' => true];
    }

    public function getChecksum(): string
    {
        return str_repeat('0', 64);
    }

    public function getSourceUpdatedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-07-16T00:00:00+00:00');
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-07-16T00:00:00+00:00');
    }

    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }

    public function getCausationId(): ?string
    {
        return $this->causationId;
    }
}
