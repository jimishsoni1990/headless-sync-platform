<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Operations;

/**
 * Registry of Operations Console operational actions (Doc 12 §17; ADR-048/ADR-052/ADR-053).
 *
 * EXPLICIT REGISTRATION ONLY — no reflection or scanning. Actions are registered by
 * ServiceProviders during register(). Duplicate action key is a composition-root error
 * and MUST throw \LogicException; get() on an unknown key MUST throw \RuntimeException.
 *
 * OPSC-S1 provides the registry only — NO action behaviour is wired here (that is OPSC-S4).
 * The permitted action set is bound by DECISION V: Replay + Reconcile only, as thin
 * delegators (DECISION V (d)); NO Flush Queue (V (e)); NO Restart Workers (V (f)).
 * ADR-051 is HELD and MUST NOT be cited; authority is DECISION V (d)/(e)/(f) + ADR-053.
 *
 * Core owns this contract; depends on nothing outside core/Contracts/.
 */
interface ActionRegistryInterface
{
    /**
     * Register an operational action.
     *
     * @throws \InvalidArgumentException if the action key or capability is empty.
     * @throws \LogicException           if an action with the same key is already registered.
     */
    public function register(ConsoleAction $action): void;

    /**
     * Whether an action with the given key is registered.
     */
    public function has(string $key): bool;

    /**
     * Return the registered action for the given key.
     *
     * @throws \RuntimeException if no action is registered for the key.
     */
    public function get(string $key): ConsoleAction;

    /**
     * All registered actions, in registration order.
     *
     * @return ConsoleAction[]
     */
    public function all(): array;
}
