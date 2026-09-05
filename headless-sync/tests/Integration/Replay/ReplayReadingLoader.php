<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Replay;

use HSP\Modules\Content\WpContentLoader;

/**
 * WpContentLoader that reads current WordPress state from a FakeWpStore.
 *
 * Used two ways in the replay integration test:
 *   - by ContentReplayEmitter (no fixed type) to decide .updated vs .deleted from current
 *     state (returns null when the aggregate is absent → tombstone);
 *   - by the upsert handlers (fixed 'page'/'post' type) to reload state for reprojection
 *     (the validators require a matching post_type).
 */
final class ReplayReadingLoader implements WpContentLoader
{
    public function __construct(
        private readonly FakeWpStore $store,
        private readonly string $postType = '',
    ) {}

    public function loadPost(int $postId): ?array
    {
        return $this->store->post($postId, $this->postType);
    }

    public function loadPostMeta(int $postId): array
    {
        return [];
    }

    public function loadTerm(int $termId): ?array
    {
        return $this->store->term($termId);
    }

    /**
     * The replay integration test's corpus has no attachments; media replay is covered by
     * MediaProjectionIntegrationTest and ContentReplayEmitterTest. Returning null here means
     * "absent", which is the honest answer for this store.
     */
    public function loadAttachment(int $postId): ?array
    {
        return null;
    }

    public function loadPostCategoryIds(int $postId): array
    {
        return [];
    }
}
