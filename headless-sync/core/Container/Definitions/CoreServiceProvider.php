<?php

declare(strict_types=1);

namespace HSP\Core\Container\Definitions;

use HSP\Core\Container\Container;
use HSP\Core\Container\ServiceProvider;

/**
 * Registers core infrastructure bindings.
 *
 * Domain services (queue, workers, adapters, events) are registered in
 * their respective P0-S4/S5/S6 sessions — do not add them here.
 *
 * Constructor injection only — ADR-012. No Container::get() calls inside
 * the closures; all dependencies are resolved through the factory arguments.
 */
final class CoreServiceProvider extends ServiceProvider
{
    public function __construct(private readonly array $config) {}

    public function register(object $container): void
    {
        assert($container instanceof Container);

        // Config values are bound as singletons for cheap repeated access.
        $container->singleton('config.app',           fn() => $this->config['app'] ?? []);
        $container->singleton('config.database',      fn() => $this->config['database'] ?? []);
        $container->singleton('config.queue',         fn() => $this->config['queue'] ?? []);
        $container->singleton('config.modules',       fn() => $this->config['modules'] ?? []);
        $container->singleton('config.security',      fn() => $this->config['security'] ?? []);
        $container->singleton('config.logging',       fn() => $this->config['logging'] ?? []);
        $container->singleton('config.observability', fn() => $this->config['observability'] ?? []);
    }
}
