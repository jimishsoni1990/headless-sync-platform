<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Operations;

/**
 * Diagnostic severity scale (ADR-049 / Doc 12 §11).
 *
 * A common severity vocabulary shared by all diagnostics providers so the console can
 * roll up and colour-code current operational state uniformly. Ordered least → most
 * severe via rank(); "current state only" (no history — ADR-049, DECISION P/Q).
 */
enum Severity: string
{
    case OK       = 'ok';
    case INFO     = 'info';
    case WARNING  = 'warning';
    case ERROR    = 'error';
    case CRITICAL = 'critical';

    /**
     * Monotonic rank (OK lowest, CRITICAL highest) for comparison and rollup.
     */
    public function rank(): int
    {
        return match ($this) {
            self::OK       => 0,
            self::INFO     => 1,
            self::WARNING  => 2,
            self::ERROR    => 3,
            self::CRITICAL => 4,
        };
    }

    /**
     * Return the more severe of two severities.
     */
    public function max(self $other): self
    {
        return $this->rank() >= $other->rank() ? $this : $other;
    }
}
