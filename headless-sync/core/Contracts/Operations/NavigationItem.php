<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Operations;

/**
 * Immutable descriptor of one Operations Console navigation entry (Doc 12 §6).
 *
 * MVP navigation is "Operations" + "API Playground" (Doc 12 §6). Each item points at
 * a registered ConsolePage by its slug. The Navigation Registry holds these so the nav
 * is discovered, never hardcoded (ADR-048/ADR-052).
 *
 * Fields:
 *   $label     — display text (e.g. 'Operations', 'API Playground').
 *   $pageSlug  — slug of the ConsolePage this entry links to.
 *   $position  — ordering hint (lower sorts first).
 */
final class NavigationItem
{
    public function __construct(
        public readonly string $label,
        public readonly string $pageSlug,
        public readonly int $position = 10,
    ) {}
}
