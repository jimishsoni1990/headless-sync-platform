<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Operations;

/**
 * Supplies current-state queue status for the console (Doc 12 §12; DECISION Q).
 *
 * Derived on-demand from existing tables (system.queue_jobs / system.dead_letter_jobs) at
 * read time — ZERO new persistence. Concrete implementation is OPSC-S2; OPSC-S1 is the
 * contract only.
 */
interface QueueStatusProviderInterface extends OperationsProviderInterface
{
    /**
     * Current queue status (depths + oldest-pending age), derived at read time.
     */
    public function status(): QueueStatus;
}
