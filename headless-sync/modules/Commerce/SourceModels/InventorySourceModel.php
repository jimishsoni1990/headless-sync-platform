<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\SourceModels;

/**
 * Normalized snapshot of one stock owner's inventory state (DECISION AG AG-8, AG-14).
 *
 * Only ever constructed for an entity that actually OWNS its stock. A variation whose
 * `manage_stock` is `'parent'` never reaches here, because the parent holds that fact and a
 * second copy would be the duplicated inventory the ruling forbids.
 */
final class InventorySourceModel
{
    /**
     * @param string   $ownerType       'product' | 'product_variation'
     * @param int      $ownerId         the owner's WordPress id (a soft reference, AG-7)
     * @param bool     $managesStock    the EFFECTIVE flag — the per-product value gated by the
     *                                  store-level woocommerce_manage_stock option
     * @param int|null $stockQuantity   NULL when no quantity is tracked, which is NOT zero: a
     *                                  made-to-order product has unlimited stock, and publishing
     *                                  0 there would read as sold out
     * @param string   $stockStatus     'instock' | 'outofstock' | 'onbackorder'. Independent of
     *                                  $managesStock — an untracked product still has a status,
     *                                  and that status is what a catalogue filter needs
     * @param string   $backorders      'no' | 'notify' | 'yes'
     * @param int|null $lowStockAmount  per-owner threshold, or NULL to use the store default
     */
    public function __construct(
        public readonly string $ownerType,
        public readonly int $ownerId,
        public readonly bool $managesStock,
        public readonly ?int $stockQuantity,
        public readonly string $stockStatus,
        public readonly string $backorders,
        public readonly ?int $lowStockAmount,
    ) {
    }
}
