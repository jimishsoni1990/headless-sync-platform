<?php

declare(strict_types=1);

namespace HSP\Core\Workers\Strategies;

use HSP\Core\Contracts\QueueProviderInterface;
use HSP\Core\Events\EventRegistry;
use HSP\Core\Workers\WorkerExecutionContext;
use HSP\Core\Workers\WorkerStrategyInterface;

/**
 * Processes domain events from the 'content' queue.
 *
 * Implements the Doc 8 §7 standard execution pipeline:
 *   1. Claim         — claim a job via QueueProviderInterface (OPEN-4 SKIP LOCKED)
 *   2. Load Event    — resolve the event record from the claimed job payload
 *   3. Create Context — WorkerExecutionContext already supplied by WorkerEngine
 *   4. Validate      — verify the event type is registered; check required fields
 *   5. Resolve       — look up the registered handler for the event type
 *   6. Execute       — invoke the handler
 *   7. Commit State  — handler is responsible for its own PG transaction (DECISION 3)
 *   8. Acknowledge   — complete() on success; release() on transient failure;
 *                      deadLetter() on retry-limit exhaustion (ADR-022)
 *
 * Authority:
 *   Doc 8 §7         — standard execution pipeline
 *   ADR-044          — stateless; reloads WP state per event
 *   ADR-022          — retry limit default 10; exhaustion → dead-letter
 *   DECISION E       — no new pg_* wrapper; PostgreSQL access only via QueueProviderInterface
 *   CLAUDE.md Rule 7 — constructor injection only
 *
 * NOTE: Handler resolution and execution are stubs in P0-S6. The EventRegistry
 * lookup, subscriber resolution, and handler invocation are wired in P1A-S1 when
 * module subscribers are registered. The claim → ack lifecycle IS fully implemented
 * here and is test-proven.
 */
final class EventWorkerStrategy implements WorkerStrategyInterface
{
    private const QUEUE_NAME = 'content';

    public function __construct(
        private readonly QueueProviderInterface $queue,
        private readonly EventRegistry          $eventRegistry,
        private readonly int                    $retryLimit = 10,
    ) {}

    public function execute(WorkerExecutionContext $context): bool
    {
        $job = $this->queue->claim(self::QUEUE_NAME, $context->workerId);

        if ($job === null) {
            return false;
        }

        $jobId        = (string) $job['id'];
        $attemptCount = (int) $job['attempts'];
        $eventType    = $this->extractEventType($job);

        try {
            // Step 4 — Validate: event type must be registered.
            if ($eventType !== null && ! $this->eventRegistry->has($eventType)) {
                throw new \RuntimeException(
                    "Event type '{$eventType}' is not registered in the EventRegistry."
                );
            }

            // Steps 5–7 — Resolve subscriber → Execute handler → Commit state.
            // Stub in P0-S6: subscriber wiring and handler invocation happen in P1A-S1.
            // The engine tick, claim lifecycle, and registry validation ARE exercised here.
            $this->executeHandler($eventType, $job, $context);

            // Step 8a — Acknowledge: job completed successfully.
            // complete() returns false when the lease was lost (visibility-timeout recovery
            // reassigned the job to another worker). On false we abandon silently — the new
            // owner is responsible; no second call must be made (P0-S5 fencing contract).
            $this->queue->complete($jobId, $context->workerId);
            // Return value intentionally not checked: false = lease lost = silent abandon.
            // The job was already processed; no harm in the new owner reprocessing it
            // (at-least-once + idempotent — CLAUDE.md Rule 4).

        } catch (\Throwable $e) {
            if ($attemptCount >= $this->retryLimit) {
                // Retry limit exhausted — move to DLQ (ADR-022).
                // deadLetter() returns false when the lease was lost. On false we abandon
                // silently — the new owner will dead-letter the job when its own limit is hit.
                // No second call (release/complete) must follow a false return.
                $this->queue->deadLetter($jobId, $context->workerId, [
                    'failure_reason'   => $e->getMessage(),
                    'stack_trace'      => $e->getTraceAsString(),
                    'attempt_count'    => $attemptCount,
                    'payload_snapshot' => $job,
                ]);
                // Return value intentionally not checked: false = lease lost = abandon.
            } else {
                // Transient failure — release for retry with backoff (ADR-022).
                // release() returns false when the lease was lost; abandon silently.
                $backoff = $this->computeBackoffSeconds($attemptCount);
                $this->queue->release($jobId, $context->workerId, $backoff);
                // Return value intentionally not checked: false = lease lost = abandon.
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
     * @param array<string, mixed> $job
     */
    private function extractEventType(array $job): ?string
    {
        // The event_type is expected inside the job payload JSONB field.
        // In P1A-S1 this will be hydrated from system.events via event_id.
        // For P0-S6 we read it directly from the job row if present.
        if (isset($job['payload']) && is_string($job['payload'])) {
            $payload = json_decode($job['payload'], true);
            if (is_array($payload) && isset($payload['event_type'])) {
                return (string) $payload['event_type'];
            }
        }

        return null;
    }

    /**
     * Execute the handler for the given event type.
     *
     * STUB — P0-S6: no subscribers are registered yet. This method is the hook
     * point that P1A-S1 will fill in when module event subscribers exist.
     * The stub logs nothing and returns cleanly so the ack path can be exercised.
     *
     * @param array<string, mixed> $job
     */
    private function executeHandler(
        ?string $eventType,
        array $job,
        WorkerExecutionContext $context,
    ): void {
        // P1A-S1 TODO: resolve subscriber from EventRegistry; invoke handler;
        // handler commits its own PG transaction (DECISION 3).
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
