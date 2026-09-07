<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce;

/**
 * The exact WooCommerce `WC_Product` surface this module reads.
 *
 * WooCommerce is an optional runtime dependency, so its classes are not on the analysis path
 * and `wc_get_product()` is untyped as far as static analysis is concerned. Rather than
 * baseline the resulting "call to an undefined method" findings — new code should be fixed,
 * not baselined — the loader annotates its return as this interface.
 *
 * A real `WC_Product` does not implement it, and does not need to: PHP resolves these calls
 * dynamically, and the interface exists to give the analyser a type and to state, in one
 * place, precisely which public WooCommerce API the module depends on. That list is the thing
 * to re-verify when WooCommerce is upgraded — see
 * docs/notes/WOOCOMMERCE-SOURCE-VERIFICATION.md.
 *
 * Everything here is WooCommerce's PUBLIC CRUD API. The module deliberately reads no
 * `wp_postmeta` key directly, even where a value is visible there.
 */
interface WooProductAccess
{
    public function get_sku(): mixed;

    public function get_slug(): mixed;

    public function get_name(): mixed;

    public function get_description(): mixed;

    public function get_short_description(): mixed;

    public function get_status(): mixed;

    /** 'simple' | 'variable' | 'grouped' | 'external' | custom (AG-13 supports the first two). */
    public function get_type(): mixed;

    /** 'visible' | 'catalog' | 'search' | 'hidden' — from product_visibility, not post status. */
    public function get_catalog_visibility(): mixed;

    public function is_featured(): mixed;

    /** Prices are strings in WooCommerce; '' means "not set", which is not zero. */
    public function get_price(): mixed;

    public function get_regular_price(): mixed;

    public function get_sale_price(): mixed;

    public function get_image_id(): mixed;

    public function get_gallery_image_ids(): mixed;

    /**
     * 0 for a top-level product; the parent product's post id for a variation.
     *
     * Declared on WC_Product itself rather than only on WC_Product_Variation, so one annotation
     * covers both — which is also why this interface is not split per product type.
     */
    public function get_parent_id(): mixed;

    /**
     * On a VARIATION: the selected values, keyed by UNPREFIXED taxonomy name, valued by term
     * slug, with '' meaning "any". On a parent product this returns WC_Product_Attribute
     * objects instead — a different shape entirely — so only the variation loader calls it.
     */
    public function get_attributes(): mixed;
}
