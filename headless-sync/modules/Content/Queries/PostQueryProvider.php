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

    /**
     * A post's tags, aggregated in SQL (P1B-S3).
     *
     * A correlated subquery rather than a second fetch: it keeps a listing at ONE round-trip
     * whatever the page size (no N+1), and each row's lookup rides
     * idx_content_entity_taxonomies_taxonomy_id / the taxonomies PK. Stitching the tags client
     * side would mean a second query and reassembly in PHP for no gain.
     *
     * Ordered by slug so the published array is deterministic — an unstable order would make
     * consumer diffs and response caching noisy for no reason.
     */
    private const TAGS_SUBQUERY =
        "COALESCE((
                        SELECT json_agg(json_build_object('slug', t.slug, 'name', t.name) ORDER BY t.slug)
                        FROM content.entity_taxonomies et
                        JOIN content.taxonomies t ON t.id = et.taxonomy_id
                        WHERE et.entity_id = p.id
                          AND t.taxonomy_type = 'post_tag'
                          AND t.deleted_at IS NULL
                    ), '[]') AS tags_json";

    /** Entity columns, the resolved featured-image columns (`fm_`-prefixed), and the tags array. */
    private const COLUMNS =
        'p.id, p.slug, p.title, p.content, p.excerpt, p.status, p.author,
                    p.published_at, p.updated_at, p.meta_jsonb, p.featured_media_id,
                    fm.slug AS fm_slug, fm.url AS fm_url, fm.alt_text AS fm_alt_text,
                    fm.mime_type AS fm_mime_type, fm.width AS fm_width, fm.height AS fm_height,
                    fm.sizes_jsonb AS fm_sizes_jsonb,
                    ' . self::TAGS_SUBQUERY;

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
            $where[]  = $this->taxonomyFilter('category', count($params));
        }

        if ($filters->tagSlug !== null) {
            $params[] = $filters->tagSlug;
            $where[]  = $this->taxonomyFilter('post_tag', count($params));
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
            'SELECT %s
             FROM content.posts p
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

    public function findBySlug(string $slug): ?array
    {
        $rows = $this->db->query(
            sprintf(
                "SELECT %s
             FROM content.posts p
             %s
             WHERE p.slug = \$1 AND p.deleted_at IS NULL AND p.status = 'publish'
             LIMIT 1",
                self::COLUMNS,
                self::FEATURED_MEDIA_JOIN,
            ),
            [$slug]
        );
        return $rows[0] ?? null;
    }

    /**
     * "This post carries a term with the given slug, in the given taxonomy."
     *
     * The taxonomy_type predicate is NOT optional since P1B-S3: categories and tags share
     * content.taxonomies, and WordPress only guarantees slug uniqueness WITHIN a taxonomy — so a
     * tag named "news" and a category named "news" coexist, and a filter without the type
     * predicate would match both.
     */
    private function taxonomyFilter(string $taxonomyType, int $slugParamIndex): string
    {
        return sprintf(
            "EXISTS (
                    SELECT 1
                    FROM content.entity_taxonomies et
                    JOIN content.taxonomies t ON t.id = et.taxonomy_id
                    WHERE et.entity_id = p.id
                      AND t.slug = $%d
                      AND t.taxonomy_type = '%s'
                      AND t.deleted_at IS NULL
                )",
            $slugParamIndex,
            $taxonomyType,
        );
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
