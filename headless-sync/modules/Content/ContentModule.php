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
    public function __construct(
        private readonly HookWiring                    $hookWiring,
        private readonly EventProviderInterface        $eventProvider,
        private readonly ContentRestRegistrarFactory   $restRegistrarFactory,
        private readonly ContentSubscriberRegistrar    $subscriberRegistrar,
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
        // Content migrations delivered in P1A-S4.
        return [];
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
        // Migrations run here in P1A-S4; no-op for now.
    }

    public function deactivate(): void
    {
        // No runtime registrations to remove at this scope.
    }

    public function upgrade(): void
    {
        // Pending migrations run here in P1A-S4; no-op for now.
    }
}
