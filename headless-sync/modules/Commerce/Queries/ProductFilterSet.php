<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Queries;

use HSP\Core\Contracts\QueryFilterInterface;

/**
 * The Commerce domain's delivery filter (DECISION AG AG-5).
 *
 * This is the class that could not have existed before AG-5: core's `FilterSet` was `final`
 * and carried content-domain fields, so a product filter had nowhere to live except as more
 * appended fields on a shared core class. Core now owns only the pagination envelope.
 *
 * Deliberately strongly typed rather than an untyped bag — a query provider handed a filter
 * from another domain rejects it explicitly instead of reading whichever properties happen to
 * exist.
 */
final class ProductFilterSet implements QueryFilterInterface
{
    /**
     * @param string|null $slug          Exact slug for single-item lookup
     * @param string|null $sku           Exact SKU match
     * @param string|null $productType   'simple' | 'variable'
     * @param bool|null   $featured      Featured products only when true
     * @param string|null $minPrice      Inclusive lower bound, exact decimal string
     * @param string|null $maxPrice      Inclusive upper bound, exact decimal string
     * @param bool        $catalogOnly   Apply WooCommerce catalog visibility (Requirement B).
     *                                   Defaults TRUE: a public listing must not expose a
     *                                   product the store owner excluded from the catalog,
     *                                   and defaulting the other way would make the unsafe
     *                                   behaviour the accidental one.
     * @param string|null $categorySlug  Product-category slug. A bare slug is safe here:
     *                                   wp_unique_term_slug() makes product_cat slugs unique
     *                                   within the taxonomy, verified at the P2-S3 preflight.
     * @param string|null $attributeTaxonomy Full `pa_*` taxonomy name. Required alongside
     *                                   $attributeTermSlug — slugs are unique only WITHIN a
     *                                   taxonomy, so `blue` on its own could match a pa_colour
     *                                   term and a pa_finish term at once.
     * @param string|null $attributeTermSlug Term slug inside that attribute taxonomy.
     * @param string|null $cursor
     * @param int|null    $limit
     */
    public function __construct(
        public readonly ?string $slug = null,
        public readonly ?string $sku = null,
        public readonly ?string $productType = null,
        public readonly ?bool $featured = null,
        public readonly ?string $minPrice = null,
        public readonly ?string $maxPrice = null,
        public readonly bool $catalogOnly = true,
        public readonly ?string $categorySlug = null,
        public readonly ?string $attributeTaxonomy = null,
        public readonly ?string $attributeTermSlug = null,
        public readonly ?string $cursor = null,
        public readonly ?int $limit = null,
    ) {
    }

    public function cursor(): ?string
    {
        return $this->cursor;
    }

    public function limit(): ?int
    {
        return $this->limit;
    }
}
