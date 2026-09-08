<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce;

/**
 * Live WooCommerce implementation of the module's read boundary.
 *
 * The ONLY place Commerce calls WooCommerce or WordPress read functions. Everything is
 * reached through WooCommerce's PUBLIC API (`wc_get_product`, the WC_Product getters) rather
 * than by reading `wp_postmeta` directly: the protocol in DECISION AG is explicit that
 * synchronisation must not be built against private storage details merely because a value
 * happens to be visible in postmeta.
 *
 * Verified against WooCommerce 11.1.0 — see docs/notes/WOOCOMMERCE-SOURCE-VERIFICATION.md.
 *
 * WooCommerce absent is a NORMAL state, not an error: every method degrades to null/empty so
 * the module can be loaded and inspected without the dependency (AG-12). In practice the
 * module will not even be composed in that case, because CommerceServiceProvider reports
 * unavailable — this is belt and braces for a plugin deactivated mid-request.
 */
final class WpCommerceLoaderImpl implements WpCommerceLoader
{
    /** Meta keys WooCommerce owns internally; never published (mirrors ProtectedMeta). */
    private const PROTECTED_PREFIX = '_';

    public function loadProduct(int $productId): ?array
    {
        $product = $this->product($productId);

        if ($product === null) {
            return null;
        }

        $post = function_exists('get_post') ? get_post($productId) : null;

        return [
            'id'                 => $productId,
            'sku'                => (string) $product->get_sku(),
            'slug'               => (string) $product->get_slug(),
            'name'               => (string) $product->get_name(),
            'description'        => (string) $product->get_description(),
            'short_description'  => (string) $product->get_short_description(),
            'status'             => (string) $product->get_status(),
            'product_type'       => (string) $product->get_type(),
            // Verified: derived from the product_visibility taxonomy, NOT post status.
            'catalog_visibility' => (string) $product->get_catalog_visibility(),
            'featured'           => (bool) $product->is_featured(),
            // Money stays a STRING all the way to normalisation (Requirement C).
            'price'              => $this->priceString($product->get_price()),
            'regular_price'      => $this->priceString($product->get_regular_price()),
            'sale_price'         => $this->priceString($product->get_sale_price()),
            'featured_media_id'  => (int) $product->get_image_id(),
            'gallery_media_ids'  => array_map('intval', (array) $product->get_gallery_image_ids()),
            'published_at'       => $post->post_date_gmt ?? null,
            'modified_at'        => $post->post_modified_gmt ?? null,
            'meta'               => $this->publicMeta($productId),
            'category_ids'       => $this->termIds($productId, CommerceTaxonomies::PRODUCT_CAT),
            'attribute_term_ids' => $this->attributeTermIds($productId),
        ];
    }

    public function productType(int $productId): ?string
    {
        $product = $this->product($productId);

        return $product === null ? null : (string) $product->get_type();
    }

    public function listProductIdsAfter(int $afterId, int $limit): array
    {
        global $wpdb;

        if (! isset($wpdb)) {
            return [];
        }

        // A direct bounded read: `ID > n ORDER BY ID LIMIT k` keyset paging cannot be
        // expressed through WP_Query, and the reconciliation corpus must page deterministically
        // over the whole table. Note NO post_status filter — a draft or private product is
        // still a product whose projection state reconciliation must be able to reason about;
        // filtering to 'publish' here is what made the media corpus permanently empty in
        // P1B-S1 until it was caught.
        $sql = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = %s AND ID > %d
             ORDER BY ID ASC
             LIMIT %d",
            'product',
            $afterId,
            $limit,
        );

        /** @var list<array<string,mixed>>|null $rows */
        $rows = $wpdb->get_results($sql, ARRAY_A);

        return array_map(static fn (array $r): int => (int) $r['ID'], $rows ?? []);
    }

    public function productExists(int $productId): bool
    {
        return $this->product($productId) !== null;
    }

    public function loadTerm(int $termId): ?array
    {
        if (! function_exists('get_term')) {
            return null;
        }

        // No taxonomy argument: WordPress term ids are unique across taxonomies and the term
        // reports its own, so a single lookup serves product_cat and every pa_* alike.
        $term = get_term($termId);

        if (! is_object($term) || ! isset($term->term_id)) {
            return null;
        }

        $taxonomy = (string) ($term->taxonomy ?? '');

        // A term this module does not own is not an error — other plugins register taxonomies
        // freely, and that is normal traffic.
        if (! CommerceTaxonomies::isSupported($taxonomy)) {
            return null;
        }

        return [
            'term_id'     => (int) $term->term_id,
            'taxonomy'    => $taxonomy,
            'slug'        => (string) ($term->slug ?? ''),
            'name'        => (string) ($term->name ?? ''),
            'description' => (string) ($term->description ?? ''),
            'parent'      => (int) ($term->parent ?? 0),
            'count'       => (int) ($term->count ?? 0),
        ];
    }

    /** @return list<int> */
    public function listTermIdsAfter(string $taxonomy, int $afterId, int $limit): array
    {
        global $wpdb;

        if (! isset($wpdb)) {
            return [];
        }

        // Direct bounded read: `term_id > n ORDER BY term_id LIMIT k` keyset paging cannot be
        // expressed through get_terms(), and the reconciliation corpus must page
        // deterministically over the whole taxonomy.
        $sql = $wpdb->prepare(
            "SELECT t.term_id
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
             WHERE tt.taxonomy = %s AND t.term_id > %d
             ORDER BY t.term_id ASC
             LIMIT %d",
            $taxonomy,
            $afterId,
            $limit,
        );

        /** @var list<array<string,mixed>>|null $rows */
        $rows = $wpdb->get_results($sql, ARRAY_A);

        return array_map(static fn (array $r): int => (int) $r['term_id'], $rows ?? []);
    }

    public function loadAttribute(int $attributeId): ?array
    {
        if (! function_exists('wc_get_attribute')) {
            return null;
        }

        // The PUBLIC accessor, not the woocommerce_attribute_taxonomies table. Verified shape:
        // id, name (the human label), slug (the FULL pa_-prefixed taxonomy name), type,
        // order_by, has_archives.
        $attribute = wc_get_attribute($attributeId);

        if (! is_object($attribute) || ! isset($attribute->id)) {
            return null;
        }

        return [
            'id'           => (int) $attribute->id,
            'slug'         => (string) ($attribute->slug ?? ''),
            'name'         => (string) ($attribute->name ?? ''),
            'type'         => (string) ($attribute->type ?? 'select'),
            'order_by'     => (string) ($attribute->order_by ?? 'menu_order'),
            'has_archives' => (bool) ($attribute->has_archives ?? false),
        ];
    }

    /** @return list<int> */
    public function listAttributeIdsAfter(int $afterId, int $limit): array
    {
        $ids = array_map(
            static fn (object $t): int => (int) ($t->attribute_id ?? 0),
            $this->attributeTaxonomies(),
        );

        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > $afterId));
        sort($ids);

        return array_slice($ids, 0, $limit);
    }

    /** @return list<string> */
    public function attributeTaxonomyNames(): array
    {
        if (! function_exists('wc_attribute_taxonomy_name')) {
            return [];
        }

        $names = [];

        foreach ($this->attributeTaxonomies() as $taxonomy) {
            $name = wc_attribute_taxonomy_name((string) ($taxonomy->attribute_name ?? ''));

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** @return list<object> */
    private function attributeTaxonomies(): array
    {
        if (! function_exists('wc_get_attribute_taxonomies')) {
            return [];
        }

        return array_values(array_filter((array) wc_get_attribute_taxonomies(), 'is_object'));
    }

    /**
     * @return WooProductAccess|null A WC_Product, or null when WooCommerce is absent or the id
     *         is not a product.
     *
     * The return is annotated as {@see WooProductAccess} — a documentation-and-analysis
     * interface listing exactly the WooCommerce API this module uses. A real WC_Product does
     * not implement it and does not need to; PHP resolves the calls dynamically. The
     * annotation gives the analyser a type without making WooCommerce a hard dependency, and
     * keeps the depended-upon surface in one reviewable place.
     *
     * @phpstan-return WooProductAccess|null
     */
    private function product(int $productId): ?object
    {
        if (! function_exists('wc_get_product')) {
            return null;
        }

        $product = wc_get_product($productId);

        /** @var WooProductAccess|null $product */
        return is_object($product) ? $product : null;
    }

    /**
     * The TERM ids this product carries in one taxonomy.
     *
     * @return list<int>
     */
    private function termIds(int $productId, string $taxonomy): array
    {
        if (! function_exists('wp_get_object_terms')) {
            return [];
        }

        $ids = wp_get_object_terms($productId, $taxonomy, ['fields' => 'ids']);

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_map('intval', array_filter($ids, 'is_scalar')));
    }

    /**
     * The `pa_*` TERM ids this product carries, across every global attribute taxonomy.
     *
     * One `wp_get_object_terms()` call for all of them rather than one per attribute: the
     * function accepts an array of taxonomies, and a store with a dozen attributes would
     * otherwise pay a dozen queries per product — an N+1 that only shows up on a real catalog.
     *
     * @return list<int>
     */
    private function attributeTermIds(int $productId): array
    {
        $taxonomies = $this->attributeTaxonomyNames();

        if ($taxonomies === [] || ! function_exists('wp_get_object_terms')) {
            return [];
        }

        $ids = wp_get_object_terms($productId, $taxonomies, ['fields' => 'ids']);

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_map('intval', array_filter($ids, 'is_scalar')));
    }

    public function loadVariation(int $variationId): ?array
    {
        $variation = $this->product($variationId);

        if ($variation === null || $variation->get_type() !== 'variation') {
            return null;
        }

        $parentId = (int) $variation->get_parent_id();

        // A variation whose parent is out of Phase 2 scope is NORMAL SOURCE, not a failure
        // (AG-13). Reporting it as absent lets the orphan path tombstone anything already
        // projected, without inventing a product-type-specific repair route.
        if ($parentId <= 0 || ! ProductScope::isSupportedType((string) $this->productType($parentId))) {
            return null;
        }

        $post = function_exists('get_post') ? get_post($variationId) : null;

        // Verified: keys are UNPREFIXED taxonomy names, values are term slugs, and an empty
        // value means "any" rather than absent. Passed through as-is; the extractor decides
        // which entries this module owns.
        $attributes = $this->variationAttributes($variation);

        return [
            'id'                 => $variationId,
            'parent_id'          => $parentId,
            'sku'                => (string) $variation->get_sku(),
            'name'               => (string) $variation->get_name(),
            'description'        => (string) $variation->get_description(),
            'status'             => (string) $variation->get_status(),
            // Money stays a STRING all the way to normalisation (Requirement C).
            'price'              => $this->priceString($variation->get_price()),
            'regular_price'      => $this->priceString($variation->get_regular_price()),
            'sale_price'         => $this->priceString($variation->get_sale_price()),
            'featured_media_id'  => (int) $variation->get_image_id(),
            'menu_order'         => (int) ($post->menu_order ?? 0),
            'attributes'         => $attributes,
            // Resolved HERE, in the read boundary, rather than in the transformer: turning a
            // (taxonomy, slug) pair into a term id is a WordPress read, and the transformer is
            // pure by contract.
            'attribute_term_ids' => $this->variationTermIds($attributes),
            'modified_at'        => $post->post_modified_gmt ?? null,
        ];
    }

    /**
     * The term ids behind a variation's selected values.
     *
     * ONE query for the whole variation rather than one per attribute: a store varying by four
     * attributes would otherwise pay four queries per variation — invisible against a test
     * fixture, an N+1 across a real reconciliation pass.
     *
     * Entries whose value is empty are skipped, because "any" selects no particular term. The
     * result is therefore deliberately smaller than the attribute map, which stays authoritative
     * for the delivery contract; these ids exist to drive the join rows.
     *
     * @param array<string,string> $attributes
     * @return list<int>
     */
    private function variationTermIds(array $attributes): array
    {
        if (! function_exists('get_terms')) {
            return [];
        }

        $wanted = array_filter($attributes, static fn (string $slug): bool => $slug !== '');

        if ($wanted === []) {
            return [];
        }

        $terms = get_terms([
            'taxonomy'   => array_keys($wanted),
            'slug'       => array_values(array_unique($wanted)),
            'hide_empty' => false,
        ]);

        if (! is_array($terms)) {
            return [];
        }

        $ids = [];

        foreach ($terms as $term) {
            // get_terms() can return ids or names depending on its `fields` argument; the call
            // above asks for whole terms, and anything else is a signal not to trust the row.
            if (! $term instanceof \WP_Term) {
                continue;
            }

            // Matched on the PAIR, never on the slug alone. A slug is unique only within one
            // taxonomy, so `pa_colour/blue` and `pa_finish/blue` both come back from the query
            // above and taking either would attach the wrong term.
            if (($wanted[(string) $term->taxonomy] ?? null) === (string) $term->slug) {
                $ids[] = (int) $term->term_id;
            }
        }

        sort($ids);

        return $ids;
    }

    /** @return list<int> */
    public function listVariationIdsAfter(int $afterId, int $limit): array
    {
        global $wpdb;

        if (! isset($wpdb)) {
            return [];
        }

        // Keyset paging over the whole table, with NO post_status filter — a draft or private
        // variation is still a variation whose projection state reconciliation must reason
        // about. Filtering to 'publish' here is what made the media corpus permanently empty in
        // P1B-S1 until it was caught.
        $sql = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = %s AND ID > %d
             ORDER BY ID ASC
             LIMIT %d",
            'product_variation',
            $afterId,
            $limit,
        );

        /** @var list<array<string,mixed>>|null $rows */
        $rows = $wpdb->get_results($sql, ARRAY_A);

        return array_map(static fn (array $r): int => (int) $r['ID'], $rows ?? []);
    }

    public function variationExists(int $variationId): bool
    {
        $variation = $this->product($variationId);

        return $variation !== null && $variation->get_type() === 'variation';
    }

    /**
     * The variation's selected attribute values, as WooCommerce reports them.
     *
     * @return array<string,string> taxonomy name => term slug ('' meaning "any")
     */
    private function variationAttributes(object $variation): array
    {
        if (! method_exists($variation, 'get_attributes')) {
            return [];
        }

        $out = [];

        foreach ((array) $variation->get_attributes() as $taxonomy => $value) {
            if (! is_string($taxonomy) || ! is_scalar($value)) {
                continue;
            }

            $out[$taxonomy] = (string) $value;
        }

        ksort($out);

        return $out;
    }

    public function loadInventory(int $entityId): ?array
    {
        $entity = $this->product($entityId);

        if ($entity === null) {
            return null;
        }

        $productType = (string) $entity->get_type();
        $ownerType   = InventoryOwner::typeFor($productType);

        // Out of Phase 2 scope owns no inventory this platform projects (AG-13).
        if ($ownerType === null) {
            return null;
        }

        // A variation of an out-of-scope parent is out of scope with it.
        if ($ownerType === InventoryOwner::TYPE_VARIATION) {
            $parentId = (int) $entity->get_parent_id();

            if ($parentId <= 0 || ! ProductScope::isSupportedType((string) $this->productType($parentId))) {
                return null;
            }
        }

        // THE ownership question, answered by WooCommerce itself. A parent-managed variation
        // reports its parent's id here and is therefore NOT an owner — no row, no duplicated
        // stock fact (AG-14).
        if (! InventoryOwner::owns($entityId, (int) $entity->get_stock_managed_by_id())) {
            return null;
        }

        // managing_stock(), not get_manage_stock(): the former applies the store-level
        // woocommerce_manage_stock gate, and on a store with stock management disabled the
        // per-product flag is not the effective answer.
        $manages = $entity->managing_stock();

        return [
            'owner_type'       => $ownerType,
            'owner_id'         => $entityId,
            // A tri-state value reaches this line, so it is compared, never cast: 'parent' is
            // truthy and would otherwise read as "manages stock".
            'manages_stock'    => $manages === true,
            'stock_quantity'   => $manages === true ? $this->intOrNull($entity->get_stock_quantity()) : null,
            'stock_status'     => (string) $entity->get_stock_status(),
            'backorders'       => (string) $entity->get_backorders(),
            'low_stock_amount' => $this->intOrNull($entity->get_low_stock_amount()),
        ];
    }

    /** @return list<int> */
    public function listInventoryOwnerIdsAfter(int $afterId, int $limit): array
    {
        global $wpdb;

        if (! isset($wpdb)) {
            return [];
        }

        // Products AND variations in one keyset page. They share the wp_posts id sequence, so a
        // single `ID > n ORDER BY ID LIMIT k` scan over both post types pages the whole corpus
        // deterministically — no interleaving of two independently-paged cursors.
        $sql = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type IN (%s, %s) AND ID > %d
             ORDER BY ID ASC
             LIMIT %d",
            'product',
            'product_variation',
            $afterId,
            $limit,
        );

        /** @var list<array<string,mixed>>|null $rows */
        $rows = $wpdb->get_results($sql, ARRAY_A);

        return array_map(static fn (array $r): int => (int) $r['ID'], $rows ?? []);
    }

    /** WooCommerce returns '' or null for "not set", which must stay distinct from 0. */
    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /** WooCommerce returns '' for "no price set", which must stay distinct from 0. */
    private function priceString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Published-safe meta: `_`-prefixed keys are WooCommerce/WordPress bookkeeping and never
     * reach the delivery contract.
     *
     * @return array<string,mixed>
     */
    private function publicMeta(int $productId): array
    {
        if (! function_exists('get_post_meta')) {
            return [];
        }

        /** @var array<string, list<mixed>> $all */
        $all = (array) get_post_meta($productId);

        $public = [];
        foreach ($all as $key => $values) {
            if (str_starts_with($key, self::PROTECTED_PREFIX)) {
                continue;
            }

            $value = is_array($values) ? ($values[0] ?? null) : $values;

            // The all-meta form of get_post_meta() does NOT unserialize — a documented
            // WordPress quirk that published raw PHP serialization until P1B-S4 caught it.
            if (is_string($value) && function_exists('maybe_unserialize')) {
                $value = maybe_unserialize($value);
            }

            // Objects and resources have no place in a public JSON contract, and would make
            // the adapter's json_encode fail and silently empty the whole meta object.
            if (is_object($value) || is_resource($value)) {
                continue;
            }

            $public[$key] = $value;
        }

        return $public;
    }
}
