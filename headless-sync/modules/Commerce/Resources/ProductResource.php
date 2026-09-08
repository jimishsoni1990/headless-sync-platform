<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Resources;

use HSP\Core\Contracts\ResourceInterface;

/**
 * Shapes a commerce.products projection row into the published product contract.
 *
 * Transport-agnostic (ADR-038): plain arrays, no WP_REST types.
 *
 * Money is published as a STRING, not a float. A JSON number would hand consumers a binary
 * floating-point value for a decimal quantity, which is how 19.99 becomes 19.989999999999998
 * in a cart total. The exact decimal string is what the projection stores and what the
 * checksum was computed over (Requirement C).
 *
 * Media appears as REFERENCES ONLY (AG-10): Commerce stores WordPress attachment ids and
 * `content.media` remains the single attachment projection. Expanding them into full media
 * objects would need the Core capability contract AG-10 describes, and Phase 2 deliberately
 * does not build that early — the contract ships references and defers expansion.
 *
 * NO `permalink` FIELD. The WooCommerce permalink base is configurable
 * (`woocommerce_permalinks`), so any URL published here would be a guess that goes stale on a
 * settings change with no event to repair it — raised as FLAG-COMMPERMA-1.
 */
final class ProductResource implements ResourceInterface
{
    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function toArray(array $row): array
    {
        return [
            'id'                 => (string) ($row['id'] ?? ''),
            'source_id'          => (int) ($row['source_product_id'] ?? 0),
            'sku'                => $this->nullableString($row['sku'] ?? null),
            'slug'               => (string) ($row['slug'] ?? ''),
            'name'               => (string) ($row['name'] ?? ''),
            'description'        => (string) ($row['description'] ?? ''),
            'short_description'  => (string) ($row['short_description'] ?? ''),
            'type'               => (string) ($row['product_type'] ?? ''),
            'catalog_visibility' => (string) ($row['catalog_visibility'] ?? 'visible'),
            'featured'           => $this->bool($row['featured'] ?? false),
            'prices'             => [
                'price'         => $this->nullableString($row['price'] ?? null),
                'regular_price' => $this->nullableString($row['regular_price'] ?? null),
                'sale_price'    => $this->nullableString($row['sale_price'] ?? null),
            ],
            'media'              => [
                'featured_id' => (int) ($row['featured_media_id'] ?? 0),
                'gallery_ids' => $this->intList($row['gallery_media_ids'] ?? '[]'),
            ],
            // NULL when no inventory row has projected — UNKNOWN, deliberately distinguishable
            // from out of stock (AG-8 / Part 4b). A consumer that chooses to treat null as false
            // is making its own call; the contract does not make it for them.
            'stock'              => [
                'status'     => $this->nullableString($row['stock_status'] ?? null),
                'managed'    => isset($row['manages_stock']) ? $this->bool($row['manages_stock']) : null,
                // NULL for both "not tracked" and "not yet known", which are different facts —
                // `managed` is what tells them apart.
                'quantity'   => ($row['stock_quantity'] ?? null) !== null
                    ? (int) $row['stock_quantity']
                    : null,
                'backorders' => $this->nullableString($row['backorders'] ?? null),
            ],
            'published_at'       => $this->nullableString($row['published_at'] ?? null),
            'updated_at'         => $this->nullableString($row['updated_at'] ?? null),
            'meta'               => $this->jsonObject($row['meta_jsonb'] ?? '{}'),
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

    private function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    /** PostgreSQL returns booleans as 't'/'f' over the text protocol. */
    private function bool(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === '1' || $value === 1;
    }

    /** @return list<int> */
    private function intList(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, associative: true);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map('intval', array_filter($value, 'is_scalar')));
    }

    /** @return array<string,mixed> */
    private function jsonObject(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, associative: true);
        }

        return is_array($value) ? $value : [];
    }
}
