<?php

declare(strict_types=1);

namespace HSP\Core\Container\Definitions;

use HSP\Core\Container\Container;
use HSP\Core\Container\ServiceProvider;
use HSP\Core\Module\ModuleDiscovery;
use HSP\Core\Module\ModuleLoader;
use HSP\Core\Module\ModuleRegistrar;
use HSP\Core\Module\ModuleRegistry;

/**
 * Registers the module infrastructure (discovery, registry, registrar) in the DI container.
 *
 * Bindings:
 *   'module.discovery'  — ModuleDiscovery
 *   'module.loader'     — ModuleLoader
 *   'module.registry'   — ModuleRegistry
 *   'module.registrar'  — ModuleRegistrar
 *
 * Constructor injection only — ADR-012.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function __construct(private readonly string $modulesBasePath) {}

    public function register(object $container): void
    {
        assert($container instanceof Container);

        $container->singleton('module.discovery', fn() =>
            new ModuleDiscovery($this->modulesBasePath)
        );

        $container->singleton('module.loader', fn(Container $c) =>
            new ModuleLoader($c)
        );

        $container->singleton('module.registry', fn(Container $c) =>
            new ModuleRegistry(
                $c->get('module.discovery'),
                $c->get('module.loader'),
            )
        );

        $container->singleton('module.registrar', fn(Container $c) =>
            new ModuleRegistrar($c->get('module.registry'))
        );
    }
}
