<?php

declare(strict_types=1);

namespace HSP\Core\Workers\Strategies;

use HSP\Core\Contracts\WorkerInterface;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Events\Outbox\Connection\MysqlOutboxConnectionInterface;
use HSP\Core\Events\Outbox\Exception\OutboxWriteException;

/**
 * Relay worker: copies pending outbox rows from wp_hsp_outbox (MySQL) into
 * system.events (PostgreSQL), then marks each row 'relayed' inside the same
 * MySQL transaction that holds the row lock.
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
 * workers skip already-locked rows via SKIP LOCKED and never see the same rows.
 * No intermediate 'relaying' status is needed or used; ENUM('pending','relayed')
 * is the complete set per the frozen OPEN-6 v1.3 DDL.
 *
 * Crash safety: if the process dies before COMMIT, the MySQL transaction rolls back
 * and rows revert to 'pending'. Any rows already inserted into system.events are
 * re-inserted on the next relay attempt and ignored by ON CONFLICT DO NOTHING.
 *
 * Idempotency: system.events INSERT uses ON CONFLICT (id) DO NOTHING.
 */
final class RelayWorkerStrategy implements WorkerInterface
{
    private bool   $running  = false;
    private string $workerId;

    public function __construct(
        private readonly MysqlOutboxConnectionInterface $mysqlConn,
        private readonly DatabaseConnectionInterface    $pgsqlConn,
        private readonly string                        $tablePrefix,
        private readonly int                           $batchSize = 100,
    ) {
        $this->workerId = $this->uuidv7();
    }

    /**
     * Claim a batch of pending outbox rows, relay each to system.events,
     * mark each 'relayed', and commit — all inside one MySQL transaction.
     * Returns true if any row was processed.
     */
    public function tick(): bool
    {
        $outbox = $this->tablePrefix . 'hsp_outbox';

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

        } catch (\Throwable $e) {
            $this->mysqlConn->rollback();
            throw new OutboxWriteException(
                "Relay tick failed: {$e->getMessage()}",
                previous: $e,
            );
        }

        return true;
    }

    /** Block and tick until shutdown() is called. */
    public function run(): void
    {
        $this->running = true;

        while ($this->running) {
            if (! $this->tick()) {
                usleep(200_000); // 200 ms idle pause
            }
        }
    }

    public function shutdown(): void
    {
        $this->running = false;
    }

    public function getWorkerId(): string
    {
        return $this->workerId;
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

    /**
     * Generate a UUIDv7 for worker identity (ADR-015, v1.1 canon).
     */
    private function uuidv7(): string
    {
        $ms    = (int) (microtime(true) * 1000);
        $bytes = random_bytes(10);

        $tsHex   = sprintf('%012x', $ms);
        $rand12  = (ord($bytes[0]) & 0x0f) << 8 | ord($bytes[1]);
        $b67hex  = sprintf('%04x', 0x7000 | $rand12);
        $rand14  = (ord($bytes[2]) & 0x3f) << 8 | ord($bytes[3]);
        $b89hex  = sprintf('%04x', 0x8000 | $rand14);
        $tailHex = bin2hex(substr($bytes, 4, 6));

        $hex = $tsHex . $b67hex . $b89hex . $tailHex;

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hex, 4));
    }
}
