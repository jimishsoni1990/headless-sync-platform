<?php

declare(strict_types=1);

namespace HSP\Core\Workers;

use HSP\Core\Contracts\WorkerInterface;

/**
 * Shared worker engine with pluggable strategies.
 *
 * Authority:
 *   Doc 8 §7  — standard pipeline executed per tick:
 *               Claim → Load Event → Create WorkerExecutionContext
 *               → Validate → Resolve Subscriber → Execute Handler
 *               → Commit State → Acknowledge Job
 *               (individual pipeline steps are the strategy's responsibility;
 *               the engine owns the outer loop, context creation, and heartbeat)
 *   ADR-044   — stateless; reload current WP state per event (state sync, not
 *               event sourcing). Engine carries no mutable domain state.
 *   ADR-022   — retry limit is enforced by the strategy via QueueProviderInterface.
 *   OPEN-3 v1.1 — worker identity is UUIDv7, self-assigned at startup.
 *   DECISION E — no new raw pg_* wrapper introduced here. PostgreSQL access goes
 *               through the strategy → QueueProviderInterface path.
 *   CLAUDE.md Rule 7 — constructor injection only; no Container::get / global $container.
 *
 * Graceful shutdown:
 *   shutdown() sets a flag. The run() loop checks it *between* ticks — never
 *   mid-tick — so the in-flight job always completes before the engine exits.
 *
 * Heartbeat:
 *   After every tick the engine publishes a HeartbeatRecord carrying worker_id,
 *   status ('idle' when queue was empty, 'processing' when a job ran), and
 *   last_heartbeat_at. Doc 8 §15.
 *
 * Idle back-off:
 *   When the strategy returns false (queue empty), the engine sleeps
 *   $idleWaitMs milliseconds before the next tick to avoid busy-spinning.
 */
final class WorkerEngine implements WorkerInterface
{
    private bool   $running  = false;
    private string $workerId;

    public function __construct(
        private readonly WorkerStrategyInterface   $strategy,
        private readonly HeartbeatPublisherInterface $heartbeatPublisher,
        private readonly int                        $idleWaitMs = 200,
    ) {
        $this->workerId = $this->uuidv7();
    }

    // -------------------------------------------------------------------------
    // WorkerInterface
    // -------------------------------------------------------------------------

    /**
     * Execute one tick: build context → delegate to strategy → publish heartbeat.
     *
     * Returns true if the strategy processed a job, false if the queue was empty.
     */
    public function tick(): bool
    {
        $context = new WorkerExecutionContext(
            workerId:      $this->workerId,
            tickStartedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );

        $didWork = $this->strategy->execute($context);

        $this->heartbeatPublisher->publish(new HeartbeatRecord(
            workerId:        $this->workerId,
            status:          $didWork ? 'processing' : 'idle',
            lastHeartbeatAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ));

        return $didWork;
    }

    /**
     * Run the worker loop until shutdown() is called.
     *
     * Graceful shutdown: the loop checks the flag only between ticks, so an
     * in-flight job is always completed before the engine exits.
     */
    public function run(): void
    {
        $this->running = true;

        while ($this->running) {
            $didWork = $this->tick();

            if (! $didWork) {
                usleep($this->idleWaitMs * 1000);
            }
        }

        // Publish a final heartbeat so monitors see the clean shutdown.
        $this->heartbeatPublisher->publish(new HeartbeatRecord(
            workerId:        $this->workerId,
            status:          'shutdown',
            lastHeartbeatAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ));
    }

    /**
     * Signal graceful shutdown. The current tick (if any) completes first.
     */
    public function shutdown(): void
    {
        $this->running = false;
    }

    public function getWorkerId(): string
    {
        return $this->workerId;
    }

    /**
     * @return string[]
     */
    public function getQueueNames(): array
    {
        return $this->strategy->getQueueNames();
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Generate a UUIDv7 for worker identity (ADR-015, OPEN-3 v1.1 canon).
     * Self-assigned once at construction; never changes during the worker lifetime.
     */
    private function uuidv7(): string
    {
        $ms    = (int) (microtime(true) * 1000);
        $bytes = random_bytes(10);

        $tsHex  = sprintf('%012x', $ms);
        $rand12 = (ord($bytes[0]) & 0x0f) << 8 | ord($bytes[1]);
        $b67hex = sprintf('%04x', 0x7000 | $rand12);
        $rand14 = (ord($bytes[2]) & 0x3f) << 8 | ord($bytes[3]);
        $b89hex = sprintf('%04x', 0x8000 | $rand14);
        $tail   = bin2hex(substr($bytes, 4, 6));

        $hex = $tsHex . $b67hex . $b89hex . $tail;

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hex, 4));
    }
}
