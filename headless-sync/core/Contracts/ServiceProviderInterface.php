<?php

declare(strict_types=1);

namespace HSP\Core\Contracts;

/**
 * Registers a module's or core component's services into the DI container.
 *
 * Constructor injection only: implementations must not call Container::get()
 * or any service-locator pattern (ADR-012 / CLAUDE.md rule 7).
 *
 * Service providers are the only place where the container binding API is used.
 * Business logic classes must not reference the container at all.
 */
interface ServiceProviderInterface
{
    /**
     * Register all bindings provided by this provider.
     *
     * @param object $container The DI container instance (typed as object to avoid coupling
     *                          contracts to the concrete container class before it exists)
     */
    public function register(object $container): void;

    /**
     * Boot phase: called after all providers have been registered.
     * Use for actions that depend on other providers being registered first.
     */
    public function boot(object $container): void;
}
