<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Handlers;

use HSP\Core\Contracts\AdapterInterface;
use HSP\Core\Contracts\EventInterface;
use HSP\Modules\Commerce\Extractors\TermExtractor;
use HSP\Modules\Commerce\Transformers\TermTransformer;
use HSP\Modules\Commerce\WpCommerceLoader;

/**
 * Projects one Commerce taxonomy term: reload current state → extract → transform → persist.
 *
 * State sync (ADR-044): the term's CURRENT state is reloaded rather than the event payload
 * replayed, so a burst of edits collapses to the correct final state.
 *
 * A term that has vanished since capture is a no-op — the deletion event that follows carries
 * the truth.
 */
final class TermUpsertHandler
{
    public function __construct(
        private readonly WpCommerceLoader $loader,
        private readonly TermExtractor $extractor,
        private readonly TermTransformer $transformer,
        private readonly AdapterInterface $adapter,
    ) {
    }

    public function handle(EventInterface $event): void
    {
        $raw = $this->loader->loadTerm((int) $event->getAggregateId());

        if ($raw === null) {
            return;
        }

        $this->adapter->persist(
            $this->transformer->transform($this->extractor->extract($raw)),
            $event,
        );
    }
}
