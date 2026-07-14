<?php

declare(strict_types=1);

namespace HSP\Core\Operations\Registries;

use HSP\Core\Contracts\Operations\ActionRegistryInterface;
use HSP\Core\Contracts\Operations\ConsoleAction;

/**
 * Explicit-registration registry of Operations Console actions (Doc 12 §17;
 * ADR-048/ADR-052/ADR-053).
 *
 * Keyed by action key. Duplicate key is a composition-root error (\LogicException);
 * unknown key on get() throws \RuntimeException. all() preserves registration order.
 *
 * OPSC-S1 provides discovery only — NO action behaviour (OPSC-S4). The permitted action set
 * is bound by DECISION V: Replay + Reconcile only (V (d)); no Flush Queue (V (e)); no Restart
 * Workers (V (f)). ADR-051 is HELD and not cited.
 */
final class ActionRegistry implements ActionRegistryInterface
{
    /** @var array<string, ConsoleAction> key → action (insertion order preserved) */
    private array $actions = [];

    public function register(ConsoleAction $action): void
    {
        if ($action->key === '') {
            throw new \InvalidArgumentException('ConsoleAction key must not be empty.');
        }

        if ($action->capability === '') {
            throw new \InvalidArgumentException(
                "ConsoleAction '{$action->key}' must declare a non-empty capability."
            );
        }

        if (isset($this->actions[$action->key])) {
            throw new \LogicException(
                "An action with key '{$action->key}' is already registered. "
                . 'Duplicate registration is a composition-root error.'
            );
        }

        $this->actions[$action->key] = $action;
    }

    public function has(string $key): bool
    {
        return isset($this->actions[$key]);
    }

    public function get(string $key): ConsoleAction
    {
        if (! $this->has($key)) {
            throw new \RuntimeException("No action registered for key '{$key}'.");
        }

        return $this->actions[$key];
    }

    public function all(): array
    {
        return array_values($this->actions);
    }
}
