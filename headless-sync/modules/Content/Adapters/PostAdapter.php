<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Adapters;

use HSP\Core\Contracts\AdapterInterface;
use HSP\Core\Contracts\CanonicalModelInterface;
use HSP\Core\Contracts\EventInterface;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Database\Exception\DatabaseException;
use HSP\Modules\Content\CanonicalModels\CanonicalPost;

/**
 * Persists CanonicalPost into the content.posts PostgreSQL projection.
 *
 * DECISION 3: all three operations commit in ONE PostgreSQL transaction:
 *   1. content.posts upsert (projection) — may be skipped; see below
 *   2. content.entity_taxonomies rewrite (delete-all + reinsert for this entity — categories
 *      AND tags; the delete is unconditional, so the full term set must be supplied)
 *   3. system.processed_events INSERT ON CONFLICT DO NOTHING
 *   4. system.aggregate_versions upsert (monotonic GREATEST guard — FLAG-P1AS4-2)
 *
 * Operations 1 and 2 are both skipped when the projection is suppressed.
 * Operations 3 and 4 are ALWAYS committed — a suppressed event is still recorded.
 *
 * Suppress rules (applied independently, evaluated INSIDE the transaction):
 *   - Checksum suppress (OPEN-11), widened by DECISION AJ (AJ-1): stored checksum == canonical
 *     checksum AND the stored link set == the canonical term set, compared as SETS → skip upsert.
 *     The checksum witnesses the content.posts row only, which Finding 004 proved is not the
 *     whole projection for this aggregate; cardinality equality is explicitly NOT sufficient.
 *     See persist().
 *   - Version guard: incoming aggregateVersion < locked latest_processed_version → skip upsert.
 *
 * Concurrency safety: version guard is atomic with the projection write via
 * materialise-then-lock (INSERT ON CONFLICT DO NOTHING + SELECT FOR UPDATE) on the
 * system.aggregate_versions row inside the DECISION 3 transaction.
 *
 * Join-table rewrite strategy: full replace per entity to handle shrinking category sets.
 *   DELETE FROM content.entity_taxonomies WHERE entity_id = $postUuid
 *   then INSERT one row per term the post carries, keyed by the term's SOURCE id — never its
 *   projection UUID, so the link does not depend on the term having projected first
 *   (DECISION AJ, migration 0009). Both delete and inserts run in the same DECISION 3 transaction.
 *
 * DECISION E (v1.6): depends on DatabaseConnectionInterface.
 * ADR-012: constructor injection only.
 */
final class PostAdapter implements AdapterInterface
{
    public function __construct(
        private readonly DatabaseConnectionInterface $db,
    ) {}

    public function getCanonicalModelClass(): string
    {
        return CanonicalPost::class;
    }

    /**
     * @throws \InvalidArgumentException if $model is not a CanonicalPost
     * @throws DatabaseException on persistence failure
     */
    public function persist(CanonicalModelInterface $model, EventInterface $event): void
    {
        if (! $model instanceof CanonicalPost) {
            throw new \InvalidArgumentException(
                self::class . ' requires ' . CanonicalPost::class . ', got ' . get_class($model)
            );
        }

        $checksum    = $model->getChecksum();
        $existingRow = $this->fetchExistingRow($model->postId);

        // The canonical term set, normalised for comparison AND for the rewrite: deduplicated and
        // sorted ascending, so it lines up with the stored set fetched in the same order.
        $termIds = array_values(array_unique([...$model->categoryIds, ...$model->tagIds]));
        sort($termIds);

        $id  = $existingRow['id'] ?? $this->uuidv7();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s+00');

        $this->db->beginTransaction();
        try {
            $lockedVersion = $this->lockAggregateVersion($event, $now);

            // A TOMBSTONED row is never suppressed, however identical its checksum.
            //
            // Tombstoning sets deleted_at and leaves the checksum alone, so when the source
            // comes back — a post restored from trash, a term or attribute re-created, an
            // aggregate re-entering supported scope — the reloaded state hashes to exactly what
            // is already stored. Without this clause the revival is suppressed and the row stays
            // invisible forever; worse, reconciliation then detects the drift on every pass and
            // repairs it by re-emission (DECISION T/U), which is suppressed in turn. That is a
            // permanent repair loop that never converges and never logs an error.
            //
            // Neither is a projection whose LINK ROWS do not match the state being persisted
            // (DECISION AJ / AJ-1).
            //
            // The checksum witnesses the content.posts row alone, but this aggregate's projection
            // is the row PLUS its content.entity_taxonomies links, and the two can disagree —
            // exactly the state Finding 004 shipped in: relationships silently dropped, a checksum
            // that still matched, and therefore a post suppressed on every replay and
            // reconciliation pass (both compare that same checksum) with its categories never
            // appearing and nothing logged.
            //
            // The comparison is EXACT SET EQUALITY, deliberately not cardinality: [10, 20] and
            // [10, 30] have the same count and are not the same projection, and this whole finding
            // exists because an assumed invariant turned out to be false in production. Both sides
            // are deduplicated and ascending — the stored side by the query's ORDER BY and the
            // table's PK, the canonical side by sort() above — so === compares them as sets,
            // insensitive to source ordering. One bounded, PK-backed read; never a per-term lookup.
            $suppressProjection = (
                $existingRow !== null
                && $existingRow['checksum'] === $checksum
                && $existingRow['deleted_at'] === null
                && $this->storedTermIds($existingRow) === $termIds
            ) || ($event->getAggregateVersion() < $lockedVersion);

            if (! $suppressProjection) {
                $this->upsertPost($model, $id, $checksum, $now);
                // BOTH taxonomies: the rewrite is a full replace per entity, so passing only
                // categories here would delete the post's tag links on every post update.
                $this->rewriteEntityTaxonomies($id, $termIds);
            }
            $this->insertProcessedEvent($event, $checksum, $now);
            $this->upsertAggregateVersion($event, $now);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Soft-delete the content.posts row for this aggregate (DECISION I).
     *
     * Sets deleted_at = event.source_updated_at (deterministic; not worker wall-clock).
     * Three-op DECISION 3 atomicity; idempotent on re-delivery.
     * If the row does not exist the UPDATE is a no-op; processed_events and
     * aggregate_versions are still written.
     */
    public function tombstone(string $aggregateType, string $aggregateId, EventInterface $event): void
    {
        $now       = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s+00');
        $deletedAt = $event->getSourceUpdatedAt()
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s+00');

        $this->db->beginTransaction();
        try {
            $this->db->execute(
                'UPDATE content.posts SET deleted_at = $1::timestamptz WHERE source_post_id = $2',
                [$deletedAt, (int) $aggregateId]
            );
            $this->insertProcessedEvent($event, $event->getChecksum(), $now);
            $this->upsertAggregateVersion($event, $now);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Not implemented in Phase 1A — persist() is the only supported entry point.
     *
     * The correct guarded batch path (events + version context, same guarantees as persist())
     * is deferred to a future ADR that lands with the first batch-with-events caller.
     * FLAG-P1AS4-3, architect ruling 2026-06-23.
     *
     * @param CanonicalModelInterface[] $models
     * @throws \LogicException always
     */
    public function bulkPersist(array $models): void
    {
        throw new \LogicException('bulkPersist() is not implemented in Phase 1A.');
    }

    /**
     * The stored projection for this aggregate: the content.posts row AND the term set it links.
     *
     * Both halves are read in ONE round-trip because both are inputs to the suppress decision
     * (see persist()). The correlated subquery is bounded to this aggregate and rides
     * pk_content_entity_taxonomies, whose leading column is entity_id — never a per-term lookup.
     *
     * The term ids come back as one ordered, comma-joined string rather than a PostgreSQL array
     * literal, which would otherwise have to be parsed; `ORDER BY` makes the comparison in
     * storedTermIds() a plain sorted-list equality.
     *
     * @return array<string,mixed>|null
     */
    private function fetchExistingRow(int $postId): ?array
    {
        $rows = $this->db->query(
            "SELECT p.id, p.checksum, p.deleted_at,
                    (SELECT string_agg(et.source_term_id::text, ',' ORDER BY et.source_term_id)
                       FROM content.entity_taxonomies et
                      WHERE et.entity_id = p.id) AS link_term_ids
             FROM content.posts p
             WHERE p.source_post_id = \$1",
            [$postId]
        );
        return $rows[0] ?? null;
    }

    /**
     * The term ids currently linked to a stored projection row, ascending.
     *
     * NULL means the aggregate has no link rows at all (string_agg over an empty set), which is a
     * legitimate state — a post in no taxonomy — and must compare equal to an empty canonical set,
     * not be confused with one.
     *
     * @param  array<string,mixed> $existingRow
     * @return list<int>
     */
    private function storedTermIds(array $existingRow): array
    {
        $joined = $existingRow['link_term_ids'] ?? null;

        if ($joined === null || $joined === '') {
            return [];
        }

        return array_map(intval(...), explode(',', (string) $joined));
    }

    /** @see PageAdapter::lockAggregateVersion() for full rationale */
    private function lockAggregateVersion(EventInterface $event, string $now): int
    {
        $this->db->execute(
            'INSERT INTO system.aggregate_versions
                (aggregate_type, aggregate_id, latest_processed_version, latest_processed_at)
             VALUES ($1, $2, 0, $3::timestamptz)
             ON CONFLICT (aggregate_type, aggregate_id) DO NOTHING',
            [$event->getAggregateType(), $event->getAggregateId(), $now]
        );

        $rows = $this->db->query(
            'SELECT latest_processed_version FROM system.aggregate_versions
             WHERE aggregate_type = $1 AND aggregate_id = $2
             FOR UPDATE',
            [$event->getAggregateType(), $event->getAggregateId()]
        );

        return isset($rows[0]) ? (int) $rows[0]['latest_processed_version'] : 0;
    }

    private function upsertPost(CanonicalPost $model, string $id, string $checksum, string $now): void
    {
        $meta        = $model->meta;
        ksort($meta);
        $publishedAt = $model->publishedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s+00');
        $updatedAt   = $model->modifiedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s+00');
        $metaJson    = json_encode($meta, JSON_UNESCAPED_UNICODE) ?: '{}';

        $this->db->execute(
            'INSERT INTO content.posts
                (id, source_post_id, source_entity_type, slug, title, content, excerpt,
                 status, author, featured_media_id, published_at, updated_at, deleted_at,
                 checksum, meta_jsonb, created_at, synced_at)
             VALUES ($1::uuid,$2,$3,$4,$5,$6,$7,$8,$9,$10,
                     $11::timestamptz,$12::timestamptz,NULL,
                     $13,$14::jsonb,$15::timestamptz,$16::timestamptz)
             ON CONFLICT (source_post_id) DO UPDATE SET
                slug         = EXCLUDED.slug,
                title        = EXCLUDED.title,
                content      = EXCLUDED.content,
                excerpt      = EXCLUDED.excerpt,
                status       = EXCLUDED.status,
                author       = EXCLUDED.author,
                featured_media_id = EXCLUDED.featured_media_id,
                published_at = EXCLUDED.published_at,
                updated_at   = EXCLUDED.updated_at,
                deleted_at   = NULL,
                checksum     = EXCLUDED.checksum,
                meta_jsonb   = EXCLUDED.meta_jsonb,
                synced_at    = EXCLUDED.synced_at',
            [
                $id,
                $model->postId,
                'post',
                $model->slug,
                $model->title,
                $model->content,
                $model->excerpt,
                $model->status,
                $model->author,
                $model->featuredMediaId,
                $publishedAt,
                $updatedAt,
                $checksum,
                $metaJson,
                $now,
                $now,
            ]
        );
    }

    /**
     * Full replace of entity_taxonomies for this post entity.
     *
     * Deletes all existing join rows for $postId, then inserts one row per TERM — category or
     * tag — the post carries.
     *
     * The delete is unconditional and covers every taxonomy, so the caller MUST pass the post's
     * full term set. Passing categories alone silently unlinked every tag on each post update
     * (the P1B-S3 join bug).
     *
     * LINKS ARE STORED BY THE TERM'S SOURCE ID, not its projection UUID — DECISION AJ (v1.42),
     * which amends the frozen FLAG-P1AS4-1 shape, and migration 0009.
     *
     * This used to resolve source_term_id → content.taxonomies.id here and silently omit whatever
     * had not projected yet, on the promise that the link would appear "when the category syncs".
     * Nothing implements that: this adapter is the only writer of the table. A post that projected
     * before its categories therefore linked to nothing, permanently — its own state had not
     * changed, so the checksum did not move, DECISION 3 suppressed the rewrite, and reconciliation
     * compared those same checksums and saw no gap (Finding 004: a populated category served an
     * empty archive). Keyed by source id the row is a pure function of the post's own state and is
     * correct in any arrival order.
     *
     * Commerce reached the same shape first under AG-7, which is supporting precedent — NOT
     * retroactive authority over Content. Content's UUID relationship identity stayed frozen until
     * DECISION AJ amended it here.
     *
     * Both delete and inserts execute inside the caller's open transaction (DECISION 3).
     *
     * @param list<int> $termIds source wp_terms.term_id values across ALL supported taxonomies
     */
    private function rewriteEntityTaxonomies(string $postUuid, array $termIds): void
    {
        // Remove all prior join rows for this entity (handles shrinking category set).
        $this->db->execute(
            'DELETE FROM content.entity_taxonomies WHERE entity_id = $1::uuid',
            [$postUuid]
        );

        $termIds = array_values(array_unique($termIds));

        if ($termIds === []) {
            return;
        }

        // One multi-row INSERT rather than a statement per term: a post in twenty categories
        // should not cost twenty round trips inside the transaction.
        $rows   = [];
        $params = [$postUuid];

        foreach ($termIds as $termId) {
            $params[] = $termId;
            $rows[]   = '($1::uuid, $' . count($params) . ')';
        }

        $this->db->execute(
            'INSERT INTO content.entity_taxonomies (entity_id, source_term_id)
             VALUES ' . implode(', ', $rows) . '
             ON CONFLICT DO NOTHING',
            $params
        );
    }

    private function insertProcessedEvent(EventInterface $event, string $checksum, string $now): void
    {
        $this->db->execute(
            'INSERT INTO system.processed_events (event_id, checksum, processed_at)
             VALUES ($1::uuid, $2, $3::timestamptz)
             ON CONFLICT (event_id) DO NOTHING',
            [$event->getId(), $checksum, $now]
        );
    }

    private function upsertAggregateVersion(EventInterface $event, string $now): void
    {
        $this->db->execute(
            'INSERT INTO system.aggregate_versions
                (aggregate_type, aggregate_id, latest_processed_version, latest_processed_at)
             VALUES ($1, $2, $3, $4::timestamptz)
             ON CONFLICT (aggregate_type, aggregate_id) DO UPDATE SET
                latest_processed_version = GREATEST(
                    system.aggregate_versions.latest_processed_version,
                    EXCLUDED.latest_processed_version
                ),
                latest_processed_at = CASE
                    WHEN EXCLUDED.latest_processed_version >= system.aggregate_versions.latest_processed_version
                    THEN EXCLUDED.latest_processed_at
                    ELSE system.aggregate_versions.latest_processed_at
                END',
            [
                $event->getAggregateType(),
                $event->getAggregateId(),
                $event->getAggregateVersion(),
                $now,
            ]
        );
    }

    private function uuidv7(): string
    {
        $ms      = (int) (microtime(true) * 1000);
        $bytes   = random_bytes(10);
        $tsHex   = sprintf('%012x', $ms);
        $rand12  = (ord($bytes[0]) & 0x0f) << 8 | ord($bytes[1]);
        $b67hex  = sprintf('%04x', 0x7000 | $rand12);
        $rand14  = (ord($bytes[2]) & 0x3f) << 8 | ord($bytes[3]);
        $b89hex  = sprintf('%04x', 0x8000 | $rand14);
        $tailHex = bin2hex(substr($bytes, 4, 6));
        $hex     = $tsHex . $b67hex . $b89hex . $tailHex;
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hex, 4));
    }
}
