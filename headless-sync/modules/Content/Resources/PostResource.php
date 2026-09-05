<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Resources;

use HSP\Core\Contracts\ResourceInterface;

/**
 * Serializes content.posts projection rows to the /hsp/v1/posts response contract.
 *
 * Authority: Doc 9 §11 — serialization only; no business logic. ADR-040 — no
 * internal columns leaked (id UUID, source_post_id, checksum, synced_at, created_at,
 * meta_jsonb internals not exposed). ADR-038 — transport-agnostic; no WP_REST_* types.
 *
 * Contract fields exposed:
 *   slug, title, content, excerpt, status, author, published_at, updated_at, meta
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
            'meta'        => $this->decodeMeta($row['meta_jsonb'] ?? null),
            'featured_media' => $this->featuredMedia($row),
        ];
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
        if (! isset($row['fm_url']) || (string) $row['fm_url'] === '') {
            return null;
        }

        return [
            'slug'      => (string) ($row['fm_slug'] ?? ''),
            'url'       => (string) $row['fm_url'],
            'alt_text'  => (string) ($row['fm_alt_text'] ?? ''),
            'mime_type' => (string) ($row['fm_mime_type'] ?? ''),
            'width'     => (int) ($row['fm_width'] ?? 0),
            'height'    => (int) ($row['fm_height'] ?? 0),
            'sizes'     => $this->decodeMeta($row['fm_sizes_jsonb'] ?? null),
        ];
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

    /** @return array<string,mixed> */
    private function decodeMeta(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, associative: true);
        return is_array($decoded) ? $decoded : [];
    }
}
