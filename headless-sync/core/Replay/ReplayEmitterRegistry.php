<?php

declare(strict_types=1);

namespace HSP\Core\Replay;

use HSP\Core\Contracts\ReplayEmitterInterface;
use HSP\Core\Contracts\ReplayEmitterRegistryInterface;

/**
 * Explicit registry of module replay emitters (DECISION AG AG-2).
 *
 * Module isolation (Rule 5): keyed by aggregate type, depends only on the core-owned
 * emitter contract, never imports a module.
 */
final class ReplayEmitterRegistry implements ReplayEmitterRegistryInterface
{
    /** @var array<string, ReplayEmitterInterface> */
    private array $byType = [];

    public function register(ReplayEmitterInterface $emitter): void
    {
        foreach ($emitter->getSupportedAggregateTypes() as $type) {
            if (isset($this->byType[$type])) {
                throw new \LogicException(
                    "Duplicate replay emitter for aggregate type '{$type}': already registered by "
                    . $this->byType[$type]::class . ', attempted by ' . $emitter::class . '.'
                    . ' Two modules cannot own one aggregate type.'
                );
            }

            $this->byType[$type] = $emitter;
        }
    }

    public function has(string $aggregateType): bool
    {
        return isset($this->byType[$aggregateType]);
    }

    public function get(string $aggregateType): ReplayEmitterInterface
    {
        if (! isset($this->byType[$aggregateType])) {
            throw new \InvalidArgumentException(
                "No replay emitter registered for aggregate type '{$aggregateType}'."
            );
        }

        return $this->byType[$aggregateType];
    }

    public function aggregateTypes(): array
    {
        return array_keys($this->byType);
    }
}
