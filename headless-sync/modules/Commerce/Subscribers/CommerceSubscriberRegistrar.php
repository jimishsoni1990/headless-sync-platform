<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Subscribers;

use HSP\Core\Events\EventRegistry;
use HSP\Modules\Commerce\Events\CommerceEventTypes;

/**
 * Registers CommerceSubscriber into EventRegistry for every Commerce event type.
 *
 * Runs during CommerceModule::register() — before boot() and before rest_api_init — so a
 * worker ticking mid-request can resolve a handler.
 *
 * Lazy closures rather than injected instances, matching the Content precedent
 * (FLAG-P1AS6A-5): building the handler graph reaches the delivery connection, and module
 * construction must not open a socket on an unconfigured site.
 */
final class CommerceSubscriberRegistrar
{
    /**
     * @param \Closure(): EventRegistry      $registryFactory
     * @param \Closure(): CommerceSubscriber $subscriberFactory
     */
    public function __construct(
        private readonly \Closure $registryFactory,
        private readonly \Closure $subscriberFactory,
    ) {
    }

    public function register(): void
    {
        $registry   = ($this->registryFactory)();
        $subscriber = ($this->subscriberFactory)();

        foreach (CommerceEventTypes::ALL as $eventType) {
            $registry->register($eventType, $subscriber);
        }
    }
}
