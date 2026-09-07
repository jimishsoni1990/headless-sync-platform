<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Extractors;

use HSP\Modules\Commerce\CommerceTaxonomies;
use HSP\Modules\Commerce\SourceModels\VariationSourceModel;
use HSP\Modules\Commerce\Validation\VariationValidator;

/**
 * Normalises a loader-shaped variation array into an immutable VariationSourceModel.
 *
 * The one judgement made here is SCOPE: WooCommerce reports a variation's selected values for
 * every attribute the parent varies by, including local/custom attributes that are not `pa_*`
 * taxonomies. Phase 2 covers global attributes only (AG-9), so non-`pa_` entries are dropped —
 * silently, because a store using a custom attribute is normal source, not an error.
 */
final class VariationExtractor
{
    public function __construct(private readonly VariationValidator $validator)
    {
    }

    /** @param array<string,mixed> $raw Loader output; see WpCommerceLoaderImpl::loadVariation(). */
    public function extract(array $raw): VariationSourceModel
    {
        $variation = new VariationSourceModel(
            variationId:     (int) ($raw['id'] ?? 0),
            parentId:        (int) ($raw['parent_id'] ?? 0),
            sku:             (string) ($raw['sku'] ?? ''),
            name:            (string) ($raw['name'] ?? ''),
            description:     (string) ($raw['description'] ?? ''),
            status:          (string) ($raw['status'] ?? ''),
            price:           $this->stringOrNull($raw['price'] ?? null),
            regularPrice:    $this->stringOrNull($raw['regular_price'] ?? null),
            salePrice:       $this->stringOrNull($raw['sale_price'] ?? null),
            featuredMediaId: (int) ($raw['featured_media_id'] ?? 0),
            menuOrder:       (int) ($raw['menu_order'] ?? 0),
            attributes:        $this->globalAttributes($raw['attributes'] ?? []),
            attributeTermIds:  $this->intList($raw['attribute_term_ids'] ?? []),
            modifiedAt:      $this->utc($raw['modified_at'] ?? null),
        );

        $this->validator->validate($variation);

        return $variation;
    }

    /**
     * The `pa_*` entries only, key-sorted.
     *
     * Sorting here rather than only in the checksum keeps the STORED map stable too, so a
     * consumer diffing two projections does not see a reordering as a change.
     *
     * @return array<string,string>
     */
    private function globalAttributes(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $taxonomy => $value) {
            if (! is_string($taxonomy) || ! is_scalar($value)) {
                continue;
            }

            if (! CommerceTaxonomies::isAttributeTaxonomy($taxonomy)) {
                continue;
            }

            // The empty string is KEPT: it means "any value of this attribute", which is not the
            // same as the attribute being absent (verified, wc-product-functions.php:1208).
            $out[$taxonomy] = (string) $value;
        }

        ksort($out);

        return $out;
    }

    /** @return list<int> */
    private function intList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map('intval', array_filter($value, 'is_scalar')));
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
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
                // An unparseable source date must not abort capture.
            }
        }

        return new \DateTimeImmutable('now', $utc);
    }
}
