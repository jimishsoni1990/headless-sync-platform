<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Adapters;

use HSP\Core\Contracts\AdapterInterface;
use HSP\Core\Contracts\CanonicalModelInterface;
use HSP\Core\Contracts\EventInterface;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Modules\Commerce\CanonicalModels\CanonicalVariation;

/**
 * Persists CanonicalVariation into commerce.product_variations.
 *
 * The DECISION 3 three-op transaction, as in every adapter in the platform: projection upsert +
 * system.processed_events + system.aggregate_versions, committed together. Suppression affects
 * only the upsert — the event is recorded and the watermark advances either way, which is what
 * makes redelivery safe (Rule 4).
 *
 * A variation's attribute-term links share commerce.entity_taxonomies with products. That table
 * keys on (entity_id, source_term_id) where entity_id is the OWNING entity's projection uuid, so
 * a variation's rows and its parent's rows cannot collide — they are different entities with
 * different uuids, which is precisely why the table was built generic rather than named
 * product_taxonomies.
 */
final class VariationAdapter implements AdapterInterface
{
    public function __construct(private readonly DatabaseConnectionInterface $db)
    {
    }

    public function getCanonicalModelClass(): string
    {
        return CanonicalVariation::class;
    }

    public function persist(CanonicalModelInterface $model, EventInterface $event): void
    {
        if (! $model instanceof CanonicalVariation) {
            throw new \InvalidArgumentException(
                self::class . ' requires ' . CanonicalVariation::class . ', got ' . $model::class
            );
        }

        $checksum = $model->getChecksum();
        $existing = $this->fetchExisting($model->sourceVariationId);
        $id       = $existing['id'] ?? $this->uuidv7();
        $now      = $this->nowUtc();

        $this->db->beginTransaction();

        try {
            $lockedVersion = $this->lockAggregateVersion($event, $now);

            // A TOMBSTONED row is never suppressed, however identical its checksum. Tombstoning
            // leaves the checksum alone, so a variation deleted and re-created — or one whose
            // parent left and re-entered supported scope — hashes to what is already stored.
            // Without this clause the revival is suppressed forever, while reconciliation
            // detects the drift on every pass and repairs it by a re-emission that is suppressed
            // in turn: a permanent loop that never converges and never logs anything.
            $suppress = (
                $existing !== null
                && $existing['checksum'] === $checksum
                && $existing['deleted_at'] === null
            ) || ($event->getAggregateVersion() < $lockedVersion);

            if (! $suppress) {
                $this->upsertVariation($model, $id, $checksum, $now);
                $this->rewriteEntityTaxonomies($id, $model->attributeTermIds);
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
                'UPDATE commerce.product_variations
                 SET deleted_at = $1::timestamptz
                 WHERE source_variation_id = $2',
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
    private function fetchExisting(int $sourceVariationId): ?array
    {
        $rows = $this->db->query(
            'SELECT id, checksum, deleted_at FROM commerce.product_variations
             WHERE source_variation_id = $1',
            [$sourceVariationId],
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

    private function upsertVariation(
        CanonicalVariation $model,
        string $id,
        string $checksum,
        string $now,
    ): void {
        $attributes = json_encode($model->attributes);

        $this->db->execute(
            'INSERT INTO commerce.product_variations
                (id, source_variation_id, source_parent_id, sku, name, description, status,
                 price, regular_price, sale_price, attributes, featured_media_id, menu_order,
                 created_at, updated_at, synced_at, deleted_at, checksum)
             VALUES ($1::uuid,$2,$3,$4,$5,$6,$7,
                     $8::numeric,$9::numeric,$10::numeric,$11::jsonb,$12,$13,
                     $14::timestamptz,$15::timestamptz,$16::timestamptz,NULL,$17)
             ON CONFLICT (source_variation_id) DO UPDATE SET
                source_parent_id  = EXCLUDED.source_parent_id,
                sku               = EXCLUDED.sku,
                name              = EXCLUDED.name,
                description       = EXCLUDED.description,
                status            = EXCLUDED.status,
                price             = EXCLUDED.price,
                regular_price     = EXCLUDED.regular_price,
                sale_price        = EXCLUDED.sale_price,
                attributes        = EXCLUDED.attributes,
                featured_media_id = EXCLUDED.featured_media_id,
                menu_order        = EXCLUDED.menu_order,
                deleted_at        = NULL,
                checksum          = EXCLUDED.checksum,
                updated_at        = EXCLUDED.updated_at,
                synced_at         = EXCLUDED.synced_at',
            [
                $id,
                $model->sourceVariationId,
                $model->sourceParentId,
                $model->sku === '' ? null : $model->sku,
                $model->name,
                $model->description,
                $model->status,
                $model->price,
                $model->regularPrice,
                $model->salePrice,
                $attributes === false ? '{}' : $attributes,
                $model->featuredMediaId,
                $model->menuOrder,
                $now,
                $model->updatedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s+00'),
                $now,
                $checksum,
            ],
        );
    }

    /**
     * Replace this variation's attribute-term links wholesale.
     *
     * A FULL REPLACE keyed on the VARIATION's own projection id, so it touches none of the
     * parent's rows. Links carry the term's SOURCE id, not its projection uuid, for the reason
     * migration 0004 records: keyed by source id the row is a pure function of the variation's
     * own state and is correct in any arrival order.
     *
     * Only the resolved terms appear here. A variation attribute set to "any" has no term to
     * link, so the join rows are deliberately a subset of the projected attribute map — which
     * is why that map, and not this table, is what a consumer reads a variation's selection
     * from.
     *
     * Runs INSIDE the caller's transaction.
     *
     * @param list<int> $termIds
     */
    private function rewriteEntityTaxonomies(string $variationId, array $termIds): void
    {
        $this->db->execute(
            'DELETE FROM commerce.entity_taxonomies WHERE entity_id = $1::uuid',
            [$variationId],
        );

        if ($termIds === []) {
            return;
        }

        $rows   = [];
        $params = [$variationId];

        foreach (array_unique($termIds) as $termId) {
            $params[] = $termId;
            $rows[]   = '($1::uuid, $' . count($params) . ')';
        }

        $this->db->execute(
            'INSERT INTO commerce.entity_taxonomies (entity_id, source_term_id)
             VALUES ' . implode(', ', $rows) . '
             ON CONFLICT DO NOTHING',
            $params,
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
