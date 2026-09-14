<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Commerce;

use HSP\Modules\Commerce\VariationSelectionScope;
use PHPUnit\Framework\TestCase;

/**
 * DECISION AL — the source rule that decides whether a product is selector-complete.
 *
 * The flags fed in are WooCommerce's own, taken verbatim from `WC_Product_Attribute`:
 * `get_variation()`, `is_taxonomy()` and `get_name()`. Nothing here parses a name to decide what
 * KIND of attribute something is — `is_taxonomy()` is the authoritative test, because a local
 * attribute may be called anything at all, including something that looks global.
 */
final class VariationSelectionScopeTest extends TestCase
{
    /** @return array{variation:bool,taxonomy:bool,name:string} */
    private function attribute(string $name, bool $variation, bool $taxonomy): array
    {
        return ['variation' => $variation, 'taxonomy' => $taxonomy, 'name' => $name];
    }

    // -------------------------------------------------------------------------
    // Supported
    // -------------------------------------------------------------------------

    public function test_a_variable_product_varying_only_by_global_attributes_is_supported(): void
    {
        self::assertTrue(VariationSelectionScope::isSupported('variable', [
            $this->attribute('pa_color', variation: true, taxonomy: true),
            $this->attribute('pa_size', variation: true, taxonomy: true),
        ]));
    }

    public function test_one_global_variation_attribute_is_enough(): void
    {
        self::assertTrue(VariationSelectionScope::isSupported('variable', [
            $this->attribute('pa_color', variation: true, taxonomy: true),
        ]));
    }

    /**
     * THE DISTINCTION THAT MATTERS MOST HERE. A local attribute that is NOT used for variations
     * is display-only — "Material: Organic cotton" on the product page. It contributes nothing to
     * identifying a variation, so its absence from the published selection costs nothing and must
     * not withhold the capability. Getting this wrong would mark ordinary stores unsupported.
     */
    public function test_a_local_attribute_that_is_not_variation_defining_does_not_withhold_support(): void
    {
        self::assertTrue(VariationSelectionScope::isSupported('variable', [
            $this->attribute('pa_color', variation: true, taxonomy: true),
            $this->attribute('Material', variation: false, taxonomy: false),
            $this->attribute('Care instructions', variation: false, taxonomy: false),
        ]));
    }

    /** Same for a GLOBAL attribute attached for display only. */
    public function test_a_display_only_global_attribute_does_not_withhold_support(): void
    {
        self::assertTrue(VariationSelectionScope::isSupported('variable', [
            $this->attribute('pa_color', variation: true, taxonomy: true),
            $this->attribute('pa_brand', variation: false, taxonomy: true),
        ]));
    }

    // -------------------------------------------------------------------------
    // Unsupported
    // -------------------------------------------------------------------------

    /** The live hoodie: pa_color (global) + Logo (local), both used for variations. */
    public function test_mixing_a_local_variation_attribute_makes_it_unsupported(): void
    {
        self::assertFalse(VariationSelectionScope::isSupported('variable', [
            $this->attribute('pa_color', variation: true, taxonomy: true),
            $this->attribute('Logo', variation: true, taxonomy: false),
        ]));
    }

    public function test_a_product_varying_only_by_local_attributes_is_unsupported(): void
    {
        self::assertFalse(VariationSelectionScope::isSupported('variable', [
            $this->attribute('Logo', variation: true, taxonomy: false),
            $this->attribute('Engraving', variation: true, taxonomy: false),
        ]));
    }

    /**
     * SOURCE TYPE BEATS THE NAME. A local attribute literally named `pa_color` is still local —
     * WooCommerce stores it on the product, not as a taxonomy, and its values are raw option
     * strings rather than term slugs. Classifying by the name alone would call this supported and
     * publish a selection whose values mean something different from what the contract promises.
     */
    public function test_a_local_attribute_named_like_a_taxonomy_is_still_unsupported(): void
    {
        self::assertFalse(VariationSelectionScope::isSupported('variable', [
            $this->attribute('pa_color', variation: true, taxonomy: false),
        ]));
    }

    /** A taxonomy this module does not own is not a `pa_*` selector either. */
    public function test_a_variation_taxonomy_outside_the_supported_prefix_is_unsupported(): void
    {
        self::assertFalse(VariationSelectionScope::isSupported('variable', [
            $this->attribute('pa_color', variation: true, taxonomy: true),
            $this->attribute('product_cat', variation: true, taxonomy: true),
        ]));
    }

    /**
     * No variation-defining attributes at all. Verified against WooCommerce 11.1.0: with an
     * empty meta-key list `find_matching_product_variation()` matches no rows and returns 0, so
     * the store resolves NOTHING — while every variation here publishes an empty pattern that
     * would match everything. Claiming support would be a confident wrong answer.
     */
    public function test_a_variable_product_with_no_variation_attributes_is_unsupported(): void
    {
        self::assertFalse(VariationSelectionScope::isSupported('variable', [
            $this->attribute('pa_color', variation: false, taxonomy: true),
        ]));

        self::assertFalse(VariationSelectionScope::isSupported('variable', []));
    }

    // -------------------------------------------------------------------------
    // Not applicable
    // -------------------------------------------------------------------------

    /**
     * NULL, not false. A simple product has no variations, so "selection unsupported" is not a
     * weaker claim than the truth — it is a different one, and it would read as a defect.
     */
    public function test_a_non_variable_product_is_not_applicable(): void
    {
        self::assertNull(VariationSelectionScope::isSupported('simple', []));
        self::assertNull(VariationSelectionScope::isSupported('simple', [
            $this->attribute('pa_color', variation: true, taxonomy: true),
        ]));
        self::assertNull(VariationSelectionScope::isSupported('grouped', []));
        self::assertNull(VariationSelectionScope::isSupported('external', []));
    }
}
