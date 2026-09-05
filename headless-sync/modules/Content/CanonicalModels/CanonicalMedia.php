<?php

declare(strict_types=1);

namespace HSP\Modules\Content\CanonicalModels;

use HSP\Core\Contracts\CanonicalModelInterface;

/**
 * Canonical representation of a WordPress attachment (post_type='attachment').
 *
 * Produced by MediaTransformer from MediaSourceModel. Delivery-target agnostic —
 * no PostgreSQL column names, no checksum stored here (the checksum is computed
 * write-side per DECISION 3).
 *
 * Size variants arrive here already RESOLVED to absolute URLs. Publishing bare filenames
 * would force every consumer to reconstruct upload paths, which couples them to WordPress
 * internals (Rule 6) — the transformation is exactly what "transform before persist"
 * (Rule 2) means for media.
 *
 * Immutable value object; no side effects.
 */
final class CanonicalMedia implements CanonicalModelInterface
{
    /**
     * @param int    $postId       wp_posts.ID of the attachment
     * @param string $slug         URL slug
     * @param string $title        Normalised title
     * @param string $mimeType     e.g. 'image/jpeg'
     * @param string $url          Absolute URL of the original file
     * @param string $altText      Alternative text ('' when unset)
     * @param string $caption      Caption
     * @param string $description  Long description
     * @param int    $width        Original width in px (0 for non-images)
     * @param int    $height       Original height in px (0 for non-images)
     * @param array<string, array{url:string,width:int,height:int,mime_type:string}> $sizes
     *        Registered size variants keyed by size name, each with an absolute URL.
     * @param int    $attachedToId post_parent (0 = unattached); soft reference (ADR-013)
     * @param \DateTimeImmutable   $publishedAt UTC upload instant
     * @param \DateTimeImmutable   $modifiedAt  UTC last-modified instant
     * @param array<string,mixed> $meta        Public post meta key→value
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

    public function getSourceId(): int
    {
        return $this->postId;
    }

    public function getChecksum(): string
    {
        // Fixed field order; separator chr(0) — cannot appear in any field value.
        // sizes and meta are ksorted: WordPress does not guarantee key order, and an
        // unstable order would change the checksum for unchanged content, which would
        // defeat the DECISION 3 write-suppress (it compares projection checksums).
        // Encoded with json_encode(JSON_UNESCAPED_UNICODE); no PHP serialize().
        $sizes = $this->sizes;
        ksort($sizes);

        $meta = $this->meta;
        self::ksortRecursive($meta);

        return hash('sha256', implode("\0", [
            (string) $this->postId,
            $this->slug,
            $this->title,
            $this->mimeType,
            $this->url,
            $this->altText,
            $this->caption,
            $this->description,
            (string) $this->width,
            (string) $this->height,
            json_encode($sizes, JSON_UNESCAPED_UNICODE),
            (string) $this->attachedToId,
            $this->publishedAt->format(\DateTimeInterface::ATOM),
            $this->modifiedAt->format(\DateTimeInterface::ATOM),
            json_encode($meta, JSON_UNESCAPED_UNICODE),
        ]));
    }

    /**
     * Sort array keys recursively, in place.
     *
     * The checksum docblock has always claimed meta is "ksorted recursively"; until P1B-S4 the
     * code sorted only the top level, which was harmless while meta was a flat map of strings.
     * Structured ACF values make it load-bearing: WordPress does not guarantee nested key order,
     * so without this two identical repeater values could hash differently and the projection
     * would churn (or read as drifted) forever.
     *
     * @param array<mixed> $value
     */
    private static function ksortRecursive(array &$value): void
    {
        ksort($value);

        foreach ($value as &$item) {
            if (is_array($item)) {
                self::ksortRecursive($item);
            }
        }
        unset($item);
    }
}
