<?php

declare(strict_types=1);

namespace HSP\Core\Module;

use HSP\Core\Contracts\ModuleInterface;
use HSP\Core\Module\Exception\InvalidManifestException;

/**
 * Orchestrates module discovery, loading, and the WP lifecycle sequence.
 *
 * Lifecycle order (OPEN-9 v1.4):
 *   1. All modules → register()        (bindings + WP hooks; may not depend on other modules)
 *   2. All modules → boot()            (safe to interact with other modules' bindings)
 *   3. activate() / deactivate() / upgrade() are called explicitly (not during normal boot)
 *
 * "boot() runs only after ALL modules have registered" — DoD requirement.
 *
 * Constructor injection only — ADR-012. No service-locator calls.
 */
final class ModuleRegistry
{
    /** @var ModuleInterface[] Modules indexed by name, in discovery order. */
    private array $modules = [];

    /** @var bool Guards against double-registration. */
    private bool $registered = false;

    public function __construct(
        private readonly ModuleDiscovery $discovery,
        private readonly ModuleLoader $loader,
    ) {}

    /**
     * Discovers all modules and calls register() on each one.
     *
     * Must be called exactly once before boot().
     *
     * @throws InvalidManifestException
     */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $manifests = $this->discovery->discover();

        foreach ($manifests as $manifest) {
            $module = $this->loader->load($manifest);
            $this->modules[$manifest->name] = $module;
        }

        // Phase 1: register() on ALL modules before any boot()
        foreach ($this->modules as $module) {
            $module->register();
        }

        $this->registered = true;
    }

    /**
     * Calls boot() on all registered modules.
     *
     * Must be called after register() has completed for all modules.
     */
    public function boot(): void
    {
        foreach ($this->modules as $module) {
            $module->boot();
        }
    }

    /**
     * Calls activate() on all registered modules.
     * Invoked on WordPress plugin activation hook.
     */
    public function activate(): void
    {
        foreach ($this->modules as $module) {
            $module->activate();
        }
    }

    /**
     * Calls deactivate() on all registered modules.
     * Invoked on WordPress plugin deactivation hook.
     */
    public function deactivate(): void
    {
        foreach ($this->modules as $module) {
            $module->deactivate();
        }
    }

    /**
     * Calls upgrade() on all registered modules.
     * Invoked on plugin version bump.
     */
    public function upgrade(): void
    {
        foreach ($this->modules as $module) {
            $module->upgrade();
        }
    }

    /**
     * Returns all registered module instances, keyed by module name.
     *
     * @return array<string, ModuleInterface>
     */
    public function all(): array
    {
        return $this->modules;
    }

    /**
     * Returns a registered module by name, or null if not found.
     */
    public function get(string $name): ?ModuleInterface
    {
        return $this->modules[$name] ?? null;
    }
}
