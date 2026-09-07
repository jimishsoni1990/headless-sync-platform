<?php

declare(strict_types=1);

namespace HSP\Core\Module;

/**
 * Immutable result of one module lifecycle evaluation (DECISION AG AG-12).
 *
 * `$ready` is the condition that gates participation: a module may be AVAILABLE (its external
 * dependency is present) while still not ready, because its migrations have not applied. No
 * endpoint or projection consumer may run against a module that is not ready.
 */
final class ModuleLifecycleStatus
{
    /**
     * @param string $moduleName
     * @param bool   $ready          Migrations applied; runtime registrations may participate.
     * @param string $bootstrapState One of ModuleBootstrapState::UNKNOWN|PENDING|COMPLETE.
     */
    public function __construct(
        public readonly string $moduleName,
        public readonly bool $ready,
        public readonly string $bootstrapState,
    ) {
    }

    /** Ready, but its existing source data has not converged yet. */
    public function needsBootstrap(): bool
    {
        return $this->ready && $this->bootstrapState === ModuleBootstrapState::PENDING;
    }
}
