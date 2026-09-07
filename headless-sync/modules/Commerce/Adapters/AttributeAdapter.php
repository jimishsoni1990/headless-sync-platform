<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Adapters;

use HSP\Core\Contracts\AdapterInterface;
use HSP\Core\Contracts\CanonicalModelInterface;
use HSP\Core\Contracts\EventInterface;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Modules\Commerce\CanonicalModels\CanonicalAttribute;

/**
 * Persists CanonicalAttribute into commerce.attributes.
 *
 * The same DECISION 3 three-op transaction as every other adapter in the platform: projection
 * upsert + system.processed_events + system.aggregate_versions, committed together, with
 * suppression affecting only the upsert.
 */
final class AttributeAdapter implements AdapterInterface
{
    public function __construct(private readonly DatabaseConnectionInterface $db)
    {
    }

    public function getCanonicalModelClass(): string
    {
        return CanonicalAttribute::class;
    }

    public function persist(CanonicalModelInterface $model, EventInterface $event): void
    {
        if (! $model instanceof CanonicalAttribute) {
            throw new \InvalidArgumentException(
                self::class . ' requires ' . CanonicalAttribute::class . ', got ' . $model::class
            );
        }

        $checksum = $model->getChecksum();
        $existing = $this->fetchExisting($model->sourceAttributeId);
        $id       = $existing['id'] ?? $this->uuidv7();
        $now      = $this->nowUtc();

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
            $suppress = (
                $existing !== null
                && $existing['checksum'] === $checksum
                && $existing['deleted_at'] === null
            ) || ($event->getAggregateVersion() < $lockedVersion);

            if (! $suppress) {
                $this->upsertAttribute($model, $id, $checksum, $now);
            }

            $this->insertProcessedEvent($event, $checksum, $now);
            $this->upsertAggregateVersion($event, $now);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function tombstone(string $aggregateType, string $aggregateId, EventInterface $event): void
    {
        $now       = $this->nowUtc();
        $deletedAt = $event->getSourceUpdatedAt()
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s+00');

        $this->db->beginTransaction();

        try {
            $this->db->execute(
                'UPDATE commerce.attributes SET deleted_at = $1::timestamptz WHERE source_attribute_id = $2',
                [$deletedAt, (int) $aggregateId],
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
     * @param CanonicalModelInterface[] $models
     * @throws \LogicException always
     */
    public function bulkPersist(array $models): void
    {
        throw new \LogicException('bulkPersist() is not implemented.');
    }

    /** @return array<string,mixed>|null */
    private function fetchExisting(int $sourceAttributeId): ?array
    {
        $rows = $this->db->query(
            'SELECT id, checksum, deleted_at FROM commerce.attributes WHERE source_attribute_id = $1',
            [$sourceAttributeId],
        );

        return $rows[0] ?? null;
    }

    private function lockAggregateVersion(EventInterface $event, string $now): int
    {
        $this->db->execute(
            'INSERT INTO system.aggregate_versions
                (aggregate_type, aggregate_id, latest_processed_version, latest_processed_at)
             VALUES ($1, $2, 0, $3::timestamptz)
             ON CONFLICT (aggregate_type, aggregate_id) DO NOTHING',
            [$event->getAggregateType(), $event->getAggregateId(), $now],
        );

        $rows = $this->db->query(
            'SELECT latest_processed_version FROM system.aggregate_versions
             WHERE aggregate_type = $1 AND aggregate_id = $2
             FOR UPDATE',
            [$event->getAggregateType(), $event->getAggregateId()],
        );

        return isset($rows[0]) ? (int) $rows[0]['latest_processed_version'] : 0;
    }

    private function upsertAttribute(CanonicalAttribute $model, string $id, string $checksum, string $now): void
    {
        $this->db->execute(
            'INSERT INTO commerce.attributes
                (id, source_attribute_id, slug, name, type, order_by, has_archives,
                 deleted_at, checksum, created_at, updated_at, synced_at)
             VALUES ($1::uuid,$2,$3,$4,$5,$6,$7,NULL,$8,$9::timestamptz,$10::timestamptz,$11::timestamptz)
             ON CONFLICT (source_attribute_id) DO UPDATE SET
                slug         = EXCLUDED.slug,
                name         = EXCLUDED.name,
                type         = EXCLUDED.type,
                order_by     = EXCLUDED.order_by,
                has_archives = EXCLUDED.has_archives,
                deleted_at   = NULL,
                checksum     = EXCLUDED.checksum,
                updated_at   = EXCLUDED.updated_at,
                synced_at    = EXCLUDED.synced_at',
            [
                $id,
                $model->sourceAttributeId,
                $model->slug,
                $model->name,
                $model->type,
                $model->orderBy,
                $model->hasArchives ? 't' : 'f',
                $checksum,
                $now,
                $now,
                $now,
            ],
        );
    }

    private function insertProcessedEvent(EventInterface $event, string $checksum, string $now): void
    {
        $this->db->execute(
            'INSERT INTO system.processed_events (event_id, checksum, processed_at)
             VALUES ($1::uuid, $2, $3::timestamptz)
             ON CONFLICT (event_id) DO NOTHING',
            [$event->getId(), $checksum, $now],
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
            ],
        );
    }

    private function nowUtc(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s+00');
    }

    private function uuidv7(): string
    {
        $ms    = (int) (microtime(true) * 1000);
        $bytes = random_bytes(10);

        $tsHex  = sprintf('%012x', $ms);
        $rand12 = (ord($bytes[0]) & 0x0f) << 8 | ord($bytes[1]);
        $b67hex = sprintf('%04x', 0x7000 | $rand12);
        $rand14 = (ord($bytes[2]) & 0x3f) << 8 | ord($bytes[3]);
        $b89hex = sprintf('%04x', 0x8000 | $rand14);
        $tail   = bin2hex(substr($bytes, 4, 6));

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($tsHex, 0, 8),
            substr($tsHex, 8, 4),
            $b67hex,
            $b89hex,
            $tail,
        );
    }
}
