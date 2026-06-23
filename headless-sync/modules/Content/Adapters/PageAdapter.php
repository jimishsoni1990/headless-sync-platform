<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Adapters;

use HSP\Core\Contracts\AdapterInterface;
use HSP\Core\Contracts\CanonicalModelInterface;
use HSP\Core\Contracts\EventInterface;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Database\Exception\DatabaseException;
use HSP\Modules\Content\CanonicalModels\CanonicalPage;

/**
 * Persists CanonicalPage into the content.pages PostgreSQL projection.
 *
 * DECISION 3: all three operations commit in ONE PostgreSQL transaction:
 *   1. content.pages upsert (projection) — may be skipped; see below
 *   2. system.processed_events INSERT ON CONFLICT DO NOTHING
 *   3. system.aggregate_versions upsert (monotonic GREATEST guard — FLAG-P1AS4-2)
 *
 * Suppress rules (applied independently, evaluated INSIDE the transaction):
 *   - Checksum suppress (OPEN-11): stored checksum == canonical checksum → skip upsert.
 *   - Version guard: incoming aggregateVersion < locked latest_processed_version → skip upsert.
 *   Both rules suppress only the projection upsert. processed_events and aggregate_versions
 *   are ALWAYS committed — a suppressed event is still recorded (DECISION 3).
 *
 * Concurrency safety: the version guard is atomic with the projection write.
 * Inside BEGIN, a sentinel INSERT ON CONFLICT DO NOTHING materialises the aggregate_versions
 * row if absent, then SELECT FOR UPDATE locks it. Only same-aggregate writers serialise;
 * different aggregates do not contend.
 *
 * DECISION E (v1.6): depends on DatabaseConnectionInterface; no raw pg_* calls.
 * ADR-012: constructor injection only.
 */
final class PageAdapter implements AdapterInterface
{
    public function __construct(
        private readonly DatabaseConnectionInterface $db,
    ) {}

    public function getCanonicalModelClass(): string
    {
        return CanonicalPage::class;
    }

    /**
     * @throws \InvalidArgumentException if $model is not a CanonicalPage
     * @throws DatabaseException on persistence failure
     */
    public function persist(CanonicalModelInterface $model, EventInterface $event): void
    {
        if (! $model instanceof CanonicalPage) {
            throw new \InvalidArgumentException(
                self::class . ' requires ' . CanonicalPage::class . ', got ' . get_class($model)
            );
        }

        $checksum    = $model->getChecksum();
        $existingRow = $this->fetchExistingRow($model->postId);

        $id  = $existingRow['id'] ?? $this->uuidv7();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s+00');

        // Always open a transaction: processed_events + aggregate_versions must be recorded
        // even when the projection upsert is suppressed (DECISION 3).
        $this->db->beginTransaction();
        try {
            // Lock the aggregate_versions row inside the txn so the version guard is
            // atomic with the projection write. Materialise it first if absent (first event).
            $lockedVersion = $this->lockAggregateVersion($event, $now);

            // Suppress projection upsert when stored checksum matches OR incoming version is stale.
            $suppressProjection = ($existingRow !== null && $existingRow['checksum'] === $checksum)
                || ($event->getAggregateVersion() < $lockedVersion);

            if (! $suppressProjection) {
                $this->upsertPage($model, $id, $checksum, $now);
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

    /** @return array<string,mixed>|null */
    private function fetchExistingRow(int $postId): ?array
    {
        $rows = $this->db->query(
            'SELECT id, checksum FROM content.pages WHERE source_post_id = $1',
            [$postId]
        );
        return $rows[0] ?? null;
    }

    /**
     * Materialise the aggregate_versions row if absent (sentinel value 0), then lock it
     * with SELECT FOR UPDATE. Returns the locked latest_processed_version.
     *
     * Must be called inside an open transaction. Serialises concurrent writes for the
     * same (aggregate_type, aggregate_id) pair; different aggregates do not contend.
     */
    private function lockAggregateVersion(EventInterface $event, string $now): int
    {
        // Materialise with version 0 so FOR UPDATE always finds a row (first-event case).
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

    private function upsertPage(CanonicalPage $model, string $id, string $checksum, string $now): void
    {
        $publishedAt = $model->publishedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s+00');
        $updatedAt   = $model->modifiedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s+00');
        $metaJson    = json_encode($model->meta, JSON_UNESCAPED_UNICODE) ?: '{}';

        $this->db->execute(
            'INSERT INTO content.pages
                (id, source_post_id, source_entity_type, slug, title, content, status,
                 parent_id, menu_order, published_at, updated_at, deleted_at,
                 checksum, meta_jsonb, created_at, synced_at)
             VALUES ($1::uuid,$2,$3,$4,$5,$6,$7,$8,$9,
                     $10::timestamptz,$11::timestamptz,NULL,
                     $12,$13::jsonb,$14::timestamptz,$15::timestamptz)
             ON CONFLICT (source_post_id) DO UPDATE SET
                slug         = EXCLUDED.slug,
                title        = EXCLUDED.title,
                content      = EXCLUDED.content,
                status       = EXCLUDED.status,
                parent_id    = EXCLUDED.parent_id,
                menu_order   = EXCLUDED.menu_order,
                published_at = EXCLUDED.published_at,
                updated_at   = EXCLUDED.updated_at,
                deleted_at   = NULL,
                checksum     = EXCLUDED.checksum,
                meta_jsonb   = EXCLUDED.meta_jsonb,
                synced_at    = EXCLUDED.synced_at',
            [
                $id,
                $model->postId,
                'page',
                $model->slug,
                $model->title,
                $model->content,
                $model->status,
                $model->parentId,
                $model->menuOrder,
                $publishedAt,
                $updatedAt,
                $checksum,
                $metaJson,
                $now,
                $now,
            ]
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
        // Monotonic guard (FLAG-P1AS4-2 architect ruling): version only ever advances.
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
