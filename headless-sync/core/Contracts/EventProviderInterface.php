<?php

declare(strict_types=1);

namespace HSP\Core\Contracts;

/**
 * Produces domain events from WordPress hook context.
 *
 * Implementations live in modules (e.g. HSP\Modules\Content\Events\PostEventProvider).
 * The provider assembles the full EventInterface envelope — including aggregate_version
 * from the atomic counter — and writes to wp_hsp_outbox post-commit (DECISION 1).
 */
interface EventProviderInterface
{
    /**
     * Returns the fully-qualified event type(s) this provider emits.
     *
     * @return string[] e.g. ['content.post.created', 'content.post.updated']
     */
    public function getSupportedEventTypes(): array;

    /**
     * Build the event envelope for the given WordPress entity identifier.
     *
     * @param string               $eventType Fully-qualified event type — OPEN-1
     * @param string               $aggregateId
     * @param array<string, mixed> $context   Hook-provided context (e.g. post data, old status)
     */
    public function provide(string $eventType, string $aggregateId, array $context = []): EventInterface;
}
