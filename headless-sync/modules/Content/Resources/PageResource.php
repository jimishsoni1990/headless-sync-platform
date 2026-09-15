<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Resources;

use HSP\Core\Contracts\ResourceInterface;
use HSP\Core\Delivery\JsonMap;

/**
 * Serializes content.pages projection rows to the /hsp/v1/pages response contract.
 *
 * Authority: Doc 9 §11 — serialization, formatting, contract shaping, response
 * consistency. No business logic. ADR-040 — no internal columns leaked (id UUID,
 * source_post_id, checksum, synced_at, created_at, meta_jsonb internals not exposed).
 * ADR-038 — transport-agnostic; no WP_REST_* types.
 *
 * Contract fields exposed:
 *   slug, path, title, content, status, parent_id, menu_order, published_at, updated_at, meta
 *
 * meta_jsonb is decoded from the JSON string the DB driver returns; exposed as 'meta'.
 * Timestamps are returned as ISO-8601 strings (UTC).
 *
 * `path` is NULL only when the projection cannot reconstruct one: an ancestor that was never
 * published has no row to contribute its slug (DECISION AE), and a corrupt parent cycle never
 * reaches the root. Null says "this page has no canonical address", which is the truth — the
 * lookup endpoint cannot resolve it either. Publishing the bare leaf slug instead would hand
 * consumers an address that 404s, reviving exactly the fallback DECISION AF removed.
 */
final class PageResource implements ResourceInterface
{
    public function toArray(array $row): array
    {
        return [
            'slug'        => $row['slug'],
            // The canonical public page identity (Finding 005): the full ancestor path, exactly
            // what GET /pages/{path} takes. Supplied ALREADY RESOLVED by the Query Provider —
            // hierarchy is query logic, and a Resource that walked parents would be a second
            // hierarchy implementation free to disagree with the first (Doc 9 §11).
            'path'        => isset($row['path']) ? (string) $row['path'] : null,
            'title'       => $row['title'],
            'content'     => $row['content'],
            'status'      => $row['status'],
            'parent_id'   => isset($row['parent_id']) ? (int) $row['parent_id'] : 0,
            'menu_order'  => isset($row['menu_order']) ? (int) $row['menu_order'] : 0,
            'published_at' => $this->normaliseTimestamp($row['published_at'] ?? null),
            'updated_at'  => $this->normaliseTimestamp($row['updated_at'] ?? null),
            'meta'        => JsonMap::decode($row['meta_jsonb'] ?? null),
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
        return MediaReference::fromRow($row, 'fm_');
    }

    public function toCollection(array $rows, ?string $nextCursor): array
    {
        return [
            'data'        => array_values(array_map($this->toArray(...), $rows)),
            'next_cursor' => $nextCursor,
        ];
    }

    /** Normalise a DB timestamp string to ISO-8601 UTC (or null). */
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
