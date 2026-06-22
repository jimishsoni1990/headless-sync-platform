<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Events\Outbox;

use HSP\Core\Events\Outbox\OutboxWriter;
use HSP\Core\Events\Outbox\Exception\OutboxWriteException;
use HSP\Core\Contracts\AggregateVersionCounterInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OutboxWriter.
 *
 * Verifies DECISION 1 (post-commit write contract), OPEN-6 v1.3 (column mapping),
 * and error propagation — all without a real database.
 */
final class OutboxWriterTest extends TestCase
{
    private FakeWpdb $wpdb;
    private AggregateVersionCounterInterface $counter;
    private OutboxWriter $writer;

    protected function setUp(): void
    {
        $this->wpdb    = new FakeWpdb();
        $this->counter = new class implements AggregateVersionCounterInterface {
            public int $lastVersion = 5;
            public function next(string $aggregateType, string $aggregateId): int
            {
                return $this->lastVersion;
            }
        };
        $this->writer = new OutboxWriter($this->wpdb, $this->counter);
    }

    // -------------------------------------------------------------------------
    // Happy-path
    // -------------------------------------------------------------------------

    public function test_write_inserts_row_and_returns_event(): void
    {
        $sourceUpdatedAt = new \DateTimeImmutable('2026-01-15 10:00:00', new \DateTimeZone('UTC'));

        $event = $this->writer->write(
            eventType:       'content.post.created',
            eventVersion:    1,
            aggregateType:   'post',
            aggregateId:     '42',
            payload:         ['title' => 'Hello'],
            correlationId:   'corr-uuid-1234',
            causationId:     null,
            sourceUpdatedAt: $sourceUpdatedAt,
        );

        self::assertCount(1, $this->wpdb->insertCalls);
        $call = $this->wpdb->insertCalls[0];

        self::assertSame($this->wpdb->prefix . 'hsp_outbox', $call['table']);
        self::assertSame('content.post.created', $call['data']['event_type']);
        self::assertSame(1,                       $call['data']['event_version']);
        self::assertSame('post',                  $call['data']['aggregate_type']);
        self::assertSame('42',                    $call['data']['aggregate_id']);
        self::assertSame(5,                       $call['data']['aggregate_version']);
        self::assertSame('pending',               $call['data']['status']);
        self::assertNull($call['data']['relayed_at']);
        self::assertNull($call['data']['causation_id']);
    }

    public function test_write_returns_event_with_correct_fields(): void
    {
        $sourceUpdatedAt = new \DateTimeImmutable('2026-01-15 10:00:00', new \DateTimeZone('UTC'));

        $event = $this->writer->write(
            eventType:       'content.post.updated',
            eventVersion:    1,
            aggregateType:   'post',
            aggregateId:     '99',
            payload:         ['title' => 'Updated'],
            correlationId:   'corr-abc',
            causationId:     'caus-xyz',
            sourceUpdatedAt: $sourceUpdatedAt,
        );

        self::assertSame('content.post.updated', $event->getEventType());
        self::assertSame(1,                      $event->getEventVersion());
        self::assertSame('post',                 $event->getAggregateType());
        self::assertSame('99',                   $event->getAggregateId());
        self::assertSame(5,                      $event->getAggregateVersion());
        self::assertSame('corr-abc',             $event->getCorrelationId());
        self::assertSame('caus-xyz',             $event->getCausationId());
        self::assertSame(['title' => 'Updated'], $event->getPayload());
    }

    public function test_write_generates_uuidv7_event_id(): void
    {
        $event = $this->writer->write(
            eventType:       'content.page.created',
            eventVersion:    1,
            aggregateType:   'page',
            aggregateId:     '1',
            payload:         [],
            correlationId:   'corr-1',
            causationId:     null,
            sourceUpdatedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );

        $id = $event->getId();
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $id,
            'event_id must be a valid UUIDv7',
        );
    }

    public function test_write_stores_uuidv7_in_outbox_row(): void
    {
        $event = $this->writer->write(
            eventType:       'content.post.deleted',
            eventVersion:    1,
            aggregateType:   'post',
            aggregateId:     '7',
            payload:         [],
            correlationId:   'corr-1',
            causationId:     null,
            sourceUpdatedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );

        $insertedId = $this->wpdb->insertCalls[0]['data']['id'];
        self::assertSame($event->getId(), $insertedId);
    }

    public function test_write_computes_checksum_as_sha256_of_payload_json(): void
    {
        $payload = ['slug' => 'hello-world', 'status' => 'publish'];

        $event = $this->writer->write(
            eventType:       'content.post.created',
            eventVersion:    1,
            aggregateType:   'post',
            aggregateId:     '5',
            payload:         $payload,
            correlationId:   'corr-1',
            causationId:     null,
            sourceUpdatedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );

        $expectedJson     = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $expectedChecksum = hash('sha256', $expectedJson);

        self::assertSame($expectedChecksum, $event->getChecksum());
        self::assertSame($expectedChecksum, $this->wpdb->insertCalls[0]['data']['checksum']);
    }

    public function test_write_persists_source_updated_at_as_utc_string(): void
    {
        $sourceUpdatedAt = new \DateTimeImmutable('2026-03-01 14:30:00', new \DateTimeZone('UTC'));

        $this->writer->write(
            eventType:       'content.category.updated',
            eventVersion:    1,
            aggregateType:   'category',
            aggregateId:     '3',
            payload:         [],
            correlationId:   'corr-1',
            causationId:     null,
            sourceUpdatedAt: $sourceUpdatedAt,
        );

        self::assertSame('2026-03-01 14:30:00', $this->wpdb->insertCalls[0]['data']['source_updated_at']);
    }

    public function test_two_writes_produce_different_event_ids(): void
    {
        $args = [
            'eventType'       => 'content.post.created',
            'eventVersion'    => 1,
            'aggregateType'   => 'post',
            'aggregateId'     => '1',
            'payload'         => [],
            'correlationId'   => 'corr-1',
            'causationId'     => null,
            'sourceUpdatedAt' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ];

        $e1 = $this->writer->write(...$args);
        $e2 = $this->writer->write(...$args);

        self::assertNotSame($e1->getId(), $e2->getId());
    }

    // -------------------------------------------------------------------------
    // Error paths
    // -------------------------------------------------------------------------

    public function test_write_throws_on_insert_failure(): void
    {
        $this->wpdb->failNextInsert = true;

        $this->expectException(OutboxWriteException::class);

        $this->writer->write(
            eventType:       'content.post.created',
            eventVersion:    1,
            aggregateType:   'post',
            aggregateId:     '1',
            payload:         [],
            correlationId:   'corr-1',
            causationId:     null,
            sourceUpdatedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    public function test_write_propagates_counter_exception(): void
    {
        $failingCounter = new class implements AggregateVersionCounterInterface {
            public function next(string $aggregateType, string $aggregateId): int
            {
                throw new OutboxWriteException('Counter exploded');
            }
        };

        $writer = new OutboxWriter($this->wpdb, $failingCounter);

        $this->expectException(OutboxWriteException::class);
        $this->expectExceptionMessage('Counter exploded');

        $writer->write(
            eventType:       'content.post.created',
            eventVersion:    1,
            aggregateType:   'post',
            aggregateId:     '1',
            payload:         [],
            correlationId:   'corr-1',
            causationId:     null,
            sourceUpdatedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }
}
