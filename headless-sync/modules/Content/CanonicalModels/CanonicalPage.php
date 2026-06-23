<?php

declare(strict_types=1);

namespace HSP\Modules\Content\CanonicalModels;

use HSP\Core\Contracts\CanonicalModelInterface;

/**
 * Canonical representation of a WordPress page (post_type='page').
 *
 * Produced by PageTransformer from PageSourceModel. Delivery-target agnostic —
 * no PostgreSQL column names, no checksum here (checksum is computed write-side
 * per DECISION 3, not stored on the canonical model).
 *
 * Immutable value object; no side effects.
 */
final class CanonicalPage implements CanonicalModelInterface
{
    /**
     * @param int                  $postId      wp_posts.ID
     * @param string               $title       Normalised page title
     * @param string               $content     Normalised page content (HTML)
     * @param string               $slug        URL slug
     * @param string               $status      Post status (e.g. 'publish')
     * @param int                  $parentId    Parent page post ID (0 = top-level)
     * @param int                  $menuOrder   Menu order for hierarchical trees
     * @param \DateTimeImmutable   $publishedAt UTC publish instant
     * @param \DateTimeImmutable   $modifiedAt  UTC last-modified instant
     * @param array<string,string> $meta        Post meta key→value
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

    public function getSourceId(): int
    {
        return $this->postId;
    }

    public function getChecksum(): string
    {
        // Fixed field order (must match the write-side recomputation in P1A-S4 adapters):
        // postId | title | content | slug | status | parentId | menuOrder |
        // publishedAt(ATOM) | modifiedAt(ATOM) | meta(JSON)
        // Separator: chr(0) — cannot appear in any field value.
        // meta: ksorted — key order from WordPress is not guaranteed stable.
        // Encoded with json_encode(JSON_UNESCAPED_UNICODE); no PHP serialize().
        $meta = $this->meta;
        ksort($meta);
        return hash('sha256', implode("\0", [
            (string) $this->postId,
            $this->title,
            $this->content,
            $this->slug,
            $this->status,
            (string) $this->parentId,
            (string) $this->menuOrder,
            $this->publishedAt->format(\DateTimeInterface::ATOM),
            $this->modifiedAt->format(\DateTimeInterface::ATOM),
            json_encode($meta, JSON_UNESCAPED_UNICODE),
        ]));
    }
}
