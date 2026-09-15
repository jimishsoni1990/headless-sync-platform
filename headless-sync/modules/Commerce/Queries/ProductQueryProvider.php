<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Queries;

use HSP\Core\Contracts\CursorPage;
use HSP\Core\Contracts\MediaReferenceProviderInterface;
use HSP\Core\Contracts\QueryFilterInterface;
use HSP\Core\Contracts\QueryProviderInterface;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Modules\Commerce\CommerceTaxonomies;

/**
 * Reads commerce.products for the Delivery API.
 *
 * Zero synchronous WordPress reads (Rule 6 / ADR-040) — everything comes from the projection.
 *
 * Sort: (published_at DESC, id DESC). The id tiebreaker is not decoration: products created in
 * one import share a timestamp to the second, which is exactly the case a naive cursor pages
 * wrongly, skipping or repeating rows across boundaries.
 *
 * CATALOG VISIBILITY, not post status (Requirement B). Verified against WooCommerce 11.1.0:
 * visibility comes from the product_visibility taxonomy, so a `publish` product carrying
 * `exclude-from-catalog` resolves to visibility 'search' or 'hidden' and must stay out of a
 * catalog listing. Filtering on status alone would publish products the store owner hid.
 *
 * STOCK IS JOINED, NOT STORED (AG-8), and the join is a LEFT one (AG-7/AG-14) — see
 * INVENTORY_JOIN below. The `in_stock` filter is where that tolerance has a limit worth stating:
 * a product with UNKNOWN inventory is not classified as in stock, because an explicit request
 * for available products must not be answered with products nothing knows the availability of.
 */
final class ProductQueryProvider implements QueryProviderInterface
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT     = 100;

    /** Visibility values that appear in a catalog listing (verified: WC read_visibility()). */
    private const CATALOG_VISIBLE = ['visible', 'catalog'];

    private const COLUMNS = 'p.id, p.source_product_id, p.sku, p.slug, p.name, p.description,
                    p.short_description, p.status, p.product_type, p.catalog_visibility,
                    p.featured, p.price, p.regular_price, p.sale_price,
                    p.featured_media_id, p.gallery_media_ids, p.published_at, p.updated_at,
                    p.meta_jsonb, p.variation_selection_supported,
                    i.manages_stock, i.stock_quantity, i.stock_status, i.backorders,
                    ' . self::ATTRIBUTE_TERMS;

    /**
     * THIS PRODUCT'S `pa_*` terms, grouped by taxonomy — the selectable values of a variable
     * product, and the labels for them.
     *
     * WHY THE PRODUCT HAS TO CARRY THIS AT ALL. A variation publishes its selection as
     * `{"pa_size": ""}` when it accepts ANY size, which is a live shape, not a hypothetical: the
     * three v-neck variations on the reference store all carry it. The empty value says a size
     * must still be chosen, but names none of them, so the set of sizes this product actually
     * offers appears NOWHERE in the variation list. WooCommerce resolves the same gap from the
     * parent — `read_variation_attributes()` falls back to `wc_get_object_terms($product, $tax)`
     * the moment any variation stores an empty value — and these are the same rows, already
     * projected by the product's own handler.
     *
     * THE GLOBAL TERM LIST IS NOT A SUBSTITUTE. `/product-attributes/pa_color/terms` serves every
     * colour the STORE defines — five on the reference store — while this product offers three.
     * A selector built from the global list advertises combinations the store does not sell.
     *
     * A correlated scalar subquery rather than a join, for the reason `categoryFilter()` records:
     * joining multiplies a product row per term and the DISTINCT that would fix it breaks the
     * cursor. One aggregate per row, the same shape `content.posts` already uses for tags and
     * categories, so the query count for a one-row page equals a full page (no N+1).
     *
     * `entity_taxonomies` is keyed by `source_term_id` (migration 0004), so a relationship
     * written before its term projected resolves to nothing now and starts resolving the moment
     * that term lands — with no rewrite of the product. Terms are ordered by slug because
     * WordPress assignment order is not projected, and an unstable order would make consumer
     * diffs noisy; WooCommerce's own option order is itself branch-dependent, so there is no
     * source order to reproduce.
     *
     * `product_cat` is deliberately excluded: this field answers "which attribute values does
     * this product offer", and categories are a different question with a different endpoint.
     */
    private const ATTRIBUTE_TERMS = "COALESCE((
                        SELECT json_object_agg(a.taxonomy_type, a.terms)
                        FROM (
                            SELECT t.taxonomy_type,
                                   json_agg(
                                       json_build_object('slug', t.slug, 'name', t.name)
                                       ORDER BY t.slug
                                   ) AS terms
                            FROM commerce.entity_taxonomies et
                            JOIN commerce.taxonomies t ON t.source_term_id = et.source_term_id
                            WHERE et.entity_id = p.id
                              AND starts_with(t.taxonomy_type, '" . CommerceTaxonomies::ATTRIBUTE_PREFIX . "')
                              AND t.deleted_at IS NULL
                            GROUP BY t.taxonomy_type
                        ) a
                    ), '{}') AS attribute_terms_json";

    /**
     * Stock is JOINED at read time, never copied onto the product row (AG-8).
     *
     * A LEFT JOIN, and that single keyword is the whole of the Part 4b read rule. Inventory
     * and product are independently synchronised aggregates that may arrive in either order
     * (AG-7), so an INNER JOIN would silently drop every product whose inventory has not
     * projected yet — a listing that looks correct and is quietly missing rows. With a LEFT
     * JOIN the product stays readable and its stock columns come back NULL, which the
     * resource publishes as UNKNOWN rather than as out of stock.
     */
    private const INVENTORY_JOIN = "LEFT JOIN commerce.inventory i
                    ON i.owner_type = 'product'
                   AND i.owner_id = p.source_product_id
                   AND i.deleted_at IS NULL";

    /**
     * The media capability is NULLABLE, and that is the AG-10 independence clause written into a
     * signature: Commerce must synchronise and serve with the Content module absent. When it is
     * null, products still list, still address, still price and still carry their attachment
     * REFERENCES — one field resolves to null and one to an empty list, and nothing fails.
     *
     * The CORE contract, never a Content class. Commerce cannot name `MediaReferenceProvider`,
     * `content.media` or the Content module at all (AG-10 / Rule 5); it knows an interface and
     * whether something implements it.
     */
    public function __construct(
        private readonly DatabaseConnectionInterface $db,
        private readonly ?MediaReferenceProviderInterface $media = null,
    ) {
    }

    /** @return CursorPage<array<string,mixed>> */
    public function list(QueryFilterInterface $filters): CursorPage
    {
        // AG-5: a filter from another domain is refused rather than silently misread.
        if (! $filters instanceof ProductFilterSet) {
            throw new \InvalidArgumentException(
                self::class . ' requires a ' . ProductFilterSet::class . ', got ' . $filters::class . '.'
            );
        }

        $limit  = min($filters->limit ?? self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $params = [];
        $where  = ['p.deleted_at IS NULL'];

        if ($filters->catalogOnly) {
            $placeholders = [];
            foreach (self::CATALOG_VISIBLE as $visibility) {
                $params[]       = $visibility;
                $placeholders[] = '$' . count($params);
            }
            $where[] = 'p.catalog_visibility IN (' . implode(', ', $placeholders) . ')';
        }

        if ($filters->productType !== null) {
            $params[] = $filters->productType;
            $where[]  = 'p.product_type = $' . count($params);
        }

        if ($filters->sku !== null) {
            $params[] = $filters->sku;
            $where[]  = 'p.sku = $' . count($params);
        }

        if ($filters->featured !== null) {
            $params[] = $filters->featured ? 't' : 'f';
            $where[]  = 'p.featured = $' . count($params) . '::boolean';
        }

        // Prices compare as NUMERIC, never as text: '9' > '10' lexically but not numerically.
        if ($filters->minPrice !== null) {
            $params[] = $filters->minPrice;
            $where[]  = 'p.price >= $' . count($params) . '::numeric';
        }

        if ($filters->maxPrice !== null) {
            $params[] = $filters->maxPrice;
            $where[]  = 'p.price <= $' . count($params) . '::numeric';
        }

        if ($filters->categorySlug !== null) {
            $params[] = $filters->categorySlug;
            $where[]  = $this->categoryFilter(count($params));
        }

        // Both halves or neither: a term slug without its taxonomy would have to match across
        // every attribute, and WordPress only guarantees slug uniqueness within one taxonomy.
        if ($filters->attributeTaxonomy !== null && $filters->attributeTermSlug !== null) {
            $params[] = $filters->attributeTermSlug;
            $params[] = $filters->attributeTaxonomy;
            $where[]  = $this->attributeFilter(count($params) - 1, count($params));
        }

        // Availability. NEITHER direction accepts a NULL status: missing inventory means "not
        // yet known", and a filter that treated it as out of stock would drop valid products
        // from a listing the moment their inventory event lagged (Part 4b).
        if ($filters->inStock !== null) {
            $where[] = $filters->inStock
                ? "i.stock_status = 'instock'"
                : "i.stock_status IN ('outofstock', 'onbackorder')";
        }

        $cursor = $filters->cursor !== null ? $this->decodeCursor($filters->cursor) : null;
        if ($cursor !== null) {
            $params[] = $cursor['s'];
            $params[] = $cursor['id'];
            $idx      = count($params);
            $where[]  = sprintf(
                '(p.published_at < $%d::timestamptz OR (p.published_at = $%d::timestamptz AND p.id::text < $%d))',
                $idx - 1,
                $idx - 1,
                $idx,
            );
        }

        // limit + 1 to detect a next page without a second COUNT query.
        $params[] = $limit + 1;

        $rows = $this->db->query(
            sprintf(
                'SELECT %s FROM commerce.products p %s WHERE %s
                 ORDER BY p.published_at DESC, p.id DESC LIMIT $%d',
                self::COLUMNS,
                self::INVENTORY_JOIN,
                implode(' AND ', $where),
                count($params),
            ),
            $params,
        );

        $hasMore = count($rows) > $limit;

        if ($hasMore) {
            array_pop($rows);
        }

        $nextCursor = null;
        if ($hasMore && $rows !== []) {
            $last       = end($rows);
            $nextCursor = $this->encodeCursor((string) $last['published_at'], (string) $last['id']);
        }

        return new CursorPage($this->withResolvedMedia($rows), $nextCursor);
    }

    /**
     * Single-product lookup.
     *
     * Catalog visibility is NOT applied here: WooCommerce's own behaviour is that a product
     * hidden from the catalog remains reachable at its direct address, and Requirement B
     * says single-product addressing follows that rather than copying listing rules.
     */
    public function findBySlug(string $slug): ?array
    {
        $rows = $this->db->query(
            'SELECT ' . self::COLUMNS . '
             FROM commerce.products p ' . self::INVENTORY_JOIN . '
             WHERE p.slug = $1 AND p.deleted_at IS NULL
             LIMIT 1',
            [$slug],
        );

        if ($rows === []) {
            return null;
        }

        return $this->withResolvedMedia($rows)[0];
    }

    /**
     * Resolve every attachment reference on this page of products in ONE capability call.
     *
     * THE WHOLE PAGE AT ONCE, not one call per product. A page of twenty products each carrying
     * a featured image and a three-image gallery holds eighty references; resolving them per
     * product would be twenty calls and per image eighty — the N+1 AG-10 prohibits by name. Every
     * reference on the page is collected first, asked once, then mapped back, so the query count
     * for a one-row page and for a full page is the same number.
     *
     * GALLERY ORDER IS THE PRODUCT'S, never the database's. The capability answers with a map
     * keyed by attachment id precisely so a caller can walk its own stored sequence: a gallery
     * stored as [124, 126, 125] publishes in that order however PostgreSQL returned the rows.
     *
     * An unresolvable reference is DROPPED from the resolved gallery rather than published as a
     * hole — a consumer rendering a carousel should not have to skip nulls — and the surviving
     * images keep their order relative to each other. None of this touches the stored references:
     * they are the product's own state, and only a WooCommerce edit changes them.
     *
     * Hydrated into the row rather than resolved inside the Resource, deliberately. A Resource
     * shapes data it is handed; injecting the capability there would make serialization perform
     * database I/O, which is exactly how an N+1 gets reintroduced by accident later.
     *
     * @param  array<int, array<string,mixed>> $rows
     * @return array<int, array<string,mixed>>
     */
    private function withResolvedMedia(array $rows): array
    {
        // Capability absent: the rows go out unhydrated and the Resource publishes the safe
        // answer. No probing for a Content class, no fallback SQL, no second code path.
        if ($this->media === null) {
            return $rows;
        }

        $galleries = [];
        $wanted    = [];

        foreach ($rows as $i => $row) {
            $galleries[$i] = $this->galleryIds($row['gallery_media_ids'] ?? '[]');

            $wanted[] = (int) ($row['featured_media_id'] ?? 0);

            foreach ($galleries[$i] as $id) {
                $wanted[] = $id;
            }
        }

        $resolved = $this->media->resolveMany($wanted);

        foreach ($rows as $i => $row) {
            $featuredId = (int) ($row['featured_media_id'] ?? 0);

            $rows[$i]['media_featured'] = $resolved[$featuredId] ?? null;
            $rows[$i]['media_gallery']  = array_values(array_filter(array_map(
                static fn (int $id): ?array => $resolved[$id] ?? null,
                $galleries[$i],
            )));
        }

        return $rows;
    }

    /**
     * The stored gallery reference list, in stored order.
     *
     * `gallery_media_ids` is jsonb, which the driver hands back as a JSON string.
     *
     * No sorting and no de-duplication. WooCommerce's own `set_gallery_image_ids()` runs
     * `wp_parse_id_list()`, which de-duplicates while preserving order, so the stored list IS the
     * source list — reshaping it here would be Commerce inventing a rule WooCommerce did not make.
     *
     * @return list<int>
     */
    private function galleryIds(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, associative: true);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map('intval', array_filter($value, 'is_scalar')));
    }

    /**
     * "This product carries a term with the given slug, in the product_cat taxonomy."
     *
     * EXISTS rather than a JOIN, deliberately: a join would multiply rows when a product sits
     * in several matching categories, and the fix for that (DISTINCT) would then break the
     * cursor. EXISTS asks the question without changing the row count.
     *
     * The taxonomy_type predicate is load-bearing, not decoration — commerce.taxonomies is
     * shared, so without it a pa_* attribute term with the same slug would match (DECISION AA).
     */
    private function categoryFilter(int $slugParam): string
    {
        return sprintf(
            'EXISTS (
                SELECT 1
                FROM commerce.entity_taxonomies et
                JOIN commerce.taxonomies t ON t.source_term_id = et.source_term_id
                WHERE et.entity_id = p.id
                  AND t.slug = $%d
                  AND t.taxonomy_type = %s
                  AND t.deleted_at IS NULL
            )',
            $slugParam,
            // A fixed module-owned literal, never request input — but quoted through a single
            // place so the predicate cannot be dropped or misspelled at a call site.
            "'" . CommerceTaxonomies::PRODUCT_CAT . "'",
        );
    }

    /**
     * Products carrying one term of one global attribute.
     *
     * Same EXISTS shape as the category filter, but the taxonomy is a BOUND PARAMETER rather
     * than a module literal — `pa_colour` is defined by the store operator, not by this module,
     * so it necessarily comes from the request. That makes the predicate itself the only thing
     * standing between an attribute query and another taxonomy's terms, which is why the
     * registrar rejects a non-`pa_` value before it ever reaches here.
     */
    private function attributeFilter(int $slugParam, int $taxonomyParam): string
    {
        return sprintf(
            'EXISTS (
                SELECT 1
                FROM commerce.entity_taxonomies et
                JOIN commerce.taxonomies t ON t.source_term_id = et.source_term_id
                WHERE et.entity_id = p.id
                  AND t.slug = $%d
                  AND t.taxonomy_type = $%d
                  AND t.deleted_at IS NULL
            )',
            $slugParam,
            $taxonomyParam,
        );
    }

    private function encodeCursor(string $publishedAt, string $id): string
    {
        $json = json_encode(['s' => $publishedAt, 'id' => $id], JSON_UNESCAPED_UNICODE) ?: '{}';

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /** @return array{s:string,id:string}|null */
    private function decodeCursor(string $cursor): ?array
    {
        $padded  = strtr($cursor, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $json    = base64_decode($padded, strict: true);

        if ($json === false) {
            return null;
        }

        $data = json_decode($json, associative: true);

        if (! is_array($data) || ! isset($data['s'], $data['id'])) {
            return null;
        }

        return ['s' => (string) $data['s'], 'id' => (string) $data['id']];
    }
}
