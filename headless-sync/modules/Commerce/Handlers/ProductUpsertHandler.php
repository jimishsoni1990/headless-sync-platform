<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Handlers;

use HSP\Core\Contracts\EventInterface;
use HSP\Core\Contracts\AdapterInterface;
use HSP\Modules\Commerce\Extractors\ProductExtractor;
use HSP\Modules\Commerce\ProductScope;
use HSP\Modules\Commerce\Transformers\ProductTransformer;
use HSP\Modules\Commerce\WpCommerceLoader;

/**
 * Projects one product: load current WordPress state → extract → transform → persist.
 *
 * State sync, not event sourcing (ADR-044 / DECISION H): the handler reloads the product's
 * CURRENT state rather than replaying the payload the event carried, so a burst of edits
 * collapses to the correct final state and a replayed event is never stale.
 *
 * Two outcomes are deliberately NOT failures:
 *
 *   - The product no longer exists. It was deleted between capture and processing; the
 *     tombstone event that follows is authoritative, so this is a no-op rather than a retry.
 *   - The product is no longer a SUPPORTED type (AG-13). A `variable` product retyped to
 *     `grouped` has left Phase 2 scope, so its previously public projection is TOMBSTONED —
 *     it must not remain visible forever merely because its new type is unsupported — and
 *     that transition uses the existing DECISION I path, not a product-type-specific repair.
 */
final class ProductUpsertHandler
{
    public function __construct(
        private readonly WpCommerceLoader $loader,
        private readonly ProductExtractor $extractor,
        private readonly ProductTransformer $transformer,
        private readonly AdapterInterface $adapter,
    ) {
    }

    public function handle(EventInterface $event): void
    {
        $productId = (int) $event->getAggregateId();
        $raw       = $this->loader->loadProduct($productId);

        if ($raw === null) {
            // Gone since capture — the deletion event carries the truth.
            return;
        }

        if (! ProductScope::isSupportedType((string) ($raw['product_type'] ?? ''))) {
            // Left supported scope: tombstone rather than leave a stale public projection.
            $this->adapter->tombstone('product', (string) $productId, $event);

            return;
        }

        $source = $this->extractor->extract($raw);
        $model  = $this->transformer->transform($source);

        $this->adapter->persist($model, $event);
    }
}
