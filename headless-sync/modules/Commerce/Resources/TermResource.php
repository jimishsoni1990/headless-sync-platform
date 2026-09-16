<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Resources;

use HSP\Core\Contracts\ResourceInterface;

/**
 * Shapes a commerce.taxonomies row into the published PRODUCT CATEGORY contract.
 *
 * A category is identified by its `slug` and points at its parent by `parent_slug` — the SAME
 * public key, so a consumer rebuilds the tree from the listing alone. No source term id is
 * published (FLAG-COMMCATPARENT-1): the P2-S3 preflight verified that `wp_unique_term_slug()`
 * makes a `product_cat` slug unique across the whole taxonomy, not per parent, so the slug is an
 * unambiguous public key and a WordPress term id is never needed to address, filter or relate a
 * category.
 *
 * `parent_slug` is NOT the old integer `parent` retyped. That field — and `source_id`, which
 * existed only to resolve it and to feed the removed `?parent={source-term-id}` filter — were
 * Removed. This is a new field with a new meaning.
 *
 * NULL means "no projected parent": the category is top-level, OR its parent has not projected
 * yet / has been tombstoned (AG-7 — hierarchy is a soft reference and projection order is not
 * guaranteed). That state converges through normal projection; it is never an error, and the
 * category itself is always published.
 *
 * `id`, `source_term_id` and `parent_id` stay in the query row as internal identity — cursor
 * tiebreaker, source key and join key — and are never serialized.
 *
 * No permalink field: WooCommerce category URLs nest and their base is configurable, so any
 * URL here would be a guess (FLAG-COMMPERMA-1).
 */
final class TermResource implements ResourceInterface
{
    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function toArray(array $row): array
    {
        return [
            'slug'        => (string) ($row['slug'] ?? ''),
            'name'        => (string) ($row['name'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'parent_slug' => isset($row['parent_slug']) ? (string) $row['parent_slug'] : null,
            'count'       => (int) ($row['term_count'] ?? 0),
        ];
    }

    /**
     * @param array<int, array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    public function toCollection(array $rows, ?string $nextCursor): array
    {
        return [
            'data'        => array_values(array_map(fn (array $row): array => $this->toArray($row), $rows)),
            'next_cursor' => $nextCursor,
        ];
    }
}
