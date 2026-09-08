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

    /**
     * Read one Commerce taxonomy term.
     *
     * Looked up by term id ALONE, with no taxonomy argument: WordPress term ids are unique
     * across taxonomies, and the term reports its own taxonomy. Content's loader originally
     * hardcoded get_term($id, 'category') and had to be generalised in P1B-S3 — this starts
     * taxonomy-agnostic so the pa_* attribute terms of P2-S4 reuse it unchanged.
     *
     * @return array<string,mixed>|null Null when the term does not exist or belongs to a
     *         taxonomy this module does not own.
     */
    public function loadTerm(int $termId): ?array;

    /**
     * Term ids in one taxonomy greater than $afterId, ascending — the reconciliation pager.
     *
     * @return list<int>
     */
    public function listTermIdsAfter(string $taxonomy, int $afterId, int $limit): array;

    /**
     * Read one global attribute DEFINITION.
     *
     * @return array<string,mixed>|null Null when the attribute does not exist.
     */
    public function loadAttribute(int $attributeId): ?array;

    /**
     * Every global attribute id greater than $afterId, ascending.
     *
     * Attribute definitions are few — one per attribute the store defines, not per product — so
     * the whole set is read and filtered rather than paged in SQL. WooCommerce exposes them
     * only through wc_get_attribute_taxonomies(), and reading its custom table directly would
     * breach the public-API rule for no benefit at this cardinality.
     *
     * @return list<int>
     */
    public function listAttributeIdsAfter(int $afterId, int $limit): array;

    /**
     * Every pa_* taxonomy name currently defined.
     *
     * The reconciliation corpus for attribute TERMS: unlike product_cat there is no fixed
     * taxonomy to page, so the set is derived from the defined attributes.
     *
     * @return list<string>
     */
    public function attributeTaxonomyNames(): array;

    /**
     * Read one product variation's state.
     *
     * @return array<string,mixed>|null Null when the variation does not exist, or when its
     *         PARENT is not a Phase 2 supported type — a variation of an out-of-scope product
     *         is normal source, not a failure (AG-13).
     */
    public function loadVariation(int $variationId): ?array;

    /**
     * Variation ids greater than $afterId, ascending — the reconciliation corpus pager.
     *
     * @return list<int>
     */
    public function listVariationIdsAfter(int $afterId, int $limit): array;

    /** Does a variation with this id exist in WordPress right now? */
    public function variationExists(int $variationId): bool;

    /**
     * Read one stock owner's inventory state.
     *
     * Returns null when the entity does not exist, is out of Phase 2 scope, or — the case that
     * matters — is NOT the owner of its own stock (AG-14). A variation whose `manage_stock` is
     * `'parent'` reads as absent here, because the parent holds that fact and inventing a second
     * copy for the variation is precisely what the ruling forbids.
     *
     * @return array<string,mixed>|null
     */
    public function loadInventory(int $entityId): ?array;

    /**
     * Candidate stock-owner ids greater than $afterId, ascending — the reconciliation corpus.
     *
     * CANDIDATES, not owners: this pages products and variations together, and whether each is
     * actually an owner is answered by loadInventory(). Filtering here instead would make the
     * corpus skip a variation that has just stopped owning its stock — and skipping it is what
     * would leave its now-stale inventory row visible forever, since the orphan sweep only
     * examines ids the corpus produces.
     *
     * @return list<int>
     */
    public function listInventoryOwnerIdsAfter(int $afterId, int $limit): array;
}
