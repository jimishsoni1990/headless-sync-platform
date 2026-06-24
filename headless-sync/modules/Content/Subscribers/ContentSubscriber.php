<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Subscribers;

use HSP\Core\Contracts\EventInterface;
use HSP\Modules\Content\Events\ContentEventTypes;
use HSP\Modules\Content\Handlers\ContentUpsertHandlerInterface;
use HSP\Modules\Content\Handlers\ContentTombstoneHandlerInterface;

/**
 * Subscriber for all nine content event types (OPEN-1).
 *
 * This callable is registered in EventRegistry once per event type.
 * It delegates to the correct typed handler based on the event type.
 *
 * Upsert handlers:   content.*.created / content.*.updated
 * Tombstone handlers: content.*.deleted
 *
 * Authority:
 *   OPEN-1     — fully-qualified event type keys.
 *   DECISION H — handlers receive current WP state via WpContentLoader.
 *   DECISION I — deleted events route to a dedicated tombstone handler.
 *   ADR-012    — constructor injection only.
 */
final class ContentSubscriber
{
    /** @param array<string, ContentUpsertHandlerInterface|ContentTombstoneHandlerInterface> $handlers */
    public function __construct(
        private readonly array $handlers,
    ) {}

    /**
     * Invoke the correct handler for the given event.
     *
     * Called by EventWorkerStrategy after the Resolve stage (handler is already
     * the resolved callable from EventRegistry — this class IS the callable).
     *
     * @throws \RuntimeException if no handler is registered for the event type
     */
    public function __invoke(EventInterface $event): void
    {
        $eventType = $event->getEventType();

        if (! isset($this->handlers[$eventType])) {
            throw new \RuntimeException(
                "ContentSubscriber: no handler registered for event type '{$eventType}'."
            );
        }

        $handler = $this->handlers[$eventType];
        $handler->handle($event);
    }

    /**
     * Returns all event types this subscriber handles.
     *
     * @return string[]
     */
    public function getSupportedEventTypes(): array
    {
        return array_keys($this->handlers);
    }
}
