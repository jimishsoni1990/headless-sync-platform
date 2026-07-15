<?php

declare(strict_types=1);

namespace HSP\Core\Operations\Diagnostics;

use HSP\Core\Database\DatabaseConnectionInterface;

/**
 * Read-only current-state reader for the Operations Console core providers (OPSC-S2).
 *
 * The single place OPSC-S2 core providers touch PostgreSQL. Every method is a SELECT over
 * the EXISTING frozen tables — it computes point-in-time state at read time and writes
 * NOTHING. There is no INSERT/UPDATE/DELETE anywhere in this class (providers are read-only;
 * DECISION V (c)/(g)).
 *
 * Authority:
 *   DECISION V (g) / DECISION K — reads run on the delivery DatabaseConnectionInterface
 *     handle injected here. This opens NO fifth handle (DECISION L Ruling 0 topology frozen
 *     at four) and introduces NO new raw pg_* wrapper (DECISION E) — it reuses the existing
 *     connection abstraction.
 *   DECISION Q / DECISION V (c) — derived-on-demand, ZERO new persistence. No metrics table,
 *     no rollups, no history; every value is computed by aggregate query at call time.
 *   DECISION P — worker rows come from the single current-state system.worker_heartbeats
 *     table (upsert per tick, no history); "offline" is a last_heartbeat_at-age comparison
 *     computed by the WorkerStatusProvider, not stored.
 *   OPEN-8 — System Information reads system.module_versions / system.schema_versions.
 *   ADR-012 — connection injected via constructor; no service-locator call.
 *
 * This reader deliberately overlaps the existing core/Observability/OperationalMetricsQuery
 * (the OPS-S1 CLI/metrics reader): that class serves the WP-CLI/structured-log surface on
 * the worker-runtime handle; this one serves the wp-admin console providers on the delivery
 * handle. They share query shapes but not a connection (DECISION K isolation), so they are
 * intentionally separate readers rather than a shared instance.
 */
final class OperationsQueryReader
{
    public function __construct(
        private readonly DatabaseConnectionInterface $conn,
    ) {}

    // -------------------------------------------------------------------------
    // Queue (system.queue_jobs) — current-state depths + oldest-pending age
    // -------------------------------------------------------------------------

    /** Total available (claimable) jobs across all partitions, at read time. */
    public function queueDepth(): int
    {
        $rows = $this->conn->query(
            "SELECT COUNT(*) AS c FROM system.queue_jobs WHERE status = 'available'"
        );

        return (int) ($rows[0]['c'] ?? 0);
    }

    /** Count of dead-lettered rows. DLQ rows are permanent (DECISION S). */
    public function deadLetterDepth(): int
    {
        $rows = $this->conn->query(
            'SELECT COUNT(*) AS c FROM system.dead_letter_jobs'
        );

        return (int) ($rows[0]['c'] ?? 0);
    }

    /**
     * Age in seconds of the oldest available (claimable) job, or null when the queue holds
     * no available job. Computed against the DB clock (NOW()) so it is independent of PHP
     * process time.
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

    // -------------------------------------------------------------------------
    // Workers (system.worker_heartbeats) — current-state rows (DECISION P)
    // -------------------------------------------------------------------------

    /**
     * Every current-state heartbeat row with the DB-computed age of its last heartbeat.
     *
     * "Offline" is NOT decided here — the age is returned and the WorkerStatusProvider
     * compares it against the config threshold. Age is computed with the DB clock so it is
     * consistent with oldestPendingAgeSeconds() and immune to PHP/DB clock skew.
     *
     * @return array<int, array{
     *     worker_id:string,
     *     worker_type:string,
     *     status:string,
     *     last_heartbeat_at:string,
     *     age_seconds:float
     * }>
     */
    public function workerHeartbeats(): array
    {
        $rows = $this->conn->query(
            'SELECT worker_id,
                    worker_type,
                    status,
                    last_heartbeat_at,
                    EXTRACT(EPOCH FROM (NOW() - last_heartbeat_at)) AS age_seconds
             FROM   system.worker_heartbeats
             ORDER BY worker_type, worker_id'
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'worker_id'         => (string) $row['worker_id'],
                'worker_type'       => (string) $row['worker_type'],
                'status'            => (string) $row['status'],
                'last_heartbeat_at' => (string) $row['last_heartbeat_at'],
                'age_seconds'       => (float) $row['age_seconds'],
            ];
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Derived point-in-time status (DECISION V (c)) — processing / replay / reconcile
    // -------------------------------------------------------------------------

    /**
     * Jobs completed within the trailing window, expressed as a per-minute rate.
     *
     * Point-in-time derivation over system.queue_jobs.completed_at — no stored counter, no
     * time-series (DECISION V (c)). Returns 0.0 when the window is empty. The window length
     * is caller-supplied so nothing about the horizon is hardcoded in the reader.
     */
    public function processingRatePerMinute(int $windowSeconds): float
    {
        if ($windowSeconds <= 0) {
            return 0.0;
        }

        $rows = $this->conn->query(
            "SELECT COUNT(*) AS c
             FROM   system.queue_jobs
             WHERE  status = 'completed'
               AND  completed_at >= NOW() - make_interval(secs => $1)",
            [$windowSeconds],
        );

        $completed = (int) ($rows[0]['c'] ?? 0);

        return $completed / ($windowSeconds / 60.0);
    }

    /**
     * Count of DLQ rows that have been replayed (replayed_at IS NOT NULL) — the point-in-time
     * "replay status" surface (DECISION V (c)). Derived from the existing replayed_at column
     * (DECISION S); no replay-history table exists or is introduced.
     */
    public function replayedCount(): int
    {
        $rows = $this->conn->query(
            'SELECT COUNT(*) AS c FROM system.dead_letter_jobs WHERE replayed_at IS NOT NULL'
        );

        return (int) ($rows[0]['c'] ?? 0);
    }

    /** DLQ rows not yet replayed (replayed_at IS NULL) — outstanding failures at read time. */
    public function pendingReplayCount(): int
    {
        $rows = $this->conn->query(
            'SELECT COUNT(*) AS c FROM system.dead_letter_jobs WHERE replayed_at IS NULL'
        );

        return (int) ($rows[0]['c'] ?? 0);
    }

    /**
     * Number of aggregates whose latest event is captured/relayed but not yet projected —
     * i.e. system.events rows with aggregate_version ahead of the processed watermark. This
     * is the point-in-time "reconciliation backlog / in-flight" signal (DECISION V (c);
     * mirrors the DECISION U D4 in-flight definition, clause 2). Derived on demand; no
     * reconciliation-run table exists or is introduced.
     */
    public function unprocessedAggregateCount(): int
    {
        $rows = $this->conn->query(
            'SELECT COUNT(*) AS c
             FROM (
                 SELECT e.aggregate_type, e.aggregate_id
                 FROM   system.events e
                 LEFT JOIN system.aggregate_versions av
                        ON av.aggregate_type = e.aggregate_type
                       AND av.aggregate_id   = e.aggregate_id
                 GROUP BY e.aggregate_type, e.aggregate_id
                 HAVING MAX(e.aggregate_version) > COALESCE(MAX(av.latest_processed_version), 0)
             ) AS behind'
        );

        return (int) ($rows[0]['c'] ?? 0);
    }

    // -------------------------------------------------------------------------
    // System Information (OPEN-8) — module + schema version reads
    // -------------------------------------------------------------------------

    /**
     * Current schema version per module (most recent applied_at wins), from
     * system.module_versions (OPEN-8). Empty when nothing has been recorded.
     *
     * @return array<string,string> module_name → schema_version
     */
    public function moduleVersions(): array
    {
        $rows = $this->conn->query(
            'SELECT DISTINCT ON (module_name) module_name, schema_version
             FROM   system.module_versions
             ORDER BY module_name, applied_at DESC'
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['module_name']] = (string) $row['schema_version'];
        }

        return $out;
    }

    /**
     * Number of currently-applied migrations (rolled_back_at IS NULL) and the most recent
     * migration name, from system.schema_versions (OPEN-8). "migration_version" for System
     * Information is the latest applied migration_name; null when none applied.
     *
     * @return array{applied_count:int, latest:?string}
     */
    public function migrationState(): array
    {
        $count = $this->conn->query(
            'SELECT COUNT(*) AS c FROM system.schema_versions WHERE rolled_back_at IS NULL'
        );

        $latest = $this->conn->query(
            'SELECT migration_name
             FROM   system.schema_versions
             WHERE  rolled_back_at IS NULL
             ORDER BY applied_at DESC
             LIMIT 1'
        );

        return [
            'applied_count' => (int) ($count[0]['c'] ?? 0),
            'latest'        => isset($latest[0]['migration_name'])
                ? (string) $latest[0]['migration_name']
                : null,
        ];
    }

    /** PostgreSQL server version string (e.g. '16.3'), for System Information display. */
    public function postgresVersion(): string
    {
        $rows = $this->conn->query('SHOW server_version');

        return (string) ($rows[0]['server_version'] ?? 'unknown');
    }
}
