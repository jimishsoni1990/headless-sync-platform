<?php

declare(strict_types=1);

namespace HSP\Core\Workers;

/**
 * Pluggable strategy executed by WorkerEngine on each tick.
 *
 * Authority: Doc 8 §7 — standard pipeline:
 *   Claim → Load Event → Create WorkerExecutionContext
 *   → Validate → Resolve Subscriber → Execute Handler
 *   → Commit State → Acknowledge Job
 *
 * ADR-044 — implementations are stateless; any state they need must be
 * injected via constructor and must not accumulate across ticks.
 *
 * DECISION E — strategies must NOT introduce a new raw pg_* wrapper.
 * PostgreSQL access goes through QueueProviderInterface or an existing
 * runtime connection. Consolidation to a shared DatabaseConnectionInterface
 * is authorised in P0-S7 only.
 */
interface WorkerStrategyInterface
{
    /**
     * Execute one unit of work.
     *
     * Returns true if work was found and processed (or attempted); false if
     * the strategy's source queue was empty and the engine should back off.
     *
     * The engine calls this inside its tick() loop. The strategy is responsible
     * for claiming, executing, and acknowledging (complete / release / deadLetter)
     * its own job via QueueProviderInterface.
     */
    public function execute(WorkerExecutionContext $context): bool;

    /**
     * The queue partition(s) this strategy consumes (e.g. ['content'], ['system']).
     *
     * @return string[]
     */
    public function getQueueNames(): array;
}
