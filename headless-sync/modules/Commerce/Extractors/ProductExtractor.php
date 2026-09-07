<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Extractors;

use HSP\Modules\Commerce\SourceModels\ProductSourceModel;
use HSP\Modules\Commerce\Validation\ProductValidator;

/**
 * Normalises a loader-shaped product array into an immutable ProductSourceModel.
 *
 * Extractors normalise; they do not create canonical models and they do not decide delivery
 * shape (Doc 6 §24). Money is carried through as a string — normalisation to the canonical
 * decimal form is the transformer's job, immediately before the checksum depends on it.
 */
final class ProductExtractor
{
    public function __construct(private readonly ProductValidator $validator)
    {
    }

    /**
     * @param array<string,mixed> $raw Loader output; see WpCommerceLoaderImpl::loadProduct().
     */
    public function extract(array $raw): ProductSourceModel
    {
        $product = new ProductSourceModel(
            productId:         (int) ($raw['id'] ?? 0),
            sku:               (string) ($raw['sku'] ?? ''),
            slug:              (string) ($raw['slug'] ?? ''),
            name:              (string) ($raw['name'] ?? ''),
            description:       (string) ($raw['description'] ?? ''),
            shortDescription:  (string) ($raw['short_description'] ?? ''),
            status:            (string) ($raw['status'] ?? ''),
            productType:       (string) ($raw['product_type'] ?? ''),
            catalogVisibility: (string) ($raw['catalog_visibility'] ?? 'visible'),
            featured:          (bool) ($raw['featured'] ?? false),
            price:             $this->stringOrNull($raw['price'] ?? null),
            regularPrice:      $this->stringOrNull($raw['regular_price'] ?? null),
            salePrice:         $this->stringOrNull($raw['sale_price'] ?? null),
            featuredMediaId:   (int) ($raw['featured_media_id'] ?? 0),
            galleryMediaIds:   $this->intList($raw['gallery_media_ids'] ?? []),
            publishedAt:       $this->utc($raw['published_at'] ?? null),
            modifiedAt:        $this->utc($raw['modified_at'] ?? null),
            meta:              is_array($raw['meta'] ?? null) ? $raw['meta'] : [],
        );

        $this->validator->validate($product);

        return $product;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /** @return list<int> */
    private function intList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map('intval', array_filter($value, 'is_scalar')));
    }

    /**
     * WordPress GMT date strings are UTC but carry no zone marker, so the zone is supplied
     * explicitly rather than left to the server's default.
     */
    private function utc(mixed $value): \DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');

        if (is_string($value) && $value !== '' && $value !== '0000-00-00 00:00:00') {
            try {
                return new \DateTimeImmutable($value, $utc);
            } catch (\Exception) {
                // Fall through to "now" — an unparseable source date must not abort capture.
            }
        }

        return new \DateTimeImmutable('now', $utc);
    }
}
