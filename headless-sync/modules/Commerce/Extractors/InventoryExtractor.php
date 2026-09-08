<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Extractors;

use HSP\Modules\Commerce\SourceModels\InventorySourceModel;
use HSP\Modules\Commerce\Validation\InventoryValidator;

/** Normalises a loader-shaped inventory array into an immutable InventorySourceModel. */
final class InventoryExtractor
{
    public function __construct(private readonly InventoryValidator $validator)
    {
    }

    /** @param array<string,mixed> $raw Loader output; see WpCommerceLoaderImpl::loadInventory(). */
    public function extract(array $raw): InventorySourceModel
    {
        $inventory = new InventorySourceModel(
            ownerType:      (string) ($raw['owner_type'] ?? ''),
            ownerId:        (int) ($raw['owner_id'] ?? 0),
            managesStock:   (bool) ($raw['manages_stock'] ?? false),
            stockQuantity:  $this->intOrNull($raw['stock_quantity'] ?? null),
            stockStatus:    (string) ($raw['stock_status'] ?? 'instock'),
            backorders:     (string) ($raw['backorders'] ?? 'no'),
            lowStockAmount: $this->intOrNull($raw['low_stock_amount'] ?? null),
        );

        $this->validator->validate($inventory);

        return $inventory;
    }

    /** NULL stays NULL — "untracked" is not zero. */
    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}