<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Resources;

use HSP\Core\Contracts\ResourceInterface;
use HSP\Core\Delivery\JsonMap;

/**
 * Serializes content.posts projection rows to the /hsp/v1/posts response contract.
 *
 * Authority: Doc 9 §11 — serialization only; no business logic. ADR-040 — no
 * internal columns leaked (id UUID, source_post_id, checksum, synced_at, created_at,
 * meta_jsonb internals not exposed). ADR-038 — transport-agnostic; no WP_REST_* types.
 *
 * Contract fields exposed:
 *   slug, title, content, excerpt, status, author, published_at, updated_at, meta,
 *   featured_media, tags, categories
 *
 * meta_jsonb is decoded from the JSON string the DB driver returns; exposed as 'meta'.
 * Timestamps are returned as ISO-8601 strings (UTC).
 */
final class PostResource implements ResourceInterface
{
    public function toArray(array $row): array
    {
        return [
            'slug'        => $row['slug'],
            'title'       => $row['title'],
            'content'     => $row['content'],
            'excerpt'     => $row['excerpt'] ?? '',
            'status'      => $row['status'],
            'author'      => $row['author'] ?? '',
            'published_at' => $this->normaliseTimestamp($row['published_at'] ?? null),
            'updated_at'  => $this->normaliseTimestamp($row['updated_at'] ?? null),
            'meta'        => JsonMap::decode($row['meta_jsonb'] ?? null),
            'featured_media' => $this->featuredMedia($row),
            'tags'        => $this->taxonomyRefs($row, 'tags_json'),
            'categories'  => $this->taxonomyRefs($row, 'categories_json'),
        ];
    }

    /**
     * One of the post's taxonomy reference lists, decoded from the aggregate the query provider
     * built (tags: P1B-S3; categories: Finding 003).
     *
     * `{slug, name}` pairs and nothing else: slug is the public addressing identity the consumer
     * routes on and the same value `?category=` / `?tag=` accepts, name is the label. Internal
     * identity (the projection UUID, source_term_id, WP term ids) stays internal — ADR-040 /
     * DECISION AJ (AJ-2).
     *
     * Always an array — never null — so consumers can iterate without a null check; a post with
     * no terms in that taxonomy is an empty list, which is the honest answer. Both taxonomies are
     * scoped by taxonomy_type in SQL, so a category and a tag sharing a slug never cross over.
     *
     * @param array<string,mixed> $row
     * @return list<array<string,mixed>>
     */
    private function taxonomyRefs(array $row, string $column): array
    {
        $json = $row[$column] ?? null;

        if (! is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, associative: true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * Shape the LEFT JOIN-ed featured image, or null.
     *
     * Null covers all three no-image cases identically, which is what keeps consumers simple: no
     * featured image set, the attachment never projected, or the attachment soft-deleted. A soft
     * reference (ADR-013) can always dangle; the contract answers null rather than leaking a bare
     * id the consumer cannot resolve (Rule 6).
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    private function featuredMedia(array $row): ?array
    {
        return MediaReference::fromRow($row, 'fm_');
    }

    public function toCollection(array $rows, ?string $nextCursor): array
    {
        return [
            'data'        => array_values(array_map($this->toArray(...), $rows)),
            'next_cursor' => $nextCursor,
        ];
    }

    private function normaliseTimestamp(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))
                ->format(\DateTimeInterface::ATOM);
        } catch (\Throwable) {
            return null;
        }
    }
}
