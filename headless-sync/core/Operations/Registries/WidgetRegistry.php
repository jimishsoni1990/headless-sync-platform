<?php

declare(strict_types=1);

namespace HSP\Core\Operations\Registries;

use HSP\Core\Contracts\Operations\ConsoleWidget;
use HSP\Core\Contracts\Operations\WidgetRegistryInterface;

/**
 * Explicit-registration registry of Operations Console widgets (Doc 12 §7; ADR-048/ADR-052).
 *
 * Keyed by widget id. Duplicate id is a composition-root error (\LogicException). Widgets
 * name the provider whose snapshot feeds them (they never poll infrastructure directly —
 * Doc 12 §7/§8). all()/forPage() are ordered by ConsoleWidget::$position ascending, ties
 * broken by registration order (stable).
 */
final class WidgetRegistry implements WidgetRegistryInterface
{
    /** @var array<string, ConsoleWidget> id → widget */
    private array $widgets = [];

    /** @var array<string, int> id → registration sequence (stable tiebreaker) */
    private array $sequence = [];

    private int $next = 0;

    public function register(ConsoleWidget $widget): void
    {
        if ($widget->id === '') {
            throw new \InvalidArgumentException('ConsoleWidget id must not be empty.');
        }

        if ($widget->pageSlug === '') {
            throw new \InvalidArgumentException(
                "ConsoleWidget '{$widget->id}' must target a non-empty page slug."
            );
        }

        if ($widget->providerKey === '') {
            throw new \InvalidArgumentException(
                "ConsoleWidget '{$widget->id}' must name a non-empty provider key."
            );
        }

        if (isset($this->widgets[$widget->id])) {
            throw new \LogicException(
                "A widget with id '{$widget->id}' is already registered. "
                . 'Duplicate registration is a composition-root error.'
            );
        }

        $this->widgets[$widget->id]  = $widget;
        $this->sequence[$widget->id] = $this->next++;
    }

    public function all(): array
    {
        $widgets = array_values($this->widgets);

        usort($widgets, function (ConsoleWidget $a, ConsoleWidget $b): int {
            return [$a->position, $this->sequence[$a->id]]
               <=> [$b->position, $this->sequence[$b->id]];
        });

        return $widgets;
    }

    public function forPage(string $pageSlug): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (ConsoleWidget $w): bool => $w->pageSlug === $pageSlug,
        ));
    }
}
