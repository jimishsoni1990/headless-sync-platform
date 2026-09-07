<?php

declare(strict_types=1);

namespace HSP\Core\Module;

/**
 * Drives a module from AVAILABLE to READY to bootstrapped (DECISION AG AG-12).
 *
 * The three conditions AG-12 separates:
 *
 *   DISCOVERED      a module.json exists                          — ModuleDiscovery
 *   AVAILABLE       external runtime requirements are satisfied   — ModuleProviderComposer
 *   READY / ACTIVE  its required migrations have applied          — HERE
 *
 * and, tracked separately, data bootstrap: pending → complete.
 *
 * Availability is NOT readiness. Conflating them leaves a window in which a module counts as
 * active while its schema may not exist, so an endpoint or a projection consumer could run
 * against missing tables. If migrations fail the module stays not-ready, every other module
 * keeps operating, and the failure surfaces through the existing migration diagnostics.
 *
 * THE CASE THIS EXISTS FOR: HSP already installed, global onboarding already complete, and
 * then WooCommerce is activated. Nothing about that sequence involves reactivating HSP, so
 * without an explicit lifecycle seam only FUTURE edits would ever synchronise and the
 * existing catalog would sit unprojected until reconciliation happened to reach it. The
 * transition therefore lives here in core — never hidden inside a module's service provider —
 * and requires no deactivate/reactivate, no manual migrate and no manual reconcile.
 *
 * Bootstrap repair is delegated, never reimplemented: convergence runs through the ratified
 * ReconciliationService re-emission path (DECISION T/U). No direct WordPress→PostgreSQL copy,
 * no second repair path, no in-request queue drain, and global onboarding state is never
 * reset — Content's API and Operations surfaces stay online while a new module bootstraps.
 *
 * Constructor injection only (ADR-012). Opens no PG handle of its own.
 */
final class ModuleLifecycleCoordinator
{
    /**
     * @param \Closure(string): bool $migrationsApplied Reports whether the named module's
     *        declared migrations are all present in system.schema_versions — the AUTHORITATIVE
     *        readiness signal (AG-6: module_versions must not be used for this). Injected as a
     *        closure so core neither imports a module nor opens a connection at construction:
     *        the DDL link throws when PostgreSQL is unreachable, and an unconfigured site must
     *        never fatal (ADR-054 Principle 8).
     * @param \Closure(string, string): void $recordVersion Records the module's declared schema
     *        version once it is genuinely ready (AG-6).
     */
    public function __construct(
        private readonly ModuleBootstrapState $state,
        private readonly \Closure $migrationsApplied,
        private readonly \Closure $recordVersion,
    ) {
    }

    /**
     * Evaluate one module and return the lifecycle state it now holds.
     *
     * Safe to call on every request: it performs no work once a module is complete, and every
     * transition it does perform is idempotent.
     *
     * @param string $moduleName    Module name from its manifest.
     * @param string $schemaVersion Declared module schema version from its manifest.
     */
    public function evaluate(string $moduleName, string $schemaVersion): ModuleLifecycleStatus
    {
        // Already converged — nothing to do, and no migration probe worth paying for.
        if ($this->state->isComplete($moduleName)) {
            return new ModuleLifecycleStatus($moduleName, true, ModuleBootstrapState::COMPLETE);
        }

        if (! $this->isReady($moduleName)) {
            // Available but not ready: migrations have not applied (or PostgreSQL is
            // unreachable). Deliberately NOT marked pending — pending means "ready and
            // awaiting convergence", and claiming it here would let a caller start a
            // backfill against schema that does not exist yet.
            return new ModuleLifecycleStatus($moduleName, false, $this->state->get($moduleName));
        }

        // Ready. Record the declared schema version — only now, never before the batch
        // succeeded (AG-6).
        ($this->recordVersion)($moduleName, $schemaVersion);

        if ($this->state->get($moduleName) === ModuleBootstrapState::UNKNOWN) {
            // First time this module has ever been ready: its existing source data has not
            // been projected, so it owes a bootstrap.
            $this->state->markPending($moduleName);
        }

        return new ModuleLifecycleStatus($moduleName, true, $this->state->get($moduleName));
    }

    /** Mark a module converged once its bootstrap has genuinely finished. */
    public function markBootstrapComplete(string $moduleName): void
    {
        $this->state->markComplete($moduleName);
    }

    /**
     * A module whose global onboarding already covered it (a fresh install where onboarding
     * ran with the module active) is complete without a second, duplicate backfill — AG-12's
     * explicit carve-out.
     */
    public function adoptGlobalOnboarding(string $moduleName): void
    {
        if ($this->state->get($moduleName) !== ModuleBootstrapState::COMPLETE) {
            $this->state->markComplete($moduleName);
        }
    }

    private function isReady(string $moduleName): bool
    {
        try {
            return ($this->migrationsApplied)($moduleName);
        } catch (\Throwable) {
            // An unreachable or unconfigured PostgreSQL is a normal state on a fresh site,
            // not an error to propagate into a page load (ADR-054 Principle 8).
            return false;
        }
    }
}
