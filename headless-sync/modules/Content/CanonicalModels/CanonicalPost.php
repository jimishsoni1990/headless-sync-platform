<?php

declare(strict_types=1);

namespace HSP\Modules\Content\CanonicalModels;

use HSP\Core\Contracts\CanonicalModelInterface;

/**
 * Canonical representation of a WordPress post (post_type='post').
 *
 * Produced by PostTransformer from PostSourceModel. Delivery-target agnostic —
 * no PostgreSQL column names, no checksum here (checksum is computed write-side
 * per DECISION 3, not stored on the canonical model).
 *
 * Immutable value object; no side effects.
 */
final class CanonicalPost implements CanonicalModelInterface
{
    /**
     * @param int                  $postId      wp_posts.ID
     * @param string               $title       Normalised post title
     * @param string               $content     Normalised post content (HTML)
     * @param string               $excerpt     Normalised post excerpt
     * @param string               $slug        URL slug
     * @param string               $status      Post status (e.g. 'publish')
     * @param string               $author      WP user login of the author
     * @param \DateTimeImmutable   $publishedAt UTC publish instant
     * @param \DateTimeImmutable   $modifiedAt  UTC last-modified instant
     * @param list<int>            $categoryIds Term IDs from the 'category' taxonomy
     * @param array<string,string> $meta        Post meta key→value
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

    public function getSourceId(): int
    {
        return $this->postId;
    }

    public function getChecksum(): string
    {
        // Fixed field order (must match the write-side recomputation in P1A-S4 adapters):
        // postId | title | content | excerpt | slug | status | author |
        // publishedAt(ATOM) | modifiedAt(ATOM) | categoryIds(JSON) | meta(JSON)
        // Separator: chr(0) — cannot appear in any field value.
        // categoryIds: sorted ascending — it is a set; insertion order is not meaningful.
        // meta: ksorted recursively — key order from WordPress is not guaranteed stable.
        // Both encoded with json_encode(JSON_UNESCAPED_UNICODE); no PHP serialize().
        $categoryIds = $this->categoryIds;
        sort($categoryIds);
        $meta = $this->meta;
        ksort($meta);
        return hash('sha256', implode("\0", [
            (string) $this->postId,
            $this->title,
            $this->content,
            $this->excerpt,
            $this->slug,
            $this->status,
            $this->author,
            $this->publishedAt->format(\DateTimeInterface::ATOM),
            $this->modifiedAt->format(\DateTimeInterface::ATOM),
            json_encode($categoryIds, JSON_UNESCAPED_UNICODE),
            json_encode($meta, JSON_UNESCAPED_UNICODE),
        ]));
    }
}
