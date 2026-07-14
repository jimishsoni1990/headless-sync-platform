<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Operations;

/**
 * Immutable descriptor of one Operations Console widget (Doc 12 §7).
 *
 * Core owns widget infrastructure; modules provide widget implementations behind
 * core-owned contracts (Rule 5). Widgets never poll independently — the Refresh
 * Coordinator (Doc 12 §8) drives all refresh and populates the Console State Store,
 * from which widgets read. This descriptor names the widget, the page it belongs to,
 * and which provider key supplies its runtime data; it carries NO live data itself.
 *
 * Fields:
 *   $id           — stable identifier, unique within the Widget Registry.
 *   $title        — human-readable widget heading.
 *   $pageSlug     — slug of the ConsolePage this widget is placed on.
 *   $providerKey  — key of the provider whose snapshot feeds this widget
 *                   (resolved by the Operations Services layer, never by the widget).
 *   $position     — ordering hint within the page (lower sorts first).
 */
final class ConsoleWidget
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $pageSlug,
        public readonly string $providerKey,
        public readonly int $position = 10,
    ) {}
}
