<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Handlers;

use HSP\Core\Contracts\EventInterface;
use HSP\Modules\Content\Adapters\PageAdapter;
use HSP\Modules\Content\Extractors\PageExtractor;
use HSP\Modules\Content\Transformers\PageTransformer;
use HSP\Modules\Content\Validation\ValidationException;
use HSP\Modules\Content\WpContentLoader;

/**
 * Handles content.page.created / content.page.updated.
 *
 * Pipeline: WpContentLoader.loadPost() → PageExtractor → PageTransformer → PageAdapter.persist()
 *
 * If the post no longer exists in WordPress (get_post returns null), the handler
 * throws so the job is retried. The post should exist — if it was deleted the
 * HookWiring should have emitted content.page.deleted instead. A missing post at
 * upsert time is an unexpected state; retry lets the worker try again in case of
 * a timing race. After retry exhaustion the job goes to DLQ.
 *
 * Authority:
 *   DECISION H (v1.10) — state-sync reload via WpContentLoader; no payload enrichment.
 *   DECISION 3         — three-op atomicity owned by PageAdapter.persist().
 *   ADR-012            — constructor injection only.
 *   ADR-044            — stateless; WP state reloaded per event.
 */
final class PageUpsertHandler implements ContentUpsertHandlerInterface
{
    public function __construct(
        private readonly WpContentLoader $loader,
        private readonly PageExtractor   $extractor,
        private readonly PageTransformer $transformer,
        private readonly PageAdapter     $adapter,
    ) {}

    public function handle(EventInterface $event): void
    {
        $postId = (int) $event->getAggregateId();

        $rawPost = $this->loader->loadPost($postId);
        if ($rawPost === null) {
            throw new \RuntimeException(
                "PageUpsertHandler: post {$postId} not found in WordPress for event {$event->getId()}."
            );
        }

        $rawMeta  = $this->loader->loadPostMeta($postId);
        $source   = $this->extractor->extract($rawPost, $rawMeta);
        $canonical = $this->transformer->transform($source);
        $this->adapter->persist($canonical, $event);
    }
}
