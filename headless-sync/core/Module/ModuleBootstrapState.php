<?php

declare(strict_types=1);

namespace HSP\Core\Module;

/**
 * Per-module data-bootstrap lifecycle state (DECISION AG AG-12 (c)).
 *
 * The global `hsp_onboarding_state` is not sufficient: an installation may already be
 * globally onboarded when a NEW domain module becomes available, and that module's existing
 * source data still has to converge. Without this, a Phase 2 upgrade would synchronise only
 * future edits and leave the existing catalog absent until reconciliation happened to reach
 * it — a silent violation of zero-configuration operation (ADR-054 Principle 8).
 *
 * ONE WORDPRESS OPTION PER MODULE, deliberately. AG-12 authorises either a single
 * module-keyed map or one option per module, and requires that updating one module's state
 * never erase a sibling's. A single serialized map is read-modify-write, which is exactly
 * where a concurrent update loses the other module's value; separate option rows make the
 * guarantee structural rather than a matter of careful sequencing.
 *
 * This is LIFECYCLE state, not metrics and not projection data: no PostgreSQL table is
 * authorised for it (AG-12), and `system.module_versions` must not be repurposed to hold it —
 * that remains schema/module-version metadata under AG-6.
 */
final class ModuleBootstrapState
{
    /** Bootstrap has not run, or has not yet converged. */
    public const PENDING = 'pending';

    /** The module's existing source data has converged into its projections. */
    public const COMPLETE = 'complete';

    private const OPTION_PREFIX = 'hsp_module_bootstrap_';

    /**
     * The module has never been evaluated — distinct from PENDING, which means evaluated
     * and awaiting convergence.
     */
    public const UNKNOWN = 'unknown';

    public function get(string $moduleName): string
    {
        if (! function_exists('get_option')) {
            return self::UNKNOWN;
        }

        $value = get_option(self::optionName($moduleName), self::UNKNOWN);

        return is_string($value) && $value !== '' ? $value : self::UNKNOWN;
    }

    public function isComplete(string $moduleName): bool
    {
        return $this->get($moduleName) === self::COMPLETE;
    }

    public function markPending(string $moduleName): void
    {
        $this->set($moduleName, self::PENDING);
    }

    public function markComplete(string $moduleName): void
    {
        $this->set($moduleName, self::COMPLETE);
    }

    private function set(string $moduleName, string $state): void
    {
        if (! function_exists('update_option')) {
            return;
        }

        // autoload 'no': read on demand during the lifecycle evaluation, not on every
        // WordPress page load.
        update_option(self::optionName($moduleName), $state, false);
    }

    public static function optionName(string $moduleName): string
    {
        return self::OPTION_PREFIX . $moduleName;
    }
}
