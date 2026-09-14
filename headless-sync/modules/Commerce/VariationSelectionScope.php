<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce;

/**
 * Whether a product's variation-defining state is FULLY REPRESENTABLE by the supported
 * selector model (DECISION AL).
 *
 * WHY THIS EXISTS. AG-9 keeps local/custom WooCommerce attributes out of Phase 2, and
 * `VariationExtractor` therefore drops them from a variation's published selection. For a
 * DISPLAY-only attribute that is harmless. For a variation-DEFINING one it is not: the omitted
 * dimension does not leave a gap a consumer can see — it silently collapses distinct variations
 * into the same published selection pattern. The live reference store proves it, and the failure
 * is not merely "less information":
 *
 *     Hoodie 95 varies by pa_color (global) AND `Logo` (local). Variations 118 (Blue/Yes) and
 *     113 (Blue/No) both publish {"pa_color":"blue"}. A consumer choosing blue+No resolves 118
 *     where WooCommerce resolves 113 — and choosing red+Yes resolves 111 where WooCommerce
 *     resolves NOTHING, because the store does not sell it.
 *
 * So the consumer needs to know, from the contract, whether the published dimensions are the
 * whole story. That is what this class decides — at CAPTURE time, from WooCommerce's own
 * attribute semantics, because after projection the fact is unrecoverable: the unsupported
 * entries are gone by then and nothing downstream can tell "no local attribute existed" from
 * "a local attribute was dropped".
 *
 * IT IS NOT A "PRODUCT SUPPORTED" FLAG. An unsupported product remains a perfectly normal
 * catalogue product — listed, addressable, with media, prices, descriptions and its Woo handoff
 * identity. Exactly one capability is withheld: deterministic variation selection from HSP data.
 *
 * SOURCE TYPE IS AUTHORITATIVE, NOT THE NAME. `WC_Product_Attribute::is_taxonomy()` returns
 * `0 < get_id()` — a real global attribute definition — and only those get a taxonomy name, which
 * `wc_attribute_taxonomy_name()` builds as `'pa_' . slug` with the prefix hardcoded (verified,
 * wc-attribute-functions.php:143, WooCommerce 11.1.0). The `pa_` check below is therefore
 * corroboration of the module's own supported-taxonomy rule, never the primary test: classifying
 * by name alone would trust a string a local attribute could be named.
 */
final class VariationSelectionScope
{
    private function __construct()
    {
    }

    /**
     * @param  string $productType WC_Product::get_type()
     * @param  list<array{variation:bool,taxonomy:bool,name:string}> $attributes
     *         every attribute assigned to the product, with WooCommerce's own flags:
     *         `variation` = WC_Product_Attribute::get_variation(),
     *         `taxonomy`  = WC_Product_Attribute::is_taxonomy(),
     *         `name`      = WC_Product_Attribute::get_name() (the taxonomy name when global).
     * @return bool|null true/false for a variable product; NULL when the question does not
     *         apply, which today means any non-variable type — a simple product has no
     *         variations to select and must not be given a capability value that implies it has.
     */
    public static function isSupported(string $productType, array $attributes): ?bool
    {
        if ($productType !== ProductScope::VARIABLE) {
            return null;
        }

        $variationDefining = array_values(array_filter(
            $attributes,
            static fn (array $a): bool => $a['variation'] === true,
        ));

        // NO VARIATION-DEFINING ATTRIBUTES AT ALL — and this is `false`, not `true`, which is
        // the one classification here that is not obvious.
        //
        // It is reachable: an operator unticks "Used for variations" on the only such attribute
        // and WooCommerce keeps the variation posts. Nothing is then OMITTED, so "complete" is
        // arguably true — but the capability promises the documented algorithm is SAFE to apply,
        // and here it is not. Every variation publishes an empty selection pattern, so every
        // variation matches every selection and a consumer would resolve the first one, while
        // WooCommerce's own resolver returns 0 unconditionally: with no variation attributes its
        // meta-key list is empty, the query matches no rows, and it bails before comparing
        // anything (class-wc-product-data-store-cpt.php:1460-1500, verified against 11.1.0).
        //
        // A capability that says "safe" where the store answers "no such variation" would
        // reintroduce exactly the confident-wrong-answer this decision exists to remove.
        if ($variationDefining === []) {
            return false;
        }

        foreach ($variationDefining as $attribute) {
            if ($attribute['taxonomy'] !== true) {
                return false; // Local/custom attribute — out of scope by AG-9, and DEFINING.
            }

            if (! CommerceTaxonomies::isAttributeTaxonomy($attribute['name'])) {
                return false; // A taxonomy this module does not own; not a `pa_*` selector.
            }
        }

        return true;
    }
}
