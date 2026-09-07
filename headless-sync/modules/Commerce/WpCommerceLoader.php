<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce;

/**
 * The module's WordPress/WooCommerce read boundary.
 *
 * Every call into WooCommerce goes through this seam, so the extractor, transformer,
 * reconciliation source and replay emitter stay testable without WordPress loaded and the
 * whole module degrades cleanly when WooCommerce is absent.
 *
 * Values are returned as plain arrays rather than WC_Product objects on purpose: the pipeline
 * must not depend on a WooCommerce class being loadable, and an out-of-scope product type must
 * be reportable without constructing a domain object for it.
 */
interface WpCommerceLoader
{
    /**
     * Read one product's published-safe state.
     *
     * @return array<string,mixed>|null Null when the product does not exist. The array carries
     *         the keys ProductExtractor consumes; see WpCommerceLoaderImpl for the mapping.
     */
    public function loadProduct(int $productId): ?array;

    /**
     * WC_Product::get_type() for this id, or null when the product does not exist.
     *
     * Separate from loadProduct() so capture can decide whether a product is in Phase 2 scope
     * (AG-13) without paying for a full read of one it will ignore.
     */
    public function productType(int $productId): ?string;

    /**
     * Product ids greater than $afterId, ascending, for the reconciliation corpus pager.
     *
     * @return list<int>
     */
    public function listProductIdsAfter(int $afterId, int $limit): array;

    /** Does a product with this id exist in WordPress right now? */
    public function productExists(int $productId): bool;
}
