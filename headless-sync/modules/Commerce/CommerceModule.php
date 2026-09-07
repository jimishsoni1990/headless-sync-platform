<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce;

use HSP\Core\Contracts\MigrationInterface;
use HSP\Core\Contracts\ModuleInterface;
use HSP\Core\Contracts\ServiceProviderInterface;
use HSP\Modules\Commerce\Events\CommerceEventTypes;
use HSP\Modules\Commerce\Rest\CommerceRestRegistrarFactory;
use HSP\Modules\Commerce\Subscribers\CommerceSubscriberRegistrar;

/**
 * Commerce module entry point — the platform's SECOND domain module.
 *
 * Constructor injection only (ADR-012): no Container reference, no global access.
 *
 * Everything here walks the seams P2-S1 built rather than adding new ones. Nothing in `core/`
 * mentions Commerce; this module is reached entirely through module.json, the generic provider
 * composition, and the core-owned registries.
 */
final class CommerceModule implements ModuleInterface
{
    /**
     * @param \Closure(): list<MigrationInterface> $migrationsFactory builds the Commerce
     *        migrations LAZILY — resolving them eagerly opens the libpq DDL link at module
     *        construction, which fatals on an unconfigured site (FLAG-P1AS6A-5).
     */
    public function __construct(
        private readonly HookWiring $hookWiring,
        private readonly CommerceRestRegistrarFactory $restRegistrarFactory,
        private readonly CommerceSubscriberRegistrar $subscriberRegistrar,
        private readonly \Closure $migrationsFactory,
    ) {
    }

    public function getName(): string
    {
        return 'commerce';
    }

    /**
     * The real provider — the same class the composition root instantiates from module.json's
     * `service_provider` key, so the two paths cannot drift (AG-1).
     */
    public function getServiceProvider(): ServiceProviderInterface
    {
        return new CommerceServiceProvider();
    }

    /** @return MigrationInterface[] */
    public function getMigrations(): array
    {
        return ($this->migrationsFactory)();
    }

    /** @return string[] */
    public function getEventTypes(): array
    {
        return CommerceEventTypes::ALL;
    }

    /**
     * Hooks and event handlers, before boot() — so a worker ticking during this request can
     * already resolve a Commerce handler from the EventRegistry.
     */
    public function register(): void
    {
        $this->hookWiring->register();
        $this->subscriberRegistrar->register();
    }

    /**
     * REST registration is DEFERRED to `rest_api_init`.
     *
     * Building the registrar reaches the delivery PostgreSQL connection, and `rest_api_init`
     * fires on every REST request to the site — `wp/v2` and the block editor included. A
     * static closure captures only the factory, so no container reference and no `$this`
     * leak into the hook (FLAG-P1AS6-2 Gap C; LAZYPG-S1).
     */
    public function boot(): void
    {
        if (! function_exists('add_action')) {
            return;
        }

        $factory = $this->restRegistrarFactory;

        add_action('rest_api_init', static function () use ($factory): void {
            ($factory)()->register();
        });
    }

    /**
     * Deliberate no-op. Migrations run through the shared engine
     * (Application::activate() → MigrationApplier), which reads getMigrations(). Running them
     * here would create a second migration path.
     */
    public function activate(): void
    {
    }

    /** Deliberate no-op — migrations are never rolled back and data is never dropped. */
    public function deactivate(): void
    {
    }

    /** Deliberate no-op, same reason as activate(). */
    public function upgrade(): void
    {
    }
}
