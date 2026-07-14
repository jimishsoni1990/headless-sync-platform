<?php

declare(strict_types=1);

namespace HSP\Core\Operations\Registries;

use HSP\Core\Contracts\Operations\NavigationItem;
use HSP\Core\Contracts\Operations\NavigationRegistryInterface;

/**
 * Explicit-registration registry of Operations Console navigation items (Doc 12 §6;
 * ADR-048/ADR-052).
 *
 * Keyed by label. Duplicate label is a composition-root error (\LogicException). The
 * registry does not verify that $pageSlug resolves to a registered page — pages and nav may
 * be registered in any order; that cross-check belongs to a consuming service. all() is
 * ordered by NavigationItem::$position ascending, ties broken by registration order.
 */
final class NavigationRegistry implements NavigationRegistryInterface
{
    /** @var array<string, NavigationItem> label → item */
    private array $items = [];

    /** @var array<string, int> label → registration sequence (stable tiebreaker) */
    private array $sequence = [];

    private int $next = 0;

    public function register(NavigationItem $item): void
    {
        if ($item->label === '') {
            throw new \InvalidArgumentException('NavigationItem label must not be empty.');
        }

        if ($item->pageSlug === '') {
            throw new \InvalidArgumentException(
                "NavigationItem '{$item->label}' must target a non-empty page slug."
            );
        }

        if (isset($this->items[$item->label])) {
            throw new \LogicException(
                "A navigation item with label '{$item->label}' is already registered. "
                . 'Duplicate registration is a composition-root error.'
            );
        }

        $this->items[$item->label]    = $item;
        $this->sequence[$item->label] = $this->next++;
    }

    public function all(): array
    {
        $items = array_values($this->items);

        usort($items, function (NavigationItem $a, NavigationItem $b): int {
            return [$a->position, $this->sequence[$a->label]]
               <=> [$b->position, $this->sequence[$b->label]];
        });

        return $items;
    }
}
