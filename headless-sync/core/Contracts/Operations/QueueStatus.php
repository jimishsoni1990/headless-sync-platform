<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Operations;

/**
 * Immutable, current-state view of the queue (Doc 12 §12; DECISION Q).
 *
 * Derived on-demand from existing tables (system.queue_jobs / system.dead_letter_jobs)
 * at read time — ZERO new persistence (DECISION Q / DECISION V (c)). Point-in-time depths
 * and the oldest-pending age; no history, no rollup.
 *
 * Fields:
 *   $depth            — number of pending/claimable jobs at read time.
 *   $deadLetterDepth  — number of dead-lettered jobs at read time.
 *   $oldestPendingAge — age of the oldest pending job at read time, or null when empty.
 *
 * @psalm-immutable
 */
final class QueueStatus
{
    public function __construct(
        public readonly int $depth,
        public readonly int $deadLetterDepth,
        public readonly ?\DateInterval $oldestPendingAge,
    ) {}
}
