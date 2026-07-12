<?php

declare(strict_types=1);

namespace HSP\Core\Workers;

/**
 * Snapshot of worker health at a given instant.
 *
 * Published by WorkerEngine after each tick. Consumers (OPS-S1 monitoring)
 * read heartbeats to detect stale workers and trigger crash recovery.
 *
 * Authority: Doc 8 §15 — heartbeat must carry worker_id, status, last_heartbeat_at.
 *   DECISION P (v1.16) — persisted to system.worker_heartbeats: the record also
 *   carries worker_type and started_at so the current-state upsert can populate
 *   the full frozen DDL. worker_type/startedAt default so pre-OPS-S1 callers and
 *   fakes remain valid.
 */
final class HeartbeatRecord
{
    public function __construct(
        /** UUIDv7 — OPEN-3 v1.1 canon. */
        public readonly string $workerId,
        /** 'idle' | 'processing' | 'shutdown'. */
        public readonly string $status,
        public readonly \DateTimeImmutable $lastHeartbeatAt,
        /** e.g. 'event' | 'dispatcher' | 'maintenance' | 'relay' — DECISION P. */
        public readonly string $workerType = 'worker',
        /** Worker process start time — DECISION P. Defaults to now if not supplied. */
        public readonly ?\DateTimeImmutable $startedAt = null,
    ) {}
}
