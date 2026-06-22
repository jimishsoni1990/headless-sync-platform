<?php

declare(strict_types=1);

namespace HSP\Core\Delivery;

use HSP\Core\Contracts\AdapterInterface;

/**
 * Registry of delivery adapters keyed by the canonical model class they accept.
 *
 * Authority: IMPLEMENTATION_PLAN.md §4 Phase 0 deliverables:
 *   "Explicit registration only; no reflection-based discovery"
 *
 * Each adapter declares the canonical model class it handles via
 * AdapterInterface::getCanonicalModelClass(). Exactly one adapter may be
 * registered per canonical model class. Registering a second adapter for the
 * same class is a programming error and throws \LogicException — like EventRegistry,
 * a duplicate is a misconfigured composition root, not a supported upgrade path.
 *
 * Adapters are registered by module ServiceProviders during the register()
 * phase (OPEN-9). No reflection, no filesystem scanning, no wildcard patterns.
 *
 * Authority:
 *   DECISION D (v1.4)  — AdapterInterface::persist() + bulkPersist()
 *   DECISION 3         — adapters commit three-op PG transactions atomically
 *   CLAUDE.md Rule 5   — module-to-module imports prohibited; adapters depend
 *                        on core/Contracts/ only (AdapterInterface, CanonicalModelInterface)
 */
final class AdapterRegistry
{
    /** @var array<string, AdapterInterface> canonical_model_class → adapter */
    private array $adapters = [];

    /**
     * Register an adapter for its declared canonical model class.
     *
     * @throws \InvalidArgumentException if the adapter returns an empty class name
     * @throws \LogicException           if an adapter is already registered for this class
     */
    public function register(AdapterInterface $adapter): void
    {
        $class = $adapter->getCanonicalModelClass();
        $this->assertValidClass($class);

        if (isset($this->adapters[$class])) {
            throw new \LogicException(
                "An adapter for canonical model class '{$class}' is already registered. "
                . 'Duplicate registration is a composition-root error.'
            );
        }

        $this->adapters[$class] = $adapter;
    }

    /**
     * Returns true if an adapter is registered for the given canonical model class.
     */
    public function has(string $canonicalModelClass): bool
    {
        return isset($this->adapters[$canonicalModelClass]);
    }

    /**
     * Returns the adapter for the given canonical model class.
     *
     * @throws \RuntimeException if no adapter is registered for the class
     */
    public function get(string $canonicalModelClass): AdapterInterface
    {
        if (! $this->has($canonicalModelClass)) {
            throw new \RuntimeException(
                "No adapter registered for canonical model class '{$canonicalModelClass}'."
            );
        }

        return $this->adapters[$canonicalModelClass];
    }

    /**
     * Returns all registered canonical model class names.
     *
     * @return string[]
     */
    public function getRegisteredClasses(): array
    {
        return array_keys($this->adapters);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * @throws \InvalidArgumentException
     */
    private function assertValidClass(string $class): void
    {
        if ($class === '') {
            throw new \InvalidArgumentException(
                'AdapterInterface::getCanonicalModelClass() must return a non-empty string.'
            );
        }
    }
}
