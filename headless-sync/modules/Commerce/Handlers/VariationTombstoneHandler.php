<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Handlers;

use HSP\Core\Contracts\AdapterInterface;
use HSP\Core\Contracts\EventInterface;

/** Soft-deletes a variation's projection (DECISION I). */
final class VariationTombstoneHandler
{
    public function __construct(private readonly AdapterInterface $adapter)
    {
    }

    public function handle(EventInterface $event): void
    {
        $this->adapter->tombstone($event->getAggregateType(), $event->getAggregateId(), $event);
    }
}
