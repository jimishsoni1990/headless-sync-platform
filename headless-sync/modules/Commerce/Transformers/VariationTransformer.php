<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Transformers;

use HSP\Core\Contracts\CanonicalModelInterface;
use HSP\Core\Contracts\TransformerInterface;
use HSP\Modules\Commerce\CanonicalModels\CanonicalVariation;
use HSP\Modules\Commerce\SourceModels\VariationSourceModel;
use HSP\Modules\Commerce\Support\Money;

/**
 * Pure source → canonical transformation for product variations (Doc 6 §24).
 *
 * The one transformation that matters is money: prices are normalised to their canonical
 * decimal form HERE, immediately before the checksum starts depending on them (Requirement C).
 * Doing it later would let `10.00` and `10` hash differently and re-project forever.
 */
final class VariationTransformer implements TransformerInterface
{
    public function transform(object $source, array $context = []): CanonicalModelInterface
    {
        if (! $source instanceof VariationSourceModel) {
            throw new \InvalidArgumentException(
                self::class . ' requires a ' . VariationSourceModel::class . ', got ' . $source::class . '.'
            );
        }

        return new CanonicalVariation(
            sourceVariationId: $source->variationId,
            sourceParentId:    $source->parentId,
            sku:               $source->sku,
            name:              $source->name,
            description:       $source->description,
            status:            $source->status,
            price:             Money::normalize($source->price),
            regularPrice:      Money::normalize($source->regularPrice),
            salePrice:         Money::normalize($source->salePrice),
            featuredMediaId:   $source->featuredMediaId,
            menuOrder:         $source->menuOrder,
            attributes:        $source->attributes,
            attributeTermIds:  $source->attributeTermIds,
            updatedAt:         $source->modifiedAt,
        );
    }

    public function getCanonicalModelClass(): string
    {
        return CanonicalVariation::class;
    }
}
