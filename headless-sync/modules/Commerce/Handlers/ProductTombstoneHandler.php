<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Handlers;

use HSP\Core\Contracts\EventInterface;
use HSP\Modules\Commerce\Adapters\ProductAdapter;

/**
 * Soft-deletes a product's projection (DECISION I).
 *
 * Envelope-only by design: a deletion event carries no source state to reload, and attempting
 * to read the deleted product would be both pointless and racy. `deleted_at` comes from the
 * event's source timestamp, so a replay is deterministic.
 */
final class ProductTombstoneHandler
{
    public function __construct(private readonly ProductAdapter $adapter)
    {
    }

    public function handle(EventInterface $event): void
    {
        $this->adapter->tombstone('product', $event->getAggregateId(), $event);
    }
}
