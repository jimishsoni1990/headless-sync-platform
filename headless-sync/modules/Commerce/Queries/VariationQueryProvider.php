<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Queries;

use HSP\Core\Contracts\CursorPage;
use HSP\Core\Contracts\QueryFilterInterface;
use HSP\Core\Contracts\QueryProviderInterface;
use HSP\Core\Database\DatabaseConnectionInterface;

/**
 * Reads commerce.product_variations for the Delivery API.
 *
 * Sort: (menu_order ASC, id ASC) — WooCommerce's own ordering, so a storefront picker renders
 * in the order the store owner arranged rather than in insertion order. The id tiebreaker
 * matters because menu_order is 0 for every variation until someone reorders them, which would
 * otherwise make the cursor non-deterministic across pages.
 */
final class VariationQueryProvider implements QueryProviderInterface
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT     = 200;

    private const COLUMNS = 'id, source_variation_id, source_parent_id, sku, name, description,
                    status, price, regular_price, sale_price, attributes, featured_media_id,
                    menu_order';

    public function __construct(private readonly DatabaseConnectionInterface $db)
    {
    }

    /** @return CursorPage<array<string,mixed>> */
    public function list(QueryFilterInterface $filters): CursorPage
    {
        if (! $filters instanceof VariationFilterSet) {
            throw new \InvalidArgumentException(
                self::class . ' requires a ' . VariationFilterSet::class . ', got ' . $filters::class . '.'
            );
        }

        // Refused rather than defaulted to "everything": variations exist only in the context of
        // a parent, and a store-wide variation listing is not part of the contract.
        if ($filters->parentSourceId === null) {
            throw new \InvalidArgumentException(
                self::class . ' requires a parent product; variations are addressed through their parent.'
            );
        }

        $limit  = min($filters->limit ?? self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $params = [$filters->parentSourceId];
        $where  = ['deleted_at IS NULL', 'source_parent_id = $1'];

        $cursor = $filters->cursor !== null ? $this->decodeCursor($filters->cursor) : null;
        if ($cursor !== null) {
            $params[] = $cursor['o'];
            $params[] = $cursor['id'];
            $idx      = count($params);
            $where[]  = sprintf(
                '(menu_order > $%d OR (menu_order = $%d AND id::text > $%d))',
                $idx - 1,
                $idx - 1,
                $idx,
            );
        }

        $params[] = $limit + 1;

        $rows = $this->db->query(
            sprintf(
                'SELECT %s FROM commerce.product_variations WHERE %s
                 ORDER BY menu_order ASC, id ASC LIMIT $%d',
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
            $nextCursor = $this->encodeCursor((int) $last['menu_order'], (string) $last['id']);
        }

        return new CursorPage($rows, $nextCursor);
    }

    /**
     * Variations have no slug in WooCommerce, so this addresses one by its SOURCE id.
     *
     * The id-as-public-key concern that applies to categories does not apply here: WooCommerce
     * itself identifies a variation by `variation_id` in its own add-to-cart form, so there is
     * no slug being bypassed — there is simply no other identifier to use.
     */
    public function findBySlug(string $slug): ?array
    {
        if (! ctype_digit($slug)) {
            return null;
        }

        $rows = $this->db->query(
            'SELECT ' . self::COLUMNS . '
             FROM commerce.product_variations
             WHERE source_variation_id = $1 AND deleted_at IS NULL
             LIMIT 1',
            [(int) $slug],
        );

        return $rows[0] ?? null;
    }

    private function encodeCursor(int $menuOrder, string $id): string
    {
        $json = json_encode(['o' => $menuOrder, 'id' => $id], JSON_UNESCAPED_UNICODE) ?: '{}';

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /** @return array{o:int,id:string}|null */
    private function decodeCursor(string $cursor): ?array
    {
        $padded  = strtr($cursor, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $json    = base64_decode($padded, strict: true);

        if ($json === false) {
            return null;
        }

        $data = json_decode($json, associative: true);

        if (! is_array($data) || ! isset($data['o'], $data['id'])) {
            return null;
        }

        return ['o' => (int) $data['o'], 'id' => (string) $data['id']];
    }
}
