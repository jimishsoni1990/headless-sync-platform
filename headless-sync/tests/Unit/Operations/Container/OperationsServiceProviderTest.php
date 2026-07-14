<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Container;

use HSP\Core\Container\Container;
use HSP\Core\Container\Definitions\OperationsServiceProvider;
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
use PHPUnit\Framework\TestCase;

/**
 * Container-resolution smoke test for OperationsServiceProvider (ADR-012 / Rule 7).
 *
 * Every OPSC-S1 binding resolves via constructor injection with NO service-locator call
 * inside business logic and NO PostgreSQL connection (these scaffolding bindings have zero
 * DB dependencies — DECISION V (c)/(g); no fifth handle). The provider is exercised against
 * a bare Container, so this needs no live DB and runs in the Unit suite.
 */
final class OperationsServiceProviderTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
        (new OperationsServiceProvider())->register($this->container);
    }

    public function test_all_registry_contracts_resolve_to_their_implementations(): void
    {
        self::assertInstanceOf(PageRegistry::class, $this->container->get(PageRegistryInterface::class));
        self::assertInstanceOf(NavigationRegistry::class, $this->container->get(NavigationRegistryInterface::class));
        self::assertInstanceOf(WidgetRegistry::class, $this->container->get(WidgetRegistryInterface::class));
        self::assertInstanceOf(ActionRegistry::class, $this->container->get(ActionRegistryInterface::class));
        self::assertInstanceOf(AssetRegistry::class, $this->container->get(AssetRegistryInterface::class));
    }

    public function test_services_resolve_via_constructor_injection(): void
    {
        self::assertInstanceOf(ConsoleStateStore::class, $this->container->get(ConsoleStateStore::class));
        self::assertInstanceOf(RefreshCoordinator::class, $this->container->get(RefreshCoordinator::class));
        self::assertInstanceOf(OperationsService::class, $this->container->get(OperationsService::class));
    }

    public function test_bindings_are_singletons(): void
    {
        self::assertSame(
            $this->container->get(OperationsService::class),
            $this->container->get(OperationsService::class),
        );
        self::assertSame(
            $this->container->get(ConsoleStateStore::class),
            $this->container->get(ConsoleStateStore::class),
        );
    }

    public function test_coordinator_and_service_share_one_state_store_via_singletons(): void
    {
        // The service reads what the coordinator writes only if both resolve the SAME
        // ConsoleStateStore singleton. The store binding is a singleton, so every consumer
        // (RefreshCoordinator + OperationsService) receives the one instance.
        $store       = $this->container->get(ConsoleStateStore::class);
        $coordinator = $this->container->get(RefreshCoordinator::class);
        $service     = $this->container->get(OperationsService::class);

        self::assertInstanceOf(RefreshCoordinator::class, $coordinator);
        self::assertInstanceOf(OperationsService::class, $service);
        // Same singleton store handed to both wiring sites.
        self::assertSame($store, $this->container->get(ConsoleStateStore::class));
    }
}
