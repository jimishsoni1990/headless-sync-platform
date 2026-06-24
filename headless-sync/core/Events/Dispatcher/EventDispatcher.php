<?php

declare(strict_types=1);

namespace HSP\Core\Events\Dispatcher;

use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Queue\Providers\Database\DatabaseQueueProvider;

/**
 * Reads undispatched rows from system.events and enqueues them into system.queue_jobs.
 *
 * Claim model (DECISION L v1.12):
 *   SELECT … NOT EXISTS (SELECT 1 FROM system.queue_jobs WHERE event_id = e.id)
 *   FOR UPDATE SKIP LOCKED LIMIT N
 *
 * Dedup (DECISION L v1.12):
 *   DatabaseQueueProvider::enqueueIdempotent() uses ON CONFLICT(event_id) DO NOTHING.
 *   UNIQUE(event_id) on system.queue_jobs (migration 0011) blocks re-dispatch permanently
 *   for completed events (rows retained; status=UPDATE not DELETE).
 *
 * Queue name resolution (Phase 1A):
 *   Hardcoded to 'content' — all Phase 1A events are content-domain events.
 *   Multi-queue routing is not in any frozen doc or the P1A-S6d authority; it is deferred
 *   to a future ADR when a second domain is introduced (DECISION L v1.12).
 *
 * Connection constraints (DECISION E v1.6 / DECISION K v1.11):
 *   - system.events read: DatabaseConnectionInterface (delivery FORCE_NEW handle)
 *   - system.queue_jobs write: DatabaseQueueProvider (queue-claim handle 'queue.connection.pgsql')
 *   No new raw pg_* wrapper introduced.
 *
 * Authority: DECISION L (v1.12, 2026-06-25); Doc 4 §3; CLAUDE.md Rule 7 (constructor injection).
 */
final class EventDispatcher
{
    private const DEFAULT_BATCH_SIZE = 100;

    public function __construct(
        private readonly DatabaseConnectionInterface $conn,
        private readonly DatabaseQueueProvider       $queueProvider,
        private readonly int                         $batchSize = self::DEFAULT_BATCH_SIZE,
    ) {}

    /**
     * Select one batch of undispatched system.events rows and enqueue each.
     *
     * Runs inside a single PG transaction on the delivery connection so the
     * FOR UPDATE SKIP LOCKED row-locks are held until all enqueue() calls
     * complete, preventing concurrent Dispatcher ticks from claiming the same batch.
     *
     * Returns the DispatchBatch that was processed (may be empty if nothing was pending).
     */
    public function dispatchBatch(): DispatchBatch
    {
        $this->conn->beginTransaction();

        try {
            $rows = $this->conn->query(
                "SELECT e.id
                 FROM   system.events e
                 WHERE  NOT EXISTS (
                            SELECT 1
                            FROM   system.queue_jobs q
                            WHERE  q.event_id = e.id
                        )
                 ORDER BY e.created_at ASC
                 LIMIT  $1
                 FOR UPDATE SKIP LOCKED",
                [(string) $this->batchSize],
            );

            if (empty($rows)) {
                $this->conn->rollback();
                return new DispatchBatch([]);
            }

            foreach ($rows as $row) {
                $this->queueProvider->enqueueIdempotent((string) $row['id'], 'content');
            }

            $this->conn->commit();

        } catch (\Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }

        return new DispatchBatch($rows);
    }


}
