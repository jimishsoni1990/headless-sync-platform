<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Handlers;

use HSP\Core\Contracts\EventInterface;
use HSP\Modules\Content\Adapters\MediaAdapter;
use HSP\Modules\Content\Extractors\MediaExtractor;
use HSP\Modules\Content\Transformers\MediaTransformer;
use HSP\Modules\Content\WpContentLoader;

/**
 * Handles content.media.created / content.media.updated.
 *
 * Pipeline: WpContentLoader.loadAttachment() → MediaExtractor → MediaTransformer
 *           → MediaAdapter.persist()
 *
 * If the attachment no longer exists in WordPress the handler throws so the job is
 * retried — the same contract the Page/Post upsert handlers use. The attachment should
 * exist; if it was deleted, HookWiring emits content.media.deleted instead, and a missing
 * attachment at upsert time is an unexpected state that a retry may resolve. After retry
 * exhaustion the job goes to DLQ (never silently dropped).
 *
 * Authority:
 *   DECISION H (v1.10) — state-sync reload via WpContentLoader; no payload enrichment.
 *   DECISION 3         — three-op atomicity owned by MediaAdapter.persist().
 *   ADR-012            — constructor injection only.
 *   ADR-044            — stateless; WP state reloaded per event.
 */
final class MediaUpsertHandler implements ContentUpsertHandlerInterface
{
    public function __construct(
        private readonly WpContentLoader $loader,
        private readonly MediaExtractor $extractor,
        private readonly MediaTransformer $transformer,
        private readonly MediaAdapter $adapter,
    ) {
    }

    public function handle(EventInterface $event): void
    {
        $postId = (int) $event->getAggregateId();

        $rawAttachment = $this->loader->loadAttachment($postId);
        if ($rawAttachment === null) {
            throw new \RuntimeException(
                "MediaUpsertHandler: attachment {$postId} not found in WordPress for event {$event->getId()}."
            );
        }

        $rawMeta   = $this->loader->loadPostMeta($postId);
        $source    = $this->extractor->extract($rawAttachment, $rawMeta);
        $canonical = $this->transformer->transform($source);
        $this->adapter->persist($canonical, $event);
    }
}
