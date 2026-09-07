<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\SourceModels;

/**
 * Normalized, immutable snapshot of a WooCommerce product.
 *
 * Produced by ProductExtractor from the values a WC_Product exposes through its public
 * getters; consumed by ProductTransformer, never by an adapter directly.
 *
 * Source-system state only: no delivery concerns, no checksum, no canonical shape.
 *
 * Money arrives as a STRING, deliberately (DECISION AG Requirement C). WooCommerce stores
 * prices as strings and PHP binary floating point must never be the canonical representation
 * used for checksum or persistence — `0.1 + 0.2` is the reason. Normalisation to a
 * deterministic decimal form happens in the transformer, before the checksum is computed.
 */
final class ProductSourceModel
{
    /**
     * @param int                 $productId         wp_posts.ID of the product
     * @param string              $sku               WooCommerce SKU ('' when unset)
     * @param string              $slug              post_name
     * @param string              $name              product name
     * @param string              $description       long description (post_content)
     * @param string              $shortDescription  short description (post_excerpt)
     * @param string              $status            WordPress post status
     * @param string              $productType       WC_Product::get_type() — 'simple' | 'variable'
     *                                               for Phase 2 (AG-13)
     * @param string              $catalogVisibility 'visible' | 'catalog' | 'search' | 'hidden',
     *                                               from the product_visibility taxonomy — NOT
     *                                               post status (Requirement B)
     * @param bool                $featured          the `featured` visibility term
     * @param string|null         $price             active price as an exact decimal string
     * @param string|null         $regularPrice      regular price as an exact decimal string
     * @param string|null         $salePrice         sale price as an exact decimal string
     * @param int                 $featuredMediaId   attachment id of the product image (0 = none);
     *                                               a SOFT reference into content.media (AG-10)
     * @param list<int>           $galleryMediaIds   gallery attachment ids, soft references
     * @param \DateTimeImmutable  $publishedAt       post_date_gmt as a UTC instant
     * @param \DateTimeImmutable  $modifiedAt        post_modified_gmt as a UTC instant
     * @param array<string,mixed> $meta              published-safe meta (protected keys stripped)
     * @param list<int>           $categoryIds       product_cat TERM ids this product carries
     * @param list<int>           $attributeTermIds  pa_* TERM ids across every global attribute
     *                                               taxonomy. Kept SEPARATE from categoryIds
     *                                               even though both land in the same link
     *                                               table: they are different domain facts and
     *                                               merging them here would make a category
     *                                               change indistinguishable from an attribute
     *                                               change to anything reading the source model.
     */
    public function __construct(
        public readonly int $productId,
        public readonly string $sku,
        public readonly string $slug,
        public readonly string $name,
        public readonly string $description,
        public readonly string $shortDescription,
        public readonly string $status,
        public readonly string $productType,
        public readonly string $catalogVisibility,
        public readonly bool $featured,
        public readonly ?string $price,
        public readonly ?string $regularPrice,
        public readonly ?string $salePrice,
        public readonly int $featuredMediaId,
        public readonly array $galleryMediaIds,
        public readonly \DateTimeImmutable $publishedAt,
        public readonly \DateTimeImmutable $modifiedAt,
        public readonly array $meta,
        public readonly array $categoryIds,
        public readonly array $attributeTermIds,
    ) {
    }
}
