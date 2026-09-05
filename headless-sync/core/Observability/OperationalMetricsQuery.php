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
    /**
     * @param int $freshnessWindowSeconds heartbeat age past which a per-cycle row is no longer
     *                                    "recent" — the ADR-054 §6 freshness window that scopes
     *                                    worker_count. Config-driven (worker.heartbeat
     *                                    .offline_after_seconds), matching the threshold the
     *                                    console's health and worker-status providers use.
     */
    public function __construct(
        private readonly DatabaseConnectionInterface $conn,
        private readonly int $freshnessWindowSeconds = 60,
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
     * Processing-component rows that heartbeated within the freshness window.
     *
     * This is the ADR-054 §6 / Doc 8 v2.0 §32 reinterpretation, verbatim: "the count of distinct
     * processing-component rows that heartbeated within the freshness window (i.e. how many
     * cycles/stages ran recently), NOT a live-daemon population".
     *
     * The window is the whole point and was missing. A bare COUNT(*) over the table answered a
     * daemon-era question — how many workers exist — which under ADR-054 has no meaning: each cycle
     * mints a fresh UUIDv7 (DECISION X (1)), so a row is a cycle EXECUTION and the unfiltered count
     * only ever climbed, reporting "22 workers" on a single-site install that had run 22 cycles.
     * Scoping to the freshness window makes it answer the ratified question instead, and makes it
     * bounded by cadence rather than by uptime. Retention still prunes the table underneath
     * (DECISION X (1) explicitly permits that age sweep), but retention is not a substitute for the
     * window: a day of history would otherwise still be counted as "current".
     */
    public function workerCount(): int
    {
        if ($this->freshnessWindowSeconds <= 0) {
            return 0;
        }

        $rows = $this->conn->query(
            'SELECT COUNT(*) AS c
             FROM   system.worker_heartbeats
             WHERE  last_heartbeat_at >= NOW() - make_interval(secs => $1)',
            [$this->freshnessWindowSeconds],
        );

        return (int) ($rows[0]['c'] ?? 0);
    }
}
