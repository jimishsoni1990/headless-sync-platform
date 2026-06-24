<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Handlers;

use HSP\Core\Contracts\EventInterface;
use HSP\Modules\Content\Adapters\PostAdapter;

/**
 * Handles content.post.deleted — soft-deletes the content.posts projection row.
 *
 * Consumes only the event envelope: aggregate_type, aggregate_id, and event metadata.
 * No WordPress state reload, no Extractor, no Transformer (DECISION I).
 * deleted_at is set to event.source_updated_at (deterministic — NOT worker wall-clock).
 *
 * Authority:
 *   DECISION I (v1.10) — dedicated tombstone path for *.deleted events.
 *   DECISION 3         — three-op atomicity owned by PostAdapter.tombstone().
 *   ADR-012            — constructor injection only.
 */
final class PostTombstoneHandler implements ContentTombstoneHandlerInterface
{
    public function __construct(
        private readonly PostAdapter $adapter,
    ) {}

    public function handle(EventInterface $event): void
    {
        $this->adapter->tombstone(
            $event->getAggregateType(),
            $event->getAggregateId(),
            $event
        );
    }
}
