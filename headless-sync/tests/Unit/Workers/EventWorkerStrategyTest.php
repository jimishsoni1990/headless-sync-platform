<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Workers;

use HSP\Core\Events\EventRegistry;
use HSP\Core\Workers\Strategies\EventWorkerStrategy;
use HSP\Core\Workers\WorkerExecutionContext;
use HSP\Tests\Unit\Content\Adapters\FakeDbConnection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EventWorkerStrategy.
 *
 * Verified:
 *   execute() empty queue          — returns false; no complete/release/deadLetter called
 *   execute() with job             — returns true; complete() called on success
 *   execute() no event_id          — throws; release() called (retry)
 *   execute() missing event row    — throws; release() called (retry)
 *   execute() unregistered type    — throws; release() called (retry)
 *   execute() registered type      — handler invoked; complete() called
 *   execute() stale event          — ack + return true; handler NOT invoked; no writes
 *   execute() retry limit          — deadLetter() called when attempts >= retryLimit
 *   execute() lease-lost complete  — silent abandon
 *   execute() lease-lost release   — silent abandon
 *   execute() lease-lost deadLetter— silent abandon
 *   getQueueNames()                — returns ['content']
 *
 * No real database — FakeQueueProvider, FakeDbConnection (controllable query results).
 */
final class EventWorkerStrategyTest extends TestCase
{
    private FakeQueueProvider   $queue;
    private EventRegistry       $registry;
    private FakeDbConnection    $db;
    private EventWorkerStrategy $strategy;
    private WorkerExecutionContext $ctx;

    private const EVENT_ID = '01900000-0000-7000-8000-000000000001';
    private const AGG_TYPE = 'page';
    private const AGG_ID   = '42';

    protected function setUp(): void
    {
        $this->queue    = new FakeQueueProvider();
        $this->registry = new EventRegistry();
        $this->db       = new FakeDbConnection();
        $this->strategy = new EventWorkerStrategy(
            $this->queue,
            $this->registry,
            $this->db,
            retryLimit: 10,
        );

        $this->ctx = new WorkerExecutionContext(
            workerId:      'test-worker-id',
            tickStartedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    // -------------------------------------------------------------------------
    // getQueueNames
    // -------------------------------------------------------------------------

    public function test_get_queue_names_returns_content(): void
    {
        self::assertSame(['content'], $this->strategy->getQueueNames());
    }

    // -------------------------------------------------------------------------
    // execute() — empty queue
    // -------------------------------------------------------------------------

    public function test_execute_returns_false_when_queue_empty(): void
    {
        $this->queue->claimResult = null;
        self::assertFalse($this->strategy->execute($this->ctx));
    }

    public function test_execute_does_not_call_complete_when_queue_empty(): void
    {
        $this->queue->claimResult = null;
        $this->strategy->execute($this->ctx);
        self::assertEmpty($this->queue->completeCalls);
    }

    // -------------------------------------------------------------------------
    // execute() — no event_id in job → release for retry
    // -------------------------------------------------------------------------

    public function test_execute_releases_when_job_has_no_event_id(): void
    {
        $this->queue->claimResult = $this->makeJobWithEventId('job-1', 1, '');

        $this->strategy->execute($this->ctx);

        self::assertCount(1, $this->queue->releaseCalls);
        self::assertEmpty($this->queue->completeCalls);
    }

    // -------------------------------------------------------------------------
    // execute() — event not in system.events → release for retry
    // -------------------------------------------------------------------------

    public function test_execute_releases_when_event_row_missing(): void
    {
        $this->queue->claimResult = $this->makeJobWithEventId('job-2', 1, self::EVENT_ID);
        // db.query() returns [] (no row found)
        $this->db->willReturnRows([]);

        $this->strategy->execute($this->ctx);

        self::assertCount(1, $this->queue->releaseCalls);
        self::assertEmpty($this->queue->completeCalls);
    }

    // -------------------------------------------------------------------------
    // execute() — event type not registered → release for retry
    // -------------------------------------------------------------------------

    public function test_execute_releases_when_event_type_not_registered(): void
    {
        $this->queue->claimResult = $this->makeJobWithEventId('job-3', 3, self::EVENT_ID);
        $this->db->queueQueryResults(
            [$this->makeEventRow('content.post.updated')],  // system.events
            [],                                             // aggregate_versions (not stale)
        );
        // Do NOT register 'content.post.updated'.

        $this->strategy->execute($this->ctx);

        self::assertCount(1, $this->queue->releaseCalls);
        self::assertEmpty($this->queue->completeCalls);
    }

    // -------------------------------------------------------------------------
    // execute() — registered event type, non-stale → handler invoked + complete
    // -------------------------------------------------------------------------

    public function test_execute_invokes_handler_and_completes_for_registered_event(): void
    {
        $handlerCalled = false;
        $this->registry->register('content.post.updated', function () use (&$handlerCalled): void {
            $handlerCalled = true;
        });

        $this->queue->claimResult = $this->makeJobWithEventId('job-4', 1, self::EVENT_ID);
        $this->db->queueQueryResults(
            [$this->makeEventRow('content.post.updated')],  // system.events
            [],                                             // aggregate_versions: no row → not stale
        );

        $result = $this->strategy->execute($this->ctx);

        self::assertTrue($result);
        self::assertTrue($handlerCalled, 'Handler must be invoked for registered, non-stale event');
        self::assertCount(1, $this->queue->completeCalls);
        self::assertSame('job-4', $this->queue->completeCalls[0]['jobId']);
        self::assertEmpty($this->queue->releaseCalls);
    }

    // -------------------------------------------------------------------------
    // execute() — Resolve-stage stale guard (DECISION J Layer 1)
    // -------------------------------------------------------------------------

    public function test_execute_acks_stale_event_without_invoking_handler(): void
    {
        $handlerCalled = false;
        $this->registry->register('content.post.updated', function () use (&$handlerCalled): void {
            $handlerCalled = true;
        });

        $this->queue->claimResult = $this->makeJobWithEventId('job-5', 1, self::EVENT_ID);
        // Event has aggregate_version=1; stored latest_processed_version=5 → stale
        $this->db->queueQueryResults(
            [$this->makeEventRow('content.post.updated', aggregateVersion: 1)],
            [['latest_processed_version' => '5']],  // aggregate_versions row
        );

        $result = $this->strategy->execute($this->ctx);

        self::assertTrue($result, 'execute() must return true even for stale events (job was claimed)');
        self::assertFalse($handlerCalled, 'Handler must NOT be invoked for stale event');
        self::assertCount(1, $this->queue->completeCalls, 'Stale event must be acked (not retried)');
        self::assertEmpty($this->queue->releaseCalls);
        self::assertEmpty($this->queue->deadLetterCalls);
    }

    public function test_execute_processes_non_stale_event_when_version_equals_stored_plus_one(): void
    {
        $handlerCalled = false;
        $this->registry->register('content.post.updated', function () use (&$handlerCalled): void {
            $handlerCalled = true;
        });

        $this->queue->claimResult = $this->makeJobWithEventId('job-6', 1, self::EVENT_ID);
        // Event aggregate_version=6; stored=5 → 6 > 5 → NOT stale
        $this->db->queueQueryResults(
            [$this->makeEventRow('content.post.updated', aggregateVersion: 6)],
            [['latest_processed_version' => '5']],
        );

        $this->strategy->execute($this->ctx);

        self::assertTrue($handlerCalled, 'Handler must be invoked when version > stored');
    }

    public function test_execute_processes_event_when_no_aggregate_version_row_exists(): void
    {
        $handlerCalled = false;
        $this->registry->register('content.page.created', function () use (&$handlerCalled): void {
            $handlerCalled = true;
        });

        $this->queue->claimResult = $this->makeJobWithEventId('job-7', 1, self::EVENT_ID);
        // aggregate_versions returns [] → first event for this aggregate → not stale
        $this->db->queueQueryResults(
            [$this->makeEventRow('content.page.created', aggregateVersion: 1)],
            [],
        );

        $this->strategy->execute($this->ctx);

        self::assertTrue($handlerCalled);
    }

    // -------------------------------------------------------------------------
    // execute() — equal version is stale (version <= stored, not just <)
    // -------------------------------------------------------------------------

    public function test_execute_acks_event_when_version_equals_stored(): void
    {
        $handlerCalled = false;
        $this->registry->register('content.post.updated', function () use (&$handlerCalled): void {
            $handlerCalled = true;
        });

        $this->queue->claimResult = $this->makeJobWithEventId('job-8', 1, self::EVENT_ID);
        // Event aggregate_version=5; stored=5 → 5 <= 5 → STALE
        $this->db->queueQueryResults(
            [$this->makeEventRow('content.post.updated', aggregateVersion: 5)],
            [['latest_processed_version' => '5']],
        );

        $result = $this->strategy->execute($this->ctx);

        self::assertTrue($result);
        self::assertFalse($handlerCalled, 'Equal version must be treated as stale');
        self::assertCount(1, $this->queue->completeCalls);
    }

    // -------------------------------------------------------------------------
    // execute() — retry limit exhausted → deadLetter
    // -------------------------------------------------------------------------

    public function test_execute_dead_letters_when_retry_limit_exhausted(): void
    {
        // Event type not registered → throws; attempts=10 → deadLetter
        $this->queue->claimResult = $this->makeJobWithEventId('job-9', 10, self::EVENT_ID);
        $this->db->queueQueryResults(
            [$this->makeEventRow('content.page.deleted')],
            [],
        );
        // 'content.page.deleted' not registered

        $this->strategy->execute($this->ctx);

        self::assertCount(1, $this->queue->deadLetterCalls);
        self::assertSame('job-9', $this->queue->deadLetterCalls[0]['jobId']);
        self::assertArrayHasKey('failure_reason', $this->queue->deadLetterCalls[0]['failureContext']);
        self::assertEmpty($this->queue->releaseCalls);
    }

    // -------------------------------------------------------------------------
    // execute() — fencing contract: lease-lost paths
    // -------------------------------------------------------------------------

    public function test_lease_lost_on_complete_causes_silent_abandon(): void
    {
        $this->registry->register('content.page.created', fn () => null);
        $this->queue->claimResult = $this->makeJobWithEventId('job-10', 1, self::EVENT_ID);
        $this->db->queueQueryResults(
            [$this->makeEventRow('content.page.created')],
            [],
        );
        $this->queue->completeReturns = false;

        $result = $this->strategy->execute($this->ctx);

        self::assertTrue($result);
        self::assertCount(1, $this->queue->completeCalls);
        self::assertEmpty($this->queue->releaseCalls);
        self::assertEmpty($this->queue->deadLetterCalls);
    }

    public function test_lease_lost_on_release_causes_silent_abandon(): void
    {
        $this->queue->claimResult    = $this->makeJobWithEventId('job-11', 3, self::EVENT_ID);
        $this->db->queueQueryResults(
            [$this->makeEventRow('content.post.created')],
            [],
        );
        // 'content.post.created' not registered → throws → release path
        $this->queue->releaseReturns = false;

        $result = $this->strategy->execute($this->ctx);

        self::assertTrue($result);
        self::assertCount(1, $this->queue->releaseCalls);
        self::assertEmpty($this->queue->completeCalls);
        self::assertEmpty($this->queue->deadLetterCalls);
    }

    public function test_lease_lost_on_dead_letter_causes_silent_abandon(): void
    {
        $this->queue->claimResult       = $this->makeJobWithEventId('job-12', 10, self::EVENT_ID);
        $this->db->queueQueryResults(
            [$this->makeEventRow('content.page.deleted')],
            [],
        );
        // unregistered → throws at retry-limit → deadLetter
        $this->queue->deadLetterReturns = false;

        $result = $this->strategy->execute($this->ctx);

        self::assertTrue($result);
        self::assertCount(1, $this->queue->deadLetterCalls);
        self::assertEmpty($this->queue->releaseCalls);
        self::assertEmpty($this->queue->completeCalls);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function makeJobWithEventId(string $id, int $attempts, string $eventId): array
    {
        return [
            'id'       => $id,
            'event_id' => $eventId,
            'attempts' => $attempts,
        ];
    }

    /**
     * Build a system.events row for FakeDbConnection to return.
     *
     * @return array<string,mixed>
     */
    private function makeEventRow(
        string $eventType,
        int    $aggregateVersion = 1,
    ): array {
        return [
            'id'                => self::EVENT_ID,
            'event_type'        => $eventType,
            'event_version'     => '1',
            'aggregate_type'    => self::AGG_TYPE,
            'aggregate_id'      => self::AGG_ID,
            'aggregate_version' => (string) $aggregateVersion,
            'payload'           => '{}',
            'checksum'          => str_repeat('a', 64),
            'source_updated_at' => '2024-01-01 00:00:00+00',
            'created_at'        => '2024-01-01 00:00:00+00',
            'correlation_id'    => '01900000-0000-7000-8000-000000000002',
            'causation_id'      => null,
        ];
    }
}
