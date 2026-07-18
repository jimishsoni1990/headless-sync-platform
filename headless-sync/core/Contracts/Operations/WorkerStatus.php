<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Operations;

/**
 * Immutable, current-state view of one processing cycle (Doc 12 §12/§13; DECISION P; ADR-054 §5).
 *
 * Derived from a current-state heartbeat row (DECISION P — upsert per cycle, no history). Under
 * the ADR-054 WP-Cron cycle model a row is a processing-cycle execution with a fresh per-cycle
 * UUIDv7 (DECISION X ruling (1)), not a daemon identity. `$online` is CYCLE-FRESHNESS computed
 * at read time as a heartbeat-age comparison — "this cycle ran within the freshness window"
 * (advancing) vs "cycles have gone stale". The console is read-only (DECISION V (f)); this DTO
 * carries NO lifecycle affordance — there is no supervised worker to control.
 *
 * Fields:
 *   $workerId        — per-cycle UUIDv7 (fresh each cycle — DECISION X ruling (1)).
 *   $workerType      — processing stage, e.g. 'processing', 'event', 'maintenance'.
 *   $online          — whether the last heartbeat is within the freshness window (cycle is fresh).
 *   $lastHeartbeatAt — timestamp of the most recent heartbeat, or null if never seen.
 *
 * @psalm-immutable
 */
final class WorkerStatus
{
    public function __construct(
        public readonly string $workerId,
        public readonly string $workerType,
        public readonly bool $online,
        public readonly ?\DateTimeImmutable $lastHeartbeatAt,
    ) {}
}
