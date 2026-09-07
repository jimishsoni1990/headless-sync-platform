<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Resources;

use HSP\Core\Contracts\ResourceInterface;

/**
 * Shapes a commerce.taxonomies row into the published term contract.
 *
 * `parent_id` is published as the SOURCE term id rather than the projection UUID, so a
 * consumer can reconstruct the tree from `id`/`parent` without a second lookup, and 0 becomes
 * null because "top level" reads better than a magic zero in JSON.
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
        $parent = (int) ($row['parent_id'] ?? 0);

        return [
            'id'          => (string) ($row['id'] ?? ''),
            'source_id'   => (int) ($row['source_term_id'] ?? 0),
            'slug'        => (string) ($row['slug'] ?? ''),
            'name'        => (string) ($row['name'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'parent'      => $parent === 0 ? null : $parent,
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
            'data' => array_map(fn (array $row): array => $this->toArray($row), $rows),
            'meta' => ['next_cursor' => $nextCursor],
        ];
    }
}
