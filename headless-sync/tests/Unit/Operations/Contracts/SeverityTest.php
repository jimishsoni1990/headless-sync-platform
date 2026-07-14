<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Contracts;

use HSP\Core\Contracts\Operations\Severity;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Severity scale (ADR-049 — common diagnostic severity vocabulary).
 */
final class SeverityTest extends TestCase
{
    public function test_ranks_are_ordered_least_to_most_severe(): void
    {
        self::assertLessThan(Severity::CRITICAL->rank(), Severity::OK->rank());
        self::assertLessThan(Severity::ERROR->rank(), Severity::WARNING->rank());
        self::assertSame(0, Severity::OK->rank());
        self::assertSame(4, Severity::CRITICAL->rank());
    }

    public function test_max_returns_the_more_severe(): void
    {
        self::assertSame(Severity::ERROR, Severity::WARNING->max(Severity::ERROR));
        self::assertSame(Severity::ERROR, Severity::ERROR->max(Severity::WARNING));
        self::assertSame(Severity::OK, Severity::OK->max(Severity::OK));
    }

    public function test_backed_values_are_stable(): void
    {
        self::assertSame('ok', Severity::OK->value);
        self::assertSame('critical', Severity::CRITICAL->value);
    }
}
