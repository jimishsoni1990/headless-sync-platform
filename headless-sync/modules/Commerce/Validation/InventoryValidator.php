<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Validation;

use HSP\Modules\Commerce\InventoryOwner;
use HSP\Modules\Commerce\SourceModels\InventorySourceModel;

/**
 * Fail-fast structural validation of an extracted inventory row (Doc 6 §24).
 *
 * Structural only. Note what is NOT rejected: a negative stock quantity. WooCommerce genuinely
 * allows one — it is how backorders are represented — so refusing it would dead-letter a normal
 * store state.
 */
final class InventoryValidator
{
    /** Verified against WooCommerce 11.1.0; see ProductStockStatus. */
    private const STATUSES = ['instock', 'outofstock', 'onbackorder'];

    public function validate(InventorySourceModel $inventory): void
    {
        if ($inventory->ownerId <= 0) {
            throw new ValidationException('Inventory owner id must be a positive integer.');
        }

        if (! InventoryOwner::isOwnerType($inventory->ownerType)) {
            throw new ValidationException(
                "Inventory owner type '{$inventory->ownerType}' is not a Commerce aggregate that can own stock."
            );
        }

        // An unrecognised status would reach a catalogue filter as a value nothing matches,
        // which is a silently empty listing rather than a visible failure.
        if (! in_array($inventory->stockStatus, self::STATUSES, true)) {
            throw new ValidationException(
                "Inventory for {$inventory->ownerType} {$inventory->ownerId} has an "
                . "unrecognised stock status '{$inventory->stockStatus}'."
            );
        }
    }
}