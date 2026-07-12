<?php

declare(strict_types=1);

namespace HSP\Core\Workers;

use HSP\Core\Database\DatabaseConnectionInterface;

/**
 * Database-backed heartbeat publisher — upserts system.worker_heartbeats per tick.
 *
 * Authority:
 *   DECISION P (v1.16) — single current-state table, one row per worker, upserted
 *     per tick; no history. Publisher implements the existing HeartbeatPublisherInterface
 *     (replaces NullHeartbeatPublisher on the runtime path).
 *   DECISION L Ruling 0 (v1.16) — heartbeat publication is worker-runtime infrastructure;
 *     it rides the EXISTING worker-runtime PostgreSQL connection (handle 2:
 *     'queue.connection.pgsql'). It introduces NO new connection, NO new connection
 *     class, and NO new raw pg_* wrapper.
 *   ADR-012 — connection injected via constructor; no service-locator call.
 *   OPEN-3 v1.1 canon — worker_id UUID; all timestamps TIMESTAMPTZ.
 *
 * The upsert (INSERT … ON CONFLICT (worker_id) DO UPDATE) advances the current-state
 * row every tick. A monitor detects a crashed worker by last_heartbeat_at age (Doc 8 §15).
 */
final class DatabaseHeartbeatPublisher implements HeartbeatPublisherInterface
{
    public function __construct(
        private readonly DatabaseConnectionInterface $conn,
    ) {}

    /** Microsecond-precision ISO-8601 so per-tick advances are visible in TIMESTAMPTZ. */
    private const TS_FORMAT = 'Y-m-d\TH:i:s.uP';

    public function publish(HeartbeatRecord $record): void
    {
        $startedAt = ($record->startedAt ?? $record->lastHeartbeatAt)
            ->format(self::TS_FORMAT);

        $this->conn->execute(
            "INSERT INTO system.worker_heartbeats
                 (worker_id, worker_type, status, last_heartbeat_at, started_at)
             VALUES ($1::uuid, $2, $3, $4::timestamptz, $5::timestamptz)
             ON CONFLICT (worker_id) DO UPDATE
             SET status            = EXCLUDED.status,
                 last_heartbeat_at  = EXCLUDED.last_heartbeat_at,
                 worker_type        = EXCLUDED.worker_type",
            [
                $record->workerId,
                $record->workerType,
                $record->status,
                $record->lastHeartbeatAt->format(self::TS_FORMAT),
                $startedAt,
            ],
        );
    }
}
