<?php

declare(strict_types=1);

namespace HSP\Modules\Content\SourceModels;

/**
 * Normalized, immutable snapshot of a WordPress page (post_type='page').
 *
 * Produced by PageExtractor from raw WP_Post-shaped data.
 * Consumed by PageTransformer (P1A-S3) — never by adapters directly.
 *
 * Pages share most fields with posts but have no category relationship and carry
 * a parent_id for hierarchical page trees.
 */
final class PageSourceModel
{
    /**
     * @param int                    $postId       wp_posts.ID
     * @param string                 $title        post_title (raw)
     * @param string                 $content      post_content (raw)
     * @param string                 $slug         post_name (URL slug)
     * @param string                 $status       post_status at extraction time
     * @param int                    $parentId     post_parent (0 = top-level page)
     * @param int                    $menuOrder    menu_order
     * @param \DateTimeImmutable     $publishedAt  post_date_gmt as UTC instant
     * @param \DateTimeImmutable     $modifiedAt   post_modified_gmt as UTC instant
     * @param array<string,string>   $meta         post meta key→value (string values)
     */
    public function __construct(
        public readonly int $postId,
        public readonly string $title,
        public readonly string $content,
        public readonly string $slug,
        public readonly string $status,
        public readonly int $parentId,
        public readonly int $menuOrder,
        public readonly \DateTimeImmutable $publishedAt,
        public readonly \DateTimeImmutable $modifiedAt,
        public readonly array $meta,
    ) {}
}
