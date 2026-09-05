<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Reconciliation;

use HSP\Core\Contracts\SourceState;
use HSP\Core\Contracts\WpReconciliationSourceInterface;
use HSP\Modules\Content\Extractors\CategoryExtractor;
use HSP\Modules\Content\Extractors\PageExtractor;
use HSP\Modules\Content\Extractors\PostExtractor;
use HSP\Modules\Content\Extractors\MediaExtractor;
use HSP\Modules\Content\Transformers\CategoryTransformer;
use HSP\Modules\Content\Transformers\MediaTransformer;
use HSP\Modules\Content\Transformers\PageTransformer;
use HSP\Modules\Content\Transformers\PostTransformer;
use HSP\Modules\Content\WpContentLoader;

/**
 * Content-module implementation of WpReconciliationSourceInterface (DECISION U v1.19).
 *
 * The WordPress boundary for reconciliation detection — the only reconciliation class that
 * calls global WP functions ($wpdb, get_posts, get_terms) and reads wp_hsp_outbox. All
 * reads are READ-ONLY; this class never writes WordPress or PG (Rule 1). Symmetric with
 * ContentReplayEmitter (core owns the contract; the module implements it — Rule 5).
 *
 * Checksum recompute (incremental/full, DECISION U D1/D2) reuses the exact
 * loader→extractor→transformer canonical pipeline the upsert handlers use, so the recomputed
 * checksum is identical to what a fresh projection would store (OPEN-11 Option A). The
 * canonical checksum is the authoritative staleness signal for taxonomies, which have no
 * modified timestamp (D2).
 */
final class WpReconciliationSource implements WpReconciliationSourceInterface
{
    private const AGGREGATE_TYPES = ['page', 'post', 'category', 'media'];

    /** post_status values in the public set (OPEN-10). */
    private const PUBLIC_POST_STATUS = 'publish';

    public function __construct(
        private readonly WpContentLoader     $loader,
        private readonly PageExtractor       $pageExtractor,
        private readonly PostExtractor       $postExtractor,
        private readonly CategoryExtractor   $categoryExtractor,
        private readonly MediaExtractor      $mediaExtractor,
        private readonly PageTransformer     $pageTransformer,
        private readonly PostTransformer     $postTransformer,
        private readonly CategoryTransformer $categoryTransformer,
        private readonly MediaTransformer    $mediaTransformer,
    ) {}

    /** @return string[] */
    public function getSupportedAggregateTypes(): array
    {
        return self::AGGREGATE_TYPES;
    }

    /** @return string[] */
    public function listAggregateIds(string $aggregateType, int $afterId, int $limit): array
    {
        // Paging by "ID > afterId" cannot be expressed through WP_Query params, so the
        // corpus is paged with direct bounded $wpdb reads (still WP-owned tables, read-only).
        switch ($aggregateType) {
            case 'page':
            case 'post':
                return $this->listPostIdsAfter($aggregateType, $afterId, $limit);

            case 'category':
                return $this->listTermIdsAfter($afterId, $limit);

            case 'media':
                return $this->listAttachmentIdsAfter($afterId, $limit);

            default:
                return [];
        }
    }

    public function getSourceState(string $aggregateType, string $aggregateId): SourceState
    {
        switch ($aggregateType) {
            case 'page':
            case 'post':
                $post = $this->loader->loadPost((int) $aggregateId);
                if ($post === null) {
                    return SourceState::absent();
                }
                $status   = (string) ($post['post_status'] ?? '');
                $public   = $status === self::PUBLIC_POST_STATUS;
                $modified = $this->parseGmt($post['post_modified_gmt'] ?? null);

                return new SourceState(true, $public, $modified);

            case 'category':
                $term = $this->loader->loadTerm((int) $aggregateId);
                if ($term === null) {
                    return SourceState::absent();
                }
                // Terms have no post_status (public == exists) and no modified timestamp (D2).
                return new SourceState(true, true, null);

            case 'media':
                $attachment = $this->loader->loadAttachment((int) $aggregateId);
                if ($attachment === null) {
                    return SourceState::absent();
                }
                // Attachments carry post_status='inherit', outside the {publish} public set,
                // so existence is membership — as for terms. Unlike terms they DO carry
                // post_modified_gmt, so incremental detection (D1) works on them.
                return new SourceState(
                    true,
                    true,
                    $this->parseGmt($attachment['post_modified_gmt'] ?? null),
                );

            default:
                return SourceState::absent();
        }
    }

    public function computeCurrentChecksum(string $aggregateType, string $aggregateId): ?string
    {
        switch ($aggregateType) {
            case 'page':
                $post = $this->loader->loadPost((int) $aggregateId);
                if ($post === null || (string) ($post['post_status'] ?? '') !== self::PUBLIC_POST_STATUS) {
                    return null;
                }
                $meta   = $this->loader->loadPostMeta((int) $aggregateId);
                $source = $this->pageExtractor->extract($post, $meta);
                return $this->pageTransformer->transform($source)->getChecksum();

            case 'post':
                $post = $this->loader->loadPost((int) $aggregateId);
                if ($post === null || (string) ($post['post_status'] ?? '') !== self::PUBLIC_POST_STATUS) {
                    return null;
                }
                $meta = $this->loader->loadPostMeta((int) $aggregateId);
                $cats = $this->loader->loadPostCategoryIds((int) $aggregateId);
                $source = $this->postExtractor->extract($post, $meta, $cats);
                return $this->postTransformer->transform($source)->getChecksum();

            case 'category':
                $term = $this->loader->loadTerm((int) $aggregateId);
                if ($term === null) {
                    return null;
                }
                $source = $this->categoryExtractor->extract($term);
                return $this->categoryTransformer->transform($source)->getChecksum();

            case 'media':
                $attachment = $this->loader->loadAttachment((int) $aggregateId);
                if ($attachment === null) {
                    return null;
                }
                $meta   = $this->loader->loadPostMeta((int) $aggregateId);
                $source = $this->mediaExtractor->extract($attachment, $meta);
                return $this->mediaTransformer->transform($source)->getChecksum();

            default:
                return null;
        }
    }

    public function hasPendingOutbox(string $aggregateType, string $aggregateId): bool
    {
        global $wpdb;

        if (! isset($wpdb) || ! is_object($wpdb)) {
            // No WP DB layer available (should not happen in the worker bootstrap).
            return false;
        }

        $table = $wpdb->prefix . 'hsp_outbox';

        $sql = $wpdb->prepare(
            "SELECT 1 FROM {$table}
             WHERE aggregate_type = %s AND aggregate_id = %s AND status = 'pending'
             LIMIT 1",
            $aggregateType,
            $aggregateId,
        );

        return (bool) $wpdb->get_var($sql);
    }

    // -------------------------------------------------------------------------
    // WP paging helpers (direct bounded queries — WP_Query cannot express "ID >").
    // -------------------------------------------------------------------------

    /** @return string[] */
    private function listPostIdsAfter(string $postType, int $afterId, int $limit): array
    {
        global $wpdb;

        if (! isset($wpdb) || ! is_object($wpdb)) {
            return [];
        }

        $sql = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = %s AND post_status = %s AND ID > %d
             ORDER BY ID ASC
             LIMIT %d",
            $postType,
            self::PUBLIC_POST_STATUS,
            $afterId,
            $limit,
        );

        $rows = $wpdb->get_col($sql);

        return is_array($rows) ? array_map('strval', $rows) : [];
    }

    /**
     * Attachments are paged separately from posts/pages: they carry post_status='inherit',
     * so the publish-only predicate in listPostIdsAfter() would return an empty corpus and
     * media would silently never reconcile.
     *
     * @return string[]
     */
    private function listAttachmentIdsAfter(int $afterId, int $limit): array
    {
        global $wpdb;

        if (! isset($wpdb) || ! is_object($wpdb)) {
            return [];
        }

        $sql = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'attachment' AND ID > %d
             ORDER BY ID ASC
             LIMIT %d",
            $afterId,
            $limit,
        );

        $rows = $wpdb->get_col($sql);

        return is_array($rows) ? array_map('strval', $rows) : [];
    }

    /** @return string[] */
    private function listTermIdsAfter(int $afterId, int $limit): array
    {
        global $wpdb;

        if (! isset($wpdb) || ! is_object($wpdb)) {
            return [];
        }

        $sql = $wpdb->prepare(
            "SELECT t.term_id
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
             WHERE tt.taxonomy = 'category' AND t.term_id > %d
             ORDER BY t.term_id ASC
             LIMIT %d",
            $afterId,
            $limit,
        );

        $rows = $wpdb->get_col($sql);

        return is_array($rows) ? array_map('strval', $rows) : [];
    }

    private function parseGmt(mixed $value): ?\DateTimeImmutable
    {
        if (! is_string($value) || $value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value . ' UTC');
        } catch (\Exception) {
            return null;
        }
    }
}
