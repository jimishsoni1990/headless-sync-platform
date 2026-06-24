<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Handlers;

use HSP\Core\Contracts\EventInterface;
use HSP\Modules\Content\Adapters\PostAdapter;
use HSP\Modules\Content\Extractors\PostExtractor;
use HSP\Modules\Content\Transformers\PostTransformer;
use HSP\Modules\Content\WpContentLoader;

/**
 * Handles content.post.created / content.post.updated.
 *
 * Pipeline:
 *   WpContentLoader.loadPost()            → raw WP_Post array
 *   WpContentLoader.loadPostMeta()        → flat meta map
 *   WpContentLoader.loadPostCategoryIds() → list<int>
 *   PostExtractor                         → PostSourceModel
 *   PostTransformer                       → CanonicalPost
 *   PostAdapter.persist()                 → three-op PG txn (DECISION 3)
 *
 * Authority:
 *   DECISION H (v1.10) — state-sync reload via WpContentLoader.
 *   DECISION 3         — three-op atomicity owned by PostAdapter.persist().
 *   ADR-012            — constructor injection only.
 *   ADR-044            — stateless; WP state reloaded per event.
 */
final class PostUpsertHandler implements ContentUpsertHandlerInterface
{
    public function __construct(
        private readonly WpContentLoader $loader,
        private readonly PostExtractor   $extractor,
        private readonly PostTransformer $transformer,
        private readonly PostAdapter     $adapter,
    ) {}

    public function handle(EventInterface $event): void
    {
        $postId = (int) $event->getAggregateId();

        $rawPost = $this->loader->loadPost($postId);
        if ($rawPost === null) {
            throw new \RuntimeException(
                "PostUpsertHandler: post {$postId} not found in WordPress for event {$event->getId()}."
            );
        }

        $rawMeta     = $this->loader->loadPostMeta($postId);
        $categoryIds = $this->loader->loadPostCategoryIds($postId);
        $source      = $this->extractor->extract($rawPost, $rawMeta, $categoryIds);
        $canonical   = $this->transformer->transform($source);
        $this->adapter->persist($canonical, $event);
    }
}
