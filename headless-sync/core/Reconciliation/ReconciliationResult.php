<?php

declare(strict_types=1);

namespace HSP\Core\Reconciliation;

/**
 * Immutable summary of one reconciliation pass (DECISION U v1.19).
 *
 * Reports what the detector found and what it repaired, so the WP-CLI surface (and the
 * `reconcile` structured-log counter — DECISION Q) can report without re-querying.
 *
 *   $mode        — 'drift' | 'incremental' | 'full'
 *   $scanned     — number of aggregates examined.
 *   $suppressed  — number flagged by comparison but skipped as IN-FLIGHT (D4).
 *   $repaired    — one row per aggregate re-emitted for repair:
 *                  ['aggregate_type','aggregate_id','reason','event_type','event_id',
 *                   'aggregate_version','correlation_id'].
 *                  'reason' ∈ {'missed_capture','checksum_drift','orphan'}.
 *   $dryRun      — true when detection ran but repair was intentionally NOT performed.
 *   $uncovered   — aggregate types a registered source supports but for which NO projection
 *                  descriptor is registered, so they could not be scanned.
 *
 * DECISION AG (AG-2): an uncovered aggregate must surface explicitly rather than let the run
 * claim success — it is the seam that silently dropped media and tags until DECISION AC.
 * It is reported here rather than thrown, deliberately: DECISION AC already rejected a
 * runtime throw as the guard, because a module shipping an aggregate ahead of core would
 * then fatal every cron cycle instead of failing a build. The build-time guard is
 * AggregateCoverageTest; this field is the runtime half that keeps the pass honest.
 */
final class ReconciliationResult
{
    /**
     * @param array<int, array<string, mixed>> $repaired
     * @param list<string>                     $uncovered
     */
    public function __construct(
        public readonly string $mode,
        public readonly int    $scanned,
        public readonly int    $suppressed,
        public readonly array  $repaired,
        public readonly bool   $dryRun = false,
        public readonly array  $uncovered = [],
    ) {}

    public function repairedCount(): int
    {
        return count($this->repaired);
    }

    /**
     * Did every supported aggregate type actually get scanned?
     *
     * A pass with uncovered types converged only over part of the platform, so callers
     * (WP-CLI, onboarding backfill, the console) must not report it as a clean result.
     */
    public function isComplete(): bool
    {
        return $this->uncovered === [];
    }
}
