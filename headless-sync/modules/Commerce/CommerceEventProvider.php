<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce;

use HSP\Core\Contracts\EventInterface;
use HSP\Core\Contracts\EventProviderInterface;
use HSP\Core\Contracts\OutboxWriterInterface;
use HSP\Modules\Commerce\Events\CommerceEventTypes;

/**
 * Builds and persists Commerce domain events to wp_hsp_outbox.
 *
 * Called by HookWiring immediately after a WordPress/WooCommerce commit (DECISION 1), and
 * delegates the write to the shared OutboxWriterInterface — Rule 3, no second capture path.
 *
 * Aggregate mapping: the second dot-segment IS the aggregate type — `commerce.product.*` →
 * `product`, `commerce.product_category.*` → `product_category`. Aggregate types are
 * platform-wide keys, so Commerce namespaces its category rather than colliding with the
 * Content module's `category`.
 *
 * NOTE ON BINDING: unlike Content, this is bound under its CONCRETE class rather than
 * EventProviderInterface. The interface is a single container key, so two modules binding it
 * would leave whichever registered last as the only provider — the identical last-writer-wins
 * hazard DECISION AG AG-2 removed for replay emitters and reconciliation sources.
 *
 * Event version 1 for every Commerce event (Doc 5 §26 — replay must use the original version;
 * a bump is a contract change, not a refactor).
 */
final class CommerceEventProvider implements EventProviderInterface
{
    private const EVENT_VERSION = 1;

    public function __construct(private readonly OutboxWriterInterface $outboxWriter)
    {
    }
    /**
     * The aggregate type for an event type.
     *
     * OPEN-1 fixes the shape as `<domain>.<aggregate>.<action>`, so **the second segment IS the
     * aggregate type** — always, by definition. This used to be a hardcoded map from segment to
     * aggregate type in which every single entry was an identity mapping, which meant it could
     * add no information and could only ever be WRONG: P2-S5 and P2-S6 added the
     * `product_variation` and `inventory` aggregates and did not extend it, so capturing either
     * threw. Live testing found it on the first product save.
     *
     * The caller has already checked the event type against {@see CommerceEventTypes::ALL}, so a
     * segment reaching here is a declared aggregate by construction. Deriving it removes the
     * class of bug entirely rather than fixing one instance of it.
     */
    private function aggregateTypeFor(string $eventType): string
    {
        $segment = explode('.', $eventType)[1] ?? '';

        if ($segment === '') {
            throw new \InvalidArgumentException(
                "Cannot resolve aggregate type for event '{$eventType}' — no aggregate segment."
            );
        }

        return $segment;
    }


    /** @return string[] */
    public function getSupportedEventTypes(): array
    {
        return CommerceEventTypes::ALL;
    }

    /**
     * @param array<string,mixed> $context Must carry 'source_updated_at' as a UTC
     *        \DateTimeImmutable when the source provides one.
     */
    public function provide(string $eventType, string $aggregateId, array $context = []): EventInterface
    {
        if (! in_array($eventType, CommerceEventTypes::ALL, true)) {
            throw new \InvalidArgumentException(
                "Event type '{$eventType}' is not a recognised Commerce event (OPEN-1)."
            );
        }

        $aggregateType = $this->aggregateTypeFor($eventType);

        return $this->outboxWriter->write(
            eventType:       $eventType,
            eventVersion:    self::EVENT_VERSION,
            aggregateType:   $aggregateType,
            aggregateId:     $aggregateId,
            payload:         $context['payload'] ?? [],
            correlationId:   $context['correlation_id'] ?? $this->newUuid(),
            causationId:     $context['causation_id'] ?? null,
            sourceUpdatedAt: $context['source_updated_at']
                ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    private function newUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
        );
    }
}
