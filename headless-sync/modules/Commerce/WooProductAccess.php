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

    /**
     * The id of whatever entity actually owns this entity's stock (AG-14).
     *
     * Its own id, except on a variation whose stock is managed by its parent, where it is the
     * parent's id. This is WooCommerce's own answer to the ownership question and the reason the
     * inventory projection needs no ownership rule of its own.
     */
    public function get_stock_managed_by_id(): mixed;

    /**
     * The EFFECTIVE "does this track a quantity" flag: the per-product value gated by the
     * store-level `woocommerce_manage_stock` option. Prefer this over get_manage_stock(), which
     * is tri-state on a variation (`true` | `false` | the string `'parent'`).
     */
    public function managing_stock(): mixed;

    /** NULL when no quantity is tracked, which is not the same as 0. */
    public function get_stock_quantity(): mixed;

    /** 'instock' | 'outofstock' | 'onbackorder'. Independent of quantity management. */
    public function get_stock_status(): mixed;

    /** 'no' | 'notify' | 'yes'. */
    public function get_backorders(): mixed;

    public function get_low_stock_amount(): mixed;
}
