<?php

declare(strict_types=1);

namespace HSP\Core\Container;

use HSP\Core\Contracts\ServiceProviderInterface;

/**
 * Base class for service providers.
 *
 * Concrete providers override register() and optionally boot().
 * Neither method may call Container::get() — all dependencies must be
 * received through the constructor of the class being registered (ADR-012).
 */
abstract class ServiceProvider implements ServiceProviderInterface
{
    public function boot(object $container): void
    {
        // Default: no boot-time setup. Override if needed.
    }
}
