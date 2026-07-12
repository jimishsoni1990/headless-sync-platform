<?php

declare(strict_types=1);

namespace HSP\Core\Observability;

/**
 * In-process runtime counters for the worker loop (DECISION Q clause 2).
 *
 * Accumulates processed / retry / failure / replay counts within a single worker
 * process. NOT persisted (DECISION Q: no metrics table). The worker loop reads the
 * snapshot and emits it as a structured log event (StructuredLogger); the counters
 * reset when the process restarts.
 *
 * Shared by reference: the WorkerEngine holds one instance and passes it to the
 * strategy so per-job outcomes (complete/release/deadLetter/replay) increment the
 * same counters the loop then emits.
 */
final class WorkerCounters
{
    private int $processed = 0;
    private int $retry     = 0;
    private int $failure   = 0;
    private int $replay    = 0;

    public function incrementProcessed(): void { $this->processed++; }
    public function incrementRetry(): void     { $this->retry++; }
    public function incrementFailure(): void   { $this->failure++; }
    public function incrementReplay(): void    { $this->replay++; }

    /**
     * @return array{processed:int,retry:int,failure:int,replay:int}
     */
    public function snapshot(): array
    {
        return [
            'processed' => $this->processed,
            'retry'     => $this->retry,
            'failure'   => $this->failure,
            'replay'    => $this->replay,
        ];
    }
}
