<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Operations;

/**
 * Registry of Operations Console navigation items (Doc 12 §6; ADR-048/ADR-052).
 *
 * EXPLICIT REGISTRATION ONLY — no reflection or scanning. Items are registered by
 * ServiceProviders during register(). A navigation item points at a ConsolePage by its
 * slug; the registry does not itself verify page existence (pages and nav may be
 * registered in any order) — that consistency check belongs to a consuming service.
 * Duplicate label registration is a composition-root error and MUST throw \LogicException.
 *
 * Core owns this contract; depends on nothing outside core/Contracts/.
 */
interface NavigationRegistryInterface
{
    /**
     * Register a navigation item.
     *
     * @throws \InvalidArgumentException if the label or target page slug is empty.
     * @throws \LogicException           if an item with the same label is already registered.
     */
    public function register(NavigationItem $item): void;

    /**
     * All registered navigation items, ordered by position (ascending) then registration order.
     *
     * @return NavigationItem[]
     */
    public function all(): array;
}
