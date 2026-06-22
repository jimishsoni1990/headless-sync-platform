<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Queue;

use HSP\Core\Contracts\EventInterface;

/**
 * Minimal EventInterface stub for queue unit tests.
 */
final class FakeEvent implements EventInterface
{
    public function __construct(
        private readonly string $id          = 'event-uuid-0001',
        private readonly string $eventType   = 'content.post.created',
    ) {}

    public function getId(): string                     { return $this->id; }
    public function getEventType(): string              { return $this->eventType; }
    public function getEventVersion(): int              { return 1; }
    public function getAggregateType(): string          { return 'post'; }
    public function getAggregateId(): string            { return '42'; }
    public function getAggregateVersion(): int          { return 1; }
    public function getPayload(): array                 { return ['title' => 'Hello']; }
    public function getChecksum(): string               { return str_repeat('a', 64); }
    public function getSourceUpdatedAt(): \DateTimeImmutable { return new \DateTimeImmutable(); }
    public function getCreatedAt(): \DateTimeImmutable  { return new \DateTimeImmutable(); }
    public function getCorrelationId(): string          { return 'corr-uuid-0001'; }
    public function getCausationId(): ?string           { return null; }
}
