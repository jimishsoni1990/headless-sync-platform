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
 * MEDIA (AG-10, completed by Finding 011). Commerce stores WordPress attachment REFERENCES and
 * `content.media` remains the single attachment projection — no duplicate Commerce copy, and no
 * attachment metadata persisted here. The references are expanded at READ time through the Core
 * media capability, so `media.featured` and `media.gallery` are directly renderable without the
 * consumer resolving ids, knowing they are WordPress attachments, or reading another module's
 * contract. The expansion is optional by design: with the capability absent they publish null
 * and `[]`, and everything else about the product is unchanged.
 *
 * Because the metadata lives in `content.media` rather than being copied here, editing an
 * image's alt text or replacing its file shows up in the next product response with no product
 * re-save and no change to the product's checksum. That is the property duplicating the media
 * onto `commerce.products` would have destroyed.
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
            // NO `id` AND NO `source_id` (FLAG-COMMSOURCEID-1). The projection UUID and the
            // generic source key were published without a ruling and are now Removed: `id` is
            // the row's internal identity — the cursor tiebreaker and the `entity_taxonomies`
            // join key — and it is still SELECTed for exactly those, just not serialized
            // (DECISION F: internal columns are never serialized). `source_id` carried the same
            // integer as `woo_product_id` under a name that means a different kind of entity on
            // every other Commerce resource.
            //
            // The one identifier a consumer needs from the source system is below, under a name
            // that can only mean it.
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
            // References AND resolved objects (Finding 011). The ids stay because they were
            // already public and removing them would break consumers for no gain; `featured` and
            // `gallery` are the additive fields a contract-only frontend renders from, so nobody
            // has to know these ids are WordPress attachment posts or where they resolve.
            'media'              => [
                'featured_id' => (int) ($row['featured_media_id'] ?? 0),
                'gallery_ids' => $this->intList($row['gallery_media_ids'] ?? '[]'),
                // Null covers every unresolved case with one value, deliberately: no image set,
                // the attachment not yet projected, the attachment tombstoned, or the media
                // capability unavailable. A consumer can do nothing different about any of them
                // — there is no image to show — so the contract does not publish which one it
                // was. Infrastructure state is not a delivery field.
                'featured'    => $this->resolvedMedia($row['media_featured'] ?? null),
                // Gallery order is the store's own (see ProductQueryProvider). Always a list,
                // never null, so a consumer can iterate without a guard.
                'gallery'     => $this->resolvedGallery($row['media_gallery'] ?? null),
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

    /**
     * One resolved media object, or null.
     *
     * Published verbatim — the object was shaped by whoever implements the Core media capability
     * and Commerce does not reshape it. That is the point: a product image and a post's featured
     * image are the same attachment representation, not two that happen to look alike today.
     *
     * @return array<string,mixed>|null
     */
    private function resolvedMedia(mixed $value): ?array
    {
        return is_array($value) && $value !== [] ? $value : null;
    }

    /** @return list<array<string,mixed>> */
    private function resolvedGallery(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
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
