<?php

declare(strict_types=1);

namespace HSP\Core\Operations\Providers;

use HSP\Core\Contracts\Operations\WorkerStatus;
use HSP\Core\Contracts\Operations\WorkerStatusProviderInterface;
use HSP\Core\Operations\Diagnostics\OperationsQueryReader;

/**
 * Current-state worker status provider (OPSC-S2; Doc 12 §12/§13; DECISION P).
 *
 * Reads the single current-state heartbeat row per worker (DECISION P — upsert per tick, no
 * history) via the delivery-handle reader (DECISION V (g)) and derives "online" at read time
 * as a heartbeat-age comparison against a config-driven threshold (DECISION P crash-detection
 * semantics; no hardcoded timing — mirrors the DECISION R config-driven-cadence precedent).
 *
 * READ-ONLY surface. Worker lifecycle belongs to the process supervisor, NOT the console
 * (DECISION V (f)); this provider carries no lifecycle affordance — it only reports status.
 * ZERO new persistence (DECISION V (c)).
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
