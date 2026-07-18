<?php

declare(strict_types=1);

namespace HSP\Core\Workers;

/**
 * Pluggable stage strategy invoked by the WP-Cron Processing Engine cycle (ADR-054).
 *
 * A strategy is a bounded batch primitive the cycle composes as a stage (dispatch,
 * projection, maintenance). The cycle calls execute() once per bounded step and never
 * loops-to-empty; a backlog larger than one cycle is continued by the next cron cycle.
 *
 * Authority: Doc 8 §7 — standard per-event pipeline (for the projection stage):
 *   Claim → Load Event → Create WorkerExecutionContext
 *   → Validate → Resolve Subscriber → Execute Handler
 *   → Commit State → Acknowledge Job
 *
 * ADR-044 — implementations are stateless; any state they need must be
 * injected via constructor and must not accumulate across cycles.
 *
 * DECISION E — strategies must NOT introduce a new raw pg_* wrapper.
 * PostgreSQL access goes through QueueProviderInterface or an existing
 * runtime connection.
 */
interface WorkerStrategyInterface
{
    /**
     * Execute one bounded unit of work for this stage.
     *
     * Returns true if work was found and processed (or attempted); false if the
     * strategy's source was empty. The projection stage calls this in a bounded loop
     * (up to projection_batch_size / the cycle time budget); a false return means the
     * queue drained and the stage stops. The strategy is responsible for claiming,
     * executing, and acknowledging (complete / release / deadLetter) its own job via
     * QueueProviderInterface.
     */
    public function execute(WorkerExecutionContext $context): bool;

    /**
     * The queue partition(s) this strategy consumes (e.g. ['content'], ['system']).
     *
     * @return string[]
     */
    public function getQueueNames(): array;
}
