<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Queries;

use HSP\Core\Contracts\CursorPage;
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
                    p.meta_jsonb,
                    i.manages_stock, i.stock_quantity, i.stock_status, i.backorders';

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

    public function __construct(private readonly DatabaseConnectionInterface $db)
    {
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

        return new CursorPage($rows, $nextCursor);
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

        return $rows[0] ?? null;
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
