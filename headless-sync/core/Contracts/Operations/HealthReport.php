<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Operations;

/**
 * Immutable, point-in-time health report from a HealthProviderInterface (ADR-049).
 *
 * Current operational state ONLY — no history is stored or implied (ADR-049; DECISION P
 * single current-state heartbeat row; DECISION Q no metrics persistence). A report names
 * the component it describes, an overall Severity, a human-readable summary, and an
 * optional bag of read-only detail values for display.
 *
 * Fields:
 *   $component  — the subsystem this report describes (e.g. 'queue', 'workers', 'database').
 *   $severity   — overall current severity (ADR-049 scale).
 *   $summary    — one-line human-readable status.
 *   $details    — arbitrary read-only key/value details for display; no behaviour.
 *
 * @psalm-immutable
 */
final class HealthReport
{
    /**
     * @param array<string,scalar|null> $details
     */
    public function __construct(
        public readonly string $component,
        public readonly Severity $severity,
        public readonly string $summary,
        public readonly array $details = [],
    ) {}
}
