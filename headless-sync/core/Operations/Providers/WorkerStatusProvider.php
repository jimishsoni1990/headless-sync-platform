<?php

declare(strict_types=1);

namespace HSP\Core\Operations\Providers;

use HSP\Core\Contracts\Operations\WorkerStatus;
use HSP\Core\Contracts\Operations\WorkerStatusProviderInterface;
use HSP\Core\Operations\Diagnostics\OperationsQueryReader;

/**
 * Current-state processing-cycle status provider (OPSC-S2; Doc 12 §12/§13; DECISION P; ADR-054 §5).
 *
 * Reads the current-state heartbeat rows (DECISION P — upsert per cycle, no history) via the
 * delivery-handle reader (DECISION V (g)) and derives CYCLE-FRESHNESS at read time as a
 * heartbeat-age comparison against a config-driven threshold (no hardcoded timing — mirrors the
 * DECISION R config-driven-cadence precedent). Under the ADR-054 WP-Cron cycle model each row is
 * a processing-cycle execution (fresh UUIDv7 per cycle — DECISION X ruling (1)), so `online`
 * means "this cycle ran within the freshness window" (advancing), not "a daemon is up".
 *
 * READ-ONLY surface. There is no supervised worker to control: the engine is a bounded WP-Cron
 * cycle (ADR-054). When cycles are not advancing, remediation is WP-Cron ("ensure WP-Cron is
 * firing / run `wp cron event run`"), NEVER a Restart Workers action (DECISION V (f) — the
 * console remains read-only). ZERO new persistence (DECISION V (c)).
 */
final class WorkerStatusProvider implements WorkerStatusProviderInterface
{
    public const KEY = 'workers';

    public function __construct(
        private readonly OperationsQueryReader $reader,
        private readonly int $offlineAfterSeconds,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    /** @return WorkerStatus[] */
    public function statuses(): array
    {
        $out = [];

        foreach ($this->reader->workerHeartbeats() as $row) {
            $out[] = new WorkerStatus(
                workerId: $row['worker_id'],
                workerType: $row['worker_type'],
                online: $row['age_seconds'] <= $this->offlineAfterSeconds,
                lastHeartbeatAt: $this->parseTimestamp($row['last_heartbeat_at']),
            );
        }

        return $out;
    }

    /**
     * Parse a PostgreSQL TIMESTAMPTZ text value into an immutable UTC-normalised datetime,
     * or null if it cannot be parsed. The DB emits an offset-bearing value, so the instant
     * is preserved regardless of server/display zone.
     */
    private function parseTimestamp(string $value): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
