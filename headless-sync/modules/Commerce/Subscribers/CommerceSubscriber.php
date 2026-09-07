<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Subscribers;

use HSP\Core\Contracts\EventInterface;
use HSP\Modules\Commerce\Events\CommerceEventTypes;
use HSP\Modules\Commerce\Handlers\ProductTombstoneHandler;
use HSP\Modules\Commerce\Handlers\ProductUpsertHandler;

/**
 * Routes a Commerce event to its handler. Registered into EventRegistry once per event type.
 *
 *   commerce.product.created / .updated → ProductUpsertHandler
 *   commerce.product.deleted            → ProductTombstoneHandler (DECISION I)
 */
final class CommerceSubscriber
{
    public function __construct(
        private readonly ProductUpsertHandler $productUpsert,
        private readonly ProductTombstoneHandler $productTombstone,
    ) {
    }

    public function __invoke(EventInterface $event): void
    {
        match ($event->getEventType()) {
            CommerceEventTypes::PRODUCT_CREATED,
            CommerceEventTypes::PRODUCT_UPDATED => $this->productUpsert->handle($event),
            CommerceEventTypes::PRODUCT_DELETED => $this->productTombstone->handle($event),
            default => throw new \RuntimeException(
                "No Commerce handler registered for event type '{$event->getEventType()}'."
            ),
        };
    }
}
