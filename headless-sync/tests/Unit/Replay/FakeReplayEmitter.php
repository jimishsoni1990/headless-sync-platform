<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Replay;

use HSP\Core\Contracts\EventInterface;
use HSP\Core\Contracts\ReplayEmitterInterface;
use HSP\Core\Events\Outbox\OutboxEvent;

/**
 * Test double for ReplayEmitterInterface.
 *
 * Records each emitForAggregate() call and returns a synthetic OutboxEvent whose
 * aggregate_version increments per call (mimicking a fresh counter version).
 */
final class FakeReplayEmitter implements ReplayEmitterInterface
{
    /** @var array<int, array{type:string,id:string,correlationId:string,causationId:string}> */
    public array $calls = [];

    /** @param string[] $supportedTypes */
    public function __construct(
        private readonly array $supportedTypes = ['page', 'post', 'category'],
    ) {}

    public function getSupportedAggregateTypes(): array
    {
        return $this->supportedTypes;
    }

    public function emitForAggregate(
        string $aggregateType,
        string $aggregateId,
        string $correlationId,
        string $causationId,
    ): EventInterface {
        $this->calls[] = [
            'type'          => $aggregateType,
            'id'            => $aggregateId,
            'correlationId' => $correlationId,
            'causationId'   => $causationId,
        ];

        $n = count($this->calls);

        return new OutboxEvent(
            id:               'evt-' . $n,
            eventType:        "content.{$aggregateType}.updated",
            eventVersion:     1,
            aggregateType:    $aggregateType,
            aggregateId:      $aggregateId,
            aggregateVersion: $n,
            payload:          ['replay' => true],
            checksum:         str_repeat('0', 64),
            sourceUpdatedAt:  new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            createdAt:        new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            correlationId:    $correlationId,
            causationId:      $causationId,
        );
    }
}
