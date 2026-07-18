<?php

declare(strict_types=1);

namespace HSP\Core\Workers;

use HSP\Core\Contracts\WorkerInterface;
use HSP\Core\Observability\StructuredLogger;
use HSP\Core\Observability\WorkerCounters;
use HSP\Core\Workers\Strategies\RelayWorkerStrategy;

/**
 * WP-Cron Processing Engine (ADR-054 / Doc 8 v2.0 §9).
 *
 * Runs ONE bounded, stateless cycle per invocation and exits cleanly — there is no daemon
 * loop, no sleep, no shutdown signal. A cycle is a single PHP execution that composes the
 * four consumer-side stages into bounded batches and returns:
 *
 *   bootstrap (fresh per-cycle UUIDv7 — DECISION X ruling (1))
 *        → relay batch      (RelayWorkerStrategy::tick(), ≤ relay_batch_size)
 *        → dispatch batch   (DispatcherWorkerStrategy::execute(), ≤ dispatch_batch_size)
 *        → projection batch (EventWorkerStrategy::execute() in a bounded loop,
 *                            ≤ projection_batch_size)
 *        → maintenance      (MaintenanceWorkerStrategy sweep — DECISION R)
 *        → persist per-cycle heartbeat + metrics (DECISION P/Q)
 *        → clean exit
 *
 * Execution-time budget (Doc 8 v2.0 §12): the cycle carries a config-driven
 * `cycle_time_budget_seconds` set well inside the environment's PHP max_execution_time. The
 * budget is checked before each stage AND before each projection claim. When the budget is
 * reached the engine STOPS claiming new work, lets the in-flight event's single DECISION 3
 * transaction finish (EventWorkerStrategy::execute() commits atomically per call — it is
 * never interrupted mid-transaction here), records metrics, and exits cleanly. The visibility
 * timeout (§14) is the hard-kill backstop if the OS terminates the process anyway.
 *
 * Statelessness (Doc 8 v2.0 §9): the cycle carries nothing forward in memory. All
 * continuation state is the residual durable state in wp_hsp_outbox / system.events /
 * system.queue_jobs; a backlog larger than one cycle is continued by the next cron cycle.
 *
 * Concurrency (Doc 8 v2.0 §11 / ADR-054 §3): overlapping cycles are safe using EXISTING
 * guarantees only — SKIP LOCKED claiming, aggregate-version ordering (DECISION J), visibility
 * timeout (DECISION R), and the DECISION 3 atomic commit. This engine adds NO new locking
 * mechanism (no cron mutex, no single-flight guard).
 *
 * Identity + heartbeat (DECISION X, v1.24): each cycle mints a FRESH UUIDv7 at bootstrap; the
 * per-cycle heartbeat status is 'running' (the cycle advanced work) or 'idle' (the pipeline
 * was empty) — the daemon-only 'processing'/'shutdown' states are gone. The
 * DatabaseHeartbeatPublisher SQL and DECISION P schema are reused verbatim.
 *
 * Authority:
 *   ADR-054 / Doc 8 v2.0 §9/§12/§15/§16/§24/§25 — the bounded cycle model.
 *   DECISION X (v1.24) — per-cycle fresh UUIDv7 (1); status set running/idle (2);
 *                        WorkerInterface bounded-cycle contract (3).
 *   DECISION 3 — three-op single-PG-transaction commit is preserved (owned by the
 *                projection strategy; the engine never interrupts it mid-transaction).
 *   DECISION P/Q — heartbeat current-state (schema verbatim) + derived metrics (structured logs).
 *   CLAUDE.md Rule 7 — constructor injection only; no Container::get / global $container.
 */
final class WorkerEngine implements WorkerInterface
{
    /** Default projection batch size if none is configured. */
    private const DEFAULT_PROJECTION_BATCH_SIZE = 100;

    /** Default execution-time budget (seconds) if none is configured. */
    private const DEFAULT_CYCLE_TIME_BUDGET_SECONDS = 20;

    private string $workerId;

    public function __construct(
        private readonly RelayWorkerStrategy         $relayStrategy,
        private readonly WorkerStrategyInterface     $dispatchStrategy,
        private readonly WorkerStrategyInterface     $projectionStrategy,
        private readonly WorkerStrategyInterface     $maintenanceStrategy,
        private readonly HeartbeatPublisherInterface $heartbeatPublisher,
        /** Max system.queue_jobs claimed + projected per cycle (Doc 8 v2.0 §9). */
        private readonly int                         $projectionBatchSize = self::DEFAULT_PROJECTION_BATCH_SIZE,
        /** Execution-time budget in seconds (Doc 8 v2.0 §12). */
        private readonly float                       $cycleTimeBudgetSeconds = self::DEFAULT_CYCLE_TIME_BUDGET_SECONDS,
        /** worker_type tag stamped on the per-cycle heartbeat (DECISION P). */
        private readonly string                      $workerType = 'processing',
        /** Optional runtime counters emitted as structured logs (DECISION Q). */
        private readonly ?WorkerCounters             $counters = null,
        /** Optional structured-log sink for the per-cycle metric (DECISION Q). */
        private readonly ?StructuredLogger           $logger = null,
    ) {
        $this->workerId = $this->uuidv7();
    }

    // -------------------------------------------------------------------------
    // WorkerInterface
    // -------------------------------------------------------------------------

    /**
     * Run exactly one bounded Processing Engine cycle and exit.
     */
    public function runCycle(): ProcessingCycleResult
    {
        // Bootstrap: mint a FRESH per-cycle identity (DECISION X ruling (1)).
        $this->workerId = $this->uuidv7();
        $startedAt      = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $start          = microtime(true);

        $context = new WorkerExecutionContext(
            workerId:      $this->workerId,
            tickStartedAt: $startedAt,
        );

        $relayed          = 0;
        $dispatched       = 0;
        $projected        = 0;
        $maintenanceSwept = false;
        $budgetExhausted  = false;

        // ---- Relay stage (one bounded batch) ----
        if ($this->withinBudget($start)) {
            if ($this->relayStrategy->tick()) {
                $relayed = $this->relayStrategy->lastRelayedCount();
            }
        } else {
            $budgetExhausted = true;
        }

        // ---- Dispatch stage (one bounded batch) ----
        if (! $budgetExhausted && $this->withinBudget($start)) {
            if ($this->dispatchStrategy->execute($context)) {
                // The dispatch strategy reports how many events it enqueued this batch via
                // lastDispatchedCount() (DispatcherWorkerStrategy). Fall back to 1 for any
                // dispatch strategy that does not expose a count (batch had work).
                $dispatched = method_exists($this->dispatchStrategy, 'lastDispatchedCount')
                    ? $this->dispatchStrategy->lastDispatchedCount()
                    : 1;
            }
        } elseif (! $budgetExhausted) {
            $budgetExhausted = true;
        }

        // ---- Projection stage (bounded loop — one job per EventWorkerStrategy::execute()) ----
        // Budget is checked BEFORE each claim; on budget the in-flight event's single
        // transaction has already committed (execute() is atomic per call), so we simply
        // stop claiming and exit cleanly mid-backlog (Doc 8 v2.0 §12/§25).
        for ($i = 0; $i < $this->projectionBatchSize; $i++) {
            if (! $this->withinBudget($start)) {
                $budgetExhausted = true;
                break;
            }

            if (! $this->projectionStrategy->execute($context)) {
                break; // queue empty — nothing left to project this cycle
            }

            $projected++;
        }

        // ---- Maintenance stage (visibility-timeout requeue sweep — DECISION R) ----
        if (! $budgetExhausted && $this->withinBudget($start)) {
            $this->maintenanceStrategy->execute($context);
            $maintenanceSwept = true;
        } elseif (! $budgetExhausted) {
            $budgetExhausted = true;
        }

        $elapsed = microtime(true) - $start;

        $result = new ProcessingCycleResult(
            workerId:         $this->workerId,
            relayed:          $relayed,
            dispatched:       $dispatched,
            projected:        $projected,
            maintenanceSwept: $maintenanceSwept,
            budgetExhausted:  $budgetExhausted,
            elapsedSeconds:   $elapsed,
        );

        // ---- Persist the per-cycle heartbeat (DECISION P schema; status set per DECISION X). ----
        $this->heartbeatPublisher->publish(new HeartbeatRecord(
            workerId:        $this->workerId,
            status:          $result->didWork() ? 'running' : 'idle',
            lastHeartbeatAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            workerType:      $this->workerType,
            startedAt:       $startedAt,
        ));

        // ---- Structured emission of the cycle metric (DECISION Q clause 2). ----
        if ($this->logger !== null) {
            $payload = $result->toArray();
            if ($this->counters !== null) {
                $payload['counters'] = $this->counters->snapshot();
            }
            $payload['worker_type'] = $this->workerType;
            $this->logger->metric('processing.cycle', $payload);
        }

        return $result;
    }

    public function getWorkerId(): string
    {
        return $this->workerId;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /** True while the cycle is still inside its execution-time budget (Doc 8 v2.0 §12). */
    private function withinBudget(float $start): bool
    {
        return (microtime(true) - $start) < $this->cycleTimeBudgetSeconds;
    }

    /**
     * Generate a UUIDv7 for the per-cycle processing-component identity
     * (ADR-015, OPEN-3 v1.1 canon; DECISION X ruling (1) — fresh per cycle).
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
