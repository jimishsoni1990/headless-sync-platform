<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content;

use HSP\Core\Contracts\EventInterface;
use HSP\Core\Contracts\OutboxWriterInterface;
use HSP\Core\Events\Outbox\OutboxEvent;

/**
 * Test double for OutboxWriterInterface.
 *
 * Records every write() call so tests can assert on event type, aggregate,
 * payload, and ordering without a real database.
 */
final class FakeOutboxWriter implements OutboxWriterInterface
{
    /** @var array<int, array{eventType: string, eventVersion: int, aggregateType: string, aggregateId: string, payload: array, correlationId: string, causationId: ?string, sourceUpdatedAt: \DateTimeImmutable}> */
    public array $writes = [];

    public function write(
        string             $eventType,
        int                $eventVersion,
        string             $aggregateType,
        string             $aggregateId,
        array              $payload,
        string             $correlationId,
        ?string            $causationId,
        \DateTimeImmutable $sourceUpdatedAt,
    ): EventInterface {
        $this->writes[] = [
            'eventType'       => $eventType,
            'eventVersion'    => $eventVersion,
            'aggregateType'   => $aggregateType,
            'aggregateId'     => $aggregateId,
            'payload'         => $payload,
            'correlationId'   => $correlationId,
            'causationId'     => $causationId,
            'sourceUpdatedAt' => $sourceUpdatedAt,
        ];

        return new OutboxEvent(
            id:               'fake-uuid-' . count($this->writes),
            eventType:        $eventType,
            eventVersion:     $eventVersion,
            aggregateType:    $aggregateType,
            aggregateId:      $aggregateId,
            aggregateVersion: count($this->writes),
            payload:          $payload,
            checksum:         hash('sha256', json_encode($payload)),
            sourceUpdatedAt:  $sourceUpdatedAt,
            createdAt:        new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            correlationId:    $correlationId,
            causationId:      $causationId,
        );
    }

    public function lastWrite(): array
    {
        return end($this->writes) ?: [];
    }

    public function writeCount(): int
    {
        return count($this->writes);
    }
}
