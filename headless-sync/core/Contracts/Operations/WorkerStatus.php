<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Operations;

/**
 * Immutable, current-state view of one worker (Doc 12 §12/§13; DECISION P).
 *
 * Derived from the single current-state heartbeat row per worker (DECISION P — upsert per
 * tick, no history). "Offline" is computed at read time as a heartbeat-age comparison
 * (DECISION V (f) — status/heartbeat surfaced read-only; worker lifecycle belongs to the
 * process supervisor, never the console). This DTO carries NO lifecycle affordance.
 *
 * Fields:
 *   $workerId        — UUIDv7 the worker self-assigned at startup.
 *   $workerType      — e.g. 'event', 'maintenance'.
 *   $online          — whether the last heartbeat is within the freshness window at read time.
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
