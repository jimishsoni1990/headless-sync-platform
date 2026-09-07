<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Validation;

use HSP\Modules\Commerce\SourceModels\ProductSourceModel;

/**
 * Fail-fast structural validation of an extracted product (Doc 6 §24).
 *
 * Structural only: a product missing an id or a slug cannot be addressed or projected, so it
 * is an error. Business-level judgements — whether a price is sensible, whether stock is
 * plausible — are not validation and are not performed here.
 *
 * Note what is NOT rejected: an unsupported product type. Under AG-13 that is normal
 * out-of-scope source, filtered at capture, never a processing failure that retries or
 * dead-letters.
 */
final class ProductValidator
{
    public function validate(ProductSourceModel $product): void
    {
        if ($product->productId <= 0) {
            throw new ValidationException('Product source id must be a positive integer.');
        }

        if (trim($product->slug) === '') {
            throw new ValidationException(
                "Product {$product->productId} has an empty slug and cannot be addressed."
            );
        }

        if (trim($product->name) === '') {
            throw new ValidationException(
                "Product {$product->productId} has an empty name."
            );
        }
    }
}
