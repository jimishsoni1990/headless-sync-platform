<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Resources;

use HSP\Core\Contracts\ResourceInterface;

/**
 * Shapes a commerce.attributes row into the published attribute contract.
 *
 * `taxonomy` carries the full `pa_`-prefixed name deliberately: it is the key a consumer uses
 * to find this attribute's terms, and stripping the prefix would force every consumer to add it
 * back before it could match anything.
 */
final class AttributeResource implements ResourceInterface
{
    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function toArray(array $row): array
    {
        return [
            // NO `id` AND NO `source_id` (FLAG-COMMSOURCEID-1) — both Removed. An attribute
            // definition is addressed by `taxonomy` and nothing else: `/product-attributes/
            // {taxonomy}` and `/product-attributes/{taxonomy}/terms` both key on it, and the
            // product filter takes `?attribute=pa_colour`. Neither removed field was reachable
            // from any published operation, so there was nothing for them to anchor.
            'taxonomy'     => (string) ($row['slug'] ?? ''),
            'name'         => (string) ($row['name'] ?? ''),
            'type'         => (string) ($row['type'] ?? 'select'),
            'order_by'     => (string) ($row['order_by'] ?? 'menu_order'),
            'has_archives' => $this->bool($row['has_archives'] ?? false),
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

    /** PostgreSQL returns booleans as 't'/'f' over the text protocol. */
    private function bool(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === '1' || $value === 1;
    }
}
