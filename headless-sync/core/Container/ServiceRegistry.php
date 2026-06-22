<?php

declare(strict_types=1);

namespace HSP\Core\Container;

use HSP\Core\Contracts\ServiceProviderInterface;

/**
 * Collects ServiceProviders and applies them to the container in registration order.
 *
 * Two-phase lifecycle (mirrors WP plugin boot):
 *   1. register() — all providers register bindings.
 *   2. boot()     — all providers run boot-time logic (safe to use registered services).
 */
final class ServiceRegistry
{
    /** @var ServiceProviderInterface[] */
    private array $providers = [];

    public function addProvider(ServiceProviderInterface $provider): void
    {
        $this->providers[] = $provider;
    }

    public function registerAll(Container $container): void
    {
        foreach ($this->providers as $provider) {
            $provider->register($container);
        }
    }

    public function bootAll(Container $container): void
    {
        foreach ($this->providers as $provider) {
            $provider->boot($container);
        }
    }
}
