<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Operations;

/**
 * Supplies current-state worker status for the console (Doc 12 §12/§13; DECISION P).
 *
 * Derived from the current-state heartbeat rows (DECISION P — no history); "online" is a
 * heartbeat-age comparison at read time. Read-only surface: worker lifecycle belongs to the
 * process supervisor, NOT the console (DECISION V (f)). Concrete implementation is OPSC-S2.
 */
interface WorkerStatusProviderInterface extends OperationsProviderInterface
{
    /**
     * Current status for every worker known from heartbeat state.
     *
     * @return WorkerStatus[]
     */
    public function statuses(): array;
}
