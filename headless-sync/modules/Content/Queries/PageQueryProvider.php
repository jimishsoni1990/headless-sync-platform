<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Queries;

use HSP\Core\Contracts\CursorPage;
use HSP\Core\Contracts\FilterSet;
use HSP\Core\Contracts\HierarchicalQueryProviderInterface;
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
final class PageQueryProvider implements QueryProviderInterface, HierarchicalQueryProviderInterface
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT     = 100;

    /**
     * Defensive bound on the ancestor walk in findByPath() — NOT a limit on legitimate WordPress
     * hierarchy depth (real page trees are two to five deep; this is an order of magnitude above
     * anything an editor builds). Its job is to make a CORRUPT hierarchy terminate: a parent cycle
     * (a → b → a) would otherwise recurse forever, and no cycle can ever reach `parent_id = 0`, so
     * bounding the depth is what makes the query cycle-safe as well as depth-safe.
     */
    private const MAX_ANCESTOR_DEPTH = 50;

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
     * Resolve a single published page by slug — which, for a hierarchical resource, means the
     * TOP-LEVEL page of that name. Exactly `findByPath($slug)` on a one-segment path.
     *
     * The old body — a bare `WHERE slug = $1` with `ORDER BY parent_id, id` to make the ambiguity
     * at least deterministic — was the FLAG-PAGESLUG-1 mitigation, kept by DECISION AD ruling 2 as
     * the `hsp/v1` compatibility fallback and **removed by DECISION AF** on completing that
     * lifecycle. It is not merely uncalled now, it is gone: leaving a method that hands back an
     * arbitrary nested page for a bare slug would be a loaded footgun for the next caller, and the
     * mitigation existed only because the canonical lookup did not yet exist.
     *
     * The method itself has to stay — {@see QueryProviderInterface} requires it, and pages need
     * that interface for the listing endpoint's list(). Delegating gives it the one meaning
     * DECISION AD ruling 2 defines for a one-segment page address, and makes it impossible for the
     * two to drift apart.
     */
    public function findBySlug(string $slug): ?array
    {
        return $this->findByPath($slug);
    }

    /**
     * Resolve a page by its FULL ancestor path (DECISION AD) — `about/team`, not `team`.
     *
     * ONE query. A recursive CTE walks UP from every live page carrying the requested LEAF slug,
     * prepending each ancestor's slug, and the outer filter keeps the branch that both reached the
     * root (`parent_id = 0`) and reconstructed exactly the requested path. Two pages sharing a leaf
     * slug are therefore independently addressable, and a wrong-parent request matches nothing
     * rather than falling back to a namesake.
     *
     * Index-backed at both ends and free of N+1: the anchor reads `idx_content_pages_slug`, and
     * each ancestor hop reads `uq_content_pages_source_post_id` — the same unique index the
     * featured-media join already rides.
     *
     * ANCESTOR VISIBILITY (DECISION AD ruling 5): the public-set predicate is applied in the
     * ANCHOR only, i.e. to the requested page. Ancestors are structural — they contribute a slug
     * to the path and nothing else — so a published child under an unpublished or soft-deleted
     * parent stays addressable, and the parent stays unreachable through its own endpoint.
     *
     * KNOWN LIMIT (FLAG-PAGEPATH-ANCESTOR-1): an ancestor that has NEVER been published has no
     * projection row at all — HookWiring emits only when one side of a transition is in the public
     * set — so its slug is unknown here and the descendant's path cannot be reconstructed. This is
     * a projection-coverage question (OPEN-10), not a lookup one, and is flagged rather than
     * worked around.
     *
     * NO stored path column, no cache, no new persistence: the path is derived from
     * `slug` + `parent_id` + `source_post_id` at request time, so a parent rename is reflected as
     * soon as the parent's own projection row is updated — nothing downstream can go stale.
     */
    public function findByPath(string $path): ?array
    {
        $segments = explode('/', $path);
        $leaf     = (string) array_pop($segments);

        if ($leaf === '') {
            return null;
        }

        $rows = $this->db->query(
            sprintf(
                "WITH RECURSIVE ancestry AS (
                 SELECT leaf.source_post_id AS leaf_id,
                        leaf.parent_id      AS next_parent,
                        leaf.slug::text     AS path,
                        1                   AS depth
                 FROM   content.pages leaf
                 WHERE  leaf.slug = \$1
                   AND  leaf.deleted_at IS NULL
                   AND  leaf.status = 'publish'

                 UNION ALL

                 SELECT a.leaf_id,
                        anc.parent_id,
                        anc.slug || '/' || a.path,
                        a.depth + 1
                 FROM   ancestry a
                 JOIN   content.pages anc ON anc.source_post_id = a.next_parent
                 WHERE  a.next_parent <> 0
                   AND  a.depth < %d
             )
             SELECT %s
             FROM   content.pages p
             %s
             WHERE  p.source_post_id = (
                        SELECT leaf_id
                        FROM   ancestry
                        WHERE  next_parent = 0 AND path = \$2
                        ORDER  BY leaf_id
                        LIMIT  1
                    )
             LIMIT 1",
                self::MAX_ANCESTOR_DEPTH,
                self::COLUMNS,
                self::FEATURED_MEDIA_JOIN,
            ),
            [$leaf, $path]
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
