<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Support;

/**
 * Deterministic decimal normalisation for monetary values (DECISION AG Requirement C).
 *
 * The ruling: money stays PostgreSQL `NUMERIC` with no imposed precision or scale, and
 * exactness is enforced at the application boundary —
 *
 *     WooCommerce source value → normalized exact decimal STRING → canonical model → NUMERIC
 *
 * PHP binary floating point is never the canonical representation used for checksum or
 * persistence.
 *
 * WHY DETERMINISM IS LOAD-BEARING, not tidiness: the canonical checksum decides whether a
 * projection write happens at all (DECISION 3). If `10`, `10.0` and `10.00` hashed differently,
 * a product whose price had not changed would churn its checksum on every pass — re-projecting
 * forever, or reading as permanently drifted to reconciliation. Phase 1B hit that trap three
 * times (featured media, taxonomy type, tag ids), so money gets one documented canonical form
 * up front.
 *
 * THE CANONICAL FORM: trailing zeros in the fractional part are removed, a bare trailing
 * decimal point is removed, `-0` normalises to `0`, and a leading `+` is dropped. The value is
 * NOT rounded and NOT padded to a fixed scale — narrowing the source value is exactly what
 * Requirement C forbids, since WooCommerce store decimal configuration is not guaranteed to be
 * two places.
 *
 * DISPLAY formatting is presentation/store-configuration semantics and is explicitly NOT
 * derived from this or from the stored numeric scale.
 */
final class Money
{
    private function __construct()
    {
    }

    /**
     * Normalise a WooCommerce monetary value to its canonical exact-decimal string.
     *
     * @param string|int|float|null $value Raw source value. Floats are accepted because a
     *        third-party filter may hand one back, but they are converted through a
     *        precision-preserving path rather than a lossy cast.
     *
     * @return string|null Canonical decimal string, or null when the value is absent or is
     *         not a number at all (WooCommerce uses '' for "no price set", which is a real
     *         state and must stay distinct from 0).
     */
    public static function normalize(string|int|float|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            // Round-trip through a high-precision representation rather than (string) casting,
            // which would emit scientific notation for small magnitudes.
            $value = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
            if ($value === '' || $value === '-') {
                $value = '0';
            }
        }

        $raw = trim($value);

        if ($raw === '') {
            return null;
        }

        // Accept an optional sign, digits, and an optional fractional part. Anything else is
        // not a monetary value and must not be silently coerced to 0 — a corrupted price
        // should surface, not quietly become free.
        if (preg_match('/^[+-]?\d*(\.\d*)?$/', $raw) !== 1 || ! preg_match('/\d/', $raw)) {
            return null;
        }

        $negative = str_starts_with($raw, '-');
        $digits   = ltrim($raw, '+-');

        [$whole, $fraction] = array_pad(explode('.', $digits, 2), 2, '');

        $whole    = ltrim($whole, '0');
        $fraction = rtrim($fraction, '0');

        if ($whole === '') {
            $whole = '0';
        }

        $result = $fraction === '' ? $whole : $whole . '.' . $fraction;

        // -0 and -0.00 are zero; a signed zero would hash differently from an unsigned one.
        if ($negative && $result !== '0') {
            $result = '-' . $result;
        }

        return $result;
    }
}
