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
 */
final class HeartbeatRecord
{
    public function __construct(
        /** UUIDv7 — OPEN-3 v1.1 canon. */
        public readonly string $workerId,
        /** 'idle' | 'processing' | 'shutdown'. */
        public readonly string $status,
        public readonly \DateTimeImmutable $lastHeartbeatAt,
    ) {}
}
