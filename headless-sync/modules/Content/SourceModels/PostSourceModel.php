<?php

declare(strict_types=1);

namespace HSP\Modules\Content\SourceModels;

/**
 * Normalized, immutable snapshot of a WordPress post (post_type='post').
 *
 * Produced by PostExtractor from raw WP_Post-shaped data.
 * Consumed by PostTransformer (P1A-S3) — never by adapters directly.
 *
 * All fields represent source-system state as it existed at extraction time.
 * No delivery concerns, no checksum, no canonical model shape here.
 */
final class PostSourceModel
{
    /**
     * @param int                    $postId          wp_posts.ID
     * @param string                 $title           post_title (raw, un-filtered)
     * @param string                 $content         post_content (raw)
     * @param string                 $excerpt         post_excerpt (raw)
     * @param string                 $slug            post_name (URL slug)
     * @param string                 $status          post_status at extraction time
     * @param string                 $author          WP user login of the post author
     * @param \DateTimeImmutable     $publishedAt     post_date_gmt as UTC instant
     * @param \DateTimeImmutable     $modifiedAt      post_modified_gmt as UTC instant
     * @param list<int>              $categoryIds     term IDs from the 'category' taxonomy
     * @param array<string,string>   $meta            post meta key→value (string values; cast at extraction)
     */
    public function __construct(
        public readonly int $postId,
        public readonly string $title,
        public readonly string $content,
        public readonly string $excerpt,
        public readonly string $slug,
        public readonly string $status,
        public readonly string $author,
        public readonly \DateTimeImmutable $publishedAt,
        public readonly \DateTimeImmutable $modifiedAt,
        public readonly array $categoryIds,
        public readonly array $meta,
    ) {}
}
