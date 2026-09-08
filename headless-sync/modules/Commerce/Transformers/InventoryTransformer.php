<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Transformers;

use HSP\Core\Contracts\CanonicalModelInterface;
use HSP\Core\Contracts\TransformerInterface;
use HSP\Modules\Commerce\CanonicalModels\CanonicalInventory;
use HSP\Modules\Commerce\SourceModels\InventorySourceModel;

/** Pure source → canonical transformation for inventory (Doc 6 §24). */
final class InventoryTransformer implements TransformerInterface
{
    public function transform(object $source, array $context = []): CanonicalModelInterface
    {
        if (! $source instanceof InventorySourceModel) {
            throw new \InvalidArgumentException(
                self::class . ' requires a ' . InventorySourceModel::class . ', got ' . $source::class . '.'
            );
        }

        return new CanonicalInventory(
            ownerType:      $source->ownerType,
            ownerId:        $source->ownerId,
            managesStock:   $source->managesStock,
            // Belt and braces with the loader, which already nulls this when nothing is tracked.
            // A quantity carried alongside managesStock = false would publish a number that
            // WooCommerce does not consider meaningful.
            stockQuantity:  $source->managesStock ? $source->stockQuantity : null,
            stockStatus:    $source->stockStatus,
            backorders:     $source->backorders,
            lowStockAmount: $source->lowStockAmount,
        );
    }

    public function getCanonicalModelClass(): string
    {
        return CanonicalInventory::class;
    }
}