<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Subscribers;

use HSP\Core\Contracts\EventInterface;
use HSP\Modules\Commerce\Events\CommerceEventTypes;
use HSP\Modules\Commerce\Handlers\ProductTombstoneHandler;
use HSP\Modules\Commerce\Handlers\ProductUpsertHandler;
use HSP\Modules\Commerce\Handlers\TermTombstoneHandler;
use HSP\Modules\Commerce\Handlers\TermUpsertHandler;

/**
 * Routes a Commerce event to its handler. Registered into EventRegistry once per event type.
 *
 *   commerce.product.created / .updated → ProductUpsertHandler
 *   commerce.product.deleted            → ProductTombstoneHandler (DECISION I)
 *   commerce.category.created / .updated → TermUpsertHandler
 *   commerce.category.deleted            → TermTombstoneHandler (DECISION I)
 *
 * Terms route to the TAXONOMY-GENERIC handlers, which is what lets P2-S4 add pa_* attribute
 * terms by adding event constants rather than another handler pair.
 */
final class CommerceSubscriber
{
    public function __construct(
        private readonly ProductUpsertHandler $productUpsert,
        private readonly ProductTombstoneHandler $productTombstone,
        private readonly TermUpsertHandler $termUpsert,
        private readonly TermTombstoneHandler $termTombstone,
    ) {
    }

    public function __invoke(EventInterface $event): void
    {
        match ($event->getEventType()) {
            CommerceEventTypes::PRODUCT_CREATED,
            CommerceEventTypes::PRODUCT_UPDATED => $this->productUpsert->handle($event),
            CommerceEventTypes::PRODUCT_DELETED => $this->productTombstone->handle($event),
            CommerceEventTypes::CATEGORY_CREATED,
            CommerceEventTypes::CATEGORY_UPDATED => $this->termUpsert->handle($event),
            CommerceEventTypes::CATEGORY_DELETED => $this->termTombstone->handle($event),
            default => throw new \RuntimeException(
                "No Commerce handler registered for event type '{$event->getEventType()}'."
            ),
        };
    }
}
