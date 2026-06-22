<?php

declare(strict_types=1);

namespace HSP\Core\Events;

/**
 * Registry of known domain event types and their handlers.
 *
 * Authority: IMPLEMENTATION_PLAN.md §4 Phase 0 deliverables:
 *   "Explicit registration only; no reflection-based discovery"
 *
 * An event type is a fully-qualified string per OPEN-1:
 *   <domain>.<aggregate>.<action>  — e.g. 'content.post.updated'
 *
 * Handlers are callables (closures, invokable class names, or [$obj, 'method'])
 * registered by module ServiceProviders during the register() phase (OPEN-9).
 * Multiple handlers may be registered for the same event type.
 *
 * No reflection, no filesystem scanning, no wildcard patterns. Every registration
 * must be an explicit call to register().
 */
final class EventRegistry
{
    /** @var array<string, callable[]> event_type → ordered list of handlers */
    private array $handlers = [];

    /**
     * Register a handler for a fully-qualified event type.
     *
     * @param string   $eventType fully-qualified — OPEN-1 (e.g. 'content.post.updated')
     * @param callable $handler   invoked by EventWorkerStrategy during execution
     *
     * @throws \InvalidArgumentException if $eventType is empty or malformed
     */
    public function register(string $eventType, callable $handler): void
    {
        $this->assertValidEventType($eventType);
        $this->handlers[$eventType][] = $handler;
    }

    /**
     * Returns true if at least one handler is registered for the given event type.
     */
    public function has(string $eventType): bool
    {
        return ! empty($this->handlers[$eventType]);
    }

    /**
     * Returns all handlers for the given event type, in registration order.
     *
     * @return callable[]
     */
    public function getHandlers(string $eventType): array
    {
        return $this->handlers[$eventType] ?? [];
    }

    /**
     * Returns all registered event types.
     *
     * @return string[]
     */
    public function getRegisteredTypes(): array
    {
        return array_keys($this->handlers);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Assert the event type is non-empty and matches <domain>.<aggregate>.<action>.
     *
     * @throws \InvalidArgumentException
     */
    private function assertValidEventType(string $eventType): void
    {
        if ($eventType === '') {
            throw new \InvalidArgumentException('Event type must not be empty.');
        }

        // Must have at least two dots: <domain>.<aggregate>.<action>
        if (substr_count($eventType, '.') < 2) {
            throw new \InvalidArgumentException(
                "Event type '{$eventType}' must follow the <domain>.<aggregate>.<action> "
                . 'convention (OPEN-1).'
            );
        }
    }
}
