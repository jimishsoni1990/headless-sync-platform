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
 */
final class ReconciliationResult
{
    /**
     * @param array<int, array<string, mixed>> $repaired
     */
    public function __construct(
        public readonly string $mode,
        public readonly int    $scanned,
        public readonly int    $suppressed,
        public readonly array  $repaired,
        public readonly bool   $dryRun = false,
    ) {}

    public function repairedCount(): int
    {
        return count($this->repaired);
    }
}
