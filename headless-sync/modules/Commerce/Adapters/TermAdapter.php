<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Adapters;

use HSP\Core\Contracts\AdapterInterface;
use HSP\Core\Contracts\CanonicalModelInterface;
use HSP\Core\Contracts\EventInterface;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Modules\Commerce\CanonicalModels\CanonicalTerm;

/**
 * Persists CanonicalTerm into the shared commerce.taxonomies projection.
 *
 * Same DECISION 3 three-op transaction as every other adapter — projection upsert +
 * system.processed_events + system.aggregate_versions, committed together, with suppression
 * affecting only the upsert.
 *
 * Taxonomy-generic: one adapter serves `product_cat` and every `pa_*` taxonomy, because the
 * discriminator travels on the model rather than being baked into the class. Content's
 * `CategoryAdapter` hardcoded the literal `'category'` and had to be generalised retroactively
 * in P1B-S3; this starts generic.
 *
 * The upsert conflicts on `source_term_id`, NOT on `(taxonomy_type, slug)` — WordPress term ids
 * are globally unique across taxonomies, and `(taxonomy_type, slug)` deliberately carries no
 * unique constraint (see migration 0003).
 */
final class TermAdapter implements AdapterInterface
{
    public function __construct(private readonly DatabaseConnectionInterface $db)
    {
    }

    public function getCanonicalModelClass(): string
    {
        return CanonicalTerm::class;
    }

    public function persist(CanonicalModelInterface $model, EventInterface $event): void
    {
        if (! $model instanceof CanonicalTerm) {
            throw new \InvalidArgumentException(
                self::class . ' requires ' . CanonicalTerm::class . ', got ' . $model::class
            );
        }

        $checksum = $model->getChecksum();
        $existing = $this->fetchExisting($model->sourceTermId);
        $id       = $existing['id'] ?? $this->uuidv7();
        $now      = $this->nowUtc();

        $this->db->beginTransaction();

        try {
            $lockedVersion = $this->lockAggregateVersion($event, $now);

            $suppress = ($existing !== null && $existing['checksum'] === $checksum)
                || ($event->getAggregateVersion() < $lockedVersion);

            if (! $suppress) {
                $this->upsertTerm($model, $id, $checksum, $now);
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
            // Scoped by source_term_id, which is globally unique — so this needs no taxonomy
            // predicate and cannot tombstone the wrong taxonomy's term.
            $this->db->execute(
                'UPDATE commerce.taxonomies SET deleted_at = $1::timestamptz WHERE source_term_id = $2',
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
    private function fetchExisting(int $sourceTermId): ?array
    {
        $rows = $this->db->query(
            'SELECT id, checksum FROM commerce.taxonomies WHERE source_term_id = $1',
            [$sourceTermId],
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

    private function upsertTerm(CanonicalTerm $model, string $id, string $checksum, string $now): void
    {
        $this->db->execute(
            'INSERT INTO commerce.taxonomies
                (id, source_term_id, taxonomy_type, slug, name, description, parent_id,
                 term_count, deleted_at, checksum, created_at, updated_at, synced_at)
             VALUES ($1::uuid,$2,$3,$4,$5,$6,$7,$8,NULL,$9,$10::timestamptz,$11::timestamptz,$12::timestamptz)
             ON CONFLICT (source_term_id) DO UPDATE SET
                taxonomy_type = EXCLUDED.taxonomy_type,
                slug          = EXCLUDED.slug,
                name          = EXCLUDED.name,
                description   = EXCLUDED.description,
                parent_id     = EXCLUDED.parent_id,
                term_count    = EXCLUDED.term_count,
                deleted_at    = NULL,
                checksum      = EXCLUDED.checksum,
                updated_at    = EXCLUDED.updated_at,
                synced_at     = EXCLUDED.synced_at',
            [
                $id,
                $model->sourceTermId,
                $model->taxonomyType,
                $model->slug,
                $model->name,
                $model->description,
                $model->parentId,
                $model->count,
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
