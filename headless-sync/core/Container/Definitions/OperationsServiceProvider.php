<?php

declare(strict_types=1);

namespace HSP\Core\Container\Definitions;

use HSP\Bootstrap\Version;
use HSP\Core\Container\Container;
use HSP\Core\Container\ServiceProvider;
use HSP\Core\Contracts\Operations\ActionRegistryInterface;
use HSP\Core\Contracts\Operations\AssetRegistryInterface;
use HSP\Core\Contracts\Operations\NavigationRegistryInterface;
use HSP\Core\Contracts\Operations\PageRegistryInterface;
use HSP\Core\Contracts\Operations\WidgetRegistryInterface;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Operations\Diagnostics\ModuleInspector;
use HSP\Core\Operations\Diagnostics\OperationsQueryReader;
use HSP\Core\Operations\Diagnostics\SystemInformationProvider;
use HSP\Core\Operations\Providers\HealthProvider;
use HSP\Core\Operations\Providers\MetricsProvider;
use HSP\Core\Operations\Providers\QueueStatusProvider;
use HSP\Core\Operations\Providers\WorkerStatusProvider;
use HSP\Core\Operations\Registries\ActionRegistry;
use HSP\Core\Operations\Registries\AssetRegistry;
use HSP\Core\Operations\Registries\NavigationRegistry;
use HSP\Core\Operations\Registries\PageRegistry;
use HSP\Core\Operations\Registries\WidgetRegistry;
use HSP\Core\Operations\Services\ConsoleStateStore;
use HSP\Core\Operations\Services\OperationsService;
use HSP\Core\Operations\Services\RefreshCoordinator;

/**
 * Registers the Operations Console scaffolding (OPSC-S1) and the concrete diagnostics /
 * metrics providers (OPSC-S2; DECISION V (c),(g),(h); DECISION P/Q; ADR-049).
 *
 * OPSC-S1 bindings (all singletons, constructor-injected — ADR-012 / Rule 7):
 *   PageRegistryInterface / NavigationRegistryInterface / WidgetRegistryInterface /
 *     ActionRegistryInterface / AssetRegistryInterface — explicit-registration registries.
 *   ConsoleStateStore / RefreshCoordinator / OperationsService — request-scope services.
 *
 * OPSC-S2 bindings (this session):
 *   OperationsQueryReader     — the single delivery-handle read seam (DECISION V (g)); reads
 *                               only, on the existing DatabaseConnectionInterface (no fifth
 *                               handle — DECISION L Ruling 0; no new pg_* wrapper — DECISION E).
 *   HealthProvider            — current-state health rollup (ADR-049).
 *   QueueStatusProvider       — queue/DLQ depths + oldest-pending age (DECISION Q).
 *   WorkerStatusProvider      — heartbeat-age liveness (DECISION P; read-only, DECISION V (f)).
 *   MetricsProvider           — derived-on-demand samples (DECISION Q / DECISION V (c)).
 *   SystemInformationProvider — Doc 12 §13 diagnostics (OPEN-8 version reads + env facts).
 *   ModuleInspector           — Doc 12 §14 aggregator; module descriptors register in boot().
 *
 * boot(): the four core providers self-register with the RefreshCoordinator so the console
 * gets one provider call per refresh (Doc 12 §7/§8). Module-provided providers (Content's
 * metrics/endpoint providers) register in ContentServiceProvider::boot(), which runs after
 * this provider's register() because OperationsServiceProvider is registered first. The
 * ModuleInspector is populated with module descriptors the same way (ContentServiceProvider).
 *
 * NO UI/admin code (OPSC-S3), NO action behaviour (OPSC-S4), NO migrations, NO new table.
 * Providers are READ-ONLY: OperationsQueryReader issues no DML.
 */
final class OperationsServiceProvider extends ServiceProvider
{
    /**
     * @param array<string,mixed> $config full platform config (worker.php under 'worker')
     */
    public function __construct(
        private readonly array $config = [],
    ) {}

    public function register(object $container): void
    {
        assert($container instanceof Container);

        // --- OPSC-S1 registries (explicit-registration discovery — ADR-048/ADR-052) ------
        $container->singleton(PageRegistryInterface::class, fn () => new PageRegistry());
        $container->singleton(NavigationRegistryInterface::class, fn () => new NavigationRegistry());
        $container->singleton(WidgetRegistryInterface::class, fn () => new WidgetRegistry());
        $container->singleton(ActionRegistryInterface::class, fn () => new ActionRegistry());
        $container->singleton(AssetRegistryInterface::class, fn () => new AssetRegistry());

        // --- OPSC-S1 request-scope services (zero persistence, zero PG) ------------------
        // Do NOT resolve in long-running worker context — state would leak across jobs.
        $container->singleton(ConsoleStateStore::class, fn () => new ConsoleStateStore());

        $container->singleton(
            RefreshCoordinator::class,
            fn (Container $c) => new RefreshCoordinator(
                $c->get(ConsoleStateStore::class),
            ),
        );

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

        // --- OPSC-S2 diagnostics reader (delivery handle, read-only — DECISION V (g)) ----
        $container->singleton(
            OperationsQueryReader::class,
            fn (Container $c) => new OperationsQueryReader(
                $c->get(DatabaseConnectionInterface::class),
            ),
        );

        // --- OPSC-S2 core providers -----------------------------------------------------
        $offlineAfter    = $this->offlineAfterSeconds();
        $rateWindow      = $this->processingRateWindowSeconds();

        $container->singleton(
            HealthProvider::class,
            fn (Container $c) => new HealthProvider($c->get(OperationsQueryReader::class), $offlineAfter),
        );

        $container->singleton(
            QueueStatusProvider::class,
            fn (Container $c) => new QueueStatusProvider($c->get(OperationsQueryReader::class)),
        );

        $container->singleton(
            WorkerStatusProvider::class,
            fn (Container $c) => new WorkerStatusProvider($c->get(OperationsQueryReader::class), $offlineAfter),
        );

        $container->singleton(
            MetricsProvider::class,
            fn (Container $c) => new MetricsProvider($c->get(OperationsQueryReader::class), $rateWindow),
        );

        // --- OPSC-S2 System Information + Module Inspector (diagnostics services) --------
        $container->singleton(
            SystemInformationProvider::class,
            fn (Container $c) => new SystemInformationProvider(
                $c->get(OperationsQueryReader::class),
                Version::CURRENT,
                $this->wordpressVersion(),
                'database',
            ),
        );

        // ModuleInspector starts empty; module inspection providers are added in
        // ContentServiceProvider::boot() (Rule 5 — module owns its descriptor).
        $container->singleton(ModuleInspector::class, fn () => new ModuleInspector());
    }

    /**
     * Register the four core providers with the RefreshCoordinator (composition-root wiring;
     * runs after all register() calls). Widgets read snapshots from the store, never poll a
     * provider directly (Doc 12 §7/§8).
     */
    public function boot(object $container): void
    {
        assert($container instanceof Container);

        /** @var RefreshCoordinator $coordinator */
        $coordinator = $container->get(RefreshCoordinator::class);

        $coordinator->addProvider($container->get(HealthProvider::class));
        $coordinator->addProvider($container->get(QueueStatusProvider::class));
        $coordinator->addProvider($container->get(WorkerStatusProvider::class));
        $coordinator->addProvider($container->get(MetricsProvider::class));
    }

    private function offlineAfterSeconds(): int
    {
        $value = $this->config['worker']['heartbeat']['offline_after_seconds'] ?? 60;

        return (int) $value;
    }

    private function processingRateWindowSeconds(): int
    {
        $value = $this->config['worker']['console']['processing_rate_window_seconds'] ?? 300;

        return (int) $value;
    }

    /**
     * Current WordPress version, read once at the wiring boundary via get_bloginfo (a
     * WordPress entry point). Null outside a WP runtime (e.g. unit tests / CLI bootstrap).
     */
    private function wordpressVersion(): ?string
    {
        if (function_exists('get_bloginfo')) {
            $v = get_bloginfo('version');

            return $v !== '' ? $v : null;
        }

        return null;
    }
}
