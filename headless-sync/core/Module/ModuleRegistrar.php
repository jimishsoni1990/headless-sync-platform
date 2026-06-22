<?php

declare(strict_types=1);

namespace HSP\Core\Module;

/**
 * Ties the ModuleRegistry into the WordPress plugin lifecycle.
 *
 * Called from the plugin entry point (headless-sync.php) or bootstrap:
 *   $registrar->registerAll();  — on every page load (discover + register + boot)
 *   $registrar->activate();     — on register_activation_hook
 *   $registrar->deactivate();   — on register_deactivation_hook
 *   $registrar->upgrade();      — on upgrade_old_db_version / version-bump detection
 *
 * Constructor injection only — ADR-012.
 */
final class ModuleRegistrar
{
    public function __construct(private readonly ModuleRegistry $registry) {}

    /**
     * Runs the normal boot sequence: discover → register all → boot all.
     *
     * boot() is called only after ALL modules have registered (OPEN-9 v1.4).
     */
    public function registerAll(): void
    {
        $this->registry->register();
        $this->registry->boot();
    }

    public function activate(): void
    {
        $this->registry->activate();
    }

    public function deactivate(): void
    {
        $this->registry->deactivate();
    }

    public function upgrade(): void
    {
        $this->registry->upgrade();
    }
}
