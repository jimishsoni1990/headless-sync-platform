<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Subscribers;

use HSP\Core\Contracts\EventInterface;
use HSP\Modules\Commerce\Events\CommerceEventTypes;
use HSP\Modules\Commerce\Handlers\InventoryTombstoneHandler;
use HSP\Modules\Commerce\Handlers\InventoryUpsertHandler;
use HSP\Modules\Commerce\Handlers\ProductTombstoneHandler;
use HSP\Modules\Commerce\Handlers\ProductUpsertHandler;
use HSP\Modules\Commerce\Handlers\AttributeTombstoneHandler;
use HSP\Modules\Commerce\Handlers\AttributeUpsertHandler;
use HSP\Modules\Commerce\Handlers\TermTombstoneHandler;
use HSP\Modules\Commerce\Handlers\TermUpsertHandler;
use HSP\Modules\Commerce\Handlers\VariationTombstoneHandler;
use HSP\Modules\Commerce\Handlers\VariationUpsertHandler;

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
        private readonly AttributeUpsertHandler $attributeUpsert,
        private readonly AttributeTombstoneHandler $attributeTombstone,
        private readonly VariationUpsertHandler $variationUpsert,
        private readonly VariationTombstoneHandler $variationTombstone,
        private readonly InventoryUpsertHandler $inventoryUpsert,
        private readonly InventoryTombstoneHandler $inventoryTombstone,
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
            // pa_* terms reuse the taxonomy-generic handlers — the whole point of building the
            // term spine generic in P2-S3 rather than category-specific.
            CommerceEventTypes::ATTRIBUTE_TERM_CREATED,
            CommerceEventTypes::ATTRIBUTE_TERM_UPDATED => $this->termUpsert->handle($event),
            CommerceEventTypes::ATTRIBUTE_TERM_DELETED => $this->termTombstone->handle($event),
            CommerceEventTypes::ATTRIBUTE_CREATED,
            CommerceEventTypes::ATTRIBUTE_UPDATED => $this->attributeUpsert->handle($event),
            CommerceEventTypes::ATTRIBUTE_DELETED => $this->attributeTombstone->handle($event),
            CommerceEventTypes::VARIATION_CREATED,
            CommerceEventTypes::VARIATION_UPDATED => $this->variationUpsert->handle($event),
            CommerceEventTypes::VARIATION_DELETED => $this->variationTombstone->handle($event),
            CommerceEventTypes::INVENTORY_UPDATED => $this->inventoryUpsert->handle($event),
            CommerceEventTypes::INVENTORY_DELETED => $this->inventoryTombstone->handle($event),
            default => throw new \RuntimeException(
                "No Commerce handler registered for event type '{$event->getEventType()}'."
            ),
        };
    }
}
