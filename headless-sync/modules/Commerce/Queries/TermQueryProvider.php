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
 *
 * PARENT SLUG, RESOLVED AT READ TIME (FLAG-COMMCATPARENT-1). `parent_id` is the WordPress parent
 * term id — internal source identity, never published. The public hierarchy reference is the
 * parent's SLUG, which the P2-S3 preflight verified is unambiguous within a taxonomy. It comes
 * from ONE self-join in the same query, never a lookup per row and never a stored column, so a
 * parent rename shows the moment the parent's own row updates.
 *
 * The join is LEFT and tolerant by design (AG-7): a child may project before its parent, or
 * outlive a tombstoned one. It then carries `parent_slug = NULL` and stays in the listing —
 * never dropped, never an error, never a slug invented from the source id. Index-backed on
 * `uq_commerce_taxonomies_source_id`; the `taxonomy_type` predicate keeps the join inside the
 * requested taxonomy (DECISION AA).
 */
final class TermQueryProvider implements QueryProviderInterface
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT     = 200;

    private const COLUMNS = 't.id, t.source_term_id, t.taxonomy_type, t.slug, t.name, t.description,
                    t.parent_id, t.term_count, p.slug AS parent_slug';

    private const FROM = 'commerce.taxonomies t
             LEFT JOIN commerce.taxonomies p
                    ON p.source_term_id = t.parent_id
                   AND p.taxonomy_type = t.taxonomy_type
                   AND p.deleted_at IS NULL';

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
        $where  = ['t.deleted_at IS NULL', 't.taxonomy_type = $1'];

        $cursor = $filters->cursor !== null ? $this->decodeCursor($filters->cursor) : null;
        if ($cursor !== null) {
            $params[] = $cursor['s'];
            $params[] = $cursor['id'];
            $idx      = count($params);
            $where[]  = sprintf(
                '(t.name > $%d OR (t.name = $%d AND t.id::text > $%d))',
                $idx - 1,
                $idx - 1,
                $idx,
            );
        }

        $params[] = $limit + 1;

        $rows = $this->db->query(
            sprintf(
                'SELECT %s FROM %s WHERE %s ORDER BY t.name ASC, t.id ASC LIMIT $%d',
                self::COLUMNS,
                self::FROM,
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
             FROM ' . self::FROM . '
             WHERE t.slug = $1 AND t.taxonomy_type = $2 AND t.deleted_at IS NULL
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
