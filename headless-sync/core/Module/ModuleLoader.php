<?php

declare(strict_types=1);

namespace HSP\Core\Module;

use HSP\Core\Container\Container;
use HSP\Core\Contracts\ModuleInterface;
use HSP\Core\Module\Exception\InvalidManifestException;

/**
 * Resolves the module class declared in a ModuleManifest through the DI container.
 *
 * Resolution order (FLAG-P1AS6-2 Gap B fix; architect ruling 2026-06-24):
 *   1. If the container has an explicit binding for the module class, use it.
 *      This is the required path for modules with non-empty constructors
 *      (e.g. ContentModule requires HookWiring + EventProviderInterface).
 *   2. If no container binding exists AND the class has a zero-argument
 *      constructor, fall back to new $class(). This preserves compatibility
 *      with modules that genuinely have no dependencies.
 *
 * Reflection-based autowiring is explicitly prohibited (IMPLEMENTATION_PLAN.md §4,
 * "explicit registration only"). The container path is an explicit lookup against
 * a registered binding — not autowiring.
 *
 * ADR-012 — constructor injection only; service-locator calls inside business
 * logic are prohibited. ModuleLoader is composition-root infrastructure, not
 * business logic, so container->get() is permitted here.
 */
class ModuleLoader
{
    public function __construct(private readonly Container $container) {}

    /**
     * @throws InvalidManifestException  If the class does not exist, does not implement
     *                                   ModuleInterface, or has constructor dependencies
     *                                   but no container binding was registered.
     */
    public function load(ModuleManifest $manifest): ModuleInterface
    {
        $class = $manifest->moduleClass;

        if (! class_exists($class)) {
            throw new InvalidManifestException(
                "Module class '{$class}' declared in '{$manifest->manifestPath}' could not be found."
            );
        }

        if ($this->container->has($class)) {
            $instance = $this->container->get($class);
        } else {
            $instance = $this->instantiateWithoutContainer($class, $manifest->manifestPath);
        }

        if (! ($instance instanceof ModuleInterface)) {
            throw new InvalidManifestException(
                "Module class '{$class}' declared in '{$manifest->manifestPath}'"
                . " does not implement " . ModuleInterface::class . "."
            );
        }

        return $instance;
    }

    /**
     * Fallback instantiation for modules with zero-argument constructors.
     *
     * Throws if the class has required constructor parameters but no container
     * binding was registered — that is a misconfiguration, not a runtime error
     * we should silently swallow.
     *
     * @throws InvalidManifestException
     */
    private function instantiateWithoutContainer(string $class, string $manifestPath): object
    {
        $ref    = new \ReflectionClass($class);
        $ctor   = $ref->getConstructor();
        $params = $ctor ? $ctor->getParameters() : [];

        $required = array_filter(
            $params,
            fn (\ReflectionParameter $p) => ! $p->isOptional(),
        );

        if (count($required) > 0) {
            throw new InvalidManifestException(
                "Module class '{$class}' declared in '{$manifestPath}' has required constructor"
                . " parameters but no container binding was registered for it."
                . " Add a binding in the module's ServiceProvider."
            );
        }

        return new $class();
    }
}
