<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Container;

use HSP\Core\Container\Container;
use HSP\Core\Container\Definitions\OperationsServiceProvider;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Operations\Diagnostics\ModuleInspector;
use HSP\Core\Operations\Diagnostics\OperationsQueryReader;
use HSP\Core\Operations\Diagnostics\SystemInformationProvider;
use HSP\Core\Operations\Providers\HealthProvider;
use HSP\Core\Operations\Providers\MetricsProvider;
use HSP\Core\Operations\Providers\QueueStatusProvider;
use HSP\Core\Operations\Providers\WorkerStatusProvider;
use HSP\Core\Operations\Services\RefreshCoordinator;
use HSP\Tests\Unit\Operations\Fakes\ScriptedReaderConnection;
use PHPUnit\Framework\TestCase;

/**
 * Wiring smoke for the OPSC-S2 additions to OperationsServiceProvider.
 *
 * A separate file from the OPSC-S1 OperationsServiceProviderTest (that file is untouched).
 * A fake DatabaseConnectionInterface stands in for the delivery handle so no live DB is
 * needed; this proves the S2 provider graph resolves via constructor injection (ADR-012) and
 * that the four core providers self-register with the RefreshCoordinator on boot() — one key
 * each, no duplicates.
 */
final class OperationsProvidersWiringTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
        // Stand-in delivery handle (the real one is FORCE_NEW PG — DECISION K).
        $this->container->instance(DatabaseConnectionInterface::class, new ScriptedReaderConnection());

        $config = [
            'worker' => [
                'heartbeat' => ['offline_after_seconds' => 45],
                'console'   => ['processing_rate_window_seconds' => 120],
            ],
        ];

        $provider = new OperationsServiceProvider($config);
        $provider->register($this->container);
        $provider->boot($this->container);
    }

    public function test_s2_providers_and_diagnostics_resolve_via_constructor_injection(): void
    {
        self::assertInstanceOf(OperationsQueryReader::class, $this->container->get(OperationsQueryReader::class));
        self::assertInstanceOf(HealthProvider::class, $this->container->get(HealthProvider::class));
        self::assertInstanceOf(QueueStatusProvider::class, $this->container->get(QueueStatusProvider::class));
        self::assertInstanceOf(WorkerStatusProvider::class, $this->container->get(WorkerStatusProvider::class));
        self::assertInstanceOf(MetricsProvider::class, $this->container->get(MetricsProvider::class));
        self::assertInstanceOf(SystemInformationProvider::class, $this->container->get(SystemInformationProvider::class));
        self::assertInstanceOf(ModuleInspector::class, $this->container->get(ModuleInspector::class));
    }

    public function test_core_providers_are_registered_with_the_coordinator_on_boot(): void
    {
        $coordinator = $this->container->get(RefreshCoordinator::class);

        self::assertInstanceOf(RefreshCoordinator::class, $coordinator);
        self::assertTrue($coordinator->has('health'));
        self::assertTrue($coordinator->has('queue'));
        self::assertTrue($coordinator->has('workers'));
        self::assertTrue($coordinator->has('metrics'));
        // OAPI-S1: the core-owned openapi.json endpoint provider self-registers (ADR-055 (4)) so
        // the generator's aggregated registry includes the openapi.json route itself.
        self::assertTrue($coordinator->has('core.openapi.endpoint'));

        // Exactly the five core keys (module providers — e.g. Content's endpoint/metrics providers —
        // are registered by ContentServiceProvider, which is not exercised here).
        $keys = $coordinator->keys();
        sort($keys);
        self::assertSame(['core.openapi.endpoint', 'health', 'metrics', 'queue', 'workers'], $keys);
    }

    public function test_module_inspector_starts_empty(): void
    {
        self::assertSame([], $this->container->get(ModuleInspector::class)->all());
    }
}
