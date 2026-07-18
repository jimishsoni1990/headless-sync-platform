<?php

declare(strict_types=1);

namespace HSP\Core\Workers\Strategies;

use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Events\Outbox\Connection\MysqlOutboxConnectionInterface;
use HSP\Core\Events\Outbox\Exception\OutboxWriteException;

/**
 * Relay stage: copies pending outbox rows from wp_hsp_outbox (MySQL) into
 * system.events (PostgreSQL), then marks each row 'relayed' inside the same
 * MySQL transaction that holds the row lock.
 *
 * ADR-054: this is the RELAY STAGE of the WP-Cron Processing Engine cycle — a bounded
 * batch primitive invoked once per cycle by WorkerEngine::runCycle(), not a daemon.
 * tick() relays at most $batchSize rows and returns; a backlog larger than one batch is
 * continued by the next cron cycle (Doc 8 v2.0 §9/§12).
 *
 * Authority:
 *   OPEN-6 v1.3  — relay fidelity; status 'relayed' set only after PG insert succeeds
 *   OPEN-4       — SELECT … FOR UPDATE SKIP LOCKED claim protocol
 *   DECISION 1   — no cross-DB transaction; PG insert committed independently
 *   DECISION E v1.6 — MySQL capture path on MysqlOutboxConnectionInterface;
 *                     PG delivery path on DatabaseConnectionInterface
 *
 * Claim and relay protocol (one MySQL transaction per batch):
 *
 *   BEGIN (MySQL);
 *     SELECT … FOR UPDATE SKIP LOCKED WHERE status='pending' LIMIT N;
 *     -- for each row:
 *       INSERT INTO system.events … ON CONFLICT (id) DO NOTHING  ← PG, committed
 *       UPDATE wp_hsp_outbox SET status='relayed' WHERE id=…      ← MySQL, still in txn
 *   COMMIT (MySQL);
 *
 * The MySQL row lock (acquired by FOR UPDATE) is the claim guard — concurrent
 * cycles skip already-locked rows via SKIP LOCKED and never see the same rows.
 * No intermediate 'relaying' status is needed or used; ENUM('pending','relayed')
 * is the complete set per the frozen OPEN-6 v1.3 DDL.
 *
 * Crash safety: if the process is hard-killed before COMMIT, the MySQL transaction rolls
 * back and rows revert to 'pending'. Any rows already inserted into system.events are
 * re-inserted on the next relay batch and ignored by ON CONFLICT DO NOTHING.
 *
 * Idempotency: system.events INSERT uses ON CONFLICT (id) DO NOTHING.
 */
final class RelayWorkerStrategy
{
    private int $lastRelayedCount = 0;

    public function __construct(
        private readonly MysqlOutboxConnectionInterface $mysqlConn,
        private readonly DatabaseConnectionInterface    $pgsqlConn,
        private readonly string                        $tablePrefix,
        private readonly int                           $batchSize = 100,
    ) {}

    /**
     * Claim a batch of pending outbox rows, relay each to system.events,
     * mark each 'relayed', and commit — all inside one MySQL transaction.
     * Returns true if any row was processed.
     */
    public function tick(): bool
    {
        $outbox = $this->tablePrefix . 'hsp_outbox';

        $this->lastRelayedCount = 0;
        $this->mysqlConn->beginTransaction();

        try {
            $rows = $this->mysqlConn->query(
                "SELECT `id`, `event_type`, `event_version`, `aggregate_type`, `aggregate_id`,
                        `aggregate_version`, `source_updated_at`, `checksum`, `correlation_id`,
                        `causation_id`, `payload`, `created_at`
                 FROM   `{$outbox}`
                 WHERE  `status` = 'pending'
                 ORDER BY `created_at` ASC
                 LIMIT  {$this->batchSize}
                 FOR UPDATE SKIP LOCKED"
            );

            if (empty($rows)) {
                $this->mysqlConn->rollback();
                return false;
            }

            foreach ($rows as $row) {
                // PG insert committed independently — not part of the MySQL txn (DECISION 1).
                $this->insertIntoSystemEvents($row);

                // Mark relayed inside the MySQL txn; committed atomically with all other
                // row updates when COMMIT is reached below.
                $this->markRelayed($row['id'], $outbox);
            }

            $this->mysqlConn->commit();
            $this->lastRelayedCount = count($rows);

        } catch (\Throwable $e) {
            $this->mysqlConn->rollback();
            throw new OutboxWriteException(
                "Relay batch failed: {$e->getMessage()}",
                previous: $e,
            );
        }

        return true;
    }

    /** Number of rows relayed by the most recent tick() (0 if the batch was empty). */
    public function lastRelayedCount(): int
    {
        return $this->lastRelayedCount;
    }

    public function getQueueNames(): array
    {
        return ['relay'];
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Insert one outbox row into system.events on the PostgreSQL connection.
     *
     * OPEN-6 v1.3 relay fidelity:
     *   - id preserved from outbox (event_id; do NOT regenerate)
     *   - created_at preserved from outbox (capture time, not relay time)
     *   - causation_id may be NULL (root events — Doc 8 §19-20)
     *   - ON CONFLICT (id) DO NOTHING — safe for re-relay after crash
     *
     * @param array<string, mixed> $row
     */
    private function insertIntoSystemEvents(array $row): void
    {
        $causationId = ($row['causation_id'] !== '' && $row['causation_id'] !== null)
            ? $row['causation_id']
            : null;

        $this->pgsqlConn->execute(
            "INSERT INTO system.events
                 (id, event_type, event_version, aggregate_type, aggregate_id,
                  aggregate_version, source_updated_at, checksum,
                  correlation_id, causation_id, payload, created_at)
             VALUES ($1::uuid, $2, $3::integer, $4, $5,
                     $6::bigint, $7::timestamptz, $8,
                     $9::uuid, $10::uuid, $11::jsonb, $12::timestamptz)
             ON CONFLICT (id) DO NOTHING",
            [
                $row['id'],
                $row['event_type'],
                (string) $row['event_version'],
                $row['aggregate_type'],
                $row['aggregate_id'],
                (string) $row['aggregate_version'],
                $row['source_updated_at'] . '+00:00',
                $row['checksum'],
                $row['correlation_id'],
                $causationId,
                $row['payload'],
                $row['created_at'] . '+00:00',
            ],
        );
    }

    /**
     * Mark one outbox row as 'relayed' inside the open MySQL transaction.
     */
    private function markRelayed(string $id, string $outbox): void
    {
        $relayedAt = gmdate('Y-m-d H:i:s');

        $this->mysqlConn->execute(
            "UPDATE `{$outbox}`
             SET `status` = 'relayed', `relayed_at` = ?
             WHERE `id` = ?",
            [$relayedAt, $id],
        );
    }
}
