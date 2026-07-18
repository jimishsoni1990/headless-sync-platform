<?php

declare(strict_types=1);

namespace HSP\Modules\Content;

use HSP\Core\Contracts\EventProviderInterface;
use HSP\Core\Contracts\MigrationInterface;
use HSP\Core\Contracts\ModuleInterface;
use HSP\Core\Contracts\ServiceProviderInterface;
use HSP\Modules\Content\Events\ContentEventTypes;
use HSP\Modules\Content\Rest\ContentRestRegistrarFactory;
use HSP\Modules\Content\Subscribers\ContentSubscriberRegistrar;

/**
 * Content module entry point — implements the ModuleInterface union shape (OPEN-9 v1.4).
 *
 * Constructor injection only (ADR-012); no Container::get() or global access.
 * ContentSubscriberRegistrar wires all 9 OPEN-1 event handlers into EventRegistry
 * during register() so EventWorkerStrategy can dispatch events at worker time.
 */
final class ContentModule implements ModuleInterface
{
    /**
     * @param \Closure(): list<MigrationInterface> $migrationsFactory builds the content migrations
     *        (over the pgsql migration connection) LAZILY — resolving them eagerly would open the
     *        libpq DDL link at module-construction time, which fatals on an unconfigured site. The
     *        closure keeps ContentModule free of a Container reference (ADR-012 / FLAG-P1AS6A-5),
     *        matching the ContentRestRegistrarFactory / ContentSubscriberRegistrar pattern.
     */
    public function __construct(
        private readonly HookWiring                    $hookWiring,
        private readonly EventProviderInterface        $eventProvider,
        private readonly ContentRestRegistrarFactory   $restRegistrarFactory,
        private readonly ContentSubscriberRegistrar    $subscriberRegistrar,
        private readonly \Closure                      $migrationsFactory,
    ) {}

    // -------------------------------------------------------------------------
    // Declarative discovery
    // -------------------------------------------------------------------------

    public function getName(): string
    {
        return 'content';
    }

    public function getServiceProvider(): ServiceProviderInterface
    {
        // Service provider delivered in a later P1A session.
        // Returning a no-op provider satisfies the interface contract.
        return new class implements ServiceProviderInterface {
            public function register(object $container): void {}
            public function boot(object $container): void {}
        };
    }

    /**
     * @return MigrationInterface[]
     */
    public function getMigrations(): array
    {
        // Content projection migrations (0001_create_content_schema … 0005_create_content_entity_taxonomies),
        // built lazily over the pgsql migration connection. The onboarding MigrationApplier collects
        // these via the module registry's declarative discovery (OPEN-9) so core never imports a
        // module migration class (Rule 5).
        return ($this->migrationsFactory)();
    }

    /**
     * @return string[]
     */
    public function getEventTypes(): array
    {
        return ContentEventTypes::ALL;
    }

    // -------------------------------------------------------------------------
    // WordPress lifecycle
    // -------------------------------------------------------------------------

    public function register(): void
    {
        $this->hookWiring->register();
        $this->subscriberRegistrar->register();
    }

    public function boot(): void
    {
        // Invoke the factory inside rest_api_init so the PG connection is only
        // opened when WordPress fires the hook — not at module load time
        // (FLAG-P1AS6-2 Gap C fix; FLAG-P1AS6A-5 fix; Doc 9 §7).
        $factory = $this->restRegistrarFactory;
        add_action('rest_api_init', static function () use ($factory): void {
            ($factory)()->register();
        });
    }

    public function activate(): void
    {
        // Content migrations are applied through the SHARED migration engine (Application::activate()
        // → MigrationApplier), which collects them via getMigrations() (Rule 5 / OPEN-9). Running
        // them here too would open a second migration path, so this stays a no-op — the module
        // declares its migrations; the engine applies them.
    }

    public function deactivate(): void
    {
        // No runtime registrations to remove at this scope. Migrations are never rolled back on
        // deactivate (OPEN-9 — do NOT drop data).
    }

    public function upgrade(): void
    {
        // Pending content migrations are applied through the SHARED engine on plugin version bump
        // (Application::upgrade() → MigrationApplier), not here — single migration path (see
        // activate()).
    }
}
