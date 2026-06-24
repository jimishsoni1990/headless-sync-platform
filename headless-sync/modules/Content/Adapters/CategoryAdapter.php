<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Adapters;

use HSP\Core\Contracts\AdapterInterface;
use HSP\Core\Contracts\CanonicalModelInterface;
use HSP\Core\Contracts\EventInterface;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Database\Exception\DatabaseException;
use HSP\Modules\Content\CanonicalModels\CanonicalCategory;

/**
 * Persists CanonicalCategory into the content.taxonomies PostgreSQL projection.
 *
 * DECISION 3: all three operations commit in ONE PostgreSQL transaction:
 *   1. content.taxonomies upsert (projection) — may be skipped; see below
 *   2. system.processed_events INSERT ON CONFLICT DO NOTHING
 *   3. system.aggregate_versions upsert (monotonic GREATEST guard — FLAG-P1AS4-2)
 *
 * Suppress rules (applied independently, evaluated INSIDE the transaction):
 *   - Checksum suppress (OPEN-11): stored checksum == canonical checksum → skip upsert.
 *   - Version guard: incoming aggregateVersion < locked latest_processed_version → skip upsert.
 *   Both suppress only the projection upsert; processed_events + aggregate_versions always written.
 *
 * Concurrency safety: version guard is atomic with the projection write via
 * materialise-then-lock (INSERT ON CONFLICT DO NOTHING + SELECT FOR UPDATE) on the
 * system.aggregate_versions row inside the DECISION 3 transaction.
 *
 * DECISION E (v1.6): depends on DatabaseConnectionInterface.
 * ADR-012: constructor injection only.
 */
final class CategoryAdapter implements AdapterInterface
{
    public function __construct(
        private readonly DatabaseConnectionInterface $db,
    ) {}

    public function getCanonicalModelClass(): string
    {
        return CanonicalCategory::class;
    }

    /**
     * @throws \InvalidArgumentException if $model is not a CanonicalCategory
     * @throws DatabaseException on persistence failure
     */
    public function persist(CanonicalModelInterface $model, EventInterface $event): void
    {
        if (! $model instanceof CanonicalCategory) {
            throw new \InvalidArgumentException(
                self::class . ' requires ' . CanonicalCategory::class . ', got ' . get_class($model)
            );
        }

        $checksum    = $model->getChecksum();
        $existingRow = $this->fetchExistingRow($model->termId);

        $id  = $existingRow['id'] ?? $this->uuidv7();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s+00');

        $this->db->beginTransaction();
        try {
            $lockedVersion = $this->lockAggregateVersion($event, $now);

            $suppressProjection = ($existingRow !== null && $existingRow['checksum'] === $checksum)
                || ($event->getAggregateVersion() < $lockedVersion);

            if (! $suppressProjection) {
                $this->upsertTaxonomy($model, $id, $checksum, $now);
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
     * Soft-delete the content.taxonomies row for this aggregate (DECISION I).
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
                'UPDATE content.taxonomies SET deleted_at = $1::timestamptz WHERE source_term_id = $2',
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

    /** @return array<string,mixed>|null */
    private function fetchExistingRow(int $termId): ?array
    {
        $rows = $this->db->query(
            'SELECT id, checksum FROM content.taxonomies WHERE source_term_id = $1',
            [$termId]
        );
        return $rows[0] ?? null;
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

    private function upsertTaxonomy(CanonicalCategory $model, string $id, string $checksum, string $now): void
    {
        $this->db->execute(
            'INSERT INTO content.taxonomies
                (id, source_term_id, taxonomy_type, slug, name, description,
                 parent_id, post_count, deleted_at, checksum, created_at, updated_at, synced_at)
             VALUES ($1::uuid,$2,$3,$4,$5,$6,$7,$8,NULL,$9,
                     $10::timestamptz,$11::timestamptz,$12::timestamptz)
             ON CONFLICT (source_term_id) DO UPDATE SET
                slug          = EXCLUDED.slug,
                name          = EXCLUDED.name,
                description   = EXCLUDED.description,
                parent_id     = EXCLUDED.parent_id,
                post_count    = EXCLUDED.post_count,
                deleted_at    = NULL,
                checksum      = EXCLUDED.checksum,
                updated_at    = EXCLUDED.updated_at,
                synced_at     = EXCLUDED.synced_at',
            [
                $id,
                $model->termId,
                'category',
                $model->slug,
                $model->name,
                $model->description,
                $model->parentId,
                $model->count,
                $checksum,
                $now,
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
