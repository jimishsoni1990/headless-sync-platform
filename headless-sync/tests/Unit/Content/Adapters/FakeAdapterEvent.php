<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Adapters;

use HSP\Core\Contracts\EventInterface;

/**
 * Configurable EventInterface stub for adapter unit tests.
 */
final class FakeAdapterEvent implements EventInterface
{
    public function __construct(
        private readonly string $id               = '01900000-0000-7000-8000-000000000001',
        private readonly string $eventType        = 'content.page.created',
        private readonly string $aggregateType    = 'page',
        private readonly string $aggregateId      = '1',
        private readonly int    $aggregateVersion = 1,
    ) {}

    public function getId(): string                         { return $this->id; }
    public function getEventType(): string                  { return $this->eventType; }
    public function getEventVersion(): int                  { return 1; }
    public function getAggregateType(): string              { return $this->aggregateType; }
    public function getAggregateId(): string                { return $this->aggregateId; }
    public function getAggregateVersion(): int              { return $this->aggregateVersion; }
    public function getPayload(): array                     { return []; }
    public function getChecksum(): string                   { return str_repeat('e', 64); }
    public function getSourceUpdatedAt(): \DateTimeImmutable { return new \DateTimeImmutable('2024-01-01T00:00:00Z'); }
    public function getCreatedAt(): \DateTimeImmutable      { return new \DateTimeImmutable('2024-01-01T00:00:00Z'); }
    public function getCorrelationId(): string              { return '01900000-0000-7000-8000-000000000002'; }
    public function getCausationId(): ?string               { return null; }
}
