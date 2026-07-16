<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Container;

use HSP\Core\Container\Container;
use HSP\Core\Container\Definitions\OperationsServiceProvider;
use HSP\Core\Contracts\Operations\AssetRegistryInterface;
use HSP\Core\Contracts\Operations\NavigationRegistryInterface;
use HSP\Core\Contracts\Operations\PageRegistryInterface;
use HSP\Core\Contracts\Operations\WidgetRegistryInterface;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Operations\Admin\AdminPageController;
use HSP\Core\Operations\Admin\ConsoleAdminRegistrar;
use HSP\Core\Operations\Admin\ConsoleAjaxController;
use HSP\Core\Operations\Admin\PlaygroundRequestExecutor;
use HSP\Core\Operations\UI\DashboardView;
use HSP\Core\Operations\UI\PlaygroundView;
use HSP\Tests\Unit\Operations\Fakes\ScriptedReaderConnection;
use HSP\Tests\Unit\Operations\Fakes\WiringTestBindings;
use PHPUnit\Framework\TestCase;

/**
 * Wiring smoke for the OPSC-S3 UI additions to OperationsServiceProvider.
 *
 * Proves the UI/Admin graph resolves via constructor injection (ADR-012) and that boot()
 * registers the MVP nav (Operations + API Playground), both console pages, and the four core
 * dashboard widgets over the core provider keys — explicit registration only (ADR-048/052).
 * A fake delivery handle stands in so no live DB is needed.
 */
final class ConsoleUiWiringTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->container->instance(DatabaseConnectionInterface::class, new ScriptedReaderConnection());

        // The OPSC-S4 action seam (OperationsActionService) depends on the two ratified worker
        // strategies + StructuredLogger, which WorkerServiceProvider binds before this provider
        // in the real composition root. Provide lightweight stand-ins so the console graph
        // (ConsoleAdminRegistrar → ConsoleActionController → OperationsActionService) resolves
        // without booting the whole worker stack.
        WiringTestBindings::registerActionDependencies($this->container);

        $provider = new OperationsServiceProvider(['worker' => []]);
        $provider->register($this->container);
        $provider->boot($this->container);
    }

    public function test_ui_and_admin_classes_resolve_via_constructor_injection(): void
    {
        self::assertInstanceOf(DashboardView::class, $this->container->get(DashboardView::class));
        self::assertInstanceOf(PlaygroundView::class, $this->container->get(PlaygroundView::class));
        self::assertInstanceOf(PlaygroundRequestExecutor::class, $this->container->get(PlaygroundRequestExecutor::class));
        self::assertInstanceOf(ConsoleAjaxController::class, $this->container->get(ConsoleAjaxController::class));
        self::assertInstanceOf(AdminPageController::class, $this->container->get(AdminPageController::class));
        self::assertInstanceOf(ConsoleAdminRegistrar::class, $this->container->get(ConsoleAdminRegistrar::class));
    }

    public function test_boot_registers_the_mvp_pages_and_navigation(): void
    {
        /** @var PageRegistryInterface $pages */
        $pages = $this->container->get(PageRegistryInterface::class);
        self::assertTrue($pages->has(AdminPageController::PAGE_OPERATIONS));
        self::assertTrue($pages->has(AdminPageController::PAGE_PLAYGROUND));

        /** @var NavigationRegistryInterface $nav */
        $nav    = $this->container->get(NavigationRegistryInterface::class);
        $labels = array_map(static fn ($n) => $n->label, $nav->all());
        self::assertSame(['Operations', 'API Playground'], $labels);
    }

    public function test_boot_registers_the_core_dashboard_widgets(): void
    {
        /** @var WidgetRegistryInterface $widgets */
        $widgets = $this->container->get(WidgetRegistryInterface::class);

        $ids = array_map(
            static fn ($w) => $w->id,
            $widgets->forPage(AdminPageController::PAGE_OPERATIONS),
        );

        self::assertSame(['health', 'queue', 'workers', 'metrics'], $ids);
    }

    public function test_boot_registers_static_assets_for_both_pages(): void
    {
        /** @var AssetRegistryInterface $assets */
        $assets = $this->container->get(AssetRegistryInterface::class);

        // Each page gets a css + js asset; no bundle, no build step (DECISION V (a)).
        self::assertCount(2, $assets->forPage(AdminPageController::PAGE_OPERATIONS));
        self::assertCount(2, $assets->forPage(AdminPageController::PAGE_PLAYGROUND));

        foreach ($assets->all() as $asset) {
            self::assertStringStartsWith('resources/operations/', $asset->relPath);
        }
    }
}
