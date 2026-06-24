<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Queries;

use HSP\Core\Contracts\CursorPage;
use HSP\Core\Contracts\FilterSet;
use HSP\Core\Contracts\QueryProviderInterface;
use HSP\Core\Database\DatabaseConnectionInterface;

/**
 * Queries content.posts projection rows for the REST Delivery API.
 *
 * Authority: Doc 9 §8/§10 — query providers encapsulate projection queries;
 * endpoints must not query tables directly. ADR-040 — no WordPress reads.
 * ADR-038 — transport-agnostic; no WP_REST_* types.
 *
 * Listing sort order: (published_at DESC, id DESC) — deterministic tiebreaker.
 *
 * Category filter: resolved projection-side via join
 *   content.posts → content.entity_taxonomies → content.taxonomies.slug
 * Never by WP term_id; never in the Resource layer. (Architect ruling, P1A-S5.)
 *
 * Cursor encoding: base64url( json({ "s": "<published_at ISO-8601>", "id": "<uuid>" }) )
 *
 * Default listing: status = 'publish' AND deleted_at IS NULL (OPEN-10).
 *
 * DECISION E (v1.6): depends on DatabaseConnectionInterface; no raw pg_* calls.
 * ADR-012: constructor injection only.
 */
final class PostQueryProvider implements QueryProviderInterface
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT     = 100;

    public function __construct(
        private readonly DatabaseConnectionInterface $db,
    ) {}

    public function list(FilterSet $filters): CursorPage
    {
        $limit  = min($filters->limit ?? self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $status = $filters->status ?? 'publish';

        $cursorPublishedAt = null;
        $cursorId          = null;
        if ($filters->cursor !== null) {
            $decoded = $this->decodeCursor($filters->cursor);
            if ($decoded !== null) {
                $cursorPublishedAt = $decoded['s'];
                $cursorId          = $decoded['id'];
            }
        }

        $params = [];
        $where  = ['p.deleted_at IS NULL'];

        $params[] = $status;
        $where[]  = 'p.status = $' . count($params);

        if ($filters->publishedAfter !== null) {
            $params[] = $filters->publishedAfter->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s+00');
            $where[] = 'p.published_at > $' . count($params) . '::timestamptz';
        }

        if ($filters->categorySlug !== null) {
            $params[] = $filters->categorySlug;
            // Projection-side join: posts → entity_taxonomies → taxonomies.slug
            $where[]  = sprintf(
                'EXISTS (
                    SELECT 1
                    FROM content.entity_taxonomies et
                    JOIN content.taxonomies t ON t.id = et.taxonomy_id
                    WHERE et.entity_id = p.id
                      AND t.slug = $%d
                      AND t.deleted_at IS NULL
                )',
                count($params)
            );
        }

        if ($cursorPublishedAt !== null && $cursorId !== null) {
            $params[] = $cursorPublishedAt;
            $params[] = $cursorId;
            $pIdx     = count($params);
            $where[]  = sprintf(
                '(p.published_at < $%d::timestamptz OR (p.published_at = $%d::timestamptz AND p.id::text < $%d))',
                $pIdx - 1,
                $pIdx - 1,
                $pIdx
            );
        }

        $whereClause = implode(' AND ', $where);

        $params[] = $limit + 1;
        $fetchSql  = sprintf(
            'SELECT p.id, p.slug, p.title, p.content, p.excerpt, p.status, p.author,
                    p.published_at, p.updated_at, p.meta_jsonb
             FROM content.posts p
             WHERE %s
             ORDER BY p.published_at DESC, p.id DESC
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
            $nextCursor = $this->encodeCursor($last['published_at'], $last['id']);
        }

        return new CursorPage($rows, $nextCursor);
    }

    public function findBySlug(string $slug): ?array
    {
        $rows = $this->db->query(
            "SELECT id, slug, title, content, excerpt, status, author,
                    published_at, updated_at, meta_jsonb
             FROM content.posts
             WHERE slug = \$1 AND deleted_at IS NULL AND status = 'publish'
             LIMIT 1",
            [$slug]
        );
        return $rows[0] ?? null;
    }

    private function encodeCursor(string $publishedAt, string $id): string
    {
        $json = json_encode(['s' => $publishedAt, 'id' => $id], JSON_UNESCAPED_UNICODE);
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
