<?php

declare(strict_types=1);

namespace HSP\Core\Contracts;

/**
 * Lifecycle and discovery contract for a business-domain module.
 *
 * This interface is the UNION of declarative discovery methods (used by the module
 * registry at boot) and WordPress lifecycle methods (called by the registry in order).
 * Neither set replaces the other — OPEN-9 (v1.4), supersedes Doc 2 §12.
 *
 * Module isolation rules (CLAUDE.md rule 5):
 *   - Modules own domain logic; core/ owns contracts and infrastructure.
 *   - Module-to-module imports are prohibited.
 *   - Modules depend on core/Contracts/ only.
 *
 * Lifecycle call order by the module registry:
 *   register() → boot() → [on activation] activate() → [on deactivation] deactivate()
 *                       → [on version bump] upgrade()
 */
interface ModuleInterface
{
    // -------------------------------------------------------------------------
    // Declarative discovery — used by the module registry at boot
    // -------------------------------------------------------------------------

    /** Unique module name, e.g. 'content', 'commerce'. */
    public function getName(): string;

    /** Returns the ServiceProviderInterface for this module's DI bindings. */
    public function getServiceProvider(): ServiceProviderInterface;

    /**
     * Returns the MigrationInterface instances owned by this module.
     *
     * @return MigrationInterface[]
     */
    public function getMigrations(): array;

    /**
     * Returns the fully-qualified event types emitted by this module.
     *
     * @return string[] e.g. ['content.post.created', 'content.post.updated', ...]
     */
    public function getEventTypes(): array;

    // -------------------------------------------------------------------------
    // WordPress lifecycle — called by the module registry in order
    // -------------------------------------------------------------------------

    /**
     * Register DI bindings and WordPress hooks.
     * Called before boot(); no other module's bindings are guaranteed to exist yet.
     * Must not call boot-time services (use boot() for that).
     */
    public function register(): void;

    /**
     * Called after all modules have registered.
     * Safe to interact with other modules' registered services via constructor injection.
     * Must not perform install operations (use activate() for that).
     */
    public function boot(): void;

    /**
     * Called on plugin activation (first install or re-activation).
     * Responsibilities: run migrations, seed configuration, register capabilities.
     * Must be idempotent — WordPress may call this multiple times across network installs.
     */
    public function activate(): void;

    /**
     * Called on plugin deactivation.
     * Responsibilities: remove runtime registrations (cron events, rewrite rules).
     * Must NOT drop data or schema — deactivation is reversible.
     */
    public function deactivate(): void;

    /**
     * Called on plugin version bump (upgrade).
     * Responsibilities: run pending migrations, apply version-specific data transforms.
     * Must be idempotent — safe to run if already at the current version.
     */
    public function upgrade(): void;
}
