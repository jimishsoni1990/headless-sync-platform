<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce;

/**
 * Live WooCommerce implementation of the module's read boundary.
 *
 * The ONLY place Commerce calls WooCommerce or WordPress read functions. Everything is
 * reached through WooCommerce's PUBLIC API (`wc_get_product`, the WC_Product getters) rather
 * than by reading `wp_postmeta` directly: the protocol in DECISION AG is explicit that
 * synchronisation must not be built against private storage details merely because a value
 * happens to be visible in postmeta.
 *
 * Verified against WooCommerce 11.1.0 — see docs/notes/WOOCOMMERCE-SOURCE-VERIFICATION.md.
 *
 * WooCommerce absent is a NORMAL state, not an error: every method degrades to null/empty so
 * the module can be loaded and inspected without the dependency (AG-12). In practice the
 * module will not even be composed in that case, because CommerceServiceProvider reports
 * unavailable — this is belt and braces for a plugin deactivated mid-request.
 */
final class WpCommerceLoaderImpl implements WpCommerceLoader
{
    /** Meta keys WooCommerce owns internally; never published (mirrors ProtectedMeta). */
    private const PROTECTED_PREFIX = '_';

    public function loadProduct(int $productId): ?array
    {
        $product = $this->product($productId);

        if ($product === null) {
            return null;
        }

        $post = function_exists('get_post') ? get_post($productId) : null;

        return [
            'id'                 => $productId,
            'sku'                => (string) $product->get_sku(),
            'slug'               => (string) $product->get_slug(),
            'name'               => (string) $product->get_name(),
            'description'        => (string) $product->get_description(),
            'short_description'  => (string) $product->get_short_description(),
            'status'             => (string) $product->get_status(),
            'product_type'       => (string) $product->get_type(),
            // Verified: derived from the product_visibility taxonomy, NOT post status.
            'catalog_visibility' => (string) $product->get_catalog_visibility(),
            'featured'           => (bool) $product->is_featured(),
            // Money stays a STRING all the way to normalisation (Requirement C).
            'price'              => $this->priceString($product->get_price()),
            'regular_price'      => $this->priceString($product->get_regular_price()),
            'sale_price'         => $this->priceString($product->get_sale_price()),
            'featured_media_id'  => (int) $product->get_image_id(),
            'gallery_media_ids'  => array_map('intval', (array) $product->get_gallery_image_ids()),
            'published_at'       => $post->post_date_gmt ?? null,
            'modified_at'        => $post->post_modified_gmt ?? null,
            'meta'               => $this->publicMeta($productId),
        ];
    }

    public function productType(int $productId): ?string
    {
        $product = $this->product($productId);

        return $product === null ? null : (string) $product->get_type();
    }

    public function listProductIdsAfter(int $afterId, int $limit): array
    {
        global $wpdb;

        if (! isset($wpdb)) {
            return [];
        }

        // A direct bounded read: `ID > n ORDER BY ID LIMIT k` keyset paging cannot be
        // expressed through WP_Query, and the reconciliation corpus must page deterministically
        // over the whole table. Note NO post_status filter — a draft or private product is
        // still a product whose projection state reconciliation must be able to reason about;
        // filtering to 'publish' here is what made the media corpus permanently empty in
        // P1B-S1 until it was caught.
        $sql = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = %s AND ID > %d
             ORDER BY ID ASC
             LIMIT %d",
            'product',
            $afterId,
            $limit,
        );

        /** @var list<array<string,mixed>>|null $rows */
        $rows = $wpdb->get_results($sql, ARRAY_A);

        return array_map(static fn (array $r): int => (int) $r['ID'], $rows ?? []);
    }

    public function productExists(int $productId): bool
    {
        return $this->product($productId) !== null;
    }

    /**
     * @return WooProductAccess|null A WC_Product, or null when WooCommerce is absent or the id
     *         is not a product.
     *
     * The return is annotated as {@see WooProductAccess} — a documentation-and-analysis
     * interface listing exactly the WooCommerce API this module uses. A real WC_Product does
     * not implement it and does not need to; PHP resolves the calls dynamically. The
     * annotation gives the analyser a type without making WooCommerce a hard dependency, and
     * keeps the depended-upon surface in one reviewable place.
     *
     * @phpstan-return WooProductAccess|null
     */
    private function product(int $productId): ?object
    {
        if (! function_exists('wc_get_product')) {
            return null;
        }

        $product = wc_get_product($productId);

        /** @var WooProductAccess|null $product */
        return is_object($product) ? $product : null;
    }

    /** WooCommerce returns '' for "no price set", which must stay distinct from 0. */
    private function priceString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Published-safe meta: `_`-prefixed keys are WooCommerce/WordPress bookkeeping and never
     * reach the delivery contract.
     *
     * @return array<string,mixed>
     */
    private function publicMeta(int $productId): array
    {
        if (! function_exists('get_post_meta')) {
            return [];
        }

        /** @var array<string, list<mixed>> $all */
        $all = (array) get_post_meta($productId);

        $public = [];
        foreach ($all as $key => $values) {
            if (str_starts_with($key, self::PROTECTED_PREFIX)) {
                continue;
            }

            $value = is_array($values) ? ($values[0] ?? null) : $values;

            // The all-meta form of get_post_meta() does NOT unserialize — a documented
            // WordPress quirk that published raw PHP serialization until P1B-S4 caught it.
            if (is_string($value) && function_exists('maybe_unserialize')) {
                $value = maybe_unserialize($value);
            }

            // Objects and resources have no place in a public JSON contract, and would make
            // the adapter's json_encode fail and silently empty the whole meta object.
            if (is_object($value) || is_resource($value)) {
                continue;
            }

            $public[$key] = $value;
        }

        return $public;
    }
}
