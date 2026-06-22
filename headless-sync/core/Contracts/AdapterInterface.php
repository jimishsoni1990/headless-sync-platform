<?php

declare(strict_types=1);

namespace HSP\Core\Contracts;

/**
 * Persists canonical models into a delivery projection store (PostgreSQL).
 *
 * Each adapter is responsible for the three-operation atomic PostgreSQL transaction
 * mandated by DECISION 3:
 *   1. Projection upsert (content.* table)
 *   2. INSERT INTO system.processed_events
 *   3. UPSERT system.aggregate_versions
 *
 * Write-suppress logic: compare a freshly-computed projection checksum against the
 * stored content.* checksum — NOT against the event's own checksum (DECISION 3).
 *
 * Adapters are idempotent: redelivery of the same event must be safe (CLAUDE.md rule 4).
 *
 * bulkPersist() is a CAPABILITY declaration per Doc 7 §19 / DECISION D (v1.4). A
 * conforming adapter may implement it by looping persist() internally. Bulk SQL,
 * batch upserts, and single-transaction semantics for bulk operations are
 * implementation-defined and specified at the adapter implementation task.
 */
interface AdapterInterface
{
    /**
     * Persist a single canonical model inside a single PostgreSQL transaction.
     *
     * @param CanonicalModelInterface $model Produced by a TransformerInterface
     * @param EventInterface          $event The triggering event envelope
     */
    public function persist(CanonicalModelInterface $model, EventInterface $event): void;

    /**
     * Persist multiple canonical models for reconciliation, full replay, or bulk import.
     *
     * This is a capability declaration — Doc 7 §19, DECISION D (v1.4). A conforming
     * adapter may loop persist() internally; it is not required to use bulk SQL or a
     * single wrapping transaction. Bulk transaction and per-model versioning/event
     * sourcing semantics are implementation-defined.
     *
     * @param CanonicalModelInterface[] $models
     */
    public function bulkPersist(array $models): void;

    /** Returns the canonical model class this adapter accepts. */
    public function getCanonicalModelClass(): string;
}
