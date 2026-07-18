<?php

declare(strict_types=1);

namespace HSP\Core\Contracts;

use HSP\Core\Workers\ProcessingCycleResult;

/**
 * Contract for the WP-Cron Processing Engine cycle.
 *
 * ADR-054 (Doc 8 v2.0) — background processing is advanced by a WP-Cron-triggered
 * Processing Engine that runs ONE bounded, stateless cycle per invocation and exits
 * cleanly. There is no daemon lifecycle: a cycle is a single PHP execution that
 * advances the pipeline by bounded per-stage batches, honours an execution-time
 * budget, records a per-cycle heartbeat/metrics, and returns.
 *
 * Contract shape (DECISION X, v1.24 — ruling (3), Option A / architectural correction):
 *   The prior daemon surface run()/shutdown() is REMOVED. This is an INTERNAL core
 *   contract (no module implements it — it is core processing infrastructure). The
 *   contract expresses exactly one bounded processing cycle: execute one cycle,
 *   honour the configured per-stage batch limits + execution-time budget, and return
 *   a ProcessingCycleResult describing the completed cycle.
 *
 * Identity (DECISION X, ruling (1)): a fresh UUIDv7 is minted per cycle at bootstrap
 * (Doc 8 v2.0 §24) — worker_id is a per-cycle processing-component identity, not a
 * daemon lifetime identity. getWorkerId() returns the identity of the LAST run cycle.
 *
 * Naming (ADR-054 §8): the WorkerInterface/WorkerEngine names are retained as
 * processing components invoked by WP-Cron — no churn-only rename.
 */
interface WorkerInterface
{
    /**
     * Run exactly ONE bounded Processing Engine cycle and exit.
     *
     * The cycle mints a fresh per-cycle worker_id, advances the pipeline by bounded
     * per-stage batches (relay → dispatch → projection → maintenance), honours the
     * configured execution-time budget (stops claiming new work at the budget,
     * finishing the in-flight event's single DECISION 3 transaction first), records a
     * per-cycle heartbeat, and returns. It does NOT loop-to-empty and does NOT sleep;
     * a backlog larger than one cycle is continued by the next cron execution.
     *
     * @return ProcessingCycleResult A description of the completed cycle.
     */
    public function runCycle(): ProcessingCycleResult;

    /** Returns the UUIDv7 identity of the most recently run cycle (per-cycle, DECISION X). */
    public function getWorkerId(): string;
}
