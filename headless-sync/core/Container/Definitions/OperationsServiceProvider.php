<?php

declare(strict_types=1);

namespace HSP\Core\Container\Definitions;

use HSP\Core\Container\Container;
use HSP\Core\Container\ServiceProvider;
use HSP\Core\Contracts\Operations\ActionRegistryInterface;
use HSP\Core\Contracts\Operations\AssetRegistryInterface;
use HSP\Core\Contracts\Operations\NavigationRegistryInterface;
use HSP\Core\Contracts\Operations\PageRegistryInterface;
use HSP\Core\Contracts\Operations\WidgetRegistryInterface;
use HSP\Core\Operations\Registries\ActionRegistry;
use HSP\Core\Operations\Registries\AssetRegistry;
use HSP\Core\Operations\Registries\NavigationRegistry;
use HSP\Core\Operations\Registries\PageRegistry;
use HSP\Core\Operations\Registries\WidgetRegistry;
use HSP\Core\Operations\Services\ConsoleStateStore;
use HSP\Core\Operations\Services\OperationsService;
use HSP\Core\Operations\Services\RefreshCoordinator;

/**
 * Registers the Operations Console core scaffolding (OPSC-S1; DECISION V (a),(h),(i)).
 *
 * Bindings (all singletons, all constructor-injected — ADR-012 / Rule 7):
 *   PageRegistryInterface        — PageRegistry (explicit registration only)
 *   NavigationRegistryInterface  — NavigationRegistry
 *   WidgetRegistryInterface      — WidgetRegistry
 *   ActionRegistryInterface      — ActionRegistry (discovery only; no action behaviour — OPSC-S4)
 *   AssetRegistryInterface       — AssetRegistry
 *   ConsoleStateStore            — request-scope snapshot cache (zero persistence)
 *   RefreshCoordinator           — centralizes provider refresh into the state store
 *   OperationsService            — the single UI-facing seam (ADR-053); fronts registries + providers
 *
 * READ-ONLY SCAFFOLDING. This provider wires NO concrete Health/Metrics/Worker/Queue/Endpoint
 * provider (that is OPSC-S2), NO UI/admin code (OPSC-S3), and NO operational action behaviour
 * (OPSC-S4). It opens NO PostgreSQL connection and introduces NO pg_* wrapper and NO new PG
 * handle — the four-handle topology (DECISION L Ruling 0) is unchanged. When concrete
 * providers arrive in OPSC-S2 they will read through the delivery DatabaseConnectionInterface
 * (DECISION V (g)) and register with the RefreshCoordinator; nothing here anticipates a fifth
 * handle.
 *
 * Authority:
 *   DECISION V (a) server-rendered model (store/coordinator are request-scope PHP, not a JS
 *     state store); (h) contracts under core/Contracts/Operations/; (i) core/Operations/ subtree.
 *   ADR-047 (console is core infrastructure); ADR-048/ADR-052 (registry-driven, explicit
 *     registration, no reflection); ADR-053 (read-only by default — UI reaches only OperationsService).
 *   CLAUDE.md Rule 5 (modules depend on core/Contracts/ only) / Rule 7 (constructor injection).
 */
final class OperationsServiceProvider extends ServiceProvider
{
    public function register(object $container): void
    {
        assert($container instanceof Container);

        // Registries — explicit-registration discovery surfaces (ADR-048/ADR-052).
        $container->singleton(PageRegistryInterface::class, fn () => new PageRegistry());
        $container->singleton(NavigationRegistryInterface::class, fn () => new NavigationRegistry());
        $container->singleton(WidgetRegistryInterface::class, fn () => new WidgetRegistry());
        $container->singleton(ActionRegistryInterface::class, fn () => new ActionRegistry());
        $container->singleton(AssetRegistryInterface::class, fn () => new AssetRegistry());

        // Services — request-scope aggregation; zero persistence, zero PG (DECISION V (a)/(c)/(g)).
        // Request-scope by virtue of PHP request lifecycle (singleton per request). Do NOT
        // resolve in long-running worker context — state would leak across jobs.
        $container->singleton(ConsoleStateStore::class, fn () => new ConsoleStateStore());

        // Request-scope by virtue of PHP request lifecycle (singleton per request). Do NOT
        // resolve in long-running worker context — state would leak across jobs.
        $container->singleton(
            RefreshCoordinator::class,
            fn (Container $c) => new RefreshCoordinator(
                $c->get(ConsoleStateStore::class),
            ),
        );

        // The single UI-facing seam (ADR-053) — fronts registries + provider snapshots.
        $container->singleton(
            OperationsService::class,
            fn (Container $c) => new OperationsService(
                $c->get(PageRegistryInterface::class),
                $c->get(NavigationRegistryInterface::class),
                $c->get(WidgetRegistryInterface::class),
                $c->get(ActionRegistryInterface::class),
                $c->get(AssetRegistryInterface::class),
                $c->get(RefreshCoordinator::class),
                $c->get(ConsoleStateStore::class),
            ),
        );
    }
}
