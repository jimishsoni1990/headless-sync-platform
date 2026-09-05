<?php

declare(strict_types=1);

namespace HSP\Modules\Content\SourceModels;

/**
 * Normalized, immutable snapshot of a WordPress attachment (post_type='attachment').
 *
 * Produced by MediaExtractor from the loader's attachment shape.
 * Consumed by MediaTransformer — never by adapters directly.
 *
 * Attachments differ from posts/pages in three ways that shape this model:
 *   - post_status is always 'inherit', so the {publish} public set (OPEN-10) does not
 *     apply and no status field is carried; existence is the membership signal.
 *   - the public URL and the registered size variants are resolved by WordPress, so the
 *     loader supplies them (no WP call may happen in an extractor or a handler).
 *   - post_excerpt is the caption and post_content is the description (WP convention).
 */
final class MediaSourceModel
{
    /**
     * @param int                    $postId       wp_posts.ID of the attachment
     * @param string                 $slug         post_name (URL slug)
     * @param string                 $title        post_title (raw)
     * @param string                 $mimeType     post_mime_type, e.g. 'image/jpeg'
     * @param string                 $url          Resolved public URL of the original file
     * @param string                 $altText      _wp_attachment_image_alt, '' when unset
     * @param string                 $caption      post_excerpt
     * @param string                 $description  post_content
     * @param int                    $width        Original width in px (0 for non-images)
     * @param int                    $height       Original height in px (0 for non-images)
     * @param array<string, array{file:string,width:int,height:int,mime_type:string}> $sizes
     *        Registered size variants keyed by size name, as WordPress records them
     *        (filename only — the transformer resolves each to a URL).
     * @param int                    $attachedToId post_parent (0 = unattached); soft reference
     * @param \DateTimeImmutable     $publishedAt  post_date_gmt as a UTC instant
     * @param \DateTimeImmutable     $modifiedAt   post_modified_gmt as a UTC instant
     * @param array<string,string>   $meta         public post meta key→value
     */
    public function __construct(
        public readonly int $postId,
        public readonly string $slug,
        public readonly string $title,
        public readonly string $mimeType,
        public readonly string $url,
        public readonly string $altText,
        public readonly string $caption,
        public readonly string $description,
        public readonly int $width,
        public readonly int $height,
        public readonly array $sizes,
        public readonly int $attachedToId,
        public readonly \DateTimeImmutable $publishedAt,
        public readonly \DateTimeImmutable $modifiedAt,
        public readonly array $meta,
    ) {
    }
}
