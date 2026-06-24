<?php

declare(strict_types=1);

namespace HSP\Core\Workers\Strategies;

use HSP\Core\Contracts\EventInterface;
use HSP\Core\Contracts\QueueProviderInterface;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Events\EventRegistry;
use HSP\Core\Events\Outbox\OutboxEvent;
use HSP\Core\Workers\WorkerExecutionContext;
use HSP\Core\Workers\WorkerStrategyInterface;

/**
 * Processes domain events from the 'content' queue.
 *
 * Implements the Doc 8 §7 standard execution pipeline:
 *   1. Claim         — claim a job via QueueProviderInterface (OPEN-4 SKIP LOCKED)
 *   2. Load Event    — load full EventInterface from system.events via event_id
 *   3. Create Context — WorkerExecutionContext already supplied by WorkerEngine
 *   4. Validate      — verify the event type is registered in EventRegistry
 *   5. Resolve       — PRIMARY stale-event guard: read system.aggregate_versions;
 *                      if event.aggregate_version <= stored → ack + skip (DECISION J)
 *   6. Execute       — invoke all registered handlers for the event type
 *   7. Commit State  — each handler commits its own PG transaction (DECISION 3)
 *   8. Acknowledge   — complete() on success; release() on transient failure;
 *                      deadLetter() on retry-limit exhaustion (ADR-022)
 *
 * Stale-event guard (DECISION J):
 *   Layer 1 (PRIMARY) — here at Resolve stage: non-locking SELECT on system.aggregate_versions.
 *     If event.aggregate_version <= stored latest_processed_version → terminate cleanly
 *     (ack job, no handler invocation, no writes).
 *   Layer 2 (defense-in-depth) — inside adapter write transaction (FLAG-P1AS4-2 / GREATEST guard).
 *   Both layers are mandatory and non-interchangeable.
 *
 * PG read for Resolve guard uses DatabaseConnectionInterface (shared delivery connection —
 * DECISION E v1.6). Non-locking SELECT (no FOR UPDATE at Resolve time — lock taken only
 * inside the adapter write txn at Layer 2). Does NOT reuse the queue's SKIP LOCKED
 * connection (queue uses 'queue.connection.pgsql' with PGSQL_CONNECT_FORCE_NEW).
 *
 * Authority:
 *   Doc 8 §7         — standard execution pipeline
 *   ADR-044          — stateless; reloads WP state per event (via handlers)
 *   ADR-022          — retry limit default 10; exhaustion → dead-letter
 *   DECISION E v1.6  — DatabaseConnectionInterface; no new raw pg_* wrapper
 *   DECISION J       — Resolve-stage stale guard is PRIMARY; adapter guard is defense-in-depth
 *   CLAUDE.md Rule 7 — constructor injection only
 */
final class EventWorkerStrategy implements WorkerStrategyInterface
{
    private const QUEUE_NAME = 'content';

    public function __construct(
        private readonly QueueProviderInterface      $queue,
        private readonly EventRegistry               $eventRegistry,
        private readonly DatabaseConnectionInterface $db,
        private readonly int                         $retryLimit = 10,
    ) {}

    public function execute(WorkerExecutionContext $context): bool
    {
        $job = $this->queue->claim(self::QUEUE_NAME, $context->workerId);

        if ($job === null) {
            return false;
        }

        $jobId        = (string) $job['id'];
        $attemptCount = (int) $job['attempts'];

        try {
            // Step 2 — Load Event: hydrate full EventInterface from system.events.
            $event = $this->loadEvent((string) ($job['event_id'] ?? ''));

            $eventType = $event->getEventType();

            // Step 4 — Validate: event type must be registered.
            if (! $this->eventRegistry->has($eventType)) {
                throw new \RuntimeException(
                    "Event type '{$eventType}' is not registered in the EventRegistry."
                );
            }

            // Step 5 — Resolve: PRIMARY stale-event guard (DECISION J Layer 1).
            // Non-locking SELECT; fires before any handler or WP-state reload.
            if ($this->isStale($event)) {
                // Stale event: ack the job (no DLQ, no retry), make no writes.
                $this->queue->complete($jobId, $context->workerId);
                return true;
            }

            // Steps 6–7 — Execute handlers → each commits its own PG txn (DECISION 3).
            $this->executeHandler($eventType, $event, $context);

            // Step 8a — Acknowledge: job completed successfully.
            $this->queue->complete($jobId, $context->workerId);

        } catch (\Throwable $e) {
            if ($attemptCount >= $this->retryLimit) {
                $this->queue->deadLetter($jobId, $context->workerId, [
                    'failure_reason'   => $e->getMessage(),
                    'stack_trace'      => $e->getTraceAsString(),
                    'attempt_count'    => $attemptCount,
                    'payload_snapshot' => $job,
                ]);
            } else {
                $backoff = $this->computeBackoffSeconds($attemptCount);
                $this->queue->release($jobId, $context->workerId, $backoff);
            }
        }

        return true;
    }

    public function getQueueNames(): array
    {
        return [self::QUEUE_NAME];
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Load a full EventInterface from system.events by event_id.
     *
     * @throws \RuntimeException if the event row is missing or malformed
     */
    private function loadEvent(string $eventId): EventInterface
    {
        if ($eventId === '') {
            throw new \RuntimeException('Job has no event_id; cannot load event from system.events.');
        }

        $rows = $this->db->query(
            'SELECT id, event_type, event_version, aggregate_type, aggregate_id,
                    aggregate_version, payload, checksum, source_updated_at,
                    created_at, correlation_id, causation_id
             FROM   system.events
             WHERE  id = $1::uuid',
            [$eventId]
        );

        if (empty($rows)) {
            throw new \RuntimeException(
                "Event '{$eventId}' not found in system.events."
            );
        }

        $row = $rows[0];

        $payload = [];
        if (isset($row['payload']) && is_string($row['payload'])) {
            $decoded = json_decode($row['payload'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        return new OutboxEvent(
            id:               (string) $row['id'],
            eventType:        (string) $row['event_type'],
            eventVersion:     (int) $row['event_version'],
            aggregateType:    (string) $row['aggregate_type'],
            aggregateId:      (string) $row['aggregate_id'],
            aggregateVersion: (int) $row['aggregate_version'],
            payload:          $payload,
            checksum:         (string) $row['checksum'],
            sourceUpdatedAt:  new \DateTimeImmutable((string) $row['source_updated_at'], new \DateTimeZone('UTC')),
            createdAt:        new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC')),
            correlationId:    (string) $row['correlation_id'],
            causationId:      isset($row['causation_id']) && $row['causation_id'] !== '' ? (string) $row['causation_id'] : null,
        );
    }

    /**
     * Resolve-stage stale-event guard (DECISION J — Layer 1, PRIMARY).
     *
     * Non-locking SELECT on system.aggregate_versions. Returns true when the event
     * is stale (aggregate_version <= stored latest_processed_version). A missing row
     * means no event for this aggregate has been processed yet — event is not stale.
     */
    private function isStale(EventInterface $event): bool
    {
        $rows = $this->db->query(
            'SELECT latest_processed_version
             FROM   system.aggregate_versions
             WHERE  aggregate_type = $1 AND aggregate_id = $2',
            [$event->getAggregateType(), $event->getAggregateId()]
        );

        if (empty($rows)) {
            return false;
        }

        $stored = (int) $rows[0]['latest_processed_version'];
        return $event->getAggregateVersion() <= $stored;
    }

    /**
     * Invoke all registered handlers for the event type (Steps 6–7).
     *
     * Handlers commit their own PG transaction (DECISION 3). Each handler is a
     * callable registered in EventRegistry — typically a ContentSubscriber instance.
     */
    private function executeHandler(
        string $eventType,
        EventInterface $event,
        WorkerExecutionContext $context,
    ): void {
        foreach ($this->eventRegistry->getHandlers($eventType) as $handler) {
            ($handler)($event);
        }
    }

    /**
     * Exponential back-off with 25% jitter (ADR-022).
     * base=30s, cap=3600s — mirrors DatabaseQueueProvider defaults.
     */
    private function computeBackoffSeconds(int $attempt): int
    {
        $base   = 30;
        $cap    = 3600;
        $raw    = $base * (2 ** max(0, $attempt - 1));
        $capped = min($cap, $raw);
        $jitter = random_int(0, (int) ($capped * 0.25));

        return $capped + $jitter;
    }
}
