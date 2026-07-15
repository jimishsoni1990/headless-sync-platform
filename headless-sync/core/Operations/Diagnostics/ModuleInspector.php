<?php

declare(strict_types=1);

namespace HSP\Core\Operations\Diagnostics;

use HSP\Core\Contracts\Operations\ModuleInspection;
use HSP\Core\Contracts\Operations\ModuleInspectionProviderInterface;

/**
 * Aggregates per-module descriptive metadata for the Module Inspector (Doc 12 §14).
 *
 * A directly-queried diagnostics service — NOT on the RefreshCoordinator snapshot path. Each
 * module contributes a ModuleInspectionProviderInterface (implemented in the module, behind
 * the core contract — Rule 5); this core service holds the registered providers by explicit
 * injection (no reflection, no scanning — ADR-048/ADR-052) and returns their descriptors.
 *
 * DESCRIPTIVE METADATA ONLY: this class issues no query and reads no live state (DECISION V
 * (c) — zero persistence; and no PG/MySQL/WP read on the inspection path). Live operational
 * state is the OPSC-S2 providers' job. Providers are injected as a list at the composition
 * root; duplicate module names are a composition-root error.
 */
final class ModuleInspector
{
    /** @var array<string, ModuleInspectionProviderInterface> module name → provider */
    private array $providers = [];

    /**
     * @param iterable<ModuleInspectionProviderInterface> $providers
     */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) {
            $this->add($provider);
        }
    }

    /**
     * Register one module inspection provider (explicit registration only).
     *
     * @throws \LogicException if two providers describe the same module name.
     */
    public function add(ModuleInspectionProviderInterface $provider): void
    {
        $name = $provider->inspect()->name;

        if ($name === '') {
            throw new \InvalidArgumentException('ModuleInspection name must not be empty.');
        }

        if (isset($this->providers[$name])) {
            throw new \LogicException(
                "A module inspection provider for '{$name}' is already registered. "
                . 'Duplicate registration is a composition-root error.'
            );
        }

        $this->providers[$name] = $provider;
    }

    /**
     * All module inspections, ordered by module name for stable display.
     *
     * @return ModuleInspection[]
     */
    public function all(): array
    {
        $inspections = [];
        foreach ($this->providers as $provider) {
            $inspections[] = $provider->inspect();
        }

        usort($inspections, static fn (ModuleInspection $a, ModuleInspection $b) => $a->name <=> $b->name);

        return $inspections;
    }

    /**
     * Inspection for one module by name, or null if no provider describes it.
     */
    public function forModule(string $name): ?ModuleInspection
    {
        return isset($this->providers[$name])
            ? $this->providers[$name]->inspect()
            : null;
    }
}
