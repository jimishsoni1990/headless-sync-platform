<?php

declare(strict_types=1);

namespace HSP\Core\Module;

use HSP\Core\Contracts\ModuleInterface;
use HSP\Core\Module\Exception\InvalidManifestException;

/**
 * Instantiates the module class declared in a ModuleManifest.
 *
 * The module_class must:
 *   - be autoloadable at load time
 *   - implement the full ModuleInterface union (OPEN-9 v1.4)
 *
 * Constructor injection only — ADR-012.
 */
class ModuleLoader
{
    /**
     * @throws InvalidManifestException  If the class does not exist or does not implement ModuleInterface.
     */
    public function load(ModuleManifest $manifest): ModuleInterface
    {
        $class = $manifest->moduleClass;

        if (! class_exists($class)) {
            throw new InvalidManifestException(
                "Module class '{$class}' declared in '{$manifest->manifestPath}' could not be found."
            );
        }

        $instance = new $class();

        if (! ($instance instanceof ModuleInterface)) {
            throw new InvalidManifestException(
                "Module class '{$class}' declared in '{$manifest->manifestPath}'"
                . " does not implement " . ModuleInterface::class . "."
            );
        }

        return $instance;
    }
}
