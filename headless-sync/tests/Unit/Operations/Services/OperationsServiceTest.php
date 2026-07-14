<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Services;

use HSP\Core\Contracts\Operations\ConsoleAction;
use HSP\Core\Contracts\Operations\ConsoleAsset;
use HSP\Core\Contracts\Operations\ConsolePage;
use HSP\Core\Contracts\Operations\ConsoleWidget;
use HSP\Core\Contracts\Operations\NavigationItem;
use HSP\Core\Operations\Registries\ActionRegistry;
use HSP\Core\Operations\Registries\AssetRegistry;
use HSP\Core\Operations\Registries\NavigationRegistry;
use HSP\Core\Operations\Registries\PageRegistry;
use HSP\Core\Operations\Registries\WidgetRegistry;
use HSP\Core\Operations\Services\ConsoleStateStore;
use HSP\Core\Operations\Services\OperationsService;
use HSP\Core\Operations\Services\RefreshCoordinator;
use HSP\Tests\Unit\Operations\Fakes\FakeQueueStatusProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OperationsService (Doc 12 §10; ADR-053 — the single UI-facing seam).
 *
 * The service is assembled entirely from core contracts + core services; NO infrastructure
 * class (no DatabaseConnectionInterface, no concrete provider) is passed to it or reachable
 * through it — the ADR-053 read-only-by-default guarantee at the composition level.
 */
final class OperationsServiceTest extends TestCase
{
    private PageRegistry $pages;
    private NavigationRegistry $nav;
    private WidgetRegistry $widgets;
    private ActionRegistry $actions;
    private AssetRegistry $assets;
    private ConsoleStateStore $store;
    private RefreshCoordinator $coordinator;
    private OperationsService $service;

    protected function setUp(): void
    {
        $this->pages       = new PageRegistry();
        $this->nav         = new NavigationRegistry();
        $this->widgets     = new WidgetRegistry();
        $this->actions     = new ActionRegistry();
        $this->assets      = new AssetRegistry();
        $this->store       = new ConsoleStateStore();
        $this->coordinator = new RefreshCoordinator($this->store);

        $this->service = new OperationsService(
            $this->pages,
            $this->nav,
            $this->widgets,
            $this->actions,
            $this->assets,
            $this->coordinator,
            $this->store,
        );
    }

    public function test_exposes_discovery_from_the_registries(): void
    {
        $this->pages->register(new ConsolePage('operations', 'Operations', 'manage_options'));
        $this->nav->register(new NavigationItem('Operations', 'operations'));
        $this->widgets->register(new ConsoleWidget('queue-depth', 'Queue Depth', 'operations', 'queue'));
        $this->assets->register(new ConsoleAsset('ops-poll', ConsoleAsset::TYPE_SCRIPT, 'poll.js', 'operations'));
        $this->actions->register(new ConsoleAction('replay', 'Replay', 'manage_options'));

        self::assertCount(1, $this->service->pages());
        self::assertCount(1, $this->service->navigation());
        self::assertCount(1, $this->service->widgetsForPage('operations'));
        self::assertCount(1, $this->service->assetsForPage('operations'));
        self::assertCount(1, $this->service->actions());
    }

    public function test_refresh_all_returns_snapshots_keyed_by_provider(): void
    {
        $this->coordinator->addProvider(new FakeQueueStatusProvider('queue'));

        $snapshots = $this->service->refreshAll();

        self::assertArrayHasKey('queue', $snapshots);
        self::assertSame(3, $snapshots['queue']->depth);
    }

    public function test_snapshot_lazily_refreshes_a_single_provider_once(): void
    {
        $queue = new FakeQueueStatusProvider('queue');
        $this->coordinator->addProvider($queue);

        $first  = $this->service->snapshot('queue');
        $second = $this->service->snapshot('queue');

        // Second read is served from the store — the provider is not called again.
        self::assertSame(1, $queue->calls);
        self::assertSame($first, $second);
    }

    public function test_widget_snapshot_resolves_through_the_widgets_provider_key(): void
    {
        $this->widgets->register(new ConsoleWidget('queue-depth', 'Queue Depth', 'operations', 'queue'));
        $this->coordinator->addProvider(new FakeQueueStatusProvider('queue'));

        $snapshot = $this->service->widgetSnapshot('operations', 'queue-depth');
        self::assertSame(3, $snapshot->depth);
    }

    public function test_two_widgets_over_one_provider_trigger_exactly_one_provider_call(): void
    {
        // Doc 12 §7/§8: widgets never poll independently — centralized refresh means one
        // provider call regardless of the number of consuming widgets.
        $this->widgets->register(new ConsoleWidget('w1', 'W1', 'operations', 'queue'));
        $this->widgets->register(new ConsoleWidget('w2', 'W2', 'operations', 'queue'));
        $queue = new FakeQueueStatusProvider('queue');
        $this->coordinator->addProvider($queue);

        $this->service->widgetSnapshot('operations', 'w1');
        $this->service->widgetSnapshot('operations', 'w2');

        self::assertSame(1, $queue->calls);
    }

    public function test_widget_snapshot_throws_for_unknown_widget(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("No widget 'nope' registered on page 'operations'");
        $this->service->widgetSnapshot('operations', 'nope');
    }
}
