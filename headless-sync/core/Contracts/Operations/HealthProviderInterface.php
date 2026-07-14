<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Operations;

/**
 * Supplies current-state health reports for the console (ADR-049 / Doc 12 §11).
 *
 * Current operational state ONLY — no history (ADR-049). Concrete implementations (OPSC-S2)
 * read live state (e.g. via the delivery DatabaseConnectionInterface — DECISION V (g) — no
 * fifth handle, no new pg_* wrapper) and return immutable HealthReport DTOs. OPSC-S1 is the
 * contract only.
 */
interface HealthProviderInterface extends OperationsProviderInterface
{
    /**
     * Current health reports, one per component this provider covers.
     *
     * @return HealthReport[]
     */
    public function reports(): array;
}
