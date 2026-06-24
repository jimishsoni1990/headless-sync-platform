<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Handlers;

use HSP\Core\Contracts\EventInterface;
use HSP\Modules\Content\Adapters\CategoryAdapter;
use HSP\Modules\Content\Extractors\CategoryExtractor;
use HSP\Modules\Content\Transformers\CategoryTransformer;
use HSP\Modules\Content\WpContentLoader;

/**
 * Handles content.category.created / content.category.updated.
 *
 * Pipeline:
 *   WpContentLoader.loadTerm() → raw WP_Term array
 *   CategoryExtractor           → CategorySourceModel
 *   CategoryTransformer         → CanonicalCategory
 *   CategoryAdapter.persist()   → three-op PG txn (DECISION 3)
 *
 * Authority:
 *   DECISION H (v1.10) — state-sync reload via WpContentLoader.
 *   DECISION 3         — three-op atomicity owned by CategoryAdapter.persist().
 *   ADR-012            — constructor injection only.
 *   ADR-044            — stateless; WP state reloaded per event.
 */
final class CategoryUpsertHandler implements ContentUpsertHandlerInterface
{
    public function __construct(
        private readonly WpContentLoader    $loader,
        private readonly CategoryExtractor  $extractor,
        private readonly CategoryTransformer $transformer,
        private readonly CategoryAdapter    $adapter,
    ) {}

    public function handle(EventInterface $event): void
    {
        $termId = (int) $event->getAggregateId();

        $rawTerm = $this->loader->loadTerm($termId);
        if ($rawTerm === null) {
            throw new \RuntimeException(
                "CategoryUpsertHandler: term {$termId} not found in WordPress for event {$event->getId()}."
            );
        }

        $source    = $this->extractor->extract($rawTerm);
        $canonical = $this->transformer->transform($source);
        $this->adapter->persist($canonical, $event);
    }
}
