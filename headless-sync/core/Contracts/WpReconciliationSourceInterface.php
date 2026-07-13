<?php

declare(strict_types=1);

namespace HSP\Core\Contracts;

/**
 * Detection-side WordPress reads for reconciliation (DECISION U v1.19).
 *
 * Reconciliation compares WordPress (source of truth) against the content.* delivery
 * projections and repairs drift by re-emission through the DECISION T primitive
 * (ReplayService::replayEntity). It never writes WordPress and never writes PG
 * projections directly — this contract is READ-ONLY on the WordPress side.
 *
 * All WordPress access for reconciliation is confined behind this core-owned contract;
 * the implementation lives in the owning module (HSP\Modules\Content\Reconciliation\
 * WpReconciliationSource). core/Reconciliation/ orchestration depends on this interface
 * only and never imports a module (Rule 5). Symmetric with ReplayEmitterInterface.
 *
 * Signals provided:
 *   - paged aggregate-id enumeration (full/window sweeps)
 *   - per-aggregate source state: existence, public-set membership (OPEN-10 {publish}),
 *     and source-modified timestamp (WP post_modified_gmt) — null for taxonomies (terms
 *     carry no modified timestamp; see DECISION U D2)
 *   - the freshly-computed canonical projection checksum for checksum modes
 *   - the pending-outbox suppression signal (a captured-but-unrelayed wp_hsp_outbox row —
 *     DECISION U D4 clause 1); the events-side suppression signal is read by
 *     ReconciliationService on the delivery handle.
 */
interface WpReconciliationSourceInterface
{
    /**
     * The aggregate types this source can reconcile (e.g. ['page', 'post', 'category']).
     *
     * @return string[]
     */
    public function getSupportedAggregateTypes(): array;

    /**
     * Paged enumeration of current WordPress aggregate IDs of the given type, ordered by
     * ascending ID, returning up to $limit IDs with ID strictly greater than $afterId.
     *
     * Used by full/window sweeps to page the corpus without loading it all at once. An
     * empty result signals the end of the corpus. IDs are returned as strings (they are
     * WP post_id / term_id values used as aggregate_id).
     *
     * @param string $aggregateType 'page' | 'post' | 'category'
     * @param int    $afterId       Exclusive lower bound (0 for the first page).
     * @param int    $limit         Page size (config-driven at the caller).
     * @return string[]             Ascending aggregate IDs; [] at end of corpus.
     */
    public function listAggregateIds(string $aggregateType, int $afterId, int $limit): array;

    /**
     * Current WordPress state for one aggregate.
     *
     * @param string $aggregateType 'page' | 'post' | 'category'
     * @param string $aggregateId   WP post_id / term_id as a string.
     * @return SourceState
     */
    public function getSourceState(string $aggregateType, string $aggregateId): SourceState;

    /**
     * Freshly-computed canonical checksum of the CURRENT WordPress state for the aggregate
     * (the same sha256 canonical checksum the projection stores under OPEN-11 Option A).
     *
     * Returns null when the aggregate does not currently exist / is not public (there is no
     * canonical model to checksum — a non-public aggregate is drift only if the projection
     * still shows it live, which is the orphan path, not the checksum path).
     *
     * Used by incremental/full modes to detect silent field drift the timestamp missed
     * (DECISION U D1) — and is the only staleness signal for taxonomies (D2).
     *
     * @return string|null 64-char sha256 hex, or null if not currently public.
     */
    public function computeCurrentChecksum(string $aggregateType, string $aggregateId): ?string;

    /**
     * True iff a pending (unrelayed) wp_hsp_outbox row currently exists for this aggregate.
     *
     * This is the MySQL-side suppression signal (DECISION U D4 clause 1): a capture that has
     * not yet been relayed means the pipeline will project the change — it is IN-FLIGHT, not
     * drift. Confined here so ReconciliationService takes no MySQL dependency.
     */
    public function hasPendingOutbox(string $aggregateType, string $aggregateId): bool;
}
