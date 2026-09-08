<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Handlers;

use HSP\Core\Contracts\AdapterInterface;
use HSP\Core\Contracts\EventInterface;
use HSP\Modules\Commerce\Extractors\InventoryExtractor;
use HSP\Modules\Commerce\Transformers\InventoryTransformer;
use HSP\Modules\Commerce\WpCommerceLoader;

/**
 * Projects one stock owner's inventory.
 *
 * State sync (ADR-044): current state is reloaded rather than the captured payload replayed.
 *
 * The loader returns null for every reason this projection should not exist — the entity is
 * gone, it is out of Phase 2 scope, or it is no longer the OWNER of its stock — and all three
 * TOMBSTONE. The third is the one that matters and the one AG-14 is about: a variation switched
 * from self-managed to parent-managed still exists and is still perfectly valid, but its
 * inventory row is now a duplicate of its parent's fact and must stop being published.
 *
 * A tombstone on a row that was never written is a harmless no-op, so treating all three the
 * same way is safe in the direction that matters.
 */
final class InventoryUpsertHandler
{
    public function __construct(
        private readonly WpCommerceLoader $loader,
        private readonly InventoryExtractor $extractor,
        private readonly InventoryTransformer $transformer,
        private readonly AdapterInterface $adapter,
    ) {
    }

    public function handle(EventInterface $event): void
    {
        $ownerId = (int) $event->getAggregateId();
        $raw     = $this->loader->loadInventory($ownerId);

        if ($raw === null) {
            $this->adapter->tombstone('inventory', (string) $ownerId, $event);

            return;
        }

        $this->adapter->persist(
            $this->transformer->transform($this->extractor->extract($raw)),
            $event,
        );
    }
}