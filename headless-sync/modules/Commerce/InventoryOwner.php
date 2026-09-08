<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce;

/**
 * Who owns a stock fact (DECISION AG AG-14).
 *
 * A shared helper rather than an inline check, because the rule is subtle enough that a second
 * copy of it would drift, and getting it wrong produces a duplicated inventory fact rather than
 * an error.
 *
 * THE RULE, taken straight from WooCommerce rather than imposed on it:
 *
 *     an entity is an inventory owner IFF get_stock_managed_by_id() returns its own id
 *
 * Verified against WooCommerce 11.1.0. `WC_Product::get_stock_managed_by_id()` returns its own
 * id; `WC_Product_Variation` overrides it to return the PARENT's id when `get_manage_stock()` is
 * the string `'parent'`. So the source already models ownership, and the projection follows it.
 *
 * THE TRAP this class exists to hold shut: `get_manage_stock()` on a variation is TRI-STATE —
 * `true`, `false`, or the string `'parent'` — and `'parent'` is TRUTHY. A boolean check makes a
 * parent-managed variation look self-managed, which produces exactly the duplicated inventory
 * row AG-14 forbids, with the parent's quantity copied onto the variation as though the
 * variation held it (`get_stock_quantity()` transparently returns the parent's value in that
 * state).
 */
final class InventoryOwner
{
    private function __construct()
    {
    }

    /** The aggregate types that can own stock. */
    public const TYPE_PRODUCT   = 'product';
    public const TYPE_VARIATION = 'product_variation';

    /** WooCommerce's sentinel for "my parent manages this". A STRING, not a bool. */
    public const MANAGED_BY_PARENT = 'parent';

    /** @var list<string> */
    public const OWNER_TYPES = [self::TYPE_PRODUCT, self::TYPE_VARIATION];

    public static function isOwnerType(string $ownerType): bool
    {
        return in_array($ownerType, self::OWNER_TYPES, true);
    }

    /**
     * Does this entity own its own stock?
     *
     * @param int   $entityId          the entity's WordPress id
     * @param int   $stockManagedById  what get_stock_managed_by_id() reported
     */
    public static function owns(int $entityId, int $stockManagedById): bool
    {
        return $entityId > 0 && $entityId === $stockManagedById;
    }

    /**
     * The owner type for a WooCommerce product type string.
     *
     * `variation` is WooCommerce's own type name for a variation; every other supported type is
     * a product. Returns null for a type outside Phase 2 scope (AG-13) — an unsupported product
     * owns no inventory this platform projects, which is not an error.
     */
    public static function typeFor(string $productType): ?string
    {
        if ($productType === 'variation') {
            return self::TYPE_VARIATION;
        }

        return ProductScope::isSupportedType($productType) ? self::TYPE_PRODUCT : null;
    }
}
