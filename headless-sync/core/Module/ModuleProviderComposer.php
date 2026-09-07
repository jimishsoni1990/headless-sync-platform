<?php

declare(strict_types=1);

namespace HSP\Core\Module;

use HSP\Core\Contracts\ModuleAvailabilityInterface;
use HSP\Core\Contracts\ServiceProviderInterface;
use HSP\Core\Module\Exception\InvalidManifestException;

/**
 * Builds each discovered module's ServiceProvider so the composition root can register
 * them generically — DECISION AG (AG-1): core must not import or hardcode a concrete
 * module class.
 *
 * Why the provider class comes from the MANIFEST and not from
 * ModuleInterface::getServiceProvider(): a module instance is resolved from the
 * container, and its constructor dependencies are bound by its own provider, so the
 * instance cannot exist until that provider has already registered. Reading the class
 * name from module.json breaks that cycle without reflection-based scanning — the glob
 * over modules/{name}/module.json is the same explicit discovery Doc 2 §11 already
 * mandates. `getServiceProvider()` still returns the very same provider instance for
 * anything that holds a module.
 *
 * Availability (AG-12) is evaluated HERE, before a single binding is registered: a
 * provider implementing ModuleAvailabilityInterface and reporting false is skipped
 * entirely, so an unavailable module contributes no bindings, no boot behaviour, no
 * hooks, no registry entries and no backfill counts.
 *
 * Constructor injection only — ADR-012. This is composition-root infrastructure.
 */
final class ModuleProviderComposer
{
    public function __construct(private readonly ModuleDiscovery $discovery)
    {
    }

    /**
     * Discovers modules and instantiates the AVAILABLE ones' service providers.
     *
     * @throws InvalidManifestException If a declared provider class is missing, is not a
     *                                  ServiceProviderInterface, or cannot be constructed
     *                                  without arguments. Each is a misconfiguration that
     *                                  must fail loudly rather than silently disable a module.
     */
    public function compose(): ModuleComposition
    {
        $providers   = [];
        $available   = [];
        $unavailable = [];

        foreach ($this->discovery->discover() as $manifest) {
            $provider = $this->instantiateProvider($manifest);

            if ($provider instanceof ModuleAvailabilityInterface && ! $provider->isAvailable()) {
                // Normal state, not an error: the module's external dependency is absent.
                $unavailable[] = $manifest->name;
                continue;
            }

            $providers[] = $provider;
            $available[] = $manifest->name;
        }

        return new ModuleComposition($providers, $available, $unavailable);
    }

    /**
     * @throws InvalidManifestException
     */
    private function instantiateProvider(ModuleManifest $manifest): ServiceProviderInterface
    {
        $class = $manifest->serviceProvider;

        if (! class_exists($class)) {
            throw new InvalidManifestException(
                "Service provider '{$class}' declared in '{$manifest->manifestPath}' could not be found."
            );
        }

        $ref  = new \ReflectionClass($class);
        $ctor = $ref->getConstructor();

        // A module service provider is the composition entry point and therefore must be
        // constructible with no arguments — it receives everything it needs through the
        // container inside register()/boot().
        if ($ctor !== null) {
            foreach ($ctor->getParameters() as $param) {
                if (! $param->isOptional()) {
                    throw new InvalidManifestException(
                        "Service provider '{$class}' declared in '{$manifest->manifestPath}' has"
                        . " required constructor parameters; a module service provider must be"
                        . " constructible with no arguments."
                    );
                }
            }
        }

        $provider = new $class();

        if (! ($provider instanceof ServiceProviderInterface)) {
            throw new InvalidManifestException(
                "Service provider '{$class}' declared in '{$manifest->manifestPath}' does not implement "
                . ServiceProviderInterface::class . "."
            );
        }

        return $provider;
    }
}
