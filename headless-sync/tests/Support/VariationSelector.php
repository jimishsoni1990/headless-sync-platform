<?php

declare(strict_types=1);

namespace HSP\Tests\Support;

/**
 * A reference implementation of the PUBLISHED variation-selection rule (Finding 010).
 *
 * TEST-ONLY, and deliberately so. Matching a shopper's choice is consumer business logic, not
 * platform infrastructure: putting it in `core/` would invent a Commerce-specific resolution
 * service Core has no reason to own (Rule 5), and putting it in the module would be the first
 * step towards a server-side resolver endpoint that no ruling authorises. It lives here because
 * its only job is to PROVE the contract is sufficient — if this class can resolve the same
 * variation WooCommerce resolves, a storefront can too.
 *
 * ITS INPUT IS THE PUBLIC PAYLOAD AND NOTHING ELSE. It reads `status`, `menu_order`,
 * `attributes` and `woo_variation_id` off resource arrays exactly as a consumer receives them
 * over HTTP. It never touches a projection column, a source model, a UUID or a term id — that
 * restriction is the whole point of the proof, and a test that fed it richer data would prove
 * nothing about what a consumer can actually do.
 *
 * STAGE 1 — THE CAPABILITY GATE (DECISION AL). Before any matching, the parent product must
 * publish `variation_selection_supported: true`. When it does not, this class resolves NOTHING,
 * and that refusal is the point rather than a limitation: a product varying by a local/custom
 * attribute publishes selections missing that dimension, so its remaining `pa_*` subset can look
 * perfectly unambiguous and still identify the wrong variation. The live hoodie does exactly
 * that. An implementation that matched anyway would reproduce the defect while appearing to work,
 * which is the failure mode this gate exists to make impossible.
 *
 * STAGE 2 — THE RULE, verified against WooCommerce 11.1.0
 * `WC_Product_Data_Store_CPT::find_matching_product_variation()`
 * (includes/data-stores/class-wc-product-data-store-cpt.php:1460):
 *
 *   1. Candidates are variations whose status is `publish`. Woo's own query constrains
 *      `post_status = 'publish'`, so nothing else can ever be the store's answer.
 *   2. Candidates are ordered by `menu_order` ascending, then variation id ascending — Woo's
 *      `ORDER BY posts.menu_order ASC, postmeta.post_id ASC`.
 *   3. A candidate matches when every entry of ITS OWN selection pattern is satisfied: an empty
 *      stored value is a wildcard and is always satisfied; a non-empty stored value requires the
 *      selection to carry that key with exactly that slug. Keys in the selection that the
 *      candidate does not constrain are ignored, because Woo iterates the variation's stored
 *      attributes, not the selection's.
 *   4. The resolved variation is the FIRST match in that order. Woo returns on first match.
 *
 * Step 4 can hide a genuine ambiguity — overlapping wildcards let one fully specified selection
 * match several variations — so {@see match()} returns EVERY match and the caller decides.
 * WooCommerce draws the same distinction in its own code: the cart takes the first match, while
 * `WC_Structured_Data` counts them and refuses to name a single offer when more than one
 * matches (includes/class-wc-structured-data.php:803).
 */
final class VariationSelector
{
    /**
     * Every published variation a selection matches, in WooCommerce's resolution order.
     *
     * STAGE 1 FIRST. An unsupported product matches NOTHING, however unambiguous its published
     * subset looks — that appearance is the defect, not a shortcut past it.
     *
     * @param  array<string,mixed>       $product    the parent product resource, as published
     * @param  list<array<string,mixed>> $variations Variation resource arrays, as published.
     * @param  array<string,string>      $selection  taxonomy => term slug. A MAP: key order is
     *                                               not part of the selection.
     * @return list<array<string,mixed>> matching variations, best (Woo's answer) first
     */
    public static function match(array $product, array $variations, array $selection): array
    {
        if (! self::supportsSelection($product)) {
            return [];
        }

        $candidates = array_values(array_filter(
            $variations,
            static fn (array $v): bool => ($v['status'] ?? null) === 'publish',
        ));

        usort($candidates, static fn (array $a, array $b): int => [
            (int) ($a['menu_order'] ?? 0),
            (int) ($a['woo_variation_id'] ?? 0),
        ] <=> [
            (int) ($b['menu_order'] ?? 0),
            (int) ($b['woo_variation_id'] ?? 0),
        ]);

        return array_values(array_filter(
            $candidates,
            static fn (array $v): bool => self::satisfies($v, $selection),
        ));
    }

    /**
     * The single variation WooCommerce would resolve, or null when the store sells no such
     * combination.
     *
     * Null is a real answer and must stay one: falling back to a nearest or first variation
     * would hand the cart something the shopper did not choose.
     *
     * @param  array<string,mixed>       $product
     * @param  list<array<string,mixed>> $variations
     * @param  array<string,string>      $selection
     * @return array<string,mixed>|null
     */
    public static function resolve(array $product, array $variations, array $selection): ?array
    {
        return self::match($product, $variations, $selection)[0] ?? null;
    }

    /**
     * True when more than one published variation matches — the caller asked for certainty and
     * the source cannot give it from this selection alone.
     *
     * @param array<string,mixed>       $product
     * @param list<array<string,mixed>> $variations
     * @param array<string,string>      $selection
     */
    public static function isAmbiguous(array $product, array $variations, array $selection): bool
    {
        return count(self::match($product, $variations, $selection)) > 1;
    }

    /**
     * STAGE 1 of the published rule (DECISION AL): may this product be resolved from HSP data?
     *
     * A variable product must publish `variation_selection_supported: true`. The field is absent
     * on a non-variable product — which has no variations to select — and absent is NOT a licence
     * to proceed, so anything that does not say yes is treated as no.
     *
     * @param array<string,mixed> $product
     */
    public static function supportsSelection(array $product): bool
    {
        return ($product['variation_selection_supported'] ?? null) === true;
    }

    /**
     * The selector dimensions of a product: the KEYS of its variations' selection patterns.
     *
     * Taken from the published variations rather than the product, because WooCommerce fills in
     * every attribute the parent varies by — including as an empty wildcard — and strips any the
     * parent no longer varies by. The product's own `attributes` map cannot answer this: it also
     * carries attributes attached for display only.
     *
     * @param  list<array<string,mixed>> $variations
     * @return list<string>              taxonomy names, sorted
     */
    public static function dimensions(array $variations): array
    {
        $keys = [];

        foreach ($variations as $v) {
            if (($v['status'] ?? null) !== 'publish') {
                continue;
            }

            foreach (array_keys((array) ($v['attributes'] ?? [])) as $taxonomy) {
                $keys[(string) $taxonomy] = true;
            }
        }

        $out = array_keys($keys);
        sort($out);

        return $out;
    }

    /**
     * The selectable values of one dimension, as a consumer must derive them.
     *
     * The non-empty values across published variations, EXCEPT where any of them carries a
     * wildcard for this dimension — then the variations name no values at all and the answer is
     * the parent product's own terms. That is exactly WooCommerce's own fallback:
     * `read_variation_attributes()` switches to `wc_get_object_terms($product, $taxonomy)` the
     * moment an empty value appears among the children
     * (includes/data-stores/class-wc-product-variable-data-store-cpt.php:289).
     *
     * @param  list<array<string,mixed>> $variations
     * @param  array<string,mixed>       $product    the product resource, for its `attributes`
     * @return list<string>              term slugs, sorted
     */
    public static function options(array $variations, array $product, string $taxonomy): array
    {
        $values   = [];
        $wildcard = false;

        foreach ($variations as $v) {
            if (($v['status'] ?? null) !== 'publish') {
                continue;
            }

            $attributes = (array) ($v['attributes'] ?? []);

            if (! array_key_exists($taxonomy, $attributes)) {
                continue;
            }

            if ($attributes[$taxonomy] === '') {
                $wildcard = true;

                continue;
            }

            $values[(string) $attributes[$taxonomy]] = true;
        }

        if ($wildcard) {
            $values = [];

            foreach ((array) (((array) ($product['attributes'] ?? []))[$taxonomy] ?? []) as $term) {
                if (is_array($term) && isset($term['slug'])) {
                    $values[(string) $term['slug']] = true;
                }
            }
        }

        $out = array_keys($values);
        sort($out);

        return $out;
    }

    /**
     * @param array<string,mixed>  $variation
     * @param array<string,string> $selection
     */
    private static function satisfies(array $variation, array $selection): bool
    {
        foreach ((array) ($variation['attributes'] ?? []) as $taxonomy => $value) {
            // The wildcard. Satisfied by any value, and by no value at all.
            if ($value === '') {
                continue;
            }

            $taxonomy = (string) $taxonomy;

            if (! array_key_exists($taxonomy, $selection) || $selection[$taxonomy] !== $value) {
                return false;
            }
        }

        return true;
    }
}
