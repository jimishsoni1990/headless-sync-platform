<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Queries;

use HSP\Core\Contracts\CursorPage;
use HSP\Core\Contracts\FilterSet;
use HSP\Core\Contracts\QueryProviderInterface;
use HSP\Core\Database\DatabaseConnectionInterface;

/**
 * Queries content.taxonomies projection rows for the REST Delivery API.
 *
 * Authority: Doc 9 §8/§10 — query providers encapsulate projection queries;
 * endpoints must not query tables directly. ADR-040 — no WordPress reads.
 * ADR-038 — transport-agnostic; no WP_REST_* types.
 *
 * Listing sort order: (name ASC, id ASC) — deterministic tiebreaker proves no
 * skipped or duplicated rows when rows share the same name.
 *
 * Cursor encoding: base64url( json({ "s": "<name>", "id": "<uuid>" }) )
 *
 * Categories have no status column; deleted_at IS NULL is the only visibility guard.
 * taxonomy_type is always 'category' for Phase 1A (OPEN-10 public set = {publish}).
 *
 * DECISION E (v1.6): depends on DatabaseConnectionInterface; no raw pg_* calls.
 * ADR-012: constructor injection only.
 */
final class CategoryQueryProvider implements QueryProviderInterface
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT     = 200;

    public function __construct(
        private readonly DatabaseConnectionInterface $db,
    ) {}

    public function list(FilterSet $filters): CursorPage
    {
        $limit = min($filters->limit ?? self::DEFAULT_LIMIT, self::MAX_LIMIT);

        $cursorName = null;
        $cursorId   = null;
        if ($filters->cursor !== null) {
            $decoded = $this->decodeCursor($filters->cursor);
            if ($decoded !== null) {
                $cursorName = $decoded['s'];
                $cursorId   = $decoded['id'];
            }
        }

        $params = [];
        $where  = ['deleted_at IS NULL', "taxonomy_type = 'category'"];

        if ($cursorName !== null && $cursorId !== null) {
            $params[] = $cursorName;
            $params[] = $cursorId;
            $pIdx     = count($params);
            // Seek: rows strictly after the cursor position in (name ASC, id ASC).
            // Row qualifies when: name > cursor_name
            //                  OR (name = cursor_name AND id::text > cursor_id)
            $where[]  = sprintf(
                '(name > $%d OR (name = $%d AND id::text > $%d))',
                $pIdx - 1,
                $pIdx - 1,
                $pIdx
            );
        }

        $whereClause = implode(' AND ', $where);

        $params[] = $limit + 1;
        $fetchSql  = sprintf(
            'SELECT id, slug, name, description, parent_id, post_count
             FROM content.taxonomies
             WHERE %s
             ORDER BY name ASC, id ASC
             LIMIT $%d',
            $whereClause,
            count($params)
        );

        $rows = $this->db->query($fetchSql, $params);

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        $nextCursor = null;
        if ($hasMore && ! empty($rows)) {
            $last       = end($rows);
            $nextCursor = $this->encodeCursor($last['name'], $last['id']);
        }

        return new CursorPage($rows, $nextCursor);
    }

    public function findBySlug(string $slug): ?array
    {
        $rows = $this->db->query(
            "SELECT id, slug, name, description, parent_id, post_count
             FROM content.taxonomies
             WHERE slug = \$1 AND deleted_at IS NULL AND taxonomy_type = 'category'
             LIMIT 1",
            [$slug]
        );
        return $rows[0] ?? null;
    }

    private function encodeCursor(string $name, string $id): string
    {
        $json = json_encode(['s' => $name, 'id' => $id], JSON_UNESCAPED_UNICODE);
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
        return $data;
    }
}
