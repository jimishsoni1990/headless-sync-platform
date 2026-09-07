<?php

declare(strict_types=1);

namespace HSP\Core\Module;

use HSP\Core\Module\Exception\InvalidManifestException;

/**
 * Discovers modules by scanning the modules/ directory for module.json manifests.
 *
 * Doc 2 §11: manifests live at modules/{name}/module.json and are mandatory.
 * Returns one ModuleManifest per discovered module, in filesystem glob order
 * (deterministic within a run; sorted by module directory name).
 *
 * Constructor injection only — ADR-012.
 */
class ModuleDiscovery
{
    /**
     * @param string $modulesBasePath Absolute path to the modules/ directory.
     */
    public function __construct(private readonly string $modulesBasePath) {}

    /**
     * Scans modulesBasePath for module.json files and returns parsed manifests.
     *
     * @return ModuleManifest[]
     * @throws InvalidManifestException  If any manifest is malformed or unreadable.
     */
    public function discover(): array
    {
        $pattern  = rtrim($this->modulesBasePath, '/\\') . '/*/module.json';
        $paths    = glob($pattern, GLOB_NOSORT);

        if ($paths === false) {
            return [];
        }

        sort($paths);

        $manifests = [];
        $seen      = [];
        foreach ($paths as $path) {
            $manifest = $this->parseManifest($path);

            // Duplicate module names must fail loudly (DECISION AG AG-1). Two modules
            // claiming one name would silently collide in every name-keyed structure —
            // the registry map, the bootstrap-state option, module_versions.
            if (isset($seen[$manifest->name])) {
                throw new InvalidManifestException(
                    "Duplicate module name '{$manifest->name}': declared in both"
                    . " '{$seen[$manifest->name]}' and '{$path}'."
                );
            }
            $seen[$manifest->name] = $path;

            $manifests[] = $manifest;
        }

        return $manifests;
    }

    /**
     * @throws InvalidManifestException
     */
    private function parseManifest(string $path): ModuleManifest
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new InvalidManifestException(
                "Cannot read module manifest at '{$path}'."
            );
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            throw new InvalidManifestException(
                "Module manifest at '{$path}' is not valid JSON."
            );
        }

        return ModuleManifest::fromArray($data, $path);
    }
}
