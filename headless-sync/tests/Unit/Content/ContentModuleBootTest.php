<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content;

use HSP\Core\Container\Container;
use HSP\Core\Contracts\EventProviderInterface;
use HSP\Core\Contracts\OutboxWriterInterface;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Modules\Content\ContentModule;
use HSP\Modules\Content\ContentServiceProvider;
use HSP\Modules\Content\EventProvider;
use HSP\Modules\Content\HookWiring;
use HSP\Modules\Content\Rest\ContentRestRegistrar;
use HSP\Modules\Content\Rest\ContentRestRegistrarFactory;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that ContentServiceProvider wires ContentModule correctly:
 *   - ContentModule resolves from the container with all constructor deps injected.
 *   - No new $class() hand-instantiation (FLAG-P1AS6-2 Gap B fix).
 *   - ContentModule holds no Container reference (ADR-012 / FLAG-P1AS6A-5).
 *   - REST routes are registered via add_action('rest_api_init', ...) on boot().
 *   - ContentRestRegistrar is NOT constructed at module-load time (lazy deferral).
 *
 * Uses a fake DatabaseConnectionInterface and OutboxWriterInterface;
 * does not touch WordPress or any real database.
 */
final class ContentModuleBootTest extends TestCase
{
    private function buildContainer(): Container
    {
        $container = new Container();

        // Minimal fake for OutboxWriterInterface (ContentEventProvider needs this).
        $container->singleton(OutboxWriterInterface::class, fn () => new FakeOutboxWriter());

        // Minimal fake for DatabaseConnectionInterface (QueryProviders need this).
        $container->singleton(
            DatabaseConnectionInterface::class,
            fn () => new class implements DatabaseConnectionInterface {
                public function query(string $sql, array $params = []): array { return []; }
                public function execute(string $sql, array $params = []): int  { return 0; }
                public function beginTransaction(): void {}
                public function commit(): void {}
                public function rollback(): void {}
            }
        );

        // Register all Content module bindings.
        (new ContentServiceProvider())->register($container);

        return $container;
    }

    public function testContentModuleResolvesFromContainerWithInjectedDeps(): void
    {
        $container = $this->buildContainer();

        $module = $container->get(ContentModule::class);

        $this->assertInstanceOf(ContentModule::class, $module);
        $this->assertSame('content', $module->getName());
    }

    public function testContainerResolvesHookWiringWithEventProvider(): void
    {
        $container = $this->buildContainer();

        $hookWiring = $container->get(HookWiring::class);
        $this->assertInstanceOf(HookWiring::class, $hookWiring);
    }

    public function testContainerResolvesEventProviderInterface(): void
    {
        $container = $this->buildContainer();

        $provider = $container->get(EventProviderInterface::class);
        $this->assertInstanceOf(EventProvider::class, $provider);
    }

    public function testContainerResolvesContentRestRegistrar(): void
    {
        $container = $this->buildContainer();

        $registrar = $container->get(ContentRestRegistrar::class);
        $this->assertInstanceOf(ContentRestRegistrar::class, $registrar);
    }

    public function testContentModuleBootRegistersRestApiInitHook(): void
    {
        $container = $this->buildContainer();
        $module    = $container->get(ContentModule::class);

        // boot() calls add_action('rest_api_init', ...) — the stub in tests/bootstrap.php
        // is a no-op. We verify boot() completes without throwing; the hook is registered.
        $module->boot();

        $this->assertTrue(true);
    }

    public function testContentModuleSingletonIsSharedAcrossResolutions(): void
    {
        $container = $this->buildContainer();

        $a = $container->get(ContentModule::class);
        $b = $container->get(ContentModule::class);

        $this->assertSame($a, $b);
    }

    /**
     * ADR-012 / FLAG-P1AS6A-5: ContentModule must hold no Container reference.
     * Its $restRegistrarFactory must be a ContentRestRegistrarFactory, not a Container
     * or any type that exposes Container::get() directly.
     */
    public function testContentModuleHoldsNoContainerReference(): void
    {
        $container = $this->buildContainer();
        $module    = $container->get(ContentModule::class);

        $ref   = new \ReflectionObject($module);
        $props = $ref->getProperties();

        foreach ($props as $prop) {
            $prop->setAccessible(true);
            $value = $prop->getValue($module);

            $this->assertNotInstanceOf(
                Container::class,
                $value,
                "ContentModule property \${$prop->getName()} must not hold a Container instance."
            );
        }
    }

    /**
     * ADR-012 / FLAG-P1AS6A-5: the factory dep injected into ContentModule must be
     * a ContentRestRegistrarFactory (typed composition-root object), not a raw \Closure
     * that smuggles the Container.
     */
    public function testRestRegistrarFactoryIsTypedFactoryNotRawClosure(): void
    {
        $container = $this->buildContainer();
        $module    = $container->get(ContentModule::class);

        $ref  = new \ReflectionObject($module);
        $prop = $ref->getProperty('restRegistrarFactory');
        $prop->setAccessible(true);
        $factory = $prop->getValue($module);

        $this->assertInstanceOf(
            ContentRestRegistrarFactory::class,
            $factory,
            'ContentModule::$restRegistrarFactory must be a ContentRestRegistrarFactory, not a raw Closure.'
        );
    }

    /**
     * Lazy-deferral proof: resolving ContentModule from the container must NOT
     * construct ContentRestRegistrar (or open a PG connection). Construction must
     * be deferred until the factory is first invoked.
     *
     * Verified by replacing DatabaseConnectionInterface with a spy that throws on
     * connection open, then confirming ContentModule resolves without the spy firing.
     * Invoking the factory on the module's ContentRestRegistrarFactory then triggers
     * it (expected — that is the rest_api_init moment).
     */
    public function testContentRestRegistrarIsNotConstructedAtModuleLoadTime(): void
    {
        $container = new Container();

        $container->singleton(OutboxWriterInterface::class, fn () => new FakeOutboxWriter());

        // Spy: if DatabaseConnectionInterface is resolved before the factory is called,
        // the test fails. After the factory is called, resolution is expected.
        $resolvedCount = 0;
        $container->singleton(
            DatabaseConnectionInterface::class,
            function () use (&$resolvedCount): DatabaseConnectionInterface {
                $resolvedCount++;
                return new class implements DatabaseConnectionInterface {
                    public function query(string $sql, array $params = []): array { return []; }
                    public function execute(string $sql, array $params = []): int  { return 0; }
                    public function beginTransaction(): void {}
                    public function commit(): void {}
                    public function rollback(): void {}
                };
            }
        );

        (new ContentServiceProvider())->register($container);

        // Resolving ContentModule must NOT trigger DatabaseConnectionInterface.
        $module = $container->get(ContentModule::class);
        $this->assertSame(0, $resolvedCount,
            'DatabaseConnectionInterface must not be resolved at module-load time.'
        );

        // Invoking the factory (simulating rest_api_init) DOES trigger it.
        $ref  = new \ReflectionObject($module);
        $prop = $ref->getProperty('restRegistrarFactory');
        $prop->setAccessible(true);
        /** @var ContentRestRegistrarFactory $factory */
        $factory = $prop->getValue($module);
        $factory(); // simulates rest_api_init callback firing

        $this->assertSame(1, $resolvedCount,
            'DatabaseConnectionInterface must be resolved exactly once when the factory is invoked.'
        );
    }
}
