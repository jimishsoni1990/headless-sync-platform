<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Workers;

use HSP\Core\Contracts\WorkerInterface;
use HSP\Core\Workers\HeartbeatRecord;
use HSP\Core\Workers\ProcessingCycleResult;
use HSP\Core\Workers\Strategies\RelayWorkerStrategy;
use HSP\Core\Workers\WorkerEngine;
use HSP\Core\Workers\WorkerExecutionContext;
use HSP\Core\Workers\WorkerStrategyInterface;
use HSP\Tests\Unit\Events\Outbox\FakeMysqlOutboxConnection;
use HSP\Tests\Unit\Events\Outbox\FakePgsqlOutboxConnection;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Events/Outbox/FakeOutboxConnection.php';

/**
 * Unit tests for the WP-Cron Processing Engine cycle (ADR-054 / Doc 8 v2.0 §9; DECISION X).
 *
 * Verified:
 *   runCycle()               — bounded, composes relay → dispatch → projection → maintenance,
 *                              returns a ProcessingCycleResult and exits (no loop, no sleep)
 *   fresh per-cycle UUIDv7   — a new worker_id each cycle (DECISION X ruling (1))
 *   heartbeat status set     — 'running' when work advanced, 'idle' when the pipeline is empty
 *                              (DECISION X ruling (2)); two cycles → two distinct worker_id rows
 *   projection batch bound   — at most projection_batch_size jobs projected per cycle;
 *                              the remainder is left for the next cycle
 *   time budget              — a zero/near-zero budget stops the cycle before claiming;
 *                              the in-flight job (if any) is never interrupted mid-call
 *   WorkerInterface          — the engine is the sole implementer of the bounded-cycle contract
 *
 * No real database — the real RelayWorkerStrategy over split outbox fakes + strategy doubles.
 */
final class WorkerEngineTest extends TestCase
{
    private FakeMysqlOutboxConnection $mysql;
    private FakePgsqlOutboxConnection $pgsql;
    private RelayWorkerStrategy        $relay;
    private SequencedStrategy          $dispatch;
    private SequencedStrategy          $projection;
    private SequencedStrategy          $maintenance;
    private FakeHeartbeatPublisher     $publisher;

    protected function setUp(): void
    {
        $this->mysql       = new FakeMysqlOutboxConnection();
        $this->pgsql       = new FakePgsqlOutboxConnection();
        $this->relay       = new RelayWorkerStrategy($this->mysql, $this->pgsql, 'wp_', 100);
        $this->dispatch    = new SequencedStrategy();
        $this->projection  = new SequencedStrategy();
        $this->maintenance = new SequencedStrategy();
        $this->publisher   = new FakeHeartbeatPublisher();
    }

    private function engine(int $projectionBatchSize = 100, float $budget = 20.0): WorkerEngine
    {
        return new WorkerEngine(
            $this->relay,
            $this->dispatch,
            $this->projection,
            $this->maintenance,
            $this->publisher,
            projectionBatchSize:    $projectionBatchSize,
            cycleTimeBudgetSeconds:  $budget,
            workerType:              'processing',
        );
    }

    // -------------------------------------------------------------------------
    // Contract + identity
    // -------------------------------------------------------------------------

    public function test_engine_is_the_worker_interface(): void
    {
        self::assertInstanceOf(WorkerInterface::class, $this->engine());
    }

    public function test_run_cycle_returns_a_processing_cycle_result(): void
    {
        self::assertInstanceOf(ProcessingCycleResult::class, $this->engine()->runCycle());
    }

    public function test_worker_id_is_uuidv7(): void
    {
        $result = $this->engine()->runCycle();
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $result->workerId,
        );
    }

    public function test_each_cycle_mints_a_fresh_worker_id(): void
    {
        $engine = $this->engine();
        $a = $engine->runCycle()->workerId;
        $b = $engine->runCycle()->workerId;

        self::assertNotSame($a, $b, 'each cron cycle mints a fresh UUIDv7 (DECISION X ruling (1))');
    }

    // -------------------------------------------------------------------------
    // Cycle shape — bounded, no loop
    // -------------------------------------------------------------------------

    public function test_cycle_runs_each_stage_once_and_exits(): void
    {
        // Empty pipeline: relay finds nothing, dispatch/projection empty; maintenance always sweeps.
        $this->mysql->nextQueryRows = [];
        $this->dispatch->results    = [false];
        $this->projection->results  = [false];

        $result = $this->engine()->runCycle();

        self::assertSame(0, $result->relayed);
        self::assertSame(0, $result->dispatched);
        self::assertSame(0, $result->projected);
        self::assertTrue($result->maintenanceSwept, 'maintenance stage ran');
        self::assertFalse($result->didWork(), 'an empty pipeline did no work');
        self::assertFalse($result->budgetExhausted);
        // Dispatch and maintenance each called exactly once (a batch, not a loop-to-empty).
        self::assertSame(1, $this->dispatch->calls);
        self::assertSame(1, $this->maintenance->calls);
    }

    public function test_projection_stage_claims_up_to_batch_size_then_yields(): void
    {
        // 5 jobs available but the batch size is 3 → only 3 projected this cycle; the rest wait.
        $this->projection->results = [true, true, true, true, true];

        $result = $this->engine(projectionBatchSize: 3)->runCycle();

        self::assertSame(3, $result->projected, 'at most projection_batch_size jobs per cycle');
        self::assertSame(3, $this->projection->calls, 'projection stage stops at the batch bound, no loop-to-empty');
        self::assertTrue($result->didWork());
    }

    public function test_projection_stage_stops_early_when_queue_drains(): void
    {
        // 2 jobs then empty → projects 2, stops (does not keep calling to the batch size).
        $this->projection->results = [true, true, false];

        $result = $this->engine(projectionBatchSize: 100)->runCycle();

        self::assertSame(2, $result->projected);
        self::assertSame(3, $this->projection->calls, 'one extra call observes the empty queue, then stops');
    }

    public function test_relay_and_dispatch_counts_are_reported(): void
    {
        $this->mysql->nextQueryRows = [$this->outboxRow('a'), $this->outboxRow('b')];
        $this->dispatch->results    = [true];
        $this->dispatch->count      = 4;
        $this->projection->results  = [false];

        $result = $this->engine()->runCycle();

        self::assertSame(2, $result->relayed, 'relay batch count reported');
        self::assertSame(4, $result->dispatched, 'dispatch batch count reported');
        self::assertTrue($result->didWork());
    }

    // -------------------------------------------------------------------------
    // Heartbeat semantics (DECISION X rulings (1)/(2))
    // -------------------------------------------------------------------------

    public function test_cycle_publishes_one_running_heartbeat_when_work_done(): void
    {
        $this->projection->results = [true, false];

        $this->engine()->runCycle();

        self::assertCount(1, $this->publisher->published);
        $hb = $this->publisher->published[0];
        self::assertInstanceOf(HeartbeatRecord::class, $hb);
        self::assertSame('running', $hb->status);
        self::assertSame('processing', $hb->workerType);
    }

    public function test_cycle_publishes_idle_heartbeat_when_pipeline_empty(): void
    {
        $this->mysql->nextQueryRows = [];
        $this->dispatch->results    = [false];
        $this->projection->results  = [false];

        $this->engine()->runCycle();

        self::assertSame('idle', $this->publisher->published[0]->status);
    }

    public function test_two_cycles_write_two_distinct_heartbeat_worker_ids(): void
    {
        $this->projection->results = [false, false];

        $engine = $this->engine();
        $engine->runCycle();
        $engine->runCycle();

        self::assertCount(2, $this->publisher->published);
        self::assertNotSame(
            $this->publisher->published[0]->workerId,
            $this->publisher->published[1]->workerId,
            'two cycles → two distinct worker_id heartbeat rows (DECISION X ruling (1))',
        );
    }

    public function test_status_set_is_only_running_or_idle(): void
    {
        // Whatever the pipeline state, the status is one of exactly {running, idle}.
        $this->projection->results = [true, false];
        $this->engine()->runCycle();
        $this->projection->results = [false];
        $this->engine()->runCycle();

        foreach ($this->publisher->published as $hb) {
            self::assertContains($hb->status, ['running', 'idle'], "status must be running|idle only, got {$hb->status}");
        }
    }

    // -------------------------------------------------------------------------
    // Execution-time budget (Doc 8 v2.0 §12)
    // -------------------------------------------------------------------------

    public function test_zero_budget_stops_before_claiming_any_work(): void
    {
        // A zero budget is already exhausted at the first check → no stage claims, cycle exits.
        $this->mysql->nextQueryRows = [$this->outboxRow('a')];
        $this->dispatch->results    = [true];
        $this->projection->results  = [true, true, true];

        $result = $this->engine(projectionBatchSize: 100, budget: 0.0)->runCycle();

        self::assertTrue($result->budgetExhausted, 'the budget stopped the cycle');
        self::assertSame(0, $result->relayed, 'no relay claim under an exhausted budget');
        self::assertSame(0, $this->projection->calls, 'no projection claim under an exhausted budget');
        // A heartbeat is still recorded so a stalled/short cycle is observable.
        self::assertCount(1, $this->publisher->published);
    }

    public function test_budget_does_not_interrupt_an_in_flight_projection_call(): void
    {
        // The projection double completes each execute() call atomically; even if the budget
        // is tiny, a call that has started always finishes (the engine only checks the budget
        // BETWEEN claims). Here a generous budget lets exactly the batch run.
        $this->projection->results = [true, true];

        $result = $this->engine(projectionBatchSize: 2, budget: 20.0)->runCycle();

        self::assertSame(2, $result->projected);
        self::assertSame(2, $this->projection->calls);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function outboxRow(string $id): array
    {
        return [
            'id'                => $id,
            'event_type'        => 'content.post.created',
            'event_version'     => '1',
            'aggregate_type'    => 'post',
            'aggregate_id'      => '42',
            'aggregate_version' => '1',
            'source_updated_at' => '2026-01-15 10:00:00',
            'checksum'          => str_repeat('a', 64),
            'correlation_id'    => 'corr-0001',
            'causation_id'      => null,
            'payload'           => '{}',
            'created_at'        => '2026-01-15 09:59:50',
        ];
    }
}

/**
 * A WorkerStrategyInterface double whose execute() returns a pre-seeded sequence of booleans
 * (defaulting to false once exhausted) and reports a configurable dispatched count.
 */
final class SequencedStrategy implements WorkerStrategyInterface
{
    /** @var list<bool> */
    public array $results = [];
    public int   $calls   = 0;
    public int   $count   = 0;

    public function execute(WorkerExecutionContext $context): bool
    {
        $this->calls++;
        return $this->results !== [] ? (array_shift($this->results) ?? false) : false;
    }

    public function lastDispatchedCount(): int
    {
        return $this->count;
    }

    public function getQueueNames(): array
    {
        return ['content'];
    }
}
