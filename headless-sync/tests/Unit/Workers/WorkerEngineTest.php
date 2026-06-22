<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Workers;

use HSP\Core\Workers\HeartbeatRecord;
use HSP\Core\Workers\WorkerEngine;
use HSP\Core\Workers\WorkerExecutionContext;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WorkerEngine.
 *
 * Verified:
 *   getWorkerId()       — returns a UUIDv7
 *   getQueueNames()     — delegates to strategy
 *   tick()              — builds WorkerExecutionContext; delegates to strategy;
 *                         returns strategy result; publishes heartbeat
 *   tick() did work     — heartbeat status 'processing'
 *   tick() no work      — heartbeat status 'idle'
 *   shutdown()          — sets flag; run() exits after current tick
 *   run()               — publishes 'shutdown' heartbeat on exit
 *   strategy exception  — propagates out of tick()
 *   context workerId    — WorkerExecutionContext carries engine's worker_id
 *
 * No real database — FakeWorkerStrategy and FakeHeartbeatPublisher only.
 */
final class WorkerEngineTest extends TestCase
{
    private FakeWorkerStrategy    $strategy;
    private FakeHeartbeatPublisher $publisher;
    private WorkerEngine           $engine;

    protected function setUp(): void
    {
        $this->strategy  = new FakeWorkerStrategy();
        $this->publisher = new FakeHeartbeatPublisher();
        $this->engine    = new WorkerEngine($this->strategy, $this->publisher, idleWaitMs: 0);
    }

    // -------------------------------------------------------------------------
    // getWorkerId
    // -------------------------------------------------------------------------

    public function test_worker_id_is_uuidv7(): void
    {
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $this->engine->getWorkerId(),
            'Worker ID must be a UUIDv7'
        );
    }

    public function test_worker_id_is_stable_across_calls(): void
    {
        self::assertSame($this->engine->getWorkerId(), $this->engine->getWorkerId());
    }

    // -------------------------------------------------------------------------
    // getQueueNames
    // -------------------------------------------------------------------------

    public function test_get_queue_names_delegates_to_strategy(): void
    {
        self::assertSame(['content'], $this->engine->getQueueNames());
    }

    // -------------------------------------------------------------------------
    // tick()
    // -------------------------------------------------------------------------

    public function test_tick_returns_true_when_strategy_finds_work(): void
    {
        $this->strategy->results = [true];
        self::assertTrue($this->engine->tick());
    }

    public function test_tick_returns_false_when_queue_empty(): void
    {
        $this->strategy->results = [false];
        self::assertFalse($this->engine->tick());
    }

    public function test_tick_passes_context_with_correct_worker_id(): void
    {
        $this->strategy->results = [false];
        $this->engine->tick();

        self::assertCount(1, $this->strategy->receivedContexts);
        $ctx = $this->strategy->receivedContexts[0];
        self::assertInstanceOf(WorkerExecutionContext::class, $ctx);
        self::assertSame($this->engine->getWorkerId(), $ctx->workerId);
    }

    public function test_tick_context_tick_started_at_is_utc(): void
    {
        $this->strategy->results = [false];
        $this->engine->tick();

        $ctx = $this->strategy->receivedContexts[0];
        self::assertSame('UTC', $ctx->tickStartedAt->getTimezone()->getName());
    }

    public function test_tick_publishes_heartbeat_after_work(): void
    {
        $this->strategy->results = [true];
        $this->engine->tick();

        self::assertCount(1, $this->publisher->published);
        $hb = $this->publisher->published[0];
        self::assertInstanceOf(HeartbeatRecord::class, $hb);
        self::assertSame('processing', $hb->status);
        self::assertSame($this->engine->getWorkerId(), $hb->workerId);
    }

    public function test_tick_publishes_idle_heartbeat_when_queue_empty(): void
    {
        $this->strategy->results = [false];
        $this->engine->tick();

        $hb = $this->publisher->published[0];
        self::assertSame('idle', $hb->status);
    }

    public function test_tick_heartbeat_last_heartbeat_at_is_utc(): void
    {
        $this->strategy->results = [false];
        $this->engine->tick();

        $hb = $this->publisher->published[0];
        self::assertSame('UTC', $hb->lastHeartbeatAt->getTimezone()->getName());
    }

    public function test_tick_propagates_strategy_exception(): void
    {
        $this->strategy->thrownMessage = 'strategy exploded';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('strategy exploded');

        $this->engine->tick();
    }

    // -------------------------------------------------------------------------
    // shutdown() + run()
    // -------------------------------------------------------------------------

    public function test_shutdown_causes_run_to_exit_after_current_tick(): void
    {
        // Strategy signals shutdown on its first tick; run() must exit after that tick.
        $ref = ['engine' => null];

        $strategy = new class ($ref) implements \HSP\Core\Workers\WorkerStrategyInterface {
            public int $calls = 0;

            public function __construct(private array &$ref) {}

            public function execute(WorkerExecutionContext $context): bool
            {
                $this->calls++;
                if ($this->calls === 1) {
                    $this->ref['engine']->shutdown();
                    return true;
                }
                return false;
            }

            public function getQueueNames(): array
            {
                return ['content'];
            }
        };

        $engine            = new WorkerEngine($strategy, $this->publisher, idleWaitMs: 0);
        $ref['engine']     = $engine;
        $engine->run();

        // run() must have exited; strategy executed exactly once.
        self::assertSame(1, $strategy->calls);
    }

    public function test_run_publishes_shutdown_heartbeat_on_exit(): void
    {
        // Shutdown immediately on first tick.
        $ref = ['engine' => null];

        $strategy = new class ($ref) implements \HSP\Core\Workers\WorkerStrategyInterface {
            public function __construct(private array &$ref) {}

            public function execute(WorkerExecutionContext $context): bool
            {
                $this->ref['engine']->shutdown();
                return false;
            }

            public function getQueueNames(): array
            {
                return ['content'];
            }
        };

        $engine        = new WorkerEngine($strategy, $this->publisher, idleWaitMs: 0);
        $ref['engine'] = $engine;
        $engine->run();

        $statuses = array_map(fn (HeartbeatRecord $r) => $r->status, $this->publisher->published);

        self::assertContains('shutdown', $statuses, 'run() must publish a shutdown heartbeat on exit');
    }

    public function test_graceful_shutdown_does_not_interrupt_in_flight_tick(): void
    {
        // The shutdown flag is set DURING a tick — the tick still finishes,
        // then run() exits cleanly.
        $ref = ['engine' => null, 'ticks' => 0];

        $strategy = new class ($ref)
            implements \HSP\Core\Workers\WorkerStrategyInterface {
            public function __construct(private array &$ref) {}

            public function execute(WorkerExecutionContext $context): bool
            {
                $this->ref['engine']->shutdown();
                $this->ref['ticks']++;
                return true;
            }

            public function getQueueNames(): array { return ['content']; }
        };

        $engine        = new WorkerEngine($strategy, $this->publisher, idleWaitMs: 0);
        $ref['engine'] = $engine;
        $engine->run();

        self::assertSame(1, $ref['ticks'], 'In-flight tick must complete before engine exits');
    }
}
