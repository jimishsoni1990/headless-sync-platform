<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Adapters;

use HSP\Core\Contracts\AdapterInterface;
use HSP\Core\Contracts\CanonicalModelInterface;
use HSP\Core\Contracts\EventInterface;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Database\Exception\DatabaseException;
use HSP\Modules\Commerce\CanonicalModels\CanonicalProduct;

/**
 * Persists CanonicalProduct into the commerce.products projection.
 *
 * DECISION 3 — all three operations commit in ONE PostgreSQL transaction:
 *   1. commerce.products upsert (may be suppressed; see below)
 *   2. system.processed_events INSERT ON CONFLICT DO NOTHING
 *   3. system.aggregate_versions upsert under the monotonic GREATEST guard
 *
 * Suppression is evaluated INSIDE the transaction and affects only the projection upsert;
 * processed_events and aggregate_versions are always committed, so a suppressed event is
 * still recorded as processed.
 *
 * The transaction shape, the version lock, the GREATEST guard and the UUIDv7 id are IDENTICAL
 * to the shipped Content adapters. That is deliberate: Phase 2 introduces a second domain, not
 * a second persistence mechanism, and any divergence here would be a second way for the
 * platform to be correct — which is how the two implementations drift.
 *
 * DECISION E: depends on DatabaseConnectionInterface; no raw pg_* calls. No new PG handle
 * (DECISION L Ruling 0) — the delivery handle is shared with Content.
 */
final class ProductAdapter implements AdapterInterface
{
    public function __construct(private readonly DatabaseConnectionInterface $db)
    {
    }

    public function getCanonicalModelClass(): string
    {
        return CanonicalProduct::class;
    }

    /**
     * @throws \InvalidArgumentException if $model is not a CanonicalProduct
     * @throws DatabaseException on persistence failure
     */
    public function persist(CanonicalModelInterface $model, EventInterface $event): void
    {
        if (! $model instanceof CanonicalProduct) {
            throw new \InvalidArgumentException(
                self::class . ' requires ' . CanonicalProduct::class . ', got ' . $model::class
            );
        }

        $checksum    = $model->getChecksum();
        $existingRow = $this->fetchExistingRow($model->sourceProductId);
        $id          = $existingRow['id'] ?? $this->uuidv7();
        $now         = $this->nowUtc();

        $this->db->beginTransaction();

        try {
            $lockedVersion = $this->lockAggregateVersion($event, $now);

            $suppressProjection = ($existingRow !== null && $existingRow['checksum'] === $checksum)
                || ($event->getAggregateVersion() < $lockedVersion);

            if (! $suppressProjection) {
                $this->upsertProduct($model, $id, $checksum, $now);
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
     * Soft-delete the product (DECISION I).
     *
     * Also the path a product takes when it LEAVES supported scope — a `variable` product
     * retyped to `grouped` tombstones here rather than remaining publicly visible forever
     * (AG-13). `deleted_at` is the event's source timestamp, not worker wall-clock, so a
     * replay produces the same value.
     */
    public function tombstone(string $aggregateType, string $aggregateId, EventInterface $event): void
    {
        $now       = $this->nowUtc();
        $deletedAt = $event->getSourceUpdatedAt()
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s+00');

        $this->db->beginTransaction();

        try {
            $this->db->execute(
                'UPDATE commerce.products SET deleted_at = $1::timestamptz WHERE source_product_id = $2',
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
     * @throws \LogicException always — persist() is the only supported entry point
     *         (FLAG-P1AS4-3 ruling, unchanged for this aggregate).
     */
    public function bulkPersist(array $models): void
    {
        throw new \LogicException('bulkPersist() is not implemented.');
    }

    /** @return array<string,mixed>|null */
    private function fetchExistingRow(int $sourceProductId): ?array
    {
        $rows = $this->db->query(
            'SELECT id, checksum FROM commerce.products WHERE source_product_id = $1',
            [$sourceProductId],
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

    private function upsertProduct(CanonicalProduct $model, string $id, string $checksum, string $now): void
    {
        $utc         = new \DateTimeZone('UTC');
        $publishedAt = $model->publishedAt->setTimezone($utc)->format('Y-m-d H:i:s+00');
        $updatedAt   = $model->updatedAt->setTimezone($utc)->format('Y-m-d H:i:s+00');
        $gallery     = json_encode($model->galleryMediaIds, JSON_UNESCAPED_UNICODE) ?: '[]';
        $metaJson    = json_encode($model->meta, JSON_UNESCAPED_UNICODE) ?: '{}';

        // Prices bind as strings into NUMERIC: PostgreSQL parses the exact decimal, so no
        // binary float ever touches the stored value (Requirement C).
        $this->db->execute(
            'INSERT INTO commerce.products
                (id, source_product_id, sku, slug, name, description, short_description,
                 status, product_type, catalog_visibility, featured,
                 price, regular_price, sale_price,
                 featured_media_id, gallery_media_ids,
                 published_at, updated_at, deleted_at, checksum, meta_jsonb, created_at, synced_at)
             VALUES ($1::uuid,$2,$3,$4,$5,$6,$7,
                     $8,$9,$10,$11,
                     $12::numeric,$13::numeric,$14::numeric,
                     $15,$16::jsonb,
                     $17::timestamptz,$18::timestamptz,NULL,$19,$20::jsonb,$21::timestamptz,$22::timestamptz)
             ON CONFLICT (source_product_id) DO UPDATE SET
                sku                = EXCLUDED.sku,
                slug               = EXCLUDED.slug,
                name               = EXCLUDED.name,
                description        = EXCLUDED.description,
                short_description  = EXCLUDED.short_description,
                status             = EXCLUDED.status,
                product_type       = EXCLUDED.product_type,
                catalog_visibility = EXCLUDED.catalog_visibility,
                featured           = EXCLUDED.featured,
                price              = EXCLUDED.price,
                regular_price      = EXCLUDED.regular_price,
                sale_price         = EXCLUDED.sale_price,
                featured_media_id  = EXCLUDED.featured_media_id,
                gallery_media_ids  = EXCLUDED.gallery_media_ids,
                published_at       = EXCLUDED.published_at,
                updated_at         = EXCLUDED.updated_at,
                deleted_at         = NULL,
                checksum           = EXCLUDED.checksum,
                meta_jsonb         = EXCLUDED.meta_jsonb,
                synced_at          = EXCLUDED.synced_at',
            [
                $id,
                $model->sourceProductId,
                $model->sku,
                $model->slug,
                $model->name,
                $model->description,
                $model->shortDescription,
                $model->status,
                $model->productType,
                $model->catalogVisibility,
                $model->featured ? 't' : 'f',
                $model->price,
                $model->regularPrice,
                $model->salePrice,
                $model->featuredMediaId,
                $gallery,
                $publishedAt,
                $updatedAt,
                $checksum,
                $metaJson,
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
