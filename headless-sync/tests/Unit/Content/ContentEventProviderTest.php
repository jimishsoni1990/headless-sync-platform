<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content;

use HSP\Modules\Content\EventProvider;
use HSP\Modules\Content\Events\ContentEventTypes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ContentEventProvider.
 *
 * Verifies: supported event types match OPEN-1 canon; provide() delegates
 * to OutboxWriterInterface with correct aggregate type + event version;
 * unsupported types are rejected.
 */
final class ContentEventProviderTest extends TestCase
{
    private FakeOutboxWriter $writer;
    private EventProvider $provider;

    protected function setUp(): void
    {
        $this->writer   = new FakeOutboxWriter();
        $this->provider = new EventProvider($this->writer);
    }

    // -------------------------------------------------------------------------
    // getSupportedEventTypes()
    // -------------------------------------------------------------------------

    public function test_supported_event_types_returns_all_nine_open1_types(): void
    {
        $types = $this->provider->getSupportedEventTypes();
        self::assertCount(9, $types);
        foreach (ContentEventTypes::ALL as $expected) {
            self::assertContains($expected, $types);
        }
    }

    // -------------------------------------------------------------------------
    // provide() — aggregate type mapping
    // -------------------------------------------------------------------------

    #[DataProvider('provideAggregateTypeMapping')]
    public function test_provide_resolves_correct_aggregate_type(
        string $eventType,
        string $expectedAggregateType,
    ): void {
        $this->provider->provide($eventType, '1');

        $write = $this->writer->lastWrite();
        self::assertSame($expectedAggregateType, $write['aggregateType']);
    }

    /** @return array<string, array{string, string}> */
    public static function provideAggregateTypeMapping(): array
    {
        return [
            'page created'     => [ContentEventTypes::PAGE_CREATED,     'page'],
            'page updated'     => [ContentEventTypes::PAGE_UPDATED,     'page'],
            'page deleted'     => [ContentEventTypes::PAGE_DELETED,     'page'],
            'post created'     => [ContentEventTypes::POST_CREATED,     'post'],
            'post updated'     => [ContentEventTypes::POST_UPDATED,     'post'],
            'post deleted'     => [ContentEventTypes::POST_DELETED,     'post'],
            'category created' => [ContentEventTypes::CATEGORY_CREATED, 'category'],
            'category updated' => [ContentEventTypes::CATEGORY_UPDATED, 'category'],
            'category deleted' => [ContentEventTypes::CATEGORY_DELETED, 'category'],
        ];
    }

    // -------------------------------------------------------------------------
    // provide() — event version
    // -------------------------------------------------------------------------

    public function test_provide_always_uses_event_version_1(): void
    {
        $this->provider->provide(ContentEventTypes::POST_CREATED, '42');

        self::assertSame(1, $this->writer->lastWrite()['eventVersion']);
    }

    // -------------------------------------------------------------------------
    // provide() — aggregate ID
    // -------------------------------------------------------------------------

    public function test_provide_passes_aggregate_id_unchanged(): void
    {
        $this->provider->provide(ContentEventTypes::POST_UPDATED, '99');

        self::assertSame('99', $this->writer->lastWrite()['aggregateId']);
    }

    // -------------------------------------------------------------------------
    // provide() — event type forwarded unchanged
    // -------------------------------------------------------------------------

    #[DataProvider('provideAllEventTypes')]
    public function test_provide_forwards_event_type_to_writer(string $eventType): void
    {
        $this->provider->provide($eventType, '1');

        self::assertSame($eventType, $this->writer->lastWrite()['eventType']);
    }

    /** @return array<string, array{string}> */
    public static function provideAllEventTypes(): array
    {
        return array_combine(
            ContentEventTypes::ALL,
            array_map(fn(string $t) => [$t], ContentEventTypes::ALL),
        );
    }

    // -------------------------------------------------------------------------
    // provide() — source_updated_at from context
    // -------------------------------------------------------------------------

    public function test_provide_uses_source_updated_at_from_context(): void
    {
        $dt = new \DateTimeImmutable('2026-01-01 12:00:00', new \DateTimeZone('UTC'));

        $this->provider->provide(ContentEventTypes::POST_CREATED, '5', [
            'source_updated_at' => $dt,
        ]);

        self::assertSame($dt, $this->writer->lastWrite()['sourceUpdatedAt']);
    }

    public function test_provide_defaults_source_updated_at_to_now_when_absent(): void
    {
        $before = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->provider->provide(ContentEventTypes::POST_CREATED, '5');

        $after      = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $recorded   = $this->writer->lastWrite()['sourceUpdatedAt'];

        self::assertGreaterThanOrEqual($before->getTimestamp(), $recorded->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp(), $recorded->getTimestamp());
    }

    // -------------------------------------------------------------------------
    // provide() — payload from context
    // -------------------------------------------------------------------------

    public function test_provide_forwards_payload_from_context(): void
    {
        $payload = ['post_id' => 10, 'status' => 'publish'];

        $this->provider->provide(ContentEventTypes::POST_UPDATED, '10', [
            'payload' => $payload,
        ]);

        self::assertSame($payload, $this->writer->lastWrite()['payload']);
    }

    public function test_provide_defaults_to_empty_payload_when_absent(): void
    {
        $this->provider->provide(ContentEventTypes::CATEGORY_CREATED, '3');

        self::assertSame([], $this->writer->lastWrite()['payload']);
    }

    // -------------------------------------------------------------------------
    // provide() — correlation_id passthrough
    // -------------------------------------------------------------------------

    public function test_provide_uses_correlation_id_from_context(): void
    {
        $corrId = 'test-correlation-id-abc';

        $this->provider->provide(ContentEventTypes::PAGE_CREATED, '1', [
            'correlation_id' => $corrId,
        ]);

        self::assertSame($corrId, $this->writer->lastWrite()['correlationId']);
    }

    public function test_provide_generates_correlation_id_when_absent(): void
    {
        $this->provider->provide(ContentEventTypes::PAGE_CREATED, '1');

        $corrId = $this->writer->lastWrite()['correlationId'];
        self::assertNotEmpty($corrId);
    }

    // -------------------------------------------------------------------------
    // provide() — causation_id
    // -------------------------------------------------------------------------

    public function test_provide_passes_causation_id_from_context(): void
    {
        $causId = 'cause-uuid-xyz';

        $this->provider->provide(ContentEventTypes::POST_DELETED, '7', [
            'causation_id' => $causId,
        ]);

        self::assertSame($causId, $this->writer->lastWrite()['causationId']);
    }

    public function test_provide_defaults_causation_id_to_null(): void
    {
        $this->provider->provide(ContentEventTypes::POST_DELETED, '7');

        self::assertNull($this->writer->lastWrite()['causationId']);
    }

    // -------------------------------------------------------------------------
    // Unsupported event types
    // -------------------------------------------------------------------------

    public function test_provide_throws_for_unknown_event_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->provider->provide('commerce.product.created', '1');
    }

    public function test_provide_throws_for_bare_event_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->provider->provide('post_created', '1');
    }

    public function test_provide_throws_for_empty_event_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->provider->provide('', '1');
    }

    // -------------------------------------------------------------------------
    // Returns the EventInterface from the writer
    // -------------------------------------------------------------------------

    public function test_provide_returns_event_interface(): void
    {
        $event = $this->provider->provide(ContentEventTypes::POST_CREATED, '42');

        self::assertSame(ContentEventTypes::POST_CREATED, $event->getEventType());
        self::assertSame('42', $event->getAggregateId());
    }
}
