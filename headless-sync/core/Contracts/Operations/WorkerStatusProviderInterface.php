<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Operations;

/**
 * Supplies current-state processing-cycle status for the console (Doc 12 §12/§13; DECISION P;
 * ADR-054 §5).
 *
 * Derived from the current-state heartbeat rows (DECISION P — no history); under the ADR-054
 * WP-Cron cycle model each row is a processing-cycle execution (fresh UUIDv7 per cycle —
 * DECISION X ruling (1)), and "online" is CYCLE-FRESHNESS at read time ("this cycle ran within
 * the freshness window", not "a daemon is up"). Read-only surface: there is no supervised worker
 * to control; when cycles are not advancing the remediation is WP-Cron, never a Restart Workers
 * action (DECISION V (f)). Concrete implementation is OPSC-S2.
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
