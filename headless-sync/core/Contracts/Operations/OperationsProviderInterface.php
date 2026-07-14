<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Operations;

/**
 * Common base for all Operations Console runtime-data providers (Doc 12 §5).
 *
 * Registries discover CAPABILITIES; providers supply RUNTIME DATA. Every provider exposes
 * a stable key() so the Operations Services layer can resolve a provider by the key a
 * ConsoleWidget references — the widget never holds a reference to the provider itself, and
 * never polls infrastructure directly (Doc 12 §7/§8; ADR-053).
 *
 * All providers are current-state / derived-on-demand (ADR-049; DECISION P/Q): they read
 * live operational state at call time and return immutable DTOs. No persistence, no history.
 *
 * Core owns this contract; concrete providers (OPSC-S2) may live in core or in modules
 * behind this contract (Rule 5). Depends on nothing outside core/Contracts/.
 */
interface OperationsProviderInterface
{
    /**
     * Stable provider key, unique per provider (e.g. 'health', 'metrics', 'queue').
     *
     * Widgets reference a provider by this key; the Operations Services layer resolves it.
     */
    public function key(): string;
}
