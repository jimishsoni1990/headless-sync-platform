<?php

declare(strict_types=1);

namespace HSP\Core\Configuration;

use HSP\Bootstrap\Environment;

/**
 * Loads and merges configuration layers in the order mandated by Architect Ruling 1:
 *
 *   Global Config → Module Config → Environment Override
 *   (each layer merges onto the prior; higher wins on conflict)
 *
 * Rules:
 * - Global config comes from config/*.php (this directory).
 * - Module config is namespaced (e.g. content.*, system.*); it may only override
 *   keys within its own namespace — never unrelated platform settings.
 * - Environment overrides (env vars) are the top layer and always win.
 * - Secrets must never appear in any file-based layer (Doc 10 §9).
 *
 * Module config loading is intentionally a no-op in P0-S1: there are no modules yet.
 * The merge contract is established here so P0-S3 (module registry) only needs to
 * call addModuleConfig() and re-call load().
 */
final class ConfigLoader
{
    /** @var array<string, array> Module config segments, keyed by namespace e.g. 'content' */
    private array $moduleConfigs = [];

    private const CONFIG_FILES = [
        'app',
        'database',
        'queue',
        'modules',
        'security',
        'logging',
        'observability',
    ];

    public function __construct(private readonly string $configDir) {}

    /**
     * Register a module's namespaced config slice.
     *
     * @param string $namespace The module's config namespace (e.g. 'content').
     * @param array  $config    The module's config array.
     */
    public function addModuleConfig(string $namespace, array $config): void
    {
        $this->moduleConfigs[$namespace] = $config;
    }

    /**
     * Load and merge all config layers, returning the effective config array.
     *
     * Effective precedence (Architect Ruling 1):
     *   Environment Override > Module Config > Global Config
     *
     * Implementation: merge in load order Global → Module → Environment,
     * so each subsequent layer wins on overlapping keys.
     */
    public function load(): array
    {
        $config = $this->loadGlobalConfig();
        $config = $this->mergeModuleConfigs($config);
        $config = $this->applyEnvironmentOverrides($config);

        return $config;
    }

    private function loadGlobalConfig(): array
    {
        $config = [];

        foreach (self::CONFIG_FILES as $file) {
            $path = rtrim($this->configDir, '/\\') . DIRECTORY_SEPARATOR . $file . '.php';

            if (file_exists($path)) {
                $values = require $path;

                if (is_array($values)) {
                    $config[$file] = $values;
                }
            }
        }

        return $config;
    }

    /**
     * Merge module config slices onto the base config.
     *
     * Module config must be namespaced — only keys within the module's own
     * namespace participate in resolution (Architect Ruling 1). A module named
     * 'content' may set keys under $config['content'] only; it must never
     * overwrite top-level platform keys like 'database' or 'queue'.
     */
    private function mergeModuleConfigs(array $config): array
    {
        foreach ($this->moduleConfigs as $namespace => $moduleConfig) {
            $existing = $config[$namespace] ?? [];
            $config[$namespace] = array_replace_recursive($existing, $moduleConfig);
        }

        return $config;
    }

    /**
     * Apply environment variable overrides as the top layer.
     *
     * Dot-notation keys (e.g. 'database.pgsql.host') are expanded and merged
     * recursively so that an env var for a leaf key does not blow away sibling keys.
     */
    private function applyEnvironmentOverrides(array $config): array
    {
        $overrides = Environment::overrides();

        foreach ($overrides as $dotKey => $value) {
            $config = $this->setDotKey($config, $dotKey, $value);
        }

        return $config;
    }

    /**
     * Write a dot-notation key into a nested array without destroying siblings.
     */
    private function setDotKey(array $array, string $key, mixed $value): array
    {
        $parts   = explode('.', $key);
        $current = &$array;

        foreach ($parts as $i => $part) {
            if ($i === count($parts) - 1) {
                $current[$part] = $value;
            } else {
                if (! isset($current[$part]) || ! is_array($current[$part])) {
                    $current[$part] = [];
                }
                $current = &$current[$part];
            }
        }

        return $array;
    }
}
