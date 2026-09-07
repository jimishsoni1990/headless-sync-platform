<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Validation;

use HSP\Modules\Commerce\SourceModels\VariationSourceModel;

/**
 * Fail-fast structural validation of an extracted variation (Doc 6 §24).
 *
 * A variation with no parent cannot be addressed — the only route to it in the delivery
 * contract is through its parent product — so a missing parent id is structural, not a
 * business judgement.
 *
 * Note what is NOT rejected: an empty attribute map. A variable product may legitimately have
 * a variation with every attribute set to "any", and a store mid-configuration may have one
 * with nothing selected yet. Neither is a processing failure.
 */
final class VariationValidator
{
    public function validate(VariationSourceModel $variation): void
    {
        if ($variation->variationId <= 0) {
            throw new ValidationException('Variation source id must be a positive integer.');
        }

        if ($variation->parentId <= 0) {
            throw new ValidationException(
                "Variation {$variation->variationId} has no parent product and cannot be addressed."
            );
        }

        if ($variation->parentId === $variation->variationId) {
            throw new ValidationException(
                "Variation {$variation->variationId} is its own parent."
            );
        }
    }
}
