<?php

declare(strict_types=1);

namespace HSP\Modules\Content;

use HSP\Core\Contracts\EventInterface;
use HSP\Core\Contracts\EventProviderInterface;
use HSP\Core\Contracts\OutboxWriterInterface;
use HSP\Modules\Content\Events\ContentEventTypes;

/**
 * Builds and persists Content domain events to wp_hsp_outbox.
 *
 * Called by HookWiring immediately after a WordPress commit (DECISION 1).
 * Delegates the actual outbox write to the injected OutboxWriterInterface so
 * no new capture path is introduced (session constraint).
 *
 * Aggregate type mapping:
 *   content.page.*     → aggregate_type = 'page'
 *   content.post.*     → aggregate_type = 'post'
 *   content.category.* → aggregate_type = 'category'
 *
 * Event version: all Content module events ship at version 1 (Doc 5 §26 — replay
 * must use the original version; bumping is a future contract change, not done here).
 */
final class EventProvider implements EventProviderInterface
{
    private const EVENT_VERSION = 1;

    /** Maps aggregate segment (second dot-component) to aggregate_type string. */
    private const AGGREGATE_TYPE_MAP = [
        'page'     => 'page',
        'post'     => 'post',
        'category' => 'category',
    ];

    public function __construct(
        private readonly OutboxWriterInterface $outboxWriter,
    ) {}

    /** @return string[] */
    public function getSupportedEventTypes(): array
    {
        return ContentEventTypes::ALL;
    }

    /**
     * Build and write the outbox row for the given Content event.
     *
     * @param string               $eventType   Fully-qualified OPEN-1 type, e.g. 'content.post.created'
     * @param string               $aggregateId WordPress post_id or term_id as a string
     * @param array<string, mixed> $context     Hook-provided context; must include 'source_updated_at'
     *                                          as a \DateTimeImmutable (UTC) when available
     */
    public function provide(string $eventType, string $aggregateId, array $context = []): EventInterface
    {
        $this->assertSupportedEventType($eventType);

        $aggregateType   = $this->resolveAggregateType($eventType);
        $correlationId   = $context['correlation_id'] ?? $this->newUuid();
        $causationId     = $context['causation_id'] ?? null;
        $sourceUpdatedAt = $context['source_updated_at']
            ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $payload = $context['payload'] ?? [];

        return $this->outboxWriter->write(
            eventType:       $eventType,
            eventVersion:    self::EVENT_VERSION,
            aggregateType:   $aggregateType,
            aggregateId:     $aggregateId,
            payload:         $payload,
            correlationId:   $correlationId,
            causationId:     $causationId,
            sourceUpdatedAt: $sourceUpdatedAt,
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function resolveAggregateType(string $eventType): string
    {
        // e.g. 'content.post.created' → segment[1] = 'post'
        $parts = explode('.', $eventType);
        $segment = $parts[1] ?? '';
        return self::AGGREGATE_TYPE_MAP[$segment]
            ?? throw new \InvalidArgumentException(
                "Cannot resolve aggregate type for event '{$eventType}'."
            );
    }

    private function assertSupportedEventType(string $eventType): void
    {
        if (! in_array($eventType, ContentEventTypes::ALL, true)) {
            throw new \InvalidArgumentException(
                "Event type '{$eventType}' is not a recognised Content event (OPEN-1)."
            );
        }
    }

    private function newUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        );
    }
}
