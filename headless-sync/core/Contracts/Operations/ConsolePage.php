<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Operations;

/**
 * Immutable descriptor of one Operations Console page (ADR-048 / Doc 12 §4).
 *
 * A capability descriptor — NOT a renderer. The Page Registry holds these so core
 * can discover which pages exist without hardcoding any of them (ADR-052). The
 * server-rendered UI (OPSC-S3, DECISION V (a)) reads the registry to build nav and
 * route requests; OPSC-S1 defines the descriptor and registry only — no rendering.
 *
 * Fields:
 *   $slug        — stable identifier, unique within the Page Registry (e.g. 'operations').
 *   $title       — human-readable menu/heading label.
 *   $capability  — WordPress capability required to view the page. Enforced at the
 *                  wp-admin boundary in a later session (DECISION V (b)); recorded here
 *                  so the descriptor is self-contained and read-only by default (ADR-053).
 *   $position    — ordering hint for navigation (lower sorts first).
 */
final class ConsolePage
{
    public function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly string $capability,
        public readonly int $position = 10,
    ) {}
}
