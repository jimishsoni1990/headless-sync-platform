<?php

declare(strict_types=1);

namespace HSP\Core\Reconciliation;

use HSP\Core\Contracts\ReconciliationSourceRegistryInterface;
use HSP\Core\Contracts\WpReconciliationSourceInterface;

/**
 * Explicit registry of module reconciliation sources (DECISION AG AG-2).
 *
 * Module isolation (Rule 5): keyed by aggregate type, depends only on the core-owned
 * source contract, never imports a module.
 */
final class ReconciliationSourceRegistry implements ReconciliationSourceRegistryInterface
{
    /** @var array<string, WpReconciliationSourceInterface> */
    private array $byType = [];

    /** @var list<WpReconciliationSourceInterface> */
    private array $sources = [];

    public function register(WpReconciliationSourceInterface $source): void
    {
        foreach ($source->getSupportedAggregateTypes() as $type) {
            if (isset($this->byType[$type])) {
                throw new \LogicException(
                    "Duplicate reconciliation source for aggregate type '{$type}': already registered by "
                    . $this->byType[$type]::class . ', attempted by ' . $source::class . '.'
                    . ' Two modules cannot own one aggregate type.'
                );
            }

            $this->byType[$type] = $source;
        }

        if (! in_array($source, $this->sources, true)) {
            $this->sources[] = $source;
        }
    }

    public function has(string $aggregateType): bool
    {
        return isset($this->byType[$aggregateType]);
    }

    public function get(string $aggregateType): WpReconciliationSourceInterface
    {
        if (! isset($this->byType[$aggregateType])) {
            throw new \InvalidArgumentException(
                "No reconciliation source registered for aggregate type '{$aggregateType}'."
            );
        }

        return $this->byType[$aggregateType];
    }

    public function aggregateTypes(): array
    {
        return array_keys($this->byType);
    }

    public function sources(): array
    {
        return $this->sources;
    }
}
