<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Queries;

use HSP\Core\Contracts\CursorPage;
use HSP\Core\Contracts\QueryFilterInterface;
use HSP\Core\Contracts\QueryProviderInterface;
use HSP\Core\Database\DatabaseConnectionInterface;

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
 * NO INVENTORY JOIN YET — stock arrives in P2-S6, and when it does it is a LEFT JOIN, because
 * a product may legitimately project before its inventory row exists (AG-7/AG-14) and must
 * remain listed with unknown stock rather than vanish behind an INNER JOIN.
 */
final class ProductQueryProvider implements QueryProviderInterface
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT     = 100;

    /** Visibility values that appear in a catalog listing (verified: WC read_visibility()). */
    private const CATALOG_VISIBLE = ['visible', 'catalog'];

    private const COLUMNS = 'id, source_product_id, sku, slug, name, description, short_description,
                    status, product_type, catalog_visibility, featured,
                    price, regular_price, sale_price,
                    featured_media_id, gallery_media_ids, published_at, updated_at, meta_jsonb';

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
        $where  = ['deleted_at IS NULL'];

        if ($filters->catalogOnly) {
            $placeholders = [];
            foreach (self::CATALOG_VISIBLE as $visibility) {
                $params[]       = $visibility;
                $placeholders[] = '$' . count($params);
            }
            $where[] = 'catalog_visibility IN (' . implode(', ', $placeholders) . ')';
        }

        if ($filters->productType !== null) {
            $params[] = $filters->productType;
            $where[]  = 'product_type = $' . count($params);
        }

        if ($filters->sku !== null) {
            $params[] = $filters->sku;
            $where[]  = 'sku = $' . count($params);
        }

        if ($filters->featured !== null) {
            $params[] = $filters->featured ? 't' : 'f';
            $where[]  = 'featured = $' . count($params) . '::boolean';
        }

        // Prices compare as NUMERIC, never as text: '9' > '10' lexically but not numerically.
        if ($filters->minPrice !== null) {
            $params[] = $filters->minPrice;
            $where[]  = 'price >= $' . count($params) . '::numeric';
        }

        if ($filters->maxPrice !== null) {
            $params[] = $filters->maxPrice;
            $where[]  = 'price <= $' . count($params) . '::numeric';
        }

        $cursor = $filters->cursor !== null ? $this->decodeCursor($filters->cursor) : null;
        if ($cursor !== null) {
            $params[] = $cursor['s'];
            $params[] = $cursor['id'];
            $idx      = count($params);
            $where[]  = sprintf(
                '(published_at < $%d::timestamptz OR (published_at = $%d::timestamptz AND id::text < $%d))',
                $idx - 1,
                $idx - 1,
                $idx,
            );
        }

        // limit + 1 to detect a next page without a second COUNT query.
        $params[] = $limit + 1;

        $rows = $this->db->query(
            sprintf(
                'SELECT %s FROM commerce.products WHERE %s ORDER BY published_at DESC, id DESC LIMIT $%d',
                self::COLUMNS,
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
             FROM commerce.products
             WHERE slug = $1 AND deleted_at IS NULL
             LIMIT 1',
            [$slug],
        );

        return $rows[0] ?? null;
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
