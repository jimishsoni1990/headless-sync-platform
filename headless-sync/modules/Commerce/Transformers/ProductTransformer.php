<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Transformers;

use HSP\Core\Contracts\CanonicalModelInterface;
use HSP\Core\Contracts\TransformerInterface;
use HSP\Modules\Commerce\CanonicalModels\CanonicalProduct;
use HSP\Modules\Commerce\SourceModels\ProductSourceModel;
use HSP\Modules\Commerce\Support\Money;

/**
 * Pure source → canonical transformation (Doc 6 §24). No side effects, no I/O, no WordPress.
 *
 * This is where money becomes canonical: every price is normalised to its exact-decimal string
 * form HERE, before CanonicalProduct computes the checksum over it, so `10`, `10.0` and
 * `10.00` cannot move the digest and churn the projection (DECISION AG Requirement C).
 */
final class ProductTransformer implements TransformerInterface
{
    public function transform(object $source, array $context = []): CanonicalModelInterface
    {
        if (! $source instanceof ProductSourceModel) {
            throw new \InvalidArgumentException(
                self::class . ' requires a ' . ProductSourceModel::class . ', got ' . $source::class . '.'
            );
        }

        return new CanonicalProduct(
            sourceProductId:   $source->productId,
            sku:               $source->sku,
            slug:              $source->slug,
            name:              $source->name,
            description:       $source->description,
            shortDescription:  $source->shortDescription,
            status:            $source->status,
            productType:       $source->productType,
            catalogVisibility: $source->catalogVisibility,
            featured:          $source->featured,
            price:             Money::normalize($source->price),
            regularPrice:      Money::normalize($source->regularPrice),
            salePrice:         Money::normalize($source->salePrice),
            featuredMediaId:   $source->featuredMediaId,
            galleryMediaIds:   $source->galleryMediaIds,
            publishedAt:       $source->publishedAt,
            updatedAt:         $source->modifiedAt,
            meta:              $source->meta,
            categoryIds:       $source->categoryIds,
            attributeTermIds:  $source->attributeTermIds,
        );
    }

    public function getCanonicalModelClass(): string
    {
        return CanonicalProduct::class;
    }
}
