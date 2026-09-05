<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Queries;

use HSP\Core\Contracts\CursorPage;
use HSP\Core\Contracts\FilterSet;
use HSP\Core\Contracts\QueryProviderInterface;
use HSP\Core\Database\DatabaseConnectionInterface;

/**
 * Queries content.media projection rows for the REST Delivery API.
 *
 * Authority: Doc 9 §8/§10 — query providers encapsulate projection queries;
 * endpoints must not query tables directly. ADR-040 — no WordPress reads.
 * ADR-038 — transport-agnostic; no WP_REST_* types.
 *
 * Listing sort order: (published_at DESC, id DESC) — deterministic tiebreaker
 * proves no skipped or duplicated rows when rows share the same published_at.
 * The partial index idx_content_media_live_published_at matches this predicate and
 * sort exactly, so the listing stays index-backed at the 100,000+ record target.
 *
 * Cursor encoding: base64url( json({ "s": "<published_at ISO-8601>", "id": "<uuid>" }) )
 *
 * Membership: attachments carry post_status='inherit', so the {publish} public set
 * (OPEN-10) does not apply — a media row is public while it is not soft-deleted, the
 * same rule categories use. The FilterSet status filter is therefore not applied here.
 *
 * DECISION E (v1.6): depends on DatabaseConnectionInterface; no raw pg_* calls.
 * ADR-012: constructor injection only.
 */
final class MediaQueryProvider implements QueryProviderInterface
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT     = 100;

    private const COLUMNS = 'id, slug, title, mime_type, url, alt_text, caption, description,
                    width, height, sizes_jsonb, attached_to_id, published_at, updated_at, meta_jsonb';

    public function __construct(
        private readonly DatabaseConnectionInterface $db,
    ) {
    }

    /** @return CursorPage<array<string,mixed>> */
    public function list(FilterSet $filters): CursorPage
    {
        $limit = min($filters->limit ?? self::DEFAULT_LIMIT, self::MAX_LIMIT);

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
        $where  = ['deleted_at IS NULL'];

        if ($filters->publishedAfter !== null) {
            $params[] = $filters->publishedAfter->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s+00');
            $where[] = 'published_at > $' . count($params) . '::timestamptz';
        }

        if ($cursorPublishedAt !== null && $cursorId !== null) {
            $params[] = $cursorPublishedAt;
            $params[] = $cursorId;
            // Seek: rows strictly before the cursor position in (published_at DESC, id DESC).
            $pIdx    = count($params);
            $where[] = sprintf(
                '(published_at < $%d::timestamptz OR (published_at = $%d::timestamptz AND id::text < $%d))',
                $pIdx - 1,
                $pIdx - 1,
                $pIdx
            );
        }

        $whereClause = implode(' AND ', $where);

        // Fetch limit+1 to detect whether a next page exists.
        $params[] = $limit + 1;
        $fetchSql = sprintf(
            'SELECT %s
             FROM content.media
             WHERE %s
             ORDER BY published_at DESC, id DESC
             LIMIT $%d',
            self::COLUMNS,
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
            'SELECT ' . self::COLUMNS . '
             FROM content.media
             WHERE slug = $1 AND deleted_at IS NULL
             LIMIT 1',
            [$slug]
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
        return $data;
    }
}
