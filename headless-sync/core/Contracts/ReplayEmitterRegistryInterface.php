<?php

declare(strict_types=1);

namespace HSP\Core\Contracts;

/**
 * Core-owned registry of module replay emitters, keyed by aggregate type (DECISION AG AG-2).
 *
 * Replaces the single `ReplayEmitterInterface` container binding. That shape was unsafe the
 * moment a second module existed: Container::singleton() is last-writer-wins with no error,
 * so a second module binding the same key silently DELETED the first module's emitter, and
 * replay for every one of its aggregates would have failed with "no emitter" — or worse,
 * quietly stopped being exercised.
 *
 * Registration is explicit and per aggregate type. Duplicate registration throws.
 */
interface ReplayEmitterRegistryInterface
{
    /**
     * Register an emitter for every aggregate type it declares support for.
     *
     * @throws \LogicException if any of its aggregate types is already registered by
     *                         another emitter.
     */
    public function register(ReplayEmitterInterface $emitter): void;

    public function has(string $aggregateType): bool;

    /**
     * @throws \InvalidArgumentException if no emitter is registered for the type.
     */
    public function get(string $aggregateType): ReplayEmitterInterface;

    /**
     * Every aggregate type that can be re-emitted.
     *
     * @return list<string>
     */
    public function aggregateTypes(): array;
}
