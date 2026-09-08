<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Events;

use HSP\Core\Contracts\EventInterface;
use HSP\Core\Contracts\OutboxWriterInterface;
use HSP\Modules\Commerce\CommerceEventProvider;
use HSP\Modules\Commerce\Events\CommerceEventTypes;
use HSP\Modules\Content\EventProvider as ContentEventProvider;
use HSP\Modules\Content\Events\ContentEventTypes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * EVERY declared event type must resolve to an aggregate type. Found by live testing, 2026-09-08.
 *
 * Both providers carried a hardcoded map from the event type's aggregate segment to an aggregate
 * type — in which **every single entry was an identity mapping**. Such a map can add no
 * information; it can only ever be WRONG, by omission. And it was: P2-S5 and P2-S6 added the
 * `product_variation` and `inventory` aggregates to Commerce without extending its copy.
 *
 * The consequence was worse than a missing projection. `CommerceEventProvider::provide()` threw
 * `InvalidArgumentException`, `HookWiring::captureEvent()` caught only `OutboxWriteException`, so
 * the exception escaped into WooCommerce's `save()` — and **saving any product fataled**. A sync
 * plugin took down product editing.
 *
 * Nothing in 1,544 unit tests or 327 integration tests caught it, because every one of them
 * constructed events directly rather than going through the provider. The first real
 * `$product->save()` on a live site hit it immediately.
 *
 * This test is the cheap guard that would have: it walks the DECLARED event vocabulary of each
 * module and asserts the provider can build every one. It costs nothing and cannot go stale,
 * because it derives its cases from the same constant the module ships.
 */
final class EventProviderAggregateResolutionTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: string}> event type => expected aggregate type
     */
    public static function commerceEventTypes(): array
    {
        return array_map(
            static fn (string $type): array => [$type, explode('.', $type)[1]],
            CommerceEventTypes::ALL,
        );
    }

    /** @return list<array{0: string, 1: string}> */
    public static function contentEventTypes(): array
    {
        return array_map(
            static fn (string $type): array => [$type, explode('.', $type)[1]],
            ContentEventTypes::ALL,
        );
    }

    #[DataProvider("commerceEventTypes")]
    public function testEveryCommerceEventTypeResolvesToItsAggregate(
        string $eventType,
        string $expectedAggregate,
    ): void {
        $writer = new RecordingOutboxWriter();

        (new CommerceEventProvider($writer))->provide($eventType, '42');

        self::assertSame(
            $expectedAggregate,
            $writer->lastAggregateType,
            "'{$eventType}' must capture as aggregate '{$expectedAggregate}'",
        );
    }

    #[DataProvider("contentEventTypes")]
    public function testEveryContentEventTypeResolvesToItsAggregate(
        string $eventType,
        string $expectedAggregate,
    ): void {
        $writer = new RecordingOutboxWriter();

        (new ContentEventProvider($writer))->provide($eventType, '42');

        self::assertSame($expectedAggregate, $writer->lastAggregateType);
    }

    /**
     * The whole vocabulary in one call, so a NEW aggregate cannot be added without this failing.
     *
     * The per-type cases above prove each one individually; this proves the SET is covered, which
     * is the property that actually broke.
     */
    public function testTheCommerceVocabularyIsCompletelyCapturable(): void
    {
        $writer = new RecordingOutboxWriter();
        $sut    = new CommerceEventProvider($writer);

        $aggregates = [];

        foreach (CommerceEventTypes::ALL as $eventType) {
            $sut->provide($eventType, '1');
            $aggregates[$writer->lastAggregateType] = true;
        }

        self::assertSame(
            ['product', 'product_category', 'attribute', 'attribute_term', 'product_variation', 'inventory'],
            array_keys($aggregates),
            'every Phase 2 aggregate must be capturable through the provider',
        );
    }

    /** An event type outside the declared vocabulary is still refused. */
    public function testAnUndeclaredEventTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new CommerceEventProvider(new RecordingOutboxWriter()))
            ->provide('commerce.order.created', '1');
    }

    public function testAContentProviderRefusesACommerceEventType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ContentEventProvider(new RecordingOutboxWriter()))
            ->provide(CommerceEventTypes::PRODUCT_CREATED, '1');
    }
}

/** Records what the provider asked the outbox to write, and writes nothing. */
final class RecordingOutboxWriter implements OutboxWriterInterface
{
    public string $lastAggregateType = '';
    public string $lastEventType     = '';

    /** @param array<string,mixed> $payload */
    public function write(
        string $eventType,
        int $eventVersion,
        string $aggregateType,
        string $aggregateId,
        array $payload,
        string $correlationId,
        ?string $causationId,
        \DateTimeImmutable $sourceUpdatedAt,
    ): EventInterface {
        $this->lastEventType     = $eventType;
        $this->lastAggregateType = $aggregateType;

        return new class ($eventType, $aggregateType, $aggregateId) implements EventInterface {
            public function __construct(
                private readonly string $eventType,
                private readonly string $aggregateType,
                private readonly string $aggregateId,
            ) {
            }

            public function getId(): string { return '01900000-0000-7000-8000-000000000001'; }
            public function getEventType(): string { return $this->eventType; }
            public function getEventVersion(): int { return 1; }
            public function getAggregateType(): string { return $this->aggregateType; }
            public function getAggregateId(): string { return $this->aggregateId; }
            public function getAggregateVersion(): int { return 1; }
            /** @return array<string,mixed> */
            public function getPayload(): array { return []; }
            public function getChecksum(): string { return str_repeat('0', 64); }
            public function getSourceUpdatedAt(): \DateTimeImmutable { return new \DateTimeImmutable('2026-01-01T00:00:00Z'); }
            public function getCreatedAt(): \DateTimeImmutable { return new \DateTimeImmutable('2026-01-01T00:00:00Z'); }
            public function getCorrelationId(): string { return '01900000-0000-7000-8000-000000000002'; }
            public function getCausationId(): ?string { return null; }
        };
    }
}
