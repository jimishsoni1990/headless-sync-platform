<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Resources;

use HSP\Core\Contracts\ResourceInterface;
use HSP\Core\Delivery\JsonMap;

/**
 * Shapes a commerce.product_variations row into the published variation contract.
 *
 * `attributes` is published as an OBJECT of taxonomy => selected value, empty values included.
 * That preserves the "any" semantic WooCommerce carries (verified: an empty value means the
 * variation matches every value of that attribute) — a consumer matching a shopper's selection
 * needs to know that `pa_size: ""` accepts any size, which is not the same as the variation
 * having no size dimension at all.
 *
 * WOO HANDOFF IDENTITY (DECISION AK). `woo_variation_id` and `woo_product_id` are the authoritative
 * WooCommerce variation id and its PARENT product id for the connected store — the pair
 * `WC_Cart::add_to_cart($product_id, $qty, $variation_id, $variation)` takes, and the pair the
 * `?add-to-cart=` form handler reads as `add-to-cart` + `variation_id`. They are published as
 * INTEROPERABILITY identifiers because WooCommerce remains the transactional authority for
 * cart, pricing, stock, tax and checkout; they are site-specific, not globally unique, and they
 * do not change how HSP addresses anything — variations are still reached through
 * `GET /products/{slug}/variations`.
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
            'id'               => (string) ($row['id'] ?? ''),
            'source_id'        => (int) ($row['source_variation_id'] ?? 0),
            // The PARENT's source id. Published because a consumer holding a variation needs to
            // get back to its product, and because it is the key this projection is queried by.
            'product_id'       => (int) ($row['source_parent_id'] ?? 0),
            // The two WooCommerce ids a native cart handoff needs, named so they cannot be read
            // the wrong way round. `WC_Cart::add_to_cart()` takes the PARENT product id and the
            // VARIATION id as SEPARATE arguments; handing it one where it expects the other
            // silently adds the wrong thing, so the contract keeps them apart rather than
            // overloading a single id. Neither is derived from the other — both come straight
            // from WooCommerce, `get_id()` and `get_parent_id()` respectively.
            'woo_product_id'   => (int) ($row['source_parent_id'] ?? 0),
            'woo_variation_id' => (int) ($row['source_variation_id'] ?? 0),
            'sku'              => $this->nullableString($row['sku'] ?? null),
            'name'             => (string) ($row['name'] ?? ''),
            'description'      => (string) ($row['description'] ?? ''),
            'status'           => (string) ($row['status'] ?? ''),
            // Exact decimal strings, never JSON numbers (Requirement C).
            'prices'           => [
                'price'         => $this->nullableString($row['price'] ?? null),
                'regular_price' => $this->nullableString($row['regular_price'] ?? null),
                'sale_price'    => $this->nullableString($row['sale_price'] ?? null),
            ],
            'attributes'       => JsonMap::decode($row['attributes'] ?? '{}'),
            'media'            => ['featured_id' => (int) ($row['featured_media_id'] ?? 0)],
            'menu_order'       => (int) ($row['menu_order'] ?? 0),
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

    private function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
