<?php

declare(strict_types=1);

namespace HSP\Core\Workers;

/**
 * Snapshot of processing-cycle health at a given instant.
 *
 * Published by WorkerEngine per Processing Engine cycle (ADR-054 / Doc 8 v2.0 §15).
 * A heartbeat row represents a PROCESSING-CYCLE EXECUTION, not a daemon identity: each
 * cycle mints a fresh UUIDv7 worker_id (DECISION X, v1.24 — ruling (1)). Consumers read
 * heartbeats to derive processing freshness/progress (a stale row while the queue is
 * non-empty = "cycles are not advancing", not "a daemon crashed" — §15).
 *
 * Authority: Doc 8 v2.0 §15/§16 — heartbeat carries worker_id, status, last_heartbeat_at.
 *   DECISION P (v1.16) — persisted to system.worker_heartbeats (schema unchanged): the
 *   record also carries worker_type and started_at so the current-state upsert can
 *   populate the full frozen DDL. worker_type/startedAt default so older callers and
 *   fakes remain valid.
 *   DECISION X (v1.24 — ruling (2)) — status value set is EXACTLY 'running' | 'idle'
 *   in v1.x ('processing' → 'running'; the daemon-only 'shutdown' state is removed —
 *   a cycle terminates normally at its batch/budget boundary).
 */
final class HeartbeatRecord
{
    public function __construct(
        /** Per-cycle UUIDv7 — DECISION X (v1.24 ruling (1)); OPEN-3 v1.1 type canon. */
        public readonly string $workerId,
        /** 'running' | 'idle' (Doc 8 v2.0 §16; DECISION X ruling (2)). */
        public readonly string $status,
        public readonly \DateTimeImmutable $lastHeartbeatAt,
        /** e.g. 'event' | 'dispatcher' | 'maintenance' | 'relay' — DECISION P. */
        public readonly string $workerType = 'worker',
        /** This cycle's start time — DECISION P / ADR-054 §24. Defaults to now if not supplied. */
        public readonly ?\DateTimeImmutable $startedAt = null,
    ) {}
}
