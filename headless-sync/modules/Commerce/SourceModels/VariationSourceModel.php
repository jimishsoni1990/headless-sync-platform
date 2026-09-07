<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\SourceModels;

/**
 * Normalized, immutable snapshot of one WooCommerce product variation.
 *
 * Money arrives as a STRING (Requirement C): WooCommerce stores prices as strings and PHP
 * binary floating point must never be the canonical representation used for checksum or
 * persistence. Normalisation to a deterministic decimal form happens in the transformer.
 */
final class VariationSourceModel
{
    /**
     * @param int                   $variationId       wp_posts.ID of the variation
     * @param int                   $parentId          wp_posts.ID of the parent product — a SOFT
     *                                                 reference (AG-7); the parent may project
     *                                                 later, or not yet at all
     * @param string                $sku               WooCommerce SKU ('' when unset)
     * @param string                $name              composed name (parent + attribute summary)
     * @param string                $description       variation description
     * @param string                $status            WordPress post status
     * @param string|null           $price             active price as an exact decimal string
     * @param string|null           $regularPrice      regular price as an exact decimal string
     * @param string|null           $salePrice         sale price as an exact decimal string
     * @param int                   $featuredMediaId   attachment id (0 = none); soft reference
     *                                                 into content.media (AG-10)
     * @param int                   $menuOrder         WooCommerce's own ordering within the parent
     * @param array<string,string>  $attributes        selected values, keyed by UNPREFIXED
     *                                                 taxonomy name. An empty VALUE means "any"
     *                                                 and is preserved — verified against
     *                                                 WooCommerce 11.1.0, where a variation
     *                                                 matching every value of an attribute carries
     *                                                 that taxonomy with ''. Dropping those
     *                                                 entries would make "any size" indistinct
     *                                                 from "no size dimension".
     * @param list<int>             $attributeTermIds  the subset of $attributes that resolves
     *                                                 to a term. Strictly smaller than the map: an
     *                                                 "any" entry has no term to resolve.
     * @param \DateTimeImmutable    $modifiedAt        post_modified_gmt as a UTC instant
     */
    public function __construct(
        public readonly int $variationId,
        public readonly int $parentId,
        public readonly string $sku,
        public readonly string $name,
        public readonly string $description,
        public readonly string $status,
        public readonly ?string $price,
        public readonly ?string $regularPrice,
        public readonly ?string $salePrice,
        public readonly int $featuredMediaId,
        public readonly int $menuOrder,
        public readonly array $attributes,
        public readonly array $attributeTermIds,
        public readonly \DateTimeImmutable $modifiedAt,
    ) {
    }
}
