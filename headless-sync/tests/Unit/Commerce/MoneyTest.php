<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Commerce;

use HSP\Modules\Commerce\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * P2-S2 — monetary normalisation (DECISION AG Requirement C).
 *
 * The canonical form is what the DECISION 3 checksum is computed over, so its determinism
 * decides whether an unchanged price re-projects forever. Phase 1B hit that write-suppress
 * trap three times; this is the guard that stops money becoming the fourth.
 */
final class MoneyTest extends TestCase
{
    /** @return array<string, array{0: string|int|float|null, 1: string|null}> */
    public static function values(): array
    {
        return [
            // The case the whole class exists for: these must all hash identically.
            'integer string'        => ['10', '10'],
            'one decimal place'     => ['10.0', '10'],
            'two decimal places'    => ['10.00', '10'],
            'many trailing zeros'   => ['10.000000', '10'],
            'integer'               => [10, '10'],

            'significant fraction'  => ['10.50', '10.5'],
            'full precision kept'   => ['19.99', '19.99'],
            'four decimal places'   => ['1.2345', '1.2345'],

            // Not rounded and not padded — narrowing the source value is what Requirement C
            // forbids, since store decimal configuration is not guaranteed to be 2 places.
            'high precision'        => ['0.123456789', '0.123456789'],

            'leading zeros'         => ['007.50', '7.5'],
            'bare fraction'         => ['.5', '0.5'],
            'trailing point'        => ['10.', '10'],
            'zero'                  => ['0', '0'],
            'zero with decimals'    => ['0.00', '0'],
            'negative zero'         => ['-0.00', '0'],
            'negative'              => ['-5.250', '-5.25'],
            'explicit plus'         => ['+3.10', '3.1'],
            'whitespace'            => ['  12.30  ', '12.3'],

            // "No price set" is a real WooCommerce state and must stay distinct from zero:
            // a product with no price is not a free product.
            'empty string'          => ['', null],
            'null'                  => [null, null],

            // A corrupted price must surface, never silently become 0.
            'not a number'          => ['abc', null],
            'currency symbol'       => ['$10.00', null],
            'thousands separator'   => ['1,000.00', null],
        ];
    }

    #[DataProvider('values')]
    public function testNormalisesToACanonicalDecimalString(
        string|int|float|null $input,
        ?string $expected,
    ): void {
        self::assertSame($expected, Money::normalize($input));
    }

    /**
     * The determinism assertion stated directly: equal values must produce one string, so the
     * canonical checksum cannot move when the price has not.
     */
    public function testSemanticallyEqualValuesShareOneCanonicalForm(): void
    {
        $forms = ['10', '10.0', '10.00', '10.000', '0010.00', ' 10.0 '];

        $normalized = array_map(static fn ($v): ?string => Money::normalize($v), $forms);

        self::assertSame(['10'], array_values(array_unique($normalized)));
    }

    public function testFloatsDoNotLeakBinaryRepresentationArtefacts(): void
    {
        // A third-party filter may hand back a float; it must not reach the checksum as
        // 10.199999999999999 or as scientific notation.
        self::assertSame('10.2', Money::normalize(10.2));
        self::assertSame('0.3', Money::normalize(0.1 + 0.2));
    }
}
