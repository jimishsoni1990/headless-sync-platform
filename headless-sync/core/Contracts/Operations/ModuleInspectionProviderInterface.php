<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Operations;

/**
 * Supplies descriptive Module Inspector metadata for one module (Doc 12 §14).
 *
 * A module implements this to describe itself to the console (its version, event types,
 * endpoints, transformers, adapters, workers, provider/action keys). The Content module's
 * implementation lives in modules/Content/Operations/ behind this core-owned contract, so
 * CLAUDE.md Rule 5 ("modules depend on core/Contracts/ only") holds verbatim.
 *
 * DELIBERATELY NOT an OperationsProviderInterface: module inspection is a directly-queried,
 * static-per-request diagnostics surface — it is NOT registered with the RefreshCoordinator
 * and never enters the snapshot path (so the coordinator's five-known-kinds match is
 * unchanged — no OPSC-S1 edit). Returns descriptive metadata only; the implementation must
 * perform no live query (no PG/MySQL/WP read) to build its ModuleInspection.
 *
 * Additive OPSC-S2 contract; modifies no OPSC-S1 contract/DTO/registry. Rule 5: imports
 * nothing outside core/Contracts/.
 */
interface ModuleInspectionProviderInterface
{
    /**
     * Descriptive metadata for the module this provider represents.
     */
    public function inspect(): ModuleInspection;
}
