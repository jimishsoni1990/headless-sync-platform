<?php

declare(strict_types=1);

namespace HSP\Core\Projection;

use HSP\Core\Contracts\ProjectionDescriptor;
use HSP\Core\Contracts\ProjectionRegistryInterface;

/**
 * Explicit, in-memory registry of module-supplied projection descriptors.
 *
 * DECISION AG (AG-3). Runtime metadata only — zero new persistence (DECISION Q), no PG
 * handle, no `pg_*` wrapper.
 */
final class ProjectionRegistry implements ProjectionRegistryInterface
{
    /** @var array<string, ProjectionDescriptor> */
    private array $descriptors = [];

    public function register(ProjectionDescriptor $descriptor): void
    {
        $type = $descriptor->aggregateType;

        if (isset($this->descriptors[$type])) {
            $existing = $this->descriptors[$type];

            throw new \LogicException(
                "Duplicate projection descriptor for aggregate type '{$type}':"
                . " already registered as '{$existing->table}', attempted '{$descriptor->table}'."
                . ' Two modules cannot own one aggregate type.'
            );
        }

        $this->descriptors[$type] = $descriptor;
    }

    public function has(string $aggregateType): bool
    {
        return isset($this->descriptors[$aggregateType]);
    }

    public function get(string $aggregateType): ProjectionDescriptor
    {
        if (! isset($this->descriptors[$aggregateType])) {
            throw new \InvalidArgumentException(
                "No projection registered for aggregate type '{$aggregateType}'."
                . ' A supported aggregate with no projection must surface here rather than'
                . ' be skipped silently (DECISION AG AG-2/AG-3).'
            );
        }

        return $this->descriptors[$aggregateType];
    }

    public function all(): array
    {
        return $this->descriptors;
    }

    public function aggregateTypes(): array
    {
        return array_keys($this->descriptors);
    }
}
