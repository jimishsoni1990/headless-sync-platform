<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Resources;

use HSP\Core\Contracts\ResourceInterface;

/**
 * Serializes content.taxonomies projection rows to the /hsp/v1/categories response contract.
 *
 * Authority: Doc 9 §11 — serialization only; no business logic. ADR-040 — no
 * internal columns leaked (id UUID, source_term_id, taxonomy_type, checksum,
 * synced_at, created_at not exposed). ADR-038 — transport-agnostic; no WP_REST_* types.
 *
 * Contract fields exposed:
 *   slug, name, description, parent_id, post_count
 */
final class CategoryResource implements ResourceInterface
{
    public function toArray(array $row): array
    {
        return [
            'slug'        => $row['slug'],
            'name'        => $row['name'],
            'description' => $row['description'] ?? '',
            'parent_id'   => isset($row['parent_id']) ? (int) $row['parent_id'] : 0,
            'post_count'  => isset($row['post_count']) ? (int) $row['post_count'] : 0,
        ];
    }

    public function toCollection(array $rows, ?string $nextCursor): array
    {
        return [
            'data'        => array_values(array_map($this->toArray(...), $rows)),
            'next_cursor' => $nextCursor,
        ];
    }
}
