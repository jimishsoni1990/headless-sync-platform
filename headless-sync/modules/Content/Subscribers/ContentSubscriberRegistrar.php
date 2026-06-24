<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Subscribers;

use HSP\Core\Events\EventRegistry;
use HSP\Modules\Content\Events\ContentEventTypes;

/**
 * Typed factory that registers ContentSubscriber into EventRegistry for all 9 event types.
 *
 * Injected into ContentModule via constructor (ADR-012). ContentModule holds no
 * Container reference. Follows the same lazy-factory pattern as ContentRestRegistrarFactory
 * (FLAG-P1AS6A-5 resolved model).
 *
 * Registration runs during ContentModule::register() — before boot(), before rest_api_init,
 * so EventWorkerStrategy can resolve handlers from EventRegistry when a worker ticks.
 *
 * Authority:
 *   OPEN-1   — fully-qualified event type keys.
 *   ADR-012  — no Container::get / global $container in ContentModule.
 */
final class ContentSubscriberRegistrar
{
    /**
     * @param \Closure(): EventRegistry       $registryFactory
     * @param \Closure(): ContentSubscriber   $subscriberFactory
     */
    public function __construct(
        private readonly \Closure $registryFactory,
        private readonly \Closure $subscriberFactory,
    ) {}

    /**
     * Register the ContentSubscriber callable for all 9 OPEN-1 event types.
     *
     * Safe to call multiple times — EventRegistry::register() is additive, but
     * ContentModule::register() is expected to call this exactly once per boot.
     */
    public function register(): void
    {
        $registry   = ($this->registryFactory)();
        $subscriber = ($this->subscriberFactory)();

        foreach (ContentEventTypes::ALL as $eventType) {
            $registry->register($eventType, $subscriber);
        }
    }
}
