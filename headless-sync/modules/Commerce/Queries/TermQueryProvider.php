<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Queries;

use HSP\Core\Contracts\CursorPage;
use HSP\Core\Contracts\QueryFilterInterface;
use HSP\Core\Contracts\QueryProviderInterface;
use HSP\Core\Database\DatabaseConnectionInterface;

/**
 * Reads commerce.taxonomies for the Delivery API.
 *
 * PARAMETERISED BY TAXONOMY, not duplicated per taxonomy: categories and (from P2-S4) every
 * `pa_*` attribute taxonomy share one projection and differ only by the discriminator, so one
 * class with two container bindings beats two near-identical classes. That is the shape P1B-S3
 * arrived at for Content after first duplicating.
 *
 * EVERY read constrains `taxonomy_type` — the rule DECISION AA made explicit after three
 * separate bugs in Phase 1B came from forgetting it. A shared table's entire cost is that a
 * read must name its taxonomy; forget it and the query silently returns another taxonomy's
 * rows, which looks like working code.
 *
 * Sort: (name ASC, id ASC). The id tiebreaker matters because taxonomy names collide far more
 * often than timestamps do.
 */
final class TermQueryProvider implements QueryProviderInterface
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT     = 200;

    private const COLUMNS = 'id, source_term_id, taxonomy_type, slug, name, description,
                    parent_id, term_count';

    public function __construct(
        private readonly DatabaseConnectionInterface $db,
        private readonly string $taxonomyType,
    ) {
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
        $params = [$this->taxonomyType];
        $where  = ['deleted_at IS NULL', 'taxonomy_type = $1'];

        if ($filters->parentId !== null) {
            $params[] = $filters->parentId;
            $where[]  = 'parent_id = $' . count($params);
        }

        $cursor = $filters->cursor !== null ? $this->decodeCursor($filters->cursor) : null;
        if ($cursor !== null) {
            $params[] = $cursor['s'];
            $params[] = $cursor['id'];
            $idx      = count($params);
            $where[]  = sprintf(
                '(name > $%d OR (name = $%d AND id::text > $%d))',
                $idx - 1,
                $idx - 1,
                $idx,
            );
        }

        $params[] = $limit + 1;

        $rows = $this->db->query(
            sprintf(
                'SELECT %s FROM commerce.taxonomies WHERE %s ORDER BY name ASC, id ASC LIMIT $%d',
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

    /**
     * Slug lookup, ALWAYS scoped by taxonomy.
     *
     * WordPress guarantees slug uniqueness only WITHIN a taxonomy, so a product category and a
     * `pa_colour` term may both be `blue`. Without the predicate `/categories/blue` could
     * resolve to the attribute term — a silently wrong answer rather than an error.
     */
    public function findBySlug(string $slug): ?array
    {
        $rows = $this->db->query(
            'SELECT ' . self::COLUMNS . '
             FROM commerce.taxonomies
             WHERE slug = $1 AND taxonomy_type = $2 AND deleted_at IS NULL
             LIMIT 1',
            [$slug, $this->taxonomyType],
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
