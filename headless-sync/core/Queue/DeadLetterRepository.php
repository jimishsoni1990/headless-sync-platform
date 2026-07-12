<?php

declare(strict_types=1);

namespace HSP\Core\Queue;

use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Queue\Exception\DeadLetterReplayException;

/**
 * Read + replay surface for system.dead_letter_jobs (DECISION S, v1.16).
 *
 * Backs the WP-CLI `hsp dlq list | inspect | replay` commands. There is no admin UI
 * in OPS-S1 (DECISION S clause (d)) — this repository is the single operational
 * entry point.
 *
 * Replay lifecycle (DECISION S clause (b)) — ONE PostgreSQL transaction, in order:
 *   1. Verify the DLQ row exists.
 *   2. Verify it has not already been replayed (`replayed_at IS NULL`).
 *   3. DELETE any system.queue_jobs row sharing the same event_id. Mandatory:
 *      DECISION L (d) retains completed/dead_lettered rows and UNIQUE(event_id) means
 *      a naive re-enqueue would ON CONFLICT DO NOTHING (a silent no-op). Clearing the
 *      prior row first is what makes the fresh insert take effect.
 *   4. INSERT a fresh system.queue_jobs job for the event with attempts reset to 0.
 *   5. Stamp replayed_at on the DLQ row (the row is NEVER deleted — permanent audit,
 *      clause (a)).
 *
 * The re-enqueued event re-enters the pipeline through the normal queue/claim path.
 * If the aggregate is already at or beyond the event's version, the DECISION J
 * Resolve-stage guard acks the job with ZERO projection writes — correct behavior,
 * not an error (clause (c)). Replay never writes projections directly.
 *
 * Connection: the runtime queue/worker handle (constructor-injected, ADR-012). This is
 * system-side DML on the same tables the queue provider owns (DECISION L Ruling 0 — no
 * new handle).
 */
final class DeadLetterRepository
{
    /** Phase 1A queue partition — all MVP events are content-domain (DECISION L clause (e)). */
    private const DEFAULT_QUEUE_NAME = 'content';

    public function __construct(
        private readonly DatabaseConnectionInterface $conn,
    ) {}

    /**
     * List DLQ rows, newest first.
     *
     * @return array<int, array<string,mixed>>
     */
    public function list(int $limit = 50): array
    {
        $limit = max(1, $limit);

        return $this->conn->query(
            "SELECT id, job_id, event_id, failure_reason, attempt_count, worker_id,
                    created_at, replayed_at
             FROM   system.dead_letter_jobs
             ORDER BY created_at DESC
             LIMIT  $1",
            [(string) $limit],
        );
    }

    /**
     * Fetch a single DLQ row by its id (full detail incl. stack_trace + payload_snapshot).
     *
     * @return array<string,mixed>|null null if not found.
     */
    public function inspect(string $dlqId): ?array
    {
        $rows = $this->conn->query(
            "SELECT id, job_id, event_id, failure_reason, stack_trace, attempt_count,
                    worker_id, payload_snapshot, created_at, replayed_at
             FROM   system.dead_letter_jobs
             WHERE  id = $1::uuid",
            [$dlqId],
        );

        return $rows[0] ?? null;
    }

    /**
     * Replay a dead-lettered event — the single-transaction lifecycle (DECISION S (b)).
     *
     * @return string The event_id that was re-enqueued.
     *
     * @throws DeadLetterReplayException if the DLQ row is missing or already replayed,
     *                                   or the transaction fails.
     */
    public function replay(string $dlqId): string
    {
        $this->conn->beginTransaction();

        try {
            // Step 1 — verify the DLQ row exists (lock it so a concurrent replay of the
            // same row serializes and the second sees replayed_at set).
            $rows = $this->conn->query(
                "SELECT id, event_id, replayed_at
                 FROM   system.dead_letter_jobs
                 WHERE  id = $1::uuid
                 FOR UPDATE",
                [$dlqId],
            );

            if (empty($rows)) {
                $this->conn->rollback();
                throw new DeadLetterReplayException("DLQ row '{$dlqId}' does not exist.");
            }

            $dlqRow  = $rows[0];
            $eventId = (string) $dlqRow['event_id'];

            // Step 2 — verify it has not already been replayed (double-replay guard).
            if (($dlqRow['replayed_at'] ?? null) !== null) {
                $this->conn->rollback();
                throw new DeadLetterReplayException(
                    "DLQ row '{$dlqId}' was already replayed at {$dlqRow['replayed_at']}."
                );
            }

            // Determine the queue partition from the prior queue_jobs row (if present),
            // else default to the Phase 1A 'content' partition (DECISION L clause (e)).
            $queueName = $this->resolveQueueName($eventId);

            // Step 3 — DELETE any system.queue_jobs row sharing this event_id. This defeats
            // the UNIQUE(event_id) silent-no-op trap (DECISION S (b) step 3 / DECISION L (d)).
            $this->conn->execute(
                "DELETE FROM system.queue_jobs WHERE event_id = $1::uuid",
                [$eventId],
            );

            // Step 4 — INSERT a fresh job with attempts reset to 0.
            $this->conn->execute(
                "INSERT INTO system.queue_jobs
                     (id, event_id, queue_name, status, attempts, available_at)
                 VALUES ($1::uuid, $2::uuid, $3, 'available', 0, NOW())",
                [$this->uuidv7(), $eventId, $queueName],
            );

            // Step 5 — stamp replayed_at on the DLQ row (never deleted — permanent audit).
            $this->conn->execute(
                "UPDATE system.dead_letter_jobs
                 SET    replayed_at = NOW()
                 WHERE  id = $1::uuid",
                [$dlqId],
            );

            $this->conn->commit();

        } catch (DeadLetterReplayException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->conn->rollback();
            throw new DeadLetterReplayException(
                "DLQ replay of '{$dlqId}' failed: " . $e->getMessage(),
                previous: $e,
            );
        }

        return $eventId;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Read the queue partition of the prior queue_jobs row for this event, if any.
     * Falls back to the Phase 1A default partition when no prior row exists.
     */
    private function resolveQueueName(string $eventId): string
    {
        $rows = $this->conn->query(
            "SELECT queue_name FROM system.queue_jobs WHERE event_id = $1::uuid LIMIT 1",
            [$eventId],
        );

        $name = $rows[0]['queue_name'] ?? null;

        return is_string($name) && $name !== '' ? $name : self::DEFAULT_QUEUE_NAME;
    }

    /**
     * Generate a UUIDv7 for the fresh queue job (ADR-015, v1.1 canon).
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
