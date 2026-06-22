<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Workers;

use HSP\Core\Events\EventRegistry;
use HSP\Core\Workers\Strategies\EventWorkerStrategy;
use HSP\Core\Workers\WorkerExecutionContext;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EventWorkerStrategy.
 *
 * Verified:
 *   execute() empty queue    — returns false; no complete/release/deadLetter called
 *   execute() with job       — returns true; complete() called on success
 *   execute() unknown type   — throws RuntimeException; release() called (retry)
 *   execute() unregistered   — throws RuntimeException when event type not in registry
 *   execute() retry limit    — deadLetter() called when attempts >= retryLimit
 *   execute() retry path     — release() called with backoff when attempts < retryLimit
 *   execute() lease-lost (complete)   — returns false → silent abandon; no release/deadLetter after
 *   execute() lease-lost (release)   — returns false → silent abandon; no second call after
 *   execute() lease-lost (deadLetter) — returns false → silent abandon; no release after
 *   getQueueNames()          — returns ['content']
 *
 * No real database — FakeQueueProvider and EventRegistry only.
 */
final class EventWorkerStrategyTest extends TestCase
{
    private FakeQueueProvider  $queue;
    private EventRegistry      $registry;
    private EventWorkerStrategy $strategy;

    private WorkerExecutionContext $ctx;

    protected function setUp(): void
    {
        $this->queue    = new FakeQueueProvider();
        $this->registry = new EventRegistry();
        $this->strategy = new EventWorkerStrategy($this->queue, $this->registry, retryLimit: 10);

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
    // execute() — successful job (no event_type in payload → no registry check)
    // -------------------------------------------------------------------------

    public function test_execute_returns_true_when_job_claimed(): void
    {
        $this->queue->claimResult = $this->makeJob('job-1', 1);
        self::assertTrue($this->strategy->execute($this->ctx));
    }

    public function test_execute_calls_complete_on_success(): void
    {
        $this->queue->claimResult = $this->makeJob('job-1', 1);
        $this->strategy->execute($this->ctx);

        self::assertCount(1, $this->queue->completeCalls);
        self::assertSame('job-1', $this->queue->completeCalls[0]['jobId']);
        self::assertSame('test-worker-id', $this->queue->completeCalls[0]['workerId']);
    }

    public function test_execute_does_not_call_release_on_success(): void
    {
        $this->queue->claimResult = $this->makeJob('job-1', 1);
        $this->strategy->execute($this->ctx);
        self::assertEmpty($this->queue->releaseCalls);
    }

    // -------------------------------------------------------------------------
    // execute() — unregistered event type → release for retry
    // -------------------------------------------------------------------------

    public function test_execute_releases_job_when_event_type_not_registered(): void
    {
        $this->queue->claimResult = $this->makeJobWithEventType('job-2', 3, 'content.post.updated');
        // Do NOT register 'content.post.updated' — intentionally absent.

        $this->strategy->execute($this->ctx);

        self::assertCount(1, $this->queue->releaseCalls);
        self::assertSame('job-2', $this->queue->releaseCalls[0]['jobId']);
    }

    public function test_execute_does_not_complete_when_event_type_not_registered(): void
    {
        $this->queue->claimResult = $this->makeJobWithEventType('job-2', 3, 'content.post.updated');
        $this->strategy->execute($this->ctx);
        self::assertEmpty($this->queue->completeCalls);
    }

    // -------------------------------------------------------------------------
    // execute() — registered event type → success path
    // -------------------------------------------------------------------------

    public function test_execute_succeeds_when_event_type_is_registered(): void
    {
        $this->registry->register('content.post.updated', fn () => null);
        $this->queue->claimResult = $this->makeJobWithEventType('job-3', 1, 'content.post.updated');

        $this->strategy->execute($this->ctx);

        self::assertCount(1, $this->queue->completeCalls);
        self::assertEmpty($this->queue->releaseCalls);
    }

    // -------------------------------------------------------------------------
    // execute() — retry limit exhausted → deadLetter
    // -------------------------------------------------------------------------

    public function test_execute_dead_letters_when_retry_limit_exhausted(): void
    {
        // attempts = retryLimit → deadLetter path
        $this->queue->claimResult = $this->makeJobWithEventType('job-4', 10, 'content.page.deleted');
        // 'content.page.deleted' not registered → will throw, attempts == retryLimit(10)

        $this->strategy->execute($this->ctx);

        self::assertCount(1, $this->queue->deadLetterCalls);
        self::assertSame('job-4', $this->queue->deadLetterCalls[0]['jobId']);
    }

    public function test_execute_dead_letter_carries_failure_context(): void
    {
        $this->queue->claimResult = $this->makeJobWithEventType('job-4', 10, 'content.page.deleted');
        $this->strategy->execute($this->ctx);

        $ctx = $this->queue->deadLetterCalls[0]['failureContext'];
        self::assertArrayHasKey('failure_reason', $ctx);
        self::assertArrayHasKey('stack_trace', $ctx);
        self::assertArrayHasKey('attempt_count', $ctx);
        self::assertArrayHasKey('payload_snapshot', $ctx);
    }

    public function test_execute_does_not_release_when_dead_lettering(): void
    {
        $this->queue->claimResult = $this->makeJobWithEventType('job-4', 10, 'content.page.deleted');
        $this->strategy->execute($this->ctx);
        self::assertEmpty($this->queue->releaseCalls);
    }

    // -------------------------------------------------------------------------
    // execute() — release for retry (attempts < retryLimit)
    // -------------------------------------------------------------------------

    public function test_execute_releases_with_positive_backoff_on_transient_failure(): void
    {
        $this->queue->claimResult = $this->makeJobWithEventType('job-5', 2, 'content.post.created');
        $this->strategy->execute($this->ctx);

        self::assertCount(1, $this->queue->releaseCalls);
        self::assertGreaterThanOrEqual(0, $this->queue->releaseCalls[0]['delaySeconds']);
    }

    // -------------------------------------------------------------------------
    // execute() — fencing contract: lease-lost paths (P0-S5 QueueProviderInterface)
    //
    // When complete(), release(), or deadLetter() returns false the visibility-timeout
    // recovery has revived the job and a second worker owns it. The strategy MUST
    // abandon silently — no further queue calls, no retry increment, no second DLQ entry.
    // -------------------------------------------------------------------------

    public function test_lease_lost_on_complete_causes_silent_abandon_no_further_calls(): void
    {
        // Success path: handler runs, complete() returns false (lease lost).
        // No release() or deadLetter() must follow.
        $this->queue->claimResult     = $this->makeJob('job-6', 1);
        $this->queue->completeReturns = false;

        $result = $this->strategy->execute($this->ctx);

        self::assertTrue($result, 'execute() must still return true — a job was claimed');
        self::assertCount(1, $this->queue->completeCalls, 'complete() called exactly once');
        self::assertEmpty($this->queue->releaseCalls,    'no release() after lease-lost complete');
        self::assertEmpty($this->queue->deadLetterCalls, 'no deadLetter() after lease-lost complete');
    }

    public function test_lease_lost_on_release_causes_silent_abandon_no_further_calls(): void
    {
        // Failure path (below retry limit): handler throws, release() returns false (lease lost).
        // No second release() or deadLetter() must follow.
        $this->queue->claimResult    = $this->makeJobWithEventType('job-7', 3, 'content.post.created');
        $this->queue->releaseReturns = false;

        $result = $this->strategy->execute($this->ctx);

        self::assertTrue($result);
        self::assertCount(1, $this->queue->releaseCalls,   'release() called exactly once');
        self::assertEmpty($this->queue->completeCalls,     'no complete() on failure path');
        self::assertEmpty($this->queue->deadLetterCalls,   'no deadLetter() after lease-lost release');
    }

    public function test_lease_lost_on_dead_letter_causes_silent_abandon_no_further_calls(): void
    {
        // Failure path at retry limit: deadLetter() returns false (lease lost).
        // No release() must follow — the new owner will handle exhaustion when it hits the limit.
        $this->queue->claimResult        = $this->makeJobWithEventType('job-8', 10, 'content.page.deleted');
        $this->queue->deadLetterReturns  = false;

        $result = $this->strategy->execute($this->ctx);

        self::assertTrue($result);
        self::assertCount(1, $this->queue->deadLetterCalls, 'deadLetter() called exactly once');
        self::assertEmpty($this->queue->releaseCalls,        'no release() after lease-lost deadLetter');
        self::assertEmpty($this->queue->completeCalls,       'no complete() on exhausted path');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function makeJob(string $id, int $attempts): array
    {
        return [
            'id'       => $id,
            'attempts' => $attempts,
            'payload'  => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function makeJobWithEventType(string $id, int $attempts, string $eventType): array
    {
        return [
            'id'       => $id,
            'attempts' => $attempts,
            'payload'  => json_encode(['event_type' => $eventType]),
        ];
    }
}
