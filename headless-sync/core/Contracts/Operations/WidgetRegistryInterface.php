<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Operations;

/**
 * Registry of Operations Console widgets (Doc 12 §7; ADR-048/ADR-052).
 *
 * EXPLICIT REGISTRATION ONLY — no reflection or scanning. Widgets are registered by
 * ServiceProviders during register(). Core owns widget infrastructure; modules provide
 * implementations behind this core-owned contract (Rule 5). Duplicate widget id is a
 * composition-root error and MUST throw \LogicException.
 *
 * Widgets never poll independently (Doc 12 §7/§8): the descriptor only names the provider
 * whose snapshot feeds the widget; the Refresh Coordinator + Console State Store supply
 * the data. This contract depends on nothing outside core/Contracts/.
 */
interface WidgetRegistryInterface
{
    /**
     * Register a widget.
     *
     * @throws \InvalidArgumentException if the widget id, page slug, or provider key is empty.
     * @throws \LogicException           if a widget with the same id is already registered.
     */
    public function register(ConsoleWidget $widget): void;

    /**
     * All registered widgets, ordered by position (ascending) then registration order.
     *
     * @return ConsoleWidget[]
     */
    public function all(): array;

    /**
     * Registered widgets placed on the given page, in the same order as all().
     *
     * @return ConsoleWidget[]
     */
    public function forPage(string $pageSlug): array;
}
