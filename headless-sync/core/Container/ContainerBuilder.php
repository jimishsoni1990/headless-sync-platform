<?php

declare(strict_types=1);

namespace HSP\Core\Container;

use HSP\Bootstrap\CredentialResolver;
use HSP\Core\Container\Definitions\CoreServiceProvider;
use HSP\Core\Container\Definitions\DeliveryServiceProvider;
use HSP\Core\Container\Definitions\DispatcherServiceProvider;
use HSP\Core\Container\Definitions\MigrationServiceProvider;
use HSP\Core\Container\Definitions\ModuleServiceProvider;
use HSP\Core\Container\Definitions\OperationsServiceProvider;
use HSP\Core\Container\Definitions\OutboxServiceProvider;
use HSP\Core\Container\Definitions\QueueServiceProvider;
use HSP\Core\Container\Definitions\WorkerServiceProvider;
use HSP\Modules\Content\ContentServiceProvider;

/**
 * Builds and wires the DI container.
 *
 * This is the composition root: service providers are registered here,
 * the container is built, and the two-phase lifecycle (register → boot) runs.
 *
 * Adding bindings: add a ServiceProvider under core/Container/Definitions/ and
 * register it here. Module service providers are added by the module registry (P0-S3).
 */
final class ContainerBuilder
{
    public function build(array $config, string $modulesBasePath = ''): Container
    {
        $container = new Container();

        $container->instance('config', (object) $config);

        // Single resolver for all runtime DB credential resolution — DECISION O (v1.15).
        // Constructed once here (composition root) and injected into each provider factory.
        $resolver = new CredentialResolver();

        $registry = new ServiceRegistry();
        $registry->addProvider(new CoreServiceProvider($config));
        $registry->addProvider(new MigrationServiceProvider($config, $resolver));
        $registry->addProvider(new OutboxServiceProvider($config, $resolver));
        $registry->addProvider(new QueueServiceProvider($config, $resolver));
        // DeliveryServiceProvider must be registered before WorkerServiceProvider
        // and ContentServiceProvider — both resolve DatabaseConnectionInterface
        // (DECISION K v1.11: dedicated FORCE_NEW delivery connection).
        $registry->addProvider(new DeliveryServiceProvider($config, $resolver));
        // DispatcherServiceProvider must follow QueueServiceProvider (DatabaseQueueProvider).
        // It opens its own FORCE_NEW connection — does NOT use DatabaseConnectionInterface
        // (DECISION K delivery handle). DECISION L v1.12.
        $registry->addProvider(new DispatcherServiceProvider($config, $resolver));
        $registry->addProvider(new WorkerServiceProvider($config));
        // Operations Console core scaffolding (OPSC-S1) — registries + provider contracts
        // + services. Read-only; no concrete providers (OPSC-S2), no UI (OPSC-S3), no
        // actions (OPSC-S4). Opens no PG handle (DECISION L Ruling 0 topology unchanged).
        $registry->addProvider(new OperationsServiceProvider());
        $registry->addProvider(new ContentServiceProvider());
        $registry->addProvider(new ModuleServiceProvider($modulesBasePath));

        $registry->registerAll($container);
        $registry->bootAll($container);

        return $container;
    }
}
