<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Commerce;

use HSP\Modules\Commerce\Resources\ProductResource;
use HSP\Modules\Commerce\Resources\VariationResource;
use HSP\Tests\Support\VariationSelector;
use PHPUnit\Framework\TestCase;

/**
 * Finding 010 / DECISION AL — the variation-selection contract, exercised the way a consumer
 * exercises it.
 *
 * Every fixture here goes through the real {@see VariationResource} / {@see ProductResource}, so
 * what the matcher sees is what a storefront receives. Feeding {@see VariationSelector} a
 * hand-built array would test the matcher and prove nothing about the contract.
 *
 * The rule under test is WooCommerce's own, verified against 11.1.0 and recorded on
 * {@see VariationSelector}. What these tests defend is that HSP publishes enough to REPRODUCE
 * it — the keys mean a taxonomy, the values mean a term slug, an empty value means "any", only
 * published variations are candidates, the tiebreak order is reconstructible — AND that the
 * capability gate stops a consumer dead on a product where those facts are incomplete.
 */
final class VariationSelectionContractTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Fixtures — rows shaped like the projection, published through the Resource
    // -------------------------------------------------------------------------

    /**
     * @param  array<string,string> $attributes
     * @return array<string,mixed>
     */
    private function variation(
        int $id,
        array $attributes,
        int $menuOrder = 0,
        string $status = 'publish',
        int $parentId = 42,
    ): array {
        return (new VariationResource())->toArray([
            'id'                  => sprintf('b2f1c0de-0000-7000-8000-%012d', $id),
            'source_variation_id' => $id,
            'source_parent_id'    => $parentId,
            'sku'                 => "SKU-{$id}",
            'name'                => "Variation {$id}",
            'status'              => $status,
            'price'               => '19.99',
            'regular_price'       => '19.99',
            'sale_price'          => null,
            'attributes'          => json_encode($attributes, JSON_THROW_ON_ERROR),
            'featured_media_id'   => 0,
            'menu_order'          => $menuOrder,
        ]);
    }

    /**
     * @param  array<string, list<array{slug:string,name:string}>> $attributes
     * @return array<string,mixed>
     */
    private function product(
        array $attributes = [],
        ?bool $supported = true,
        string $type = 'variable',
    ): array {
        return (new ProductResource())->toArray([
            'id'                            => 'b2f1c0de-0000-7000-8000-000000000042',
            'source_product_id'             => 42,
            'slug'                          => 'tee',
            'name'                          => 'Tee',
            'product_type'                  => $type,
            'attribute_terms_json'          => json_encode($attributes, JSON_THROW_ON_ERROR),
            'variation_selection_supported' => $supported,
        ]);
    }

    // -------------------------------------------------------------------------
    // One dimension
    // -------------------------------------------------------------------------

    public function test_one_attribute_resolves_the_matching_variation(): void
    {
        $product    = $this->product();
        $variations = [
            $this->variation(101, ['pa_color' => 'blue']),
            $this->variation(102, ['pa_color' => 'red']),
        ];

        self::assertSame(
            102,
            VariationSelector::resolve($product, $variations, ['pa_color' => 'red'])['woo_variation_id'],
        );
    }

    // -------------------------------------------------------------------------
    // Two dimensions — the COMPLETE selection decides, never one key
    // -------------------------------------------------------------------------

    public function test_two_attributes_match_on_the_complete_selection(): void
    {
        $product    = $this->product();
        $variations = [
            $this->variation(101, ['pa_color' => 'blue', 'pa_size' => 'small']),
            $this->variation(102, ['pa_color' => 'blue', 'pa_size' => 'large']),
            $this->variation(103, ['pa_color' => 'red',  'pa_size' => 'large']),
        ];

        self::assertSame(102, VariationSelector::resolve(
            $product,
            $variations,
            ['pa_color' => 'blue', 'pa_size' => 'large'],
        )['woo_variation_id']);
    }

    /** A selection is a MAP. JSON insertion order is not part of it. */
    public function test_attribute_key_order_does_not_change_the_result(): void
    {
        $product    = $this->product();
        $variations = [
            $this->variation(101, ['pa_color' => 'blue', 'pa_size' => 'large']),
            $this->variation(102, ['pa_size' => 'large', 'pa_color' => 'red']),
        ];

        $a = VariationSelector::resolve($product, $variations, ['pa_color' => 'blue', 'pa_size' => 'large']);
        $b = VariationSelector::resolve($product, $variations, ['pa_size' => 'large', 'pa_color' => 'blue']);

        self::assertSame($a, $b);
        self::assertSame(101, $a['woo_variation_id']);
    }

    /**
     * WordPress guarantees slug uniqueness per taxonomy, not globally, so the taxonomy key is
     * part of a value's identity. `pa_color: large` and `pa_size: large` are different values.
     */
    public function test_the_same_slug_under_two_taxonomies_does_not_collide(): void
    {
        $product    = $this->product();
        $variations = [
            $this->variation(101, ['pa_color' => 'large', 'pa_size' => 'small']),
            $this->variation(102, ['pa_color' => 'small', 'pa_size' => 'large']),
        ];

        self::assertSame(102, VariationSelector::resolve(
            $product,
            $variations,
            ['pa_color' => 'small', 'pa_size' => 'large'],
        )['woo_variation_id']);

        // The mirror selection resolves to the OTHER variation, which it could not do if the
        // matcher compared values without their taxonomy.
        self::assertSame(101, VariationSelector::resolve(
            $product,
            $variations,
            ['pa_color' => 'large', 'pa_size' => 'small'],
        )['woo_variation_id']);
    }

    // -------------------------------------------------------------------------
    // Partial and invalid selections
    // -------------------------------------------------------------------------

    /** A dimension a candidate genuinely constrains must be supplied, or nothing resolves. */
    public function test_a_partial_selection_does_not_resolve_a_concrete_variation(): void
    {
        $product    = $this->product();
        $variations = [
            $this->variation(101, ['pa_color' => 'blue', 'pa_size' => 'small']),
            $this->variation(102, ['pa_color' => 'blue', 'pa_size' => 'large']),
        ];

        self::assertNull(VariationSelector::resolve($product, $variations, ['pa_color' => 'blue']));
        self::assertSame([], VariationSelector::match($product, $variations, ['pa_color' => 'blue']));
    }

    /**
     * But a partial selection SHOULD resolve when the omitted dimension is a wildcard on the
     * winner — that is WooCommerce's behaviour, not a leniency invented here: its matcher skips
     * an empty stored value before it ever checks whether the selection carries the key.
     */
    public function test_an_omitted_dimension_resolves_when_the_variation_wildcards_it(): void
    {
        $product    = $this->product();
        $variations = [
            $this->variation(101, ['pa_color' => 'blue', 'pa_size' => '']),
            $this->variation(102, ['pa_color' => 'red',  'pa_size' => '']),
        ];

        self::assertSame(
            101,
            VariationSelector::resolve($product, $variations, ['pa_color' => 'blue'])['woo_variation_id'],
        );
    }

    /** A combination the store does not sell resolves to NOTHING, never to the nearest row. */
    public function test_an_invalid_combination_resolves_to_no_variation(): void
    {
        $product    = $this->product();
        $variations = [
            $this->variation(101, ['pa_color' => 'blue', 'pa_size' => 'small']),
            $this->variation(102, ['pa_color' => 'red',  'pa_size' => 'large']),
        ];

        self::assertNull(
            VariationSelector::resolve($product, $variations, ['pa_color' => 'blue', 'pa_size' => 'large']),
        );
        self::assertNull(
            VariationSelector::resolve($product, $variations, ['pa_color' => 'green', 'pa_size' => 'small']),
        );
    }

    // -------------------------------------------------------------------------
    // Wildcards
    // -------------------------------------------------------------------------

    /** An empty value accepts every value of that attribute, including unusual ones. */
    public function test_a_wildcard_accepts_any_value_of_that_attribute(): void
    {
        $product    = $this->product();
        $variations = [$this->variation(101, ['pa_color' => 'blue', 'pa_size' => ''])];

        foreach (['small', 'medium', 'large'] as $size) {
            self::assertSame(101, VariationSelector::resolve(
                $product,
                $variations,
                ['pa_color' => 'blue', 'pa_size' => $size],
            )['woo_variation_id'], "A wildcard must accept pa_size={$size}.");
        }
    }

    /** A wildcard is not a free pass: the concrete dimensions still have to match. */
    public function test_a_wildcard_does_not_relax_the_other_dimensions(): void
    {
        $product    = $this->product();
        $variations = [$this->variation(101, ['pa_color' => 'blue', 'pa_size' => ''])];

        self::assertNull(
            VariationSelector::resolve($product, $variations, ['pa_color' => 'red', 'pa_size' => 'small']),
        );
    }

    /** An empty value is a wildcard, NOT the attribute being absent. */
    public function test_a_wildcard_is_distinguishable_from_an_absent_dimension(): void
    {
        $wildcarded = $this->variation(101, ['pa_color' => 'blue', 'pa_size' => '']);
        $absent     = $this->variation(102, ['pa_color' => 'blue']);

        self::assertArrayHasKey('pa_size', $wildcarded['attributes']);
        self::assertSame('', $wildcarded['attributes']['pa_size']);
        self::assertArrayNotHasKey('pa_size', $absent['attributes']);

        // And the DIMENSION set differs, which is what a selector is built from.
        self::assertSame(['pa_color', 'pa_size'], VariationSelector::dimensions([$wildcarded]));
        self::assertSame(['pa_color'], VariationSelector::dimensions([$absent]));
    }

    // -------------------------------------------------------------------------
    // Overlap and ambiguity — the store's own tiebreak, reconstructible
    // -------------------------------------------------------------------------

    /**
     * Overlapping wildcards let one complete selection match several variations. WooCommerce
     * returns the first by (menu_order, variation id) and a consumer must be able to reach the
     * same answer — both keys are published, so it can.
     */
    public function test_overlapping_wildcards_resolve_by_menu_order_then_variation_id(): void
    {
        $product    = $this->product();
        $variations = [
            // Deliberately supplied out of order: resolution must not depend on array order.
            $this->variation(103, ['pa_color' => '',     'pa_size' => 'large'], menuOrder: 2),
            $this->variation(101, ['pa_color' => 'blue', 'pa_size' => ''],      menuOrder: 1),
        ];

        $selection = ['pa_color' => 'blue', 'pa_size' => 'large'];

        self::assertTrue(VariationSelector::isAmbiguous($product, $variations, $selection));
        self::assertSame([101, 103], array_column(
            VariationSelector::match($product, $variations, $selection),
            'woo_variation_id',
        ));
        self::assertSame(
            101,
            VariationSelector::resolve($product, $variations, $selection)['woo_variation_id'],
        );
    }

    /** Equal menu_order falls through to the variation id, ascending — Woo's second sort key. */
    public function test_equal_menu_order_breaks_on_the_lower_variation_id(): void
    {
        $product    = $this->product();
        $variations = [
            $this->variation(205, ['pa_color' => '',     'pa_size' => 'large']),
            $this->variation(104, ['pa_color' => 'blue', 'pa_size' => '']),
        ];

        self::assertSame(104, VariationSelector::resolve(
            $product,
            $variations,
            ['pa_color' => 'blue', 'pa_size' => 'large'],
        )['woo_variation_id']);
    }

    /** A single match is NOT ambiguous, so a consumer demanding certainty still gets an answer. */
    public function test_a_unique_match_is_not_reported_as_ambiguous(): void
    {
        $product    = $this->product();
        $variations = [
            $this->variation(101, ['pa_color' => 'blue']),
            $this->variation(102, ['pa_color' => 'red']),
        ];

        self::assertFalse(VariationSelector::isAmbiguous($product, $variations, ['pa_color' => 'blue']));
    }

    // -------------------------------------------------------------------------
    // Candidate scope
    // -------------------------------------------------------------------------

    /**
     * WooCommerce's resolver queries `post_status = 'publish'` only. A private variation is
     * published by this API — a consumer may legitimately want to see it — but it must never be
     * the answer to a selection, or the handoff hands the cart something Woo will refuse.
     */
    public function test_only_published_variations_are_selection_candidates(): void
    {
        $product    = $this->product();
        $variations = [
            $this->variation(101, ['pa_color' => 'blue'], status: 'private'),
            $this->variation(102, ['pa_color' => 'red']),
        ];

        self::assertNull(VariationSelector::resolve($product, $variations, ['pa_color' => 'blue']));
        self::assertSame(
            102,
            VariationSelector::resolve($product, $variations, ['pa_color' => 'red'])['woo_variation_id'],
        );
    }

    /** A private variation must not contribute a dimension or an option either. */
    public function test_a_non_published_variation_contributes_no_dimension(): void
    {
        $variations = [
            $this->variation(101, ['pa_color' => 'blue', 'pa_material' => 'wool'], status: 'draft'),
            $this->variation(102, ['pa_color' => 'red']),
        ];

        self::assertSame(['pa_color'], VariationSelector::dimensions($variations));
    }

    // =========================================================================
    // DECISION AL — the capability gate
    // =========================================================================

    /**
     * THE REGRESSION THIS DECISION EXISTS FOR, in miniature.
     *
     * These are the live hoodie's two blue variations as HSP publishes them: 118 (Blue/Logo=Yes)
     * and 113 (Blue/Logo=No), identical once the local `Logo` dimension is dropped by AG-9. The
     * supported subset looks like an ordinary ambiguous match — and a matcher obeying stage 2
     * alone would hand back 118, which is what a consumer used to get where WooCommerce answers
     * 113. The gate must refuse BEFORE any of that reasoning happens.
     */
    public function test_an_unsupported_product_refuses_to_resolve_even_when_a_subset_matches(): void
    {
        $unsupported = $this->product(supported: false);
        $variations  = [
            $this->variation(118, ['pa_color' => 'blue'], menuOrder: 0),
            $this->variation(113, ['pa_color' => 'blue'], menuOrder: 3),
        ];

        self::assertFalse(VariationSelector::supportsSelection($unsupported));
        self::assertNull(VariationSelector::resolve($unsupported, $variations, ['pa_color' => 'blue']));
        self::assertSame([], VariationSelector::match($unsupported, $variations, ['pa_color' => 'blue']));
        self::assertFalse(VariationSelector::isAmbiguous($unsupported, $variations, ['pa_color' => 'blue']));
    }

    /**
     * And the refusal is not merely the ambiguous case being caught downstream: a selection that
     * would resolve UNIQUELY under stage 2 is still refused, because uniqueness over an
     * incomplete dimension set proves nothing about the store's answer. The live hoodie's
     * `green` is exactly that — one published match, and WooCommerce sells no such variation.
     */
    public function test_an_unsupported_product_refuses_a_selection_that_looks_unique(): void
    {
        $unsupported = $this->product(supported: false);
        $variations  = [
            $this->variation(112, ['pa_color' => 'green'], menuOrder: 2),
            $this->variation(111, ['pa_color' => 'red'], menuOrder: 1),
        ];

        self::assertNull(VariationSelector::resolve($unsupported, $variations, ['pa_color' => 'green']));
    }

    /** Absent is not permission. A simple product publishes no flag and resolves nothing. */
    public function test_a_product_without_the_capability_field_is_treated_as_unsupported(): void
    {
        $simple = $this->product(supported: null, type: 'simple');

        self::assertArrayNotHasKey('variation_selection_supported', $simple);
        self::assertFalse(VariationSelector::supportsSelection($simple));
        self::assertNull(VariationSelector::resolve(
            $simple,
            [$this->variation(101, ['pa_color' => 'blue'])],
            ['pa_color' => 'blue'],
        ));
    }

    // -------------------------------------------------------------------------
    // The capability field on the Resource
    // -------------------------------------------------------------------------

    public function test_a_supported_variable_product_publishes_the_capability_as_true(): void
    {
        self::assertTrue($this->product(supported: true)['variation_selection_supported']);
    }

    public function test_an_unsupported_variable_product_publishes_the_capability_as_false(): void
    {
        self::assertFalse($this->product(supported: false)['variation_selection_supported']);
    }

    /**
     * A variable product projected before DECISION AL carries NULL, and delivery resolves that
     * CONSERVATIVELY as false rather than optimistically as true. The consumer sees a usable
     * boolean and never has to reason about whether the platform has converged.
     */
    public function test_an_unknown_capability_publishes_as_false_rather_than_true(): void
    {
        $published = $this->product(supported: null);

        self::assertArrayHasKey('variation_selection_supported', $published);
        self::assertFalse($published['variation_selection_supported']);
    }

    /** PostgreSQL hands booleans back as 't'/'f' over the text protocol. */
    public function test_the_capability_survives_the_postgres_text_protocol(): void
    {
        foreach (['t' => true, 'f' => false] as $raw => $expected) {
            $published = (new ProductResource())->toArray([
                'id'                            => 'b2f1c0de-0000-7000-8000-000000000044',
                'source_product_id'             => 44,
                'slug'                          => 'tee',
                'product_type'                  => 'variable',
                'variation_selection_supported' => $raw,
            ]);

            self::assertSame($expected, $published['variation_selection_supported'], "raw '{$raw}'");
        }
    }

    /** A simple product gets NO capability field — not true, not false, not null (AK-8's rule). */
    public function test_a_simple_product_publishes_no_capability_field_at_all(): void
    {
        foreach ([true, false, null] as $stored) {
            $published = $this->product(supported: $stored, type: 'simple');

            self::assertArrayNotHasKey(
                'variation_selection_supported',
                $published,
                'A product with no variations must not carry a selection capability.',
            );
        }
    }

    /**
     * An unsupported product keeps everything else. The flag withholds ONE capability; it does
     * not degrade the product, and a consumer must still be able to list, address and display it.
     */
    public function test_an_unsupported_product_keeps_its_catalog_and_handoff_data(): void
    {
        $published = (new ProductResource())->toArray([
            'id'                            => 'b2f1c0de-0000-7000-8000-000000000095',
            'source_product_id'             => 95,
            'slug'                          => 'hoodie',
            'name'                          => 'Hoodie',
            'product_type'                  => 'variable',
            'price'                         => '42.00',
            'featured_media_id'             => 122,
            'attribute_terms_json'          => json_encode(
                ['pa_color' => [['slug' => 'blue', 'name' => 'Blue']]],
                JSON_THROW_ON_ERROR,
            ),
            'variation_selection_supported' => false,
        ]);

        self::assertFalse($published['variation_selection_supported']);
        self::assertSame('hoodie', $published['slug']);
        self::assertSame(95, $published['woo_product_id']);
        self::assertSame('42.00', $published['prices']['price']);
        self::assertSame(122, $published['media']['featured_id']);
        self::assertSame(
            [['slug' => 'blue', 'name' => 'Blue']],
            $published['attributes']['pa_color'],
            'Supported global options stay published; the flag gates how they are READ.',
        );
    }

    /** No local attribute data leaks alongside the flag — the flag IS the whole disclosure. */
    public function test_no_local_attribute_data_is_published_for_an_unsupported_product(): void
    {
        $published = $this->product(supported: false);
        $json      = json_encode($published, JSON_THROW_ON_ERROR);

        foreach (['logo', 'Logo', 'attribute_', '_product_attributes', 'is_variation'] as $leak) {
            self::assertStringNotContainsString($leak, $json, "Must not leak local attribute data: {$leak}");
        }
    }

    // -------------------------------------------------------------------------
    // Building the selector
    // -------------------------------------------------------------------------

    /** With no wildcard, the options are exactly the values the variations carry. */
    public function test_options_come_from_the_variation_values_when_nothing_is_wildcarded(): void
    {
        $variations = [
            $this->variation(101, ['pa_color' => 'blue']),
            $this->variation(102, ['pa_color' => 'red']),
        ];

        // The product carries a colour the variations do not use; the variations win here.
        $product = $this->product([
            'pa_color' => [
                ['slug' => 'blue', 'name' => 'Blue'],
                ['slug' => 'green', 'name' => 'Green'],
                ['slug' => 'red', 'name' => 'Red'],
            ],
        ]);

        self::assertSame(['blue', 'red'], VariationSelector::options($variations, $product, 'pa_color'));
    }

    /**
     * THE CASE THE PRODUCT FIELD EXISTS FOR. Every variation wildcards the size, so the sizes on
     * offer appear nowhere in the variation list — without the product's `attributes` the
     * selector has a dimension it cannot populate.
     */
    public function test_options_fall_back_to_the_product_when_a_dimension_is_wildcarded(): void
    {
        $variations = [
            $this->variation(101, ['pa_color' => 'blue', 'pa_size' => '']),
            $this->variation(102, ['pa_color' => 'red',  'pa_size' => '']),
        ];

        $product = $this->product([
            'pa_color' => [['slug' => 'blue', 'name' => 'Blue'], ['slug' => 'red', 'name' => 'Red']],
            'pa_size'  => [
                ['slug' => 'large', 'name' => 'Large'],
                ['slug' => 'medium', 'name' => 'Medium'],
                ['slug' => 'small', 'name' => 'Small'],
            ],
        ]);

        self::assertSame(
            [],
            array_values(array_filter(
                array_map(fn (array $v): string => (string) $v['attributes']['pa_size'], $variations),
            )),
            'Precondition: the variations name no size at all.',
        );

        self::assertSame(
            ['large', 'medium', 'small'],
            VariationSelector::options($variations, $product, 'pa_size'),
        );
    }

    /**
     * And the product's own terms are narrower than the store's. A selector built from
     * `/product-attributes/{taxonomy}/terms` would advertise values this product does not sell —
     * which is exactly why that endpoint is not the source for options.
     */
    public function test_product_options_are_product_specific_not_store_wide(): void
    {
        $product = $this->product([
            'pa_color' => [['slug' => 'blue', 'name' => 'Blue'], ['slug' => 'red', 'name' => 'Red']],
        ]);

        self::assertSame(['blue', 'red'], array_column($product['attributes']['pa_color'], 'slug'));
    }

    /** Machine value and human label travel together, so no per-term lookup is needed. */
    public function test_each_product_option_carries_both_a_slug_and_a_label(): void
    {
        $product = $this->product([
            'pa_color' => [['slug' => 'dark-blue', 'name' => 'Dark Blue']],
        ]);

        self::assertSame(
            [['slug' => 'dark-blue', 'name' => 'Dark Blue']],
            $product['attributes']['pa_color'],
        );
    }

    /** A product with no attribute terms publishes an empty OBJECT, never an empty list. */
    public function test_a_product_without_attribute_terms_publishes_an_empty_object(): void
    {
        $published = (new ProductResource())->toArray([
            'id'                => 'b2f1c0de-0000-7000-8000-000000000043',
            'source_product_id' => 43,
            'slug'              => 'beanie',
        ]);

        self::assertEquals(new \stdClass(), $published['attributes']);
        self::assertSame('{}', json_encode($published['attributes']));
    }

    // -------------------------------------------------------------------------
    // The handoff the whole exercise exists for (DECISION AK)
    // -------------------------------------------------------------------------

    public function test_a_resolved_variation_carries_the_woo_handoff_pair(): void
    {
        $product    = $this->product();
        $variations = [$this->variation(118, ['pa_color' => 'blue'], parentId: 95)];

        $resolved = VariationSelector::resolve($product, $variations, ['pa_color' => 'blue']);

        self::assertSame(95, $resolved['woo_product_id'], 'The PARENT product id.');
        self::assertSame(118, $resolved['woo_variation_id'], "The variation's own id.");
        self::assertNotSame($resolved['woo_product_id'], $resolved['woo_variation_id']);
    }

    /** No internal identity is required to select — not a UUID, not a term id, not a source id. */
    public function test_selection_needs_no_internal_identifier(): void
    {
        $product    = ['variation_selection_supported' => true];
        $variations = [
            $this->variation(101, ['pa_color' => 'blue']),
            $this->variation(102, ['pa_color' => 'red']),
        ];

        $stripped = array_map(
            static fn (array $v): array => array_intersect_key(
                $v,
                array_flip(['status', 'menu_order', 'attributes', 'woo_variation_id', 'woo_product_id']),
            ),
            $variations,
        );

        self::assertSame(
            102,
            VariationSelector::resolve($product, $stripped, ['pa_color' => 'red'])['woo_variation_id'],
        );
    }
}
