<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Queries;

use HSP\Core\Contracts\CursorPage;
use HSP\Core\Contracts\FilterSet;
use HSP\Core\Contracts\QueryProviderInterface;
use HSP\Core\Database\DatabaseConnectionInterface;

/**
 * Queries content.pages projection rows for the REST Delivery API.
 *
 * Authority: Doc 9 §8/§10 — query providers encapsulate projection queries;
 * endpoints must not query tables directly. ADR-040 — no WordPress reads.
 * ADR-038 — transport-agnostic; no WP_REST_* types.
 *
 * Listing sort order: (published_at DESC, id DESC) — deterministic tiebreaker
 * proves no skipped or duplicated rows when rows share the same published_at.
 *
 * Cursor encoding: base64url( json({ "s": "<published_at ISO-8601>", "id": "<uuid>" }) )
 *
 * Default listing: status = 'publish' AND deleted_at IS NULL (OPEN-10).
 * status filter: validated by REST boundary to the public set; Query Provider
 * applies it literally — the 400 guard lives in route registration.
 *
 * DECISION E (v1.6): depends on DatabaseConnectionInterface; no raw pg_* calls.
 * ADR-012: constructor injection only.
 */
final class PageQueryProvider implements QueryProviderInterface
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT     = 100;

    /**
     * Featured image resolution (P1B-S2).
     *
     * A LEFT JOIN, deliberately: resolving the featured image per row would turn one listing into
     * N+1 round-trips, which at the 100,000+ record target is the difference between a page load
     * and a timeout. One join keeps a listing at exactly ONE query whatever the page size, and it
     * rides `uq_content_media_source_post_id`, so it stays index-backed.
     *
     * featured_media_id is a SOFT reference (ADR-013): no FK, so the join simply finds nothing
     * when the attachment was never projected, was soft-deleted, or the entity has no featured
     * image at all (id 0). All three cases yield NULLs and the Resource emits `featured_media:
     * null` — never a dangling reference and never a 500.
     */
    private const FEATURED_MEDIA_JOIN =
        'LEFT JOIN content.media fm
                ON fm.source_post_id = p.featured_media_id
               AND fm.deleted_at IS NULL';

    /** Entity columns plus the resolved featured-image columns, all `fm_`-prefixed. */
    private const COLUMNS =
        'p.id, p.slug, p.title, p.content, p.status, p.parent_id, p.menu_order,
                    p.published_at, p.updated_at, p.meta_jsonb, p.featured_media_id,
                    fm.slug AS fm_slug, fm.url AS fm_url, fm.alt_text AS fm_alt_text,
                    fm.mime_type AS fm_mime_type, fm.width AS fm_width, fm.height AS fm_height,
                    fm.sizes_jsonb AS fm_sizes_jsonb';

    public function __construct(
        private readonly DatabaseConnectionInterface $db,
    ) {}

    public function list(FilterSet $filters): CursorPage
    {
        $limit  = min($filters->limit ?? self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $status = $filters->status ?? 'publish';

        // Decode cursor: { "s": "<published_at>", "id": "<uuid>" }
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

        if ($cursorPublishedAt !== null && $cursorId !== null) {
            $params[] = $cursorPublishedAt;
            $params[] = $cursorId;
            // Seek: rows strictly before the cursor position in (published_at DESC, id DESC).
            // Row qualifies when: published_at < cursor_published_at
            //                  OR (published_at = cursor_published_at AND id < cursor_id)
            $pIdx     = count($params);
            $where[]  = sprintf(
                '(p.published_at < $%d::timestamptz OR (p.published_at = $%d::timestamptz AND p.id::text < $%d))',
                $pIdx - 1,
                $pIdx - 1,
                $pIdx
            );
        }

        $whereClause = implode(' AND ', $where);

        // Fetch limit+1 to detect whether a next page exists.
        $params[] = $limit + 1;
        $fetchSql  = sprintf(
            'SELECT %s
             FROM content.pages p
             %s
             WHERE %s
             ORDER BY p.published_at DESC, p.id DESC
             LIMIT $%d',
            self::COLUMNS,
            self::FEATURED_MEDIA_JOIN,
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

    /**
     * Resolve a single published page by slug.
     *
     * MITIGATION, NOT THE FIX — see FLAG-PAGESLUG-1. WordPress enforces page slug uniqueness
     * WITHIN a parent, not globally, so `/about/team` and `/services/team` are both legal and both
     * land here as slug='team'. Without an ORDER BY, `LIMIT 1` returned whichever row PostgreSQL
     * happened to produce — potentially a DIFFERENT page between requests, which is the worst
     * version of the bug because it cannot be reproduced or reported.
     *
     * `ORDER BY p.parent_id, p.id` makes the answer deterministic and picks the least surprising
     * one: parent_id 0 sorts first, so a top-level page wins over a nested namesake, with the id
     * as a stable tiebreak beyond that. It does NOT make the endpoint able to address a specific
     * nested page — that needs the published-contract change under FLAG-PAGESLUG-1 (path-based
     * lookup or a parent filter), which is awaiting a ruling.
     */
    public function findBySlug(string $slug): ?array
    {
        $rows = $this->db->query(
            sprintf(
                "SELECT %s
             FROM content.pages p
             %s
             WHERE p.slug = \$1 AND p.deleted_at IS NULL AND p.status = 'publish'
             ORDER BY p.parent_id, p.id
             LIMIT 1",
                self::COLUMNS,
                self::FEATURED_MEDIA_JOIN,
            ),
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
