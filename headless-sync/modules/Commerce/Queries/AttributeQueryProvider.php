<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Queries;

use HSP\Core\Contracts\CursorPage;
use HSP\Core\Contracts\QueryFilterInterface;
use HSP\Core\Contracts\QueryProviderInterface;
use HSP\Core\Database\DatabaseConnectionInterface;

/**
 * Reads commerce.attributes for the Delivery API.
 *
 * Unlike the taxonomy provider this needs no discriminator: commerce.attributes holds exactly
 * one aggregate type, so there is no shared-table hazard to guard against.
 *
 * Sort: (name ASC, id ASC), with the id tiebreaker because attribute labels collide easily
 * across a large catalogue.
 */
final class AttributeQueryProvider implements QueryProviderInterface
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT     = 200;

    private const COLUMNS = 'id, source_attribute_id, slug, name, type, order_by, has_archives';

    public function __construct(private readonly DatabaseConnectionInterface $db)
    {
    }

    /** @return CursorPage<array<string,mixed>> */
    public function list(QueryFilterInterface $filters): CursorPage
    {
        if (! $filters instanceof TermFilterSet) {
            throw new \InvalidArgumentException(
                self::class . ' requires a ' . TermFilterSet::class . ', got ' . $filters::class . '.'
            );
        }

        $limit  = min($filters->limit ?? self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $params = [];
        $where  = ['deleted_at IS NULL'];

        $cursor = $filters->cursor !== null ? $this->decodeCursor($filters->cursor) : null;
        if ($cursor !== null) {
            $params[] = $cursor['s'];
            $params[] = $cursor['id'];
            $idx      = count($params);
            $where[]  = sprintf('(name > $%d OR (name = $%d AND id::text > $%d))', $idx - 1, $idx - 1, $idx);
        }

        $params[] = $limit + 1;

        $rows = $this->db->query(
            sprintf(
                'SELECT %s FROM commerce.attributes WHERE %s ORDER BY name ASC, id ASC LIMIT $%d',
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
            $nextCursor = $this->encodeCursor((string) $last['name'], (string) $last['id']);
        }

        return new CursorPage($rows, $nextCursor);
    }

    /** Looked up by the FULL taxonomy name, e.g. `pa_colour` — what wc_get_attribute() reports. */
    public function findBySlug(string $slug): ?array
    {
        $rows = $this->db->query(
            'SELECT ' . self::COLUMNS . '
             FROM commerce.attributes
             WHERE slug = $1 AND deleted_at IS NULL
             LIMIT 1',
            [$slug],
        );

        return $rows[0] ?? null;
    }

    private function encodeCursor(string $name, string $id): string
    {
        $json = json_encode(['s' => $name, 'id' => $id], JSON_UNESCAPED_UNICODE) ?: '{}';

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
