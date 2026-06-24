<?php

declare(strict_types=1);

namespace HSP\Core\Container;

use HSP\Core\Container\Definitions\CoreServiceProvider;
use HSP\Core\Container\Definitions\DeliveryServiceProvider;
use HSP\Core\Container\Definitions\DispatcherServiceProvider;
use HSP\Core\Container\Definitions\MigrationServiceProvider;
use HSP\Core\Container\Definitions\ModuleServiceProvider;
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

        $registry = new ServiceRegistry();
        $registry->addProvider(new CoreServiceProvider($config));
        $registry->addProvider(new MigrationServiceProvider($config));
        $registry->addProvider(new OutboxServiceProvider($config));
        $registry->addProvider(new QueueServiceProvider($config));
        // DeliveryServiceProvider must be registered before WorkerServiceProvider
        // and ContentServiceProvider — both resolve DatabaseConnectionInterface
        // (DECISION K v1.11: dedicated FORCE_NEW delivery connection).
        $registry->addProvider(new DeliveryServiceProvider($config));
        // DispatcherServiceProvider must follow QueueServiceProvider (DatabaseQueueProvider).
        // It opens its own FORCE_NEW connection — does NOT use DatabaseConnectionInterface
        // (DECISION K delivery handle). DECISION L v1.12.
        $registry->addProvider(new DispatcherServiceProvider($config));
        $registry->addProvider(new WorkerServiceProvider());
        $registry->addProvider(new ContentServiceProvider());
        $registry->addProvider(new ModuleServiceProvider($modulesBasePath));

        $registry->registerAll($container);
        $registry->bootAll($container);

        return $container;
    }
}
