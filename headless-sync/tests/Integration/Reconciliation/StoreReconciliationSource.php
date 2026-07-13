<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Reconciliation;

use HSP\Core\Contracts\SourceState;
use HSP\Core\Contracts\WpReconciliationSourceInterface;
use HSP\Modules\Content\Extractors\CategoryExtractor;
use HSP\Modules\Content\Extractors\PageExtractor;
use HSP\Modules\Content\Extractors\PostExtractor;
use HSP\Modules\Content\Transformers\CategoryTransformer;
use HSP\Modules\Content\Transformers\PageTransformer;
use HSP\Modules\Content\Transformers\PostTransformer;
use HSP\Modules\Content\Validation\CategoryValidator;
use HSP\Modules\Content\Validation\PageValidator;
use HSP\Modules\Content\Validation\PostValidator;
use HSP\Tests\Integration\Replay\FakeWpStore;

/**
 * Headless WpReconciliationSourceInterface for the reconciliation integration test.
 *
 * Substitutes ONLY the WordPress-read boundary (same principle as ReplayReadingLoader in the
 * replay integration test — a headless PHPUnit process cannot bootstrap WordPress). WP state
 * comes from FakeWpStore; the pending-outbox suppression signal is read from the LIVE MySQL
 * wp_hsp_outbox table (real, not faked). Checksum recompute uses the REAL extractor/transformer
 * canonical pipeline, so the recomputed checksum is identical to what a fresh projection stores.
 *
 * Everything downstream of detection (ReconciliationService, ReplayService, outbox, relay,
 * dispatch, worker, adapters, guard, PG) is the real runtime.
 */
final class StoreReconciliationSource implements WpReconciliationSourceInterface
{
    private PageExtractor $pageExtractor;
    private PostExtractor $postExtractor;
    private CategoryExtractor $categoryExtractor;
    private PageTransformer $pageTransformer;
    private PostTransformer $postTransformer;
    private CategoryTransformer $categoryTransformer;

    public function __construct(
        private readonly FakeWpStore $wp,
        private readonly \mysqli $mysqli,
        private readonly string $outboxTable,
    ) {
        $this->pageExtractor       = new PageExtractor(new PageValidator());
        $this->postExtractor       = new PostExtractor(new PostValidator());
        $this->categoryExtractor   = new CategoryExtractor(new CategoryValidator());
        $this->pageTransformer     = new PageTransformer();
        $this->postTransformer     = new PostTransformer();
        $this->categoryTransformer = new CategoryTransformer();
    }

    public function getSupportedAggregateTypes(): array
    {
        return ['page', 'post', 'category'];
    }

    public function listAggregateIds(string $aggregateType, int $afterId, int $limit): array
    {
        $all = match ($aggregateType) {
            'page', 'post' => $this->wp->postIds($aggregateType),
            'category'     => $this->wp->termIds(),
            default        => [],
        };

        $out = [];
        foreach ($all as $id) {
            if ($id > $afterId) {
                $out[] = (string) $id;
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    public function getSourceState(string $aggregateType, string $aggregateId): SourceState
    {
        switch ($aggregateType) {
            case 'page':
            case 'post':
                $post = $this->wp->post((int) $aggregateId, $aggregateType);
                if ($post === null) {
                    return SourceState::absent();
                }
                $public   = (string) $post['post_status'] === 'publish';
                $modified = new \DateTimeImmutable((string) $post['post_modified_gmt'] . ' UTC');
                return new SourceState(true, $public, $modified);

            case 'category':
                $term = $this->wp->term((int) $aggregateId);
                return $term === null ? SourceState::absent() : new SourceState(true, true, null);

            default:
                return SourceState::absent();
        }
    }

    public function computeCurrentChecksum(string $aggregateType, string $aggregateId): ?string
    {
        switch ($aggregateType) {
            case 'page':
                $post = $this->wp->post((int) $aggregateId, 'page');
                if ($post === null || (string) $post['post_status'] !== 'publish') {
                    return null;
                }
                return $this->pageTransformer->transform($this->pageExtractor->extract($post))->getChecksum();

            case 'post':
                $post = $this->wp->post((int) $aggregateId, 'post');
                if ($post === null || (string) $post['post_status'] !== 'publish') {
                    return null;
                }
                return $this->postTransformer->transform($this->postExtractor->extract($post))->getChecksum();

            case 'category':
                $term = $this->wp->term((int) $aggregateId);
                if ($term === null) {
                    return null;
                }
                return $this->categoryTransformer->transform($this->categoryExtractor->extract($term))->getChecksum();

            default:
                return null;
        }
    }

    public function hasPendingOutbox(string $aggregateType, string $aggregateId): bool
    {
        $stmt = $this->mysqli->prepare(
            "SELECT 1 FROM `{$this->outboxTable}`
             WHERE aggregate_type = ? AND aggregate_id = ? AND status = 'pending' LIMIT 1"
        );
        $stmt->bind_param('ss', $aggregateType, $aggregateId);
        $stmt->execute();
        $result = $stmt->get_result();
        $found  = $result->num_rows > 0;
        $stmt->close();

        return $found;
    }
}
