<?php

declare(strict_types=1);

namespace HSP\Modules\Content;

use HSP\Core\Contracts\EventProviderInterface;
use HSP\Core\Contracts\MigrationInterface;
use HSP\Core\Contracts\ModuleInterface;
use HSP\Core\Contracts\ServiceProviderInterface;
use HSP\Modules\Content\Events\ContentEventTypes;

/**
 * Content module entry point — implements the ModuleInterface union shape (OPEN-9 v1.4).
 *
 * P1A-S1 scope: event types + WP hook wiring only.
 * Migrations and full service provider are delivered in later P1A sessions.
 *
 * Constructor injection only (ADR-012); no Container::get() or global access.
 */
final class ContentModule implements ModuleInterface
{
    public function __construct(
        private readonly HookWiring            $hookWiring,
        private readonly EventProviderInterface $eventProvider,
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
    }

    public function boot(): void
    {
        // Nothing to do at boot for P1A-S1 scope.
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
