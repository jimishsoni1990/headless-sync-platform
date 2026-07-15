<?php

declare(strict_types=1);

namespace HSP\Core\Container\Definitions;

use HSP\Bootstrap\Version;
use HSP\Core\Container\Container;
use HSP\Core\Container\ServiceProvider;
use HSP\Core\Contracts\Operations\ActionRegistryInterface;
use HSP\Core\Contracts\Operations\AssetRegistryInterface;
use HSP\Core\Contracts\Operations\ConsoleAsset;
use HSP\Core\Contracts\Operations\ConsolePage;
use HSP\Core\Contracts\Operations\ConsoleWidget;
use HSP\Core\Contracts\Operations\NavigationItem;
use HSP\Core\Contracts\Operations\NavigationRegistryInterface;
use HSP\Core\Contracts\Operations\PageRegistryInterface;
use HSP\Core\Contracts\Operations\WidgetRegistryInterface;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Operations\Admin\AdminPageController;
use HSP\Core\Operations\Admin\ConsoleAdminRegistrar;
use HSP\Core\Operations\Admin\ConsoleAjaxController;
use HSP\Core\Operations\Admin\PlaygroundRequestExecutor;
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
use HSP\Core\Operations\UI\DashboardView;
use HSP\Core\Operations\UI\PlaygroundView;

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

        // --- OPSC-S3 server-rendered UI (pure renderers — no infra; ADR-053) -------------
        $container->singleton(DashboardView::class, fn () => new DashboardView());
        $container->singleton(PlaygroundView::class, fn () => new PlaygroundView());
        $container->singleton(PlaygroundRequestExecutor::class, fn () => new PlaygroundRequestExecutor());

        // --- OPSC-S3 wp-admin boundary ---------------------------------------------------
        // These are the ONLY console classes that touch WordPress. They talk to
        // OperationsService + the two diagnostics services only — never a
        // DatabaseConnectionInterface, the reader, or a concrete provider (ADR-053).
        $container->singleton(
            ConsoleAjaxController::class,
            fn (Container $c) => new ConsoleAjaxController(
                $c->get(OperationsService::class),
                $c->get(PlaygroundRequestExecutor::class),
            ),
        );

        $container->singleton(
            AdminPageController::class,
            fn (Container $c) => new AdminPageController(
                $c->get(OperationsService::class),
                $c->get(SystemInformationProvider::class),
                $c->get(ModuleInspector::class),
                $c->get(DashboardView::class),
                $c->get(PlaygroundView::class),
                $c->get(ConsoleAjaxController::class),
            ),
        );

        $container->singleton(
            ConsoleAdminRegistrar::class,
            fn (Container $c) => new ConsoleAdminRegistrar(
                $c->get(AdminPageController::class),
                $c->get(ConsoleAjaxController::class),
            ),
        );
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

        $this->registerConsoleUi($container);
    }

    /**
     * Register the MVP console pages, navigation, and dashboard widgets (OPSC-S3).
     *
     * MVP nav is "Operations" + "API Playground" (Doc 12 §6). The dashboard carries one
     * read-only widget per core provider (Health/Queue/Workers/Metrics), each naming its
     * provider key so the Operations Services layer resolves the snapshot (widgets never poll
     * — Doc 12 §7/§8). Explicit registration only (ADR-048/ADR-052). Module-provided widgets
     * (e.g. Content metrics) are registered in the module's boot().
     */
    private function registerConsoleUi(Container $container): void
    {
        /** @var PageRegistryInterface $pages */
        $pages = $container->get(PageRegistryInterface::class);
        /** @var NavigationRegistryInterface $nav */
        $nav = $container->get(NavigationRegistryInterface::class);
        /** @var WidgetRegistryInterface $widgets */
        $widgets = $container->get(WidgetRegistryInterface::class);

        $cap = 'manage_options';

        $pages->register(new ConsolePage(AdminPageController::PAGE_OPERATIONS, 'Operations', $cap, 10));
        $pages->register(new ConsolePage(AdminPageController::PAGE_PLAYGROUND, 'API Playground', $cap, 20));

        $nav->register(new NavigationItem('Operations', AdminPageController::PAGE_OPERATIONS, 10));
        $nav->register(new NavigationItem('API Playground', AdminPageController::PAGE_PLAYGROUND, 20));

        $page = AdminPageController::PAGE_OPERATIONS;
        $widgets->register(new ConsoleWidget('health', 'Health', $page, HealthProvider::KEY, 10));
        $widgets->register(new ConsoleWidget('queue', 'Queue', $page, QueueStatusProvider::KEY, 20));
        $widgets->register(new ConsoleWidget('workers', 'Workers', $page, WorkerStatusProvider::KEY, 30));
        $widgets->register(new ConsoleWidget('metrics', 'Metrics', $page, MetricsProvider::KEY, 40));

        // Static, hand-authored assets (no bundle, no build step — DECISION V (a)). Enqueued
        // at the wp-admin boundary by AdminPageController when a console page renders.
        /** @var AssetRegistryInterface $assets */
        $assets = $container->get(AssetRegistryInterface::class);
        foreach ([AdminPageController::PAGE_OPERATIONS, AdminPageController::PAGE_PLAYGROUND] as $slug) {
            $assets->register(new ConsoleAsset(
                "hsp-ops-console-{$slug}-css",
                ConsoleAsset::TYPE_STYLE,
                'resources/operations/console.css',
                $slug,
            ));
            $assets->register(new ConsoleAsset(
                "hsp-ops-console-{$slug}-js",
                ConsoleAsset::TYPE_SCRIPT,
                'resources/operations/console.js',
                $slug,
            ));
        }
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
