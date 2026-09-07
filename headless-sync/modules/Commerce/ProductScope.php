<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce;

/**
 * Which WooCommerce product types Phase 2 projects (DECISION AG AG-13).
 *
 * `simple` and `variable` only. `grouped`, `external`/affiliate and any custom third-party
 * type are OUT of Phase 2 scope.
 *
 * The important half of AG-13 is what "out of scope" means at runtime: an unsupported type is
 * NORMAL SOURCE, not a processing failure. It must not retry repeatedly, must not enter the
 * DLQ merely for being unsupported, must not block reconciliation or bootstrap convergence,
 * and must not count toward backfill expected counts. It is also never coerced into `simple`
 * and never partially projected — a half-populated row presented as a supported product is
 * worse than no row at all.
 *
 * Type TRANSITIONS are the reason this is a shared helper rather than an inline check:
 * WooCommerce product type changes over time, and each direction has a required behaviour
 * (entering scope projects normally; leaving scope tombstones through DECISION I/T/U).
 *
 * Verified against WooCommerce 11.1.0: WC_Product::get_type() returns the ProductType enum's
 * string values — see docs/notes/WOOCOMMERCE-SOURCE-VERIFICATION.md.
 */
final class ProductScope
{
    private function __construct()
    {
    }

    public const SIMPLE   = 'simple';
    public const VARIABLE = 'variable';

    /** @var list<string> */
    public const SUPPORTED_TYPES = [self::SIMPLE, self::VARIABLE];

    public static function isSupportedType(string $productType): bool
    {
        return in_array($productType, self::SUPPORTED_TYPES, true);
    }
}
