<?php

declare(strict_types=1);

namespace HSP\Core\Contracts;

/**
 * Emits ONE synthetic replay event for a single aggregate (DECISION T).
 *
 * Replay is projection repair by synthetic re-emission: the emitter reads the
 * CURRENT WordPress state for the aggregate (ADR-044/ADR-045 — WordPress wins) and
 * writes a NEW event through wp_hsp_outbox, taking a NEW aggregate_version from
 * wp_hsp_aggregate_counters (DECISION 2). Because the new version is strictly greater
 * than system.aggregate_versions.latest_processed_version, the synthetic event passes
 * the DECISION J Resolve-stage stale guard naturally — the guard is never weakened,
 * bypassed, or made conditional. Historical system.events rows are never mutated or
 * re-enqueued: replay appends, never rewrites (Doc 5 §26).
 *
 * Semantics (DECISION T point 2):
 *   aggregate exists and is public  → emit the OPEN-1 `.updated` type
 *   aggregate missing or non-public → emit the OPEN-1 `.deleted` type (tombstone)
 *
 * No new event-type contracts are introduced — the emitter reuses the existing OPEN-1
 * nine-type set.
 *
 * Module isolation (Rule 5): this contract is core-owned; the implementation lives in
 * the owning module (e.g. HSP\Modules\Content\Replay\ContentReplayEmitter). core/Replay/
 * orchestration depends on this interface only and never imports a module.
 */
interface ReplayEmitterInterface
{
    /**
     * The aggregate types this emitter can replay (e.g. ['page', 'post', 'category']).
     *
     * ReplayService uses this to route each discovered aggregate to a supporting emitter
     * and to reject unknown aggregate types with a clear error.
     *
     * @return string[]
     */
    public function getSupportedAggregateTypes(): array;

    /**
     * Emit one synthetic replay event for the given aggregate.
     *
     * Reads current WordPress state, decides `.updated` vs `.deleted` per DECISION T
     * point 2, and writes the event through the outbox with a fresh counter version.
     * The synthetic event carries the supplied correlation_id (groups the replay run)
     * and causation_id (references the replay operation) for traceability (DECISION T
     * point 4).
     *
     * @param string $aggregateType  e.g. 'page', 'post', 'category'
     * @param string $aggregateId    WordPress post_id or term_id as a string
     * @param string $correlationId  Shared across one replay run (UUID)
     * @param string $causationId    Identifies the replay operation (UUID)
     *
     * @return EventInterface The synthetic event written to the outbox.
     *
     * @throws \InvalidArgumentException if $aggregateType is not supported.
     */
    public function emitForAggregate(
        string $aggregateType,
        string $aggregateId,
        string $correlationId,
        string $causationId,
    ): EventInterface;
}
