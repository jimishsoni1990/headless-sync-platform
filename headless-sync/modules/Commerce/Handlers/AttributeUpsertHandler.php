<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Handlers;

use HSP\Core\Contracts\AdapterInterface;
use HSP\Core\Contracts\EventInterface;
use HSP\Modules\Commerce\Extractors\AttributeExtractor;
use HSP\Modules\Commerce\Transformers\AttributeTransformer;
use HSP\Modules\Commerce\WpCommerceLoader;

/**
 * Projects one global attribute definition.
 *
 * State sync (ADR-044): current state is reloaded rather than the event payload replayed, which
 * matters here more than usual — WooCommerce passes the OLD slug alongside an update because a
 * rename changes the taxonomy name, and reloading means the projection always lands on the
 * current one.
 */
final class AttributeUpsertHandler
{
    public function __construct(
        private readonly WpCommerceLoader $loader,
        private readonly AttributeExtractor $extractor,
        private readonly AttributeTransformer $transformer,
        private readonly AdapterInterface $adapter,
    ) {
    }

    public function handle(EventInterface $event): void
    {
        $raw = $this->loader->loadAttribute((int) $event->getAggregateId());

        if ($raw === null) {
            return;
        }

        $this->adapter->persist(
            $this->transformer->transform($this->extractor->extract($raw)),
            $event,
        );
    }
}
