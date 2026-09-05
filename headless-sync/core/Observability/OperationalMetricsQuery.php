<?php

declare(strict_types=1);

namespace HSP\Core\Observability;

use HSP\Core\Database\DatabaseConnectionInterface;

/**
 * Derived operational metrics — computed on demand from PostgreSQL (DECISION Q).
 *
 * DECISION Q (v1.16): MVP introduces NO metrics table, NO rollups, NO external
 * telemetry backend. Operational metrics are served two ways:
 *   1. Derived metrics — computed on demand from PostgreSQL (this class).
 *   2. Runtime counters — emitted as structured worker log events (WorkerCounters).
 *
 * This class computes the derived set by aggregate query at read time over the
 * existing frozen tables. It adds no columns and persists no counters.
 *
 * Derived set (DECISION Q clause 1):
 *   - queue depth (available jobs), per partition and total
 *   - DLQ depth (dead-lettered rows)
 *   - oldest-pending age (seconds since the oldest available job became available)
 *   - worker count (rows in system.worker_heartbeats)
 *
 * ADR-012: connection injected via constructor. Read-only; no transaction needed.
 */
final class OperationalMetricsQuery
{
    public function __construct(
        private readonly DatabaseConnectionInterface $conn,
    ) {}

    /**
     * Compute the full derived-metric snapshot in one call.
     *
     * @return array{
     *     queue_depth_total:int,
     *     queue_depth_by_partition:array<string,int>,
     *     dlq_depth:int,
     *     oldest_pending_age_seconds:?float,
     *     worker_count:int
     * }
     */
    public function snapshot(): array
    {
        return [
            'queue_depth_total'          => $this->queueDepthTotal(),
            'queue_depth_by_partition'   => $this->queueDepthByPartition(),
            'dlq_depth'                  => $this->dlqDepth(),
            'oldest_pending_age_seconds' => $this->oldestPendingAgeSeconds(),
            'worker_count'               => $this->workerCount(),
        ];
    }

    /** Total available (claimable) jobs across all partitions. */
    public function queueDepthTotal(): int
    {
        $rows = $this->conn->query(
            "SELECT COUNT(*) AS c FROM system.queue_jobs WHERE status = 'available'"
        );

        return (int) ($rows[0]['c'] ?? 0);
    }

    /**
     * Available job counts keyed by queue partition.
     *
     * @return array<string,int>
     */
    public function queueDepthByPartition(): array
    {
        $rows = $this->conn->query(
            "SELECT queue_name, COUNT(*) AS c
             FROM   system.queue_jobs
             WHERE  status = 'available'
             GROUP BY queue_name"
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['queue_name']] = (int) $row['c'];
        }

        return $out;
    }

    /** Count of dead-lettered rows. DLQ rows are permanent (DECISION S). */
    public function dlqDepth(): int
    {
        $rows = $this->conn->query(
            'SELECT COUNT(*) AS c FROM system.dead_letter_jobs'
        );

        return (int) ($rows[0]['c'] ?? 0);
    }

    /**
     * Age in seconds of the oldest available (claimable) job.
     * Returns null when the queue is empty.
     */
    public function oldestPendingAgeSeconds(): ?float
    {
        $rows = $this->conn->query(
            "SELECT EXTRACT(EPOCH FROM (NOW() - MIN(available_at))) AS age
             FROM   system.queue_jobs
             WHERE  status = 'available'"
        );

        $age = $rows[0]['age'] ?? null;

        return $age === null ? null : (float) $age;
    }

    /**
     * Number of heartbeat rows currently recorded.
     *
     * NOTE (flagged, deliberately unchanged): under ADR-054 each cycle mints a fresh UUIDv7
     * (DECISION X (1)), so a row is a cycle EXECUTION and this is a count of recent cycles, not of
     * workers. The unbounded-growth half of that problem is fixed — MaintenanceWorkerStrategy now
     * prunes rows past the retention window — but the NAME still reads as a daemon census.
     * Renaming it (or switching to distinct worker_type) changes a value asserted by the ratified
     * OperabilityValidationTest §4 criterion, so it needs a ruling rather than a quiet edit.
     * The Operations console does not use this method: MetricsProvider derives its own
     * live-stage count (see the comment there).
     */
    public function workerCount(): int
    {
        $rows = $this->conn->query(
            'SELECT COUNT(*) AS c FROM system.worker_heartbeats'
        );

        return (int) ($rows[0]['c'] ?? 0);
    }
}
