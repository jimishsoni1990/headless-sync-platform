<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Handlers;

use HSP\Core\Contracts\AdapterInterface;
use HSP\Core\Contracts\EventInterface;

/** Soft-deletes a stock owner's inventory projection (DECISION I). */
final class InventoryTombstoneHandler
{
    public function __construct(private readonly AdapterInterface $adapter)
    {
    }

    public function handle(EventInterface $event): void
    {
        $this->adapter->tombstone($event->getAggregateType(), $event->getAggregateId(), $event);
    }
}