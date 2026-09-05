<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Admin;

use HSP\Core\Contracts\Operations\ConsoleAsset;
use HSP\Core\Contracts\Operations\ConsolePage;
use HSP\Core\Contracts\Operations\ConsoleWidget;
use HSP\Core\Contracts\Operations\NavigationItem;
use HSP\Core\Operations\Admin\AdminPageController;
use HSP\Core\Operations\Admin\ConsoleAjaxController;
use HSP\Core\Operations\Admin\PlaygroundRequestExecutor;
use HSP\Core\Operations\Diagnostics\ModuleInspector;
use HSP\Core\Operations\Diagnostics\OperationsQueryReader;
use HSP\Core\Operations\Diagnostics\SystemInformationProvider;
use HSP\Core\Operations\Registries\ActionRegistry;
use HSP\Core\Operations\Registries\AssetRegistry;
use HSP\Core\Operations\Registries\NavigationRegistry;
use HSP\Core\Operations\Registries\PageRegistry;
use HSP\Core\Operations\Registries\WidgetRegistry;
use HSP\Core\Operations\Services\ConsoleStateStore;
use HSP\Core\Operations\Services\OperationsService;
use HSP\Core\Operations\Services\RefreshCoordinator;
use HSP\Core\Operations\UI\ActionsView;
use HSP\Core\Operations\UI\DashboardView;
use HSP\Core\Operations\UI\PlaygroundView;
use HSP\Core\Contracts\Onboarding\OnboardingStateInterface;
use HSP\Core\Onboarding\OnboardingState;
use HSP\Tests\Support\WpJsonHalt;
use HSP\Tests\Unit\Operations\Fakes\FakeQueueStatusProvider;
use HSP\Tests\Unit\Operations\Fakes\ScriptedReaderConnection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AdminPageController — the wp-admin boundary (DECISION V (a)/(b); ADR-053).
 *
 * The controller is assembled from OperationsService + two directly-queried diagnostics
 * services (SystemInformationProvider, ModuleInspector) + pure views. It never receives a
 * DatabaseConnectionInterface, the reader, or a concrete provider (ADR-053). add_menu_page /
 * add_submenu_page / current_user_can / wp_die are stubbed in tests/bootstrap.php so menu
 * registration, capability enforcement, and server-side rendering are assertable without WP.
 */
final class AdminPageControllerTest extends TestCase
{
    private PageRegistry $pages;
    private WidgetRegistry $widgets;
    private AssetRegistry $assets;
    private OperationsService $operations;
    private AdminPageController $controller;

    protected function setUp(): void
    {
        $this->pages   = new PageRegistry();
        $nav           = new NavigationRegistry();
        $this->widgets = new WidgetRegistry();
        $this->assets  = new AssetRegistry();
        $store         = new ConsoleStateStore();
        $coordinator   = new RefreshCoordinator($store);
        $coordinator->addProvider(new FakeQueueStatusProvider('queue'));

        // MVP pages + one dashboard widget over the queue provider.
        $this->pages->register(new ConsolePage(AdminPageController::PAGE_OPERATIONS, 'Operations', 'manage_hsp'));
        $this->pages->register(new ConsolePage(AdminPageController::PAGE_PLAYGROUND, 'API Playground', 'manage_hsp'));
        $nav->register(new NavigationItem('Operations', AdminPageController::PAGE_OPERATIONS));
        $this->widgets->register(new ConsoleWidget('queue', 'Queue', AdminPageController::PAGE_OPERATIONS, 'queue'));
        $this->assets->register(new ConsoleAsset('hsp-ops-css', ConsoleAsset::TYPE_STYLE, 'resources/operations/console.css', AdminPageController::PAGE_OPERATIONS));
        $this->assets->register(new ConsoleAsset('hsp-ops-js', ConsoleAsset::TYPE_SCRIPT, 'resources/operations/console.js', AdminPageController::PAGE_OPERATIONS));

        $this->operations = new OperationsService(
            $this->pages,
            $nav,
            $this->widgets,
            new ActionRegistry(),
            $this->assets,
            $coordinator,
            $store,
        );

        // Default controller: onboarding COMPLETE so the console pages register (the ungated
        // behaviour the render/enqueue tests exercise). Nav-gating tests build their own.
        $GLOBALS['_hsp_stub_options'] = [
            OnboardingStateInterface::OPTION_NAME => OnboardingStateInterface::COMPLETE,
        ];

        $this->controller = $this->makeController(new OnboardingState());

        $GLOBALS['_hsp_stub_current_user_can'] = true;
        $GLOBALS['_hsp_stub_menu_pages']    = [];
        $GLOBALS['_hsp_stub_submenu_pages'] = [];
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['_hsp_stub_current_user_can'],
            $GLOBALS['_hsp_stub_menu_pages'],
            $GLOBALS['_hsp_stub_submenu_pages'],
            $GLOBALS['_hsp_stub_options'],
        );
    }

    private function makeController(OnboardingStateInterface $onboarding): AdminPageController
    {
        $reader     = new OperationsQueryReader($this->scriptedConnection());
        $systemInfo = new SystemInformationProvider($reader, '0.1.0', '6.5', 'database');

        return new AdminPageController(
            $this->operations,
            $systemInfo,
            new ModuleInspector(),
            new DashboardView(),
            new PlaygroundView(),
            new ConsoleAjaxController($this->operations, new PlaygroundRequestExecutor(), new DashboardView()),
            $onboarding,
            new ActionsView(),
        );
    }

    private function scriptedConnection(): ScriptedReaderConnection
    {
        return (new ScriptedReaderConnection())
            ->on('SHOW server_version', [['server_version' => '16.2']])
            ->on('COUNT(*) AS c FROM system.schema_versions', [['c' => 3]])
            ->on('migration_name', [['migration_name' => '0003_x']])
            ->on('module_versions', []);
    }

    public function test_registers_menu_using_the_page_capabilities(): void
    {
        $this->controller->registerMenu();

        self::assertNotEmpty($GLOBALS['_hsp_stub_menu_pages']);
        // add_menu_page(title, menu_title, capability, slug, callback, icon)
        $menu = $GLOBALS['_hsp_stub_menu_pages'][0];
        self::assertSame('manage_hsp', $menu[2]);
        self::assertSame(AdminPageController::MENU_SLUG, $menu[3]);

        // Two submenus: Operations + API Playground.
        self::assertCount(2, $GLOBALS['_hsp_stub_submenu_pages']);
    }

    public function test_renders_operations_dashboard_server_side(): void
    {
        ob_start();
        $this->controller->renderOperations();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('HSP Operations', $html);
        self::assertStringContainsString('Queue depth', $html);       // widget rendered
        self::assertStringContainsString('System Information', $html); // diagnostics rendered
        self::assertStringContainsString('16.2', $html);              // PG version from reader
    }

    public function test_renders_playground_server_side(): void
    {
        ob_start();
        $this->controller->renderPlayground();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('API Playground', $html);
        self::assertStringContainsString('Endpoint Explorer', $html);
        self::assertStringContainsString('data-action="' . ConsoleAjaxController::ACTION_EXECUTE . '"', $html);
    }

    public function test_denies_rendering_without_the_page_capability(): void
    {
        $GLOBALS['_hsp_stub_current_user_can'] = false;

        $this->expectException(WpJsonHalt::class); // wp_die stub throws WpJsonHalt
        $this->controller->renderOperations();
    }

    public function test_enqueues_static_assets_and_localizes_the_nonce_on_the_console_page(): void
    {
        $GLOBALS['_hsp_stub_enqueued_styles']  = [];
        $GLOBALS['_hsp_stub_enqueued_scripts'] = [];
        $GLOBALS['_hsp_stub_localized']        = [];

        // WP passes the page hook suffix; the console menu slug appears in it.
        $this->controller->enqueueAssets('toplevel_page_' . AdminPageController::MENU_SLUG);

        self::assertArrayHasKey('hsp-ops-css', $GLOBALS['_hsp_stub_enqueued_styles']);
        self::assertArrayHasKey('hsp-ops-js', $GLOBALS['_hsp_stub_enqueued_scripts']);

        // The script is localized with the nonce + ajax action (minimal polling — DECISION V (a)).
        self::assertArrayHasKey('hsp-ops-js', $GLOBALS['_hsp_stub_localized']);
        [$objectName, $data] = $GLOBALS['_hsp_stub_localized']['hsp-ops-js'];
        self::assertSame('HSP_OPS', $objectName);
        self::assertArrayHasKey('nonce', $data);
        self::assertSame(ConsoleAjaxController::ACTION_POLL, $data['pollAction']);

        unset(
            $GLOBALS['_hsp_stub_enqueued_styles'],
            $GLOBALS['_hsp_stub_enqueued_scripts'],
            $GLOBALS['_hsp_stub_localized'],
        );
    }

    public function test_does_not_enqueue_assets_on_unrelated_admin_pages(): void
    {
        $GLOBALS['_hsp_stub_enqueued_scripts'] = [];

        $this->controller->enqueueAssets('edit.php');

        self::assertSame([], $GLOBALS['_hsp_stub_enqueued_scripts']);

        unset($GLOBALS['_hsp_stub_enqueued_scripts']);
    }

    // -------------------------------------------------------------------------
    // Nav gating (ONB-S1b; DECISION W (f)) — console pages hidden until onboarding completes
    // -------------------------------------------------------------------------

    public function test_does_not_register_the_console_menu_until_onboarding_completes(): void
    {
        $GLOBALS['_hsp_stub_options'] = [
            OnboardingStateInterface::OPTION_NAME => OnboardingStateInterface::PENDING,
        ];

        $gated = $this->makeController(new OnboardingState());
        $gated->registerMenu();

        // Neither the parent menu nor any submenu is registered while onboarding is incomplete.
        self::assertSame([], $GLOBALS['_hsp_stub_menu_pages']);
        self::assertSame([], $GLOBALS['_hsp_stub_submenu_pages']);
    }

    public function test_registers_the_console_menu_once_onboarding_is_complete(): void
    {
        $GLOBALS['_hsp_stub_options'] = [
            OnboardingStateInterface::OPTION_NAME => OnboardingStateInterface::COMPLETE,
        ];

        $ungated = $this->makeController(new OnboardingState());
        $ungated->registerMenu();

        self::assertNotEmpty($GLOBALS['_hsp_stub_menu_pages']);
        self::assertCount(2, $GLOBALS['_hsp_stub_submenu_pages']);
    }
}
