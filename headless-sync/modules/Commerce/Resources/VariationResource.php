<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Resources;

use HSP\Core\Contracts\ResourceInterface;

/**
 * Shapes a commerce.product_variations row into the published variation contract.
 *
 * `attributes` is published as an OBJECT of taxonomy => selected value, empty values included.
 * That preserves the "any" semantic WooCommerce carries (verified: an empty value means the
 * variation matches every value of that attribute) — a consumer matching a shopper's selection
 * needs to know that `pa_size: ""` accepts any size, which is not the same as the variation
 * having no size dimension at all.
 *
 * `media` is an id reference, never an expanded object: content.media owns attachment state and
 * Commerce must not require the Content module to be active (AG-10).
 */
final class VariationResource implements ResourceInterface
{
    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function toArray(array $row): array
    {
        return [
            'id'         => (string) ($row['id'] ?? ''),
            'source_id'  => (int) ($row['source_variation_id'] ?? 0),
            // The PARENT's source id. Published because a consumer holding a variation needs to
            // get back to its product, and because it is the key this projection is queried by.
            'product_id' => (int) ($row['source_parent_id'] ?? 0),
            'sku'        => $this->nullableString($row['sku'] ?? null),
            'name'       => (string) ($row['name'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'status'     => (string) ($row['status'] ?? ''),
            // Exact decimal strings, never JSON numbers (Requirement C).
            'prices'     => [
                'price'         => $this->nullableString($row['price'] ?? null),
                'regular_price' => $this->nullableString($row['regular_price'] ?? null),
                'sale_price'    => $this->nullableString($row['sale_price'] ?? null),
            ],
            'attributes' => $this->jsonObject($row['attributes'] ?? '{}'),
            'media'      => ['featured_id' => (int) ($row['featured_media_id'] ?? 0)],
            'menu_order' => (int) ($row['menu_order'] ?? 0),
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

    /** @return array<string,mixed> */
    private function jsonObject(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, associative: true);
        }

        return is_array($value) ? $value : [];
    }
}
