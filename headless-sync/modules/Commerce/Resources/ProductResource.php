<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Resources;

use HSP\Core\Contracts\ResourceInterface;
use HSP\Core\Delivery\JsonMap;

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
 * WOO HANDOFF IDENTITY (DECISION AK). `woo_product_id` is the authoritative WooCommerce product
 * id for the connected store — the value `WC_Cart::add_to_cart()`, the `?add-to-cart=` form
 * handler and the Store API all accept. It is published as an INTEROPERABILITY identifier,
 * because WooCommerce remains the transactional authority for cart, pricing, stock, tax and
 * checkout; it is not a licence to expose internal ids generally, and it is NOT how HSP
 * addresses a product — `GET /products/{slug}` is unchanged. Site-specific by nature:
 * authoritative for the connected source store, never a globally unique identifier.
 *
 * `variation_selection_supported` (DECISION AL) appears on VARIABLE products only and gates how
 * the two fields below may be read. WooCommerce lets a product vary by a local/custom attribute,
 * which AG-9 keeps out of Phase 2 — so its variations' published selections are missing a
 * dimension and two of them can look identical. The flag says whether they do, because a
 * consumer cannot tell from the data and the failure is silent: it resolves a variation, just
 * the wrong one.
 *
 * `attributes` carries the `pa_*` terms this product itself offers, keyed by taxonomy. It is the
 * product half of the variation-selection contract: a variation that accepts every value of an
 * attribute publishes an empty string for it, so the values a shopper may choose between are not
 * in the variation list at all. They are here, from the relationships the product's own handler
 * already projects — no new column, no second lookup per term.
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
        $type = (string) ($row['product_type'] ?? '');

        $published = [
            'id'                 => (string) ($row['id'] ?? ''),
            'source_id'          => (int) ($row['source_product_id'] ?? 0),
            // The SAME value as `source_id`, published under a name that says what it is.
            // `source_id` is generic infrastructure vocabulary — it also appears on categories
            // and attribute definitions, meaning a term id and a definition id there — so a
            // consumer holding only the contract cannot tell that this particular one is the
            // identifier WooCommerce's own cart accepts. This name can only mean that.
            'woo_product_id'     => (int) ($row['source_product_id'] ?? 0),
            'sku'                => $this->nullableString($row['sku'] ?? null),
            'slug'               => (string) ($row['slug'] ?? ''),
            'name'               => (string) ($row['name'] ?? ''),
            'description'        => (string) ($row['description'] ?? ''),
            'short_description'  => (string) ($row['short_description'] ?? ''),
            'type'               => $type,
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
            // The `pa_*` terms THIS product carries, keyed by taxonomy — the values a variable
            // product's selector offers, and their labels. See the field's schema and
            // ProductQueryProvider::ATTRIBUTE_TERMS for why the variation list alone cannot
            // supply them: a variation that accepts any size publishes `{"pa_size": ""}`, which
            // names no size at all.
            //
            // NOT a statement that these are the selector DIMENSIONS. A store may attach an
            // attribute for display only; only the keys of a variation's `attributes` map say
            // which attributes a variation is chosen by.
            'attributes'         => JsonMap::decode($row['attribute_terms_json'] ?? '{}'),
            'published_at'       => $this->nullableString($row['published_at'] ?? null),
            'updated_at'         => $this->nullableString($row['updated_at'] ?? null),
            'meta'               => JsonMap::decode($row['meta_jsonb'] ?? '{}'),
        ];

        // DECISION AL — present ONLY on a variable product, because only a variable product has
        // variations to select. A simple product carrying `variation_selection_supported: true`
        // would read as "selection works here", which is not a weaker claim than the truth but a
        // different one; `false` would read as a defect. AK-8 settled the same question for
        // `woo_variation_id` the same way: no field beats a meaningless one.
        //
        // UNKNOWN PUBLISHES AS FALSE. The column is NULL on a row projected before this
        // capability existed and not yet re-projected. A consumer must never be told selection
        // is safe on the strength of a value nobody has computed, so the conservative answer is
        // published and the migration state stays invisible — "not selectable from HSP" is true
        // while it is unknown, and it becomes true or false for real once the product converges
        // through the ordinary pipeline.
        if ($type === 'variable') {
            $published['variation_selection_supported'] = ($row['variation_selection_supported'] ?? null) !== null
                && $this->bool($row['variation_selection_supported']);
        }

        return $published;
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
}
