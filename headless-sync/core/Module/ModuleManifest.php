<?php

declare(strict_types=1);

namespace HSP\Core\Module;

use HSP\Core\Module\Exception\InvalidManifestException;

/**
 * Value object representing a parsed module.json manifest.
 *
 * Doc 2 §11: manifests are mandatory. Required fields: name, version,
 * module_class, schema_version. Optional: requires (defaults to []).
 */
final class ModuleManifest
{
    private function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $moduleClass,
        public readonly string $schemaVersion,
        /** @var string[] */
        public readonly array $requires,
        public readonly string $manifestPath,
    ) {}

    /**
     * @param array<string,mixed> $data   Decoded JSON from module.json
     * @param string              $path   Filesystem path to the manifest (for error messages)
     *
     * @throws InvalidManifestException
     */
    public static function fromArray(array $data, string $path): self
    {
        $required = ['name', 'version', 'module_class', 'schema_version'];
        foreach ($required as $field) {
            if (! isset($data[$field]) || ! is_string($data[$field]) || $data[$field] === '') {
                throw new InvalidManifestException(
                    "Manifest '{$path}' is missing or has an empty required field: '{$field}'."
                );
            }
        }

        $requires = $data['requires'] ?? [];
        if (! is_array($requires)) {
            throw new InvalidManifestException(
                "Manifest '{$path}': 'requires' must be an array if present."
            );
        }

        return new self(
            name:          $data['name'],
            version:       $data['version'],
            moduleClass:   $data['module_class'],
            schemaVersion: $data['schema_version'],
            requires:      array_values(array_filter($requires, 'is_string')),
            manifestPath:  $path,
        );
    }
}
