<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Handlers;

use HSP\Core\Contracts\AdapterInterface;
use HSP\Core\Contracts\EventInterface;
use HSP\Modules\Commerce\Extractors\VariationExtractor;
use HSP\Modules\Commerce\Transformers\VariationTransformer;
use HSP\Modules\Commerce\WpCommerceLoader;

/**
 * Projects one product variation: load current state → extract → transform → persist.
 *
 * State sync, not event sourcing (ADR-044 / DECISION H): current state is reloaded rather than
 * the captured payload replayed, so a burst of edits collapses to the correct final state.
 *
 * The loader returns null for two different situations, and both are TOMBSTONES rather than
 * no-ops:
 *
 *   - the variation is gone since capture, or
 *   - its PARENT is no longer a Phase 2 supported type (AG-13) — a variable product retyped to
 *     `grouped` takes its variations out of scope with it.
 *
 * The second is why this differs from ProductUpsertHandler, which returns early when its
 * subject has vanished and tombstones only on the type transition. A variation has no state of
 * its own to consult once the loader declines to return it, and leaving the projection visible
 * because the reason could not be distinguished is the failure DECISION I exists to prevent. A
 * tombstone on an already-absent row is a harmless no-op, so treating both the same way is safe
 * in the direction that matters.
 */
final class VariationUpsertHandler
{
    public function __construct(
        private readonly WpCommerceLoader $loader,
        private readonly VariationExtractor $extractor,
        private readonly VariationTransformer $transformer,
        private readonly AdapterInterface $adapter,
    ) {
    }

    public function handle(EventInterface $event): void
    {
        $variationId = (int) $event->getAggregateId();
        $raw         = $this->loader->loadVariation($variationId);

        if ($raw === null) {
            $this->adapter->tombstone('product_variation', (string) $variationId, $event);

            return;
        }

        $this->adapter->persist(
            $this->transformer->transform($this->extractor->extract($raw)),
            $event,
        );
    }
}
