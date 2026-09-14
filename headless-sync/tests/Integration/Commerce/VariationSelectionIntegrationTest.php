<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Commerce;

use HSP\Core\Contracts\EventInterface;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Modules\Commerce\Adapters\ProductAdapter;
use HSP\Modules\Commerce\Adapters\TermAdapter;
use HSP\Modules\Commerce\Adapters\VariationAdapter;
use HSP\Modules\Commerce\Extractors\ProductExtractor;
use HSP\Modules\Commerce\Extractors\TermExtractor;
use HSP\Modules\Commerce\Extractors\VariationExtractor;
use HSP\Modules\Commerce\Handlers\ProductUpsertHandler;
use HSP\Modules\Commerce\Handlers\TermUpsertHandler;
use HSP\Modules\Commerce\Handlers\VariationUpsertHandler;
use HSP\Modules\Commerce\Queries\ProductFilterSet;
use HSP\Modules\Commerce\Queries\ProductQueryProvider;
use HSP\Modules\Commerce\Queries\VariationFilterSet;
use HSP\Modules\Commerce\Queries\VariationQueryProvider;
use HSP\Modules\Commerce\Resources\ProductResource;
use HSP\Modules\Commerce\Resources\VariationResource;
use HSP\Modules\Commerce\Transformers\ProductTransformer;
use HSP\Modules\Commerce\Transformers\TermTransformer;
use HSP\Modules\Commerce\Transformers\VariationTransformer;
use HSP\Modules\Commerce\Validation\ProductValidator;
use HSP\Modules\Commerce\Validation\TermValidator;
use HSP\Modules\Commerce\Validation\VariationValidator;
use HSP\Tests\Support\CommerceSchema;
use HSP\Tests\Support\InMemoryCommerceLoader;
use HSP\Tests\Support\VariationSelector;
use PHPUnit\Framework\TestCase;

/**
 * Finding 010 — a shopper's selection resolves to exactly one variation, end to end.
 *
 * Every assertion here runs on the PUBLIC payload, produced by the real spine: loader →
 * extractor → transformer → canonical → adapter → live PostgreSQL (schema from the real
 * migration files) → query provider → Resource. The matcher then sees exactly what a storefront
 * receives over HTTP. Asserting against hand-built Resource arrays would prove the matcher works
 * and say nothing about whether the pipeline preserves what it needs.
 *
 * The central fixture mirrors the live reference store's v-neck tee, because it is the case that
 * motivated the product-side field: three variations, each varying by colour and each carrying a
 * WILDCARD for size, so the sizes on offer exist nowhere in the variation list.
 */
final class VariationSelectionIntegrationTest extends TestCase
{
    private const PARENT = 94;

    /** The live reference store's hoodie — the product outside the supported selector model. */
    private const HOODIE = 95;

    /** Term ids, as WordPress would issue them across two taxonomies. */
    private const COLOURS = [34 => 'blue', 35 => 'green', 36 => 'red'];
    private const SIZES   = [37 => 'large', 38 => 'medium', 39 => 'small'];

    private mixed $pgConn = null;
    private PostgresDatabaseConnection $db;
    private InMemoryCommerceLoader $loader;

    protected function setUp(): void
    {
        $this->pgConn = $this->connectPgsql();
        $this->db     = new PostgresDatabaseConnection($this->pgConn);
        $this->loader = new InMemoryCommerceLoader();

        pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS commerce CASCADE');
        pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS system CASCADE');
        CommerceSchema::applySystemTables($this->pgConn);
        CommerceSchema::applyAll($this->pgConn);
    }

    protected function tearDown(): void
    {
        if ($this->pgConn !== null) {
            pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS commerce CASCADE');
            pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS system CASCADE');
            pg_close($this->pgConn);
            $this->pgConn = null;
        }
    }

    // =========================================================================
    // The whole point: a complete selection → one woo_variation_id
    // =========================================================================

    /**
     * Nine complete selections over colour × size, against three variations that wildcard size.
     * Every one resolves, none is ambiguous, and each colour resolves to its own variation
     * whatever size is chosen — which is precisely what "any size" means.
     */
    public function test_every_complete_selection_resolves_to_exactly_one_variation(): void
    {
        $this->seedVNeckTee();

        $product    = $this->publishedProduct();
        $variations = $this->publishedVariations();

        $expected = [108 => 'red', 109 => 'green', 110 => 'blue'];

        foreach (self::SIZES as $size) {
            foreach ($expected as $variationId => $colour) {
                $selection = ['pa_color' => $colour, 'pa_size' => $size];

                self::assertFalse(
                    VariationSelector::isAmbiguous($product, $variations, $selection),
                    "{$colour}/{$size} must not be ambiguous.",
                );

                $resolved = VariationSelector::resolve($product, $variations, $selection);

                self::assertNotNull($resolved, "{$colour}/{$size} must resolve.");
                self::assertSame($variationId, $resolved['woo_variation_id']);
                self::assertSame(self::PARENT, $resolved['woo_product_id']);
            }
        }

        // And the selector a consumer builds from the two payloads is the right one.
        self::assertSame(['pa_color', 'pa_size'], VariationSelector::dimensions($variations));
        self::assertSame(
            ['blue', 'green', 'red'],
            VariationSelector::options($variations, $product, 'pa_color'),
        );
        self::assertSame(
            ['large', 'medium', 'small'],
            VariationSelector::options($variations, $product, 'pa_size'),
            'The sizes come from the product: no variation names one.',
        );
    }

    /**
     * The wildcard survives the round trip through JSONB. If the projection dropped the empty
     * entry, `pa_size` would vanish as a dimension and the selector would never ask for a size.
     */
    public function test_the_wildcard_entry_survives_projection_and_delivery(): void
    {
        $this->seedVNeckTee();

        foreach ($this->publishedVariations() as $variation) {
            self::assertArrayHasKey('pa_size', $variation['attributes']);
            self::assertSame('', $variation['attributes']['pa_size']);
        }
    }

    /** A combination the store does not sell resolves to nothing, through the real pipeline. */
    public function test_an_unsold_combination_resolves_to_nothing(): void
    {
        $this->seedVNeckTee();

        self::assertNull(VariationSelector::resolve(
            $this->publishedProduct(),
            $this->publishedVariations(),
            ['pa_color' => 'purple', 'pa_size' => 'large'],
        ));
    }

    // =========================================================================
    // Convergence — the relationship is not frozen at first projection
    // =========================================================================

    /**
     * A variation's selection changes at source. After normal convergence the OLD selection must
     * no longer reach it and the NEW one must — no stale selection relationship survives.
     */
    public function test_changing_a_variation_selection_moves_it_through_the_real_handler(): void
    {
        $this->seedVNeckTee();

        self::assertSame(110, VariationSelector::resolve(
            $this->publishedProduct(),
            $this->publishedVariations(),
            ['pa_color' => 'blue', 'pa_size' => 'large'],
        )['woo_variation_id']);

        // Source edit: variation 110 becomes green, through the ordinary upsert path.
        $this->givenVariation(110, ['pa_color' => 'green', 'pa_size' => ''], [35], version: 2);

        $product    = $this->publishedProduct();
        $variations = $this->publishedVariations();

        self::assertNull(
            VariationSelector::resolve($product, $variations, ['pa_color' => 'blue', 'pa_size' => 'large']),
            'The old selection must no longer reach variation 110.',
        );

        // Green now matches two variations, and the store's own order decides — 109 first.
        $green = VariationSelector::match($product, $variations, ['pa_color' => 'green', 'pa_size' => 'large']);
        self::assertSame([109, 110], array_column($green, 'woo_variation_id'));
    }

    /**
     * The PARENT's allowed options change while the variations stay put. The selector's option
     * list must converge without any variation being rewritten.
     */
    public function test_changing_the_parent_options_converges_without_touching_variations(): void
    {
        $this->seedVNeckTee();

        self::assertSame(
            ['large', 'medium', 'small'],
            VariationSelector::options($this->publishedVariations(), $this->publishedProduct(), 'pa_size'),
        );

        $checksumsBefore = $this->variationChecksums();

        // The store drops `small` from the product and adds nothing.
        $this->givenProduct([34, 35, 36, 37, 38], version: 2);

        self::assertSame(
            ['large', 'medium'],
            VariationSelector::options($this->publishedVariations(), $this->publishedProduct(), 'pa_size'),
        );
        self::assertSame(
            $checksumsBefore,
            $this->variationChecksums(),
            'A parent option change must not rewrite variation rows.',
        );
    }

    // =========================================================================
    // Out-of-order arrival (AG-7) — no 500, and the final state is correct
    // =========================================================================

    /**
     * The three aggregates PROJECT in the worst order: variations first, then their parent, then
     * the attribute terms. At every step the API answers, and selection works as soon as the
     * facts it needs are present — it never fails, it converges.
     *
     * "Before its parent" means before the parent's PROJECTION, not before the parent exists:
     * a variation and its product are one WordPress save apart, and the loader reads the parent's
     * type from WordPress to decide scope (AG-13). What arrives out of order is the event.
     */
    public function test_selection_converges_whatever_the_arrival_order(): void
    {
        $this->registerProduct(array_merge(array_keys(self::COLOURS), array_keys(self::SIZES)));

        // 1. The variation events land first. Neither the product nor the terms have projected.
        foreach ([108 => 'red', 109 => 'green', 110 => 'blue'] as $id => $colour) {
            $this->givenVariation($id, ['pa_color' => $colour, 'pa_size' => ''], []);
        }

        $variations = $this->publishedVariations();
        self::assertCount(3, $variations, 'Variations serve before their parent projects (AG-7).');
        self::assertSame(['pa_color', 'pa_size'], VariationSelector::dimensions($variations));

        // No product row yet — no error, and no options. Note what DECISION AL makes of that:
        // the capability lives on the parent, so until the parent projects a consumer has no
        // permission to resolve. That is the right answer rather than a gap, and it is the same
        // conservative default the unknown-capability case gets.
        self::assertNull((new ProductQueryProvider($this->db))->findBySlug('v-neck-t-shirt'));
        self::assertNull(VariationSelector::resolve([], $variations, ['pa_color' => 'blue']));

        // 2. The product projects, but its terms have not.
        $this->projectProduct();

        $product = $this->publishedProduct();
        self::assertEquals(
            new \stdClass(),
            $product['attributes'],
            'Unresolved term references publish as an empty map, never as an error.',
        );
        self::assertSame([], VariationSelector::options($variations, $product, 'pa_size'));

        // 3. The terms land. No product rewrite; the link resolves on source_term_id.
        $this->seedTerms();

        $product = $this->publishedProduct();
        self::assertSame(
            ['large', 'medium', 'small'],
            VariationSelector::options($this->publishedVariations(), $product, 'pa_size'),
        );
        self::assertSame(
            ['blue', 'green', 'red'],
            VariationSelector::options($this->publishedVariations(), $product, 'pa_color'),
        );
    }

    /** A tombstoned variation leaves the candidate set — a deleted option cannot be selected. */
    public function test_a_tombstoned_variation_stops_being_a_candidate(): void
    {
        $this->seedVNeckTee();

        unset($this->loader->variations[110]);
        $this->variationHandler()->handle($this->event(110, 'product_variation', 'deleted', 2));

        $product    = $this->publishedProduct();
        $variations = $this->publishedVariations();

        self::assertCount(2, $variations);
        self::assertNull(VariationSelector::resolve($product, $variations, ['pa_color' => 'blue', 'pa_size' => 'large']));
    }

    // =========================================================================
    // Read cost
    // =========================================================================

    /** The product options are an aggregate, not a lookup per product or per term. */
    public function test_publishing_product_attributes_costs_no_extra_query(): void
    {
        $this->seedVNeckTee();

        for ($i = 1; $i <= 20; $i++) {
            $this->givenProduct([34, 37], productId: 1000 + $i, slug: "filler-{$i}");
        }

        $counting = new SelectionCountingConnection($this->db);
        $provider = new ProductQueryProvider($counting);

        $provider->list(new ProductFilterSet(limit: 1));
        $one = $counting->queries;

        $counting->queries = 0;
        $provider->list(new ProductFilterSet(limit: 20));

        self::assertSame(1, $one, 'A product listing must be a single query.');
        self::assertSame($one, $counting->queries, 'Query count must not grow with page size.');
    }

    /** The aggregate must not multiply product rows — one product, one row, however many terms. */
    public function test_a_product_with_many_terms_still_returns_one_row(): void
    {
        $this->seedVNeckTee();

        $rows = (new ProductQueryProvider($this->db))->list(new ProductFilterSet())->rows;

        self::assertCount(1, $rows);
    }

    // =========================================================================
    // Scope regression (AG-9)
    // =========================================================================

    /**
     * A local/custom variation attribute is dropped by the pipeline — Phase 2 is global-only.
     * This pins the behaviour so it cannot expand silently, and records the consequence the
     * live reference store already exhibits: two variations that differ only by a local
     * attribute publish the same selection pattern and cannot be told apart.
     *
     * Raised as FLAG-COMMVARLOCAL-1. The fix is an architect ruling, not a quiet broadening.
     */
    public function test_a_local_variation_attribute_is_dropped_and_the_product_is_marked_unsupported(): void
    {
        $this->seedHoodie();

        $product    = $this->publishedProduct('hoodie');
        $variations = $this->publishedVariations(self::HOODIE);

        // AG-9 still holds: the local dimension is not projected, and nothing about it leaks.
        foreach ($variations as $variation) {
            self::assertArrayNotHasKey('logo', $variation['attributes'], 'AG-9: global attributes only.');
        }
        self::assertStringNotContainsString('logo', strtolower((string) json_encode($product)));

        // The published selections really are indistinguishable — the defect, projected.
        self::assertSame(['pa_color'], VariationSelector::dimensions($variations));
        self::assertSame(
            ['{"pa_color":"blue"}', '{"pa_color":"blue"}'],
            array_map(fn (array $v): string => (string) json_encode($v['attributes']), $variations),
        );

        // And this is what stops it being a WRONG answer rather than merely an incomplete one:
        // the capability is published false, so the matcher refuses instead of picking one.
        self::assertFalse($product['variation_selection_supported']);
        self::assertNull(VariationSelector::resolve($product, $variations, ['pa_color' => 'blue']));
        self::assertSame([], VariationSelector::match($product, $variations, ['pa_color' => 'blue']));

        // The product itself stays a perfectly ordinary catalogue entry.
        self::assertSame('hoodie', $product['slug']);
        self::assertSame(self::HOODIE, $product['woo_product_id']);
        self::assertSame(
            ['blue'],
            array_column($product['attributes']['pa_color'], 'slug'),
            'Supported global options stay published; the flag gates how they are READ.',
        );
    }

    // =========================================================================
    // DECISION AL — capability convergence through the normal pipeline
    // =========================================================================

    /**
     * MIGRATION SHAPE. Nullable, no default and no index are each a decision the migration
     * argues for, so each is asserted against the schema the real file actually produced.
     */
    public function test_the_capability_column_is_nullable_with_no_default_and_no_index(): void
    {
        $rows = $this->db->query(
            "SELECT data_type, is_nullable, column_default
             FROM information_schema.columns
             WHERE table_schema = 'commerce' AND table_name = 'products'
               AND column_name = 'variation_selection_supported'",
            [],
        );

        self::assertCount(1, $rows, 'Migration 0008 must have added the column.');
        self::assertSame('boolean', $rows[0]['data_type']);
        self::assertSame('YES', $rows[0]['is_nullable'], 'NULL is the unknown / not-applicable state.');
        self::assertNull(
            $rows[0]['column_default'],
            'A default would classify unconverged rows as a fact nobody computed.',
        );

        self::assertSame([], $this->db->query(
            "SELECT indexdef FROM pg_indexes
             WHERE schemaname = 'commerce' AND tablename = 'products'
               AND indexdef LIKE '%variation_selection_supported%'",
            [],
        ), 'Nothing filters on it; an index would be write cost for no read.');
    }

    /**
     * A row projected BEFORE this capability existed carries NULL, and delivery answers
     * conservatively. The consumer sees `false` — true while it is unknown — and never has to
     * reason about whether the platform has converged.
     */
    public function test_an_unclassified_row_publishes_false_and_refuses_resolution(): void
    {
        $this->seedVNeckTee();

        // Exactly what migration 0008 leaves behind on an existing installation.
        $this->db->execute(
            'UPDATE commerce.products SET variation_selection_supported = NULL WHERE source_product_id = $1',
            [self::PARENT],
        );

        $product = $this->publishedProduct();

        self::assertFalse(
            $product['variation_selection_supported'],
            'Unknown must publish as false, never as optimistically true.',
        );
        self::assertNull(VariationSelector::resolve($product, $this->publishedVariations(), [
            'pa_color' => 'blue',
            'pa_size'  => 'large',
        ]));
    }

    /**
     * REPLAY ESTABLISHES IT — through the ordinary handler, with no repair SQL.
     *
     * The upgrade path in one test: an existing row is left unclassified by the migration, the
     * product is re-emitted exactly as DECISION T/U re-emits it, and the capability lands.
     * Nothing bespoke runs, and no UPDATE touches the column except the one simulating the
     * pre-migration state.
     */
    public function test_replaying_an_unclassified_product_establishes_the_capability(): void
    {
        $this->seedVNeckTee();

        $this->db->execute(
            'UPDATE commerce.products
                SET variation_selection_supported = NULL, checksum = $2
              WHERE source_product_id = $1',
            [self::PARENT, str_repeat('0', 64)],
        );

        self::assertFalse($this->publishedProduct()['variation_selection_supported']);

        $this->projectProduct(self::PARENT, 2);

        self::assertTrue(
            $this->publishedProduct()['variation_selection_supported'],
            'Ordinary re-emission must converge the capability.',
        );
        self::assertSame(110, VariationSelector::resolve(
            $this->publishedProduct(),
            $this->publishedVariations(),
            ['pa_color' => 'blue', 'pa_size' => 'large'],
        )['woo_variation_id']);
    }

    /**
     * SUPPORTED → UNSUPPORTED. A store adds a local attribute and ticks "Used for variations".
     * No other projected value changes — not the price, not the terms, not the name — so if the
     * capability sat outside the checksum the write would be suppressed and consumers would keep
     * being told selection is safe. It must flip, and the matcher must stop.
     */
    public function test_adding_a_local_variation_attribute_flips_the_capability_to_false(): void
    {
        $this->seedVNeckTee();

        self::assertTrue($this->publishedProduct()['variation_selection_supported']);
        self::assertNotNull(VariationSelector::resolve(
            $this->publishedProduct(),
            $this->publishedVariations(),
            ['pa_color' => 'blue', 'pa_size' => 'large'],
        ));

        $this->givenProduct(
            array_merge(array_keys(self::COLOURS), array_keys(self::SIZES)),
            version: 2,
            selectionSupported: false,
        );

        self::assertFalse($this->publishedProduct()['variation_selection_supported']);
        self::assertNull(
            VariationSelector::resolve(
                $this->publishedProduct(),
                $this->publishedVariations(),
                ['pa_color' => 'blue', 'pa_size' => 'large'],
            ),
            'No stale capability: selection stops the moment the source stops supporting it.',
        );
    }

    /** UNSUPPORTED → SUPPORTED. The local attribute is removed and selection comes back. */
    public function test_removing_a_local_variation_attribute_flips_the_capability_to_true(): void
    {
        $this->givenProduct(
            array_merge(array_keys(self::COLOURS), array_keys(self::SIZES)),
            selectionSupported: false,
        );
        $this->seedTerms();

        foreach ([108 => 36, 109 => 35, 110 => 34] as $variationId => $termId) {
            $this->givenVariation($variationId, ['pa_color' => self::COLOURS[$termId], 'pa_size' => ''], [$termId]);
        }

        self::assertFalse($this->publishedProduct()['variation_selection_supported']);
        self::assertNull(VariationSelector::resolve(
            $this->publishedProduct(),
            $this->publishedVariations(),
            ['pa_color' => 'blue'],
        ));

        $this->givenProduct(
            array_merge(array_keys(self::COLOURS), array_keys(self::SIZES)),
            version: 2,
            selectionSupported: true,
        );

        self::assertTrue($this->publishedProduct()['variation_selection_supported']);
        self::assertSame(110, VariationSelector::resolve(
            $this->publishedProduct(),
            $this->publishedVariations(),
            ['pa_color' => 'blue'],
        )['woo_variation_id']);
    }

    /**
     * The capability is inside the checksum, so a flip is not write-suppressed. Asserted on the
     * STORED digest, not only on the published value, because suppression is precisely what
     * would silently defeat the two transitions above.
     */
    public function test_the_capability_moves_the_stored_checksum(): void
    {
        $this->seedVNeckTee();

        $before = $this->storedChecksum();

        $this->givenProduct(
            array_merge(array_keys(self::COLOURS), array_keys(self::SIZES)),
            version: 2,
            selectionSupported: false,
        );

        self::assertNotSame($before, $this->storedChecksum());
    }

    /** A simple product stores NULL and publishes no field at all, through the real pipeline. */
    public function test_a_simple_product_carries_no_capability_end_to_end(): void
    {
        $this->loader->products[300] = [
            'id' => 300, 'sku' => 'P-300', 'slug' => 'beanie', 'name' => 'Beanie',
            'description' => '', 'short_description' => '', 'status' => 'publish',
            'product_type' => 'simple', 'catalog_visibility' => 'visible', 'featured' => false,
            'price' => '9.00', 'regular_price' => '9.00', 'sale_price' => null,
            'featured_media_id' => 0, 'gallery_media_ids' => [],
            'published_at' => '2026-01-01 00:00:00', 'modified_at' => '2026-01-01 00:00:00',
            'meta' => [], 'category_ids' => [], 'attribute_term_ids' => [],
            'variation_selection_supported' => null,
        ];
        $this->projectProduct(300);

        self::assertNull(
            $this->db->query(
                'SELECT variation_selection_supported FROM commerce.products WHERE source_product_id = $1',
                [300],
            )[0]['variation_selection_supported'],
            'Not applicable stays NULL in storage.',
        );
        self::assertArrayNotHasKey(
            'variation_selection_supported',
            $this->publishedProduct('beanie'),
        );
    }

    // =========================================================================
    // Fixtures and spine
    // =========================================================================

    /**
     * The live hoodie: colour is a global attribute, `Logo` is a LOCAL one — and both are used
     * for variations, which is what puts the product outside the supported selector model.
     *
     * The loader reports `variation_selection_supported: false` for it, exactly as
     * VariationSelectionScope classifies the real WooCommerce object, and the two blue
     * variations arrive with their local entry still attached so the extractor is the thing that
     * drops it (AG-9) rather than the fixture quietly pre-dropping it.
     */
    private function seedHoodie(): void
    {
        $this->givenProduct([34], productId: self::HOODIE, slug: 'hoodie', selectionSupported: false);
        $this->seedTerms();

        $this->givenVariation(118, ['pa_color' => 'blue', 'logo' => 'Yes'], [34], menuOrder: 0, parentId: self::HOODIE);
        $this->givenVariation(113, ['pa_color' => 'blue', 'logo' => 'No'], [34], menuOrder: 3, parentId: self::HOODIE);
    }

    /** The live v-neck tee: colour varies, size is "any" on every variation. */
    private function seedVNeckTee(): void
    {
        $this->givenProduct(array_merge(array_keys(self::COLOURS), array_keys(self::SIZES)));
        $this->seedTerms();

        foreach ([108 => 36, 109 => 35, 110 => 34] as $variationId => $termId) {
            $this->givenVariation(
                $variationId,
                ['pa_color' => self::COLOURS[$termId], 'pa_size' => ''],
                [$termId],
            );
        }
    }

    private function seedTerms(): void
    {
        foreach (self::COLOURS as $id => $slug) {
            $this->givenTerm($id, $slug, 'pa_color');
        }

        foreach (self::SIZES as $id => $slug) {
            $this->givenTerm($id, $slug, 'pa_size');
        }
    }

    /** Register the product with the source loader AND project it. @param list<int> $termIds */
    private function givenProduct(
        array $termIds,
        int $version = 1,
        int $productId = self::PARENT,
        string $slug = 'v-neck-t-shirt',
        ?bool $selectionSupported = true,
    ): void {
        $this->registerProduct($termIds, $productId, $slug, $selectionSupported);
        $this->projectProduct($productId, $version);
    }

    /**
     * Source registration ONLY — no event, no projection.
     *
     * Split from projection because a variation's loader must be able to read its parent's TYPE
     * from WordPress (AG-13 scope) in the very case where the parent's own event has not been
     * processed yet. Collapsing the two would make that order untestable.
     *
     * @param list<int> $termIds
     */
    private function registerProduct(
        array $termIds,
        int $productId = self::PARENT,
        string $slug = 'v-neck-t-shirt',
        ?bool $selectionSupported = true,
    ): void {
        $this->loader->products[$productId] = [
            'id' => $productId, 'sku' => "P-{$productId}", 'slug' => $slug,
            'name' => 'V-Neck T-Shirt', 'description' => '', 'short_description' => '',
            'status' => 'publish', 'product_type' => 'variable', 'catalog_visibility' => 'visible',
            'featured' => false, 'price' => '15.00', 'regular_price' => '15.00', 'sale_price' => null,
            'featured_media_id' => 0, 'gallery_media_ids' => [],
            'published_at' => '2026-01-01 00:00:00', 'modified_at' => '2026-01-01 00:00:00',
            'meta' => [], 'category_ids' => [], 'attribute_term_ids' => $termIds,
            // What WpCommerceLoaderImpl computes from WooCommerce's own attribute flags
            // (DECISION AL); the in-memory loader carries the same key through.
            'variation_selection_supported' => $selectionSupported,
        ];
    }

    private function projectProduct(int $productId = self::PARENT, int $version = 1): void
    {
        $this->productHandler()->handle($this->event($productId, 'product', 'updated', $version));
    }

    /** @param array<string,string> $attributes @param list<int> $termIds */
    private function givenVariation(
        int $id,
        array $attributes,
        array $termIds,
        int $version = 1,
        int $menuOrder = 0,
        int $parentId = self::PARENT,
    ): void {
        $this->loader->variations[$id] = [
            'id' => $id, 'parent_id' => $parentId, 'sku' => "SKU-{$id}",
            'name' => "Variation {$id}", 'description' => '', 'status' => 'publish',
            'price' => '15.00', 'regular_price' => '15.00', 'sale_price' => null,
            'featured_media_id' => 0, 'menu_order' => $menuOrder,
            'attributes' => $attributes, 'attribute_term_ids' => $termIds,
            'modified_at' => '2026-01-01 00:00:00',
        ];

        $this->variationHandler()->handle($this->event($id, 'product_variation', 'updated', $version));
    }

    private function givenTerm(int $termId, string $slug, string $taxonomy): void
    {
        $this->loader->terms[$termId] = [
            'term_id' => $termId, 'taxonomy' => $taxonomy, 'slug' => $slug,
            'name' => ucfirst($slug), 'description' => '', 'parent' => 0, 'count' => 0,
        ];

        $this->termHandler()->handle($this->event($termId, 'attribute_term', 'created', 1));
    }

    private function productHandler(): ProductUpsertHandler
    {
        return new ProductUpsertHandler(
            $this->loader,
            new ProductExtractor(new ProductValidator()),
            new ProductTransformer(),
            new ProductAdapter($this->db),
        );
    }

    private function variationHandler(): VariationUpsertHandler
    {
        return new VariationUpsertHandler(
            $this->loader,
            new VariationExtractor(new VariationValidator()),
            new VariationTransformer(),
            new VariationAdapter($this->db),
        );
    }

    private function termHandler(): TermUpsertHandler
    {
        return new TermUpsertHandler(
            $this->loader,
            new TermExtractor(new TermValidator()),
            new TermTransformer(),
            new TermAdapter($this->db),
        );
    }

    // -------------------------------------------------------------------------
    // The PUBLIC payloads — the only thing the matcher is allowed to see
    // -------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function publishedProduct(string $slug = 'v-neck-t-shirt'): array
    {
        $row = (new ProductQueryProvider($this->db))->findBySlug($slug);

        self::assertNotNull($row, "Product {$slug} must be projected.");

        return (new ProductResource())->toArray($row);
    }

    /** @return list<array<string,mixed>> */
    private function publishedVariations(int $parentId = self::PARENT): array
    {
        $page = (new VariationQueryProvider($this->db))
            ->list(new VariationFilterSet(parentSourceId: $parentId));

        /** @var list<array<string,mixed>> $data */
        $data = (new VariationResource())->toCollection($page->rows, $page->nextCursor)['data'];

        return $data;
    }

    private function storedChecksum(int $productId = self::PARENT): string
    {
        return (string) $this->db->query(
            'SELECT checksum FROM commerce.products WHERE source_product_id = $1',
            [$productId],
        )[0]['checksum'];
    }

    /** @return array<int,string> variation source id → checksum */
    private function variationChecksums(): array
    {
        $out = [];

        foreach ($this->db->query(
            'SELECT source_variation_id, checksum FROM commerce.product_variations ORDER BY source_variation_id',
            [],
        ) as $row) {
            $out[(int) $row['source_variation_id']] = (string) $row['checksum'];
        }

        return $out;
    }

    private function event(int $id, string $aggregate, string $action, int $version): EventInterface
    {
        return new VariationSelectionEvent(
            "commerce.{$aggregate}.{$action}",
            $aggregate,
            (string) $id,
            $version,
            "{$aggregate}-{$id}-{$action}-{$version}",
        );
    }

    private function connectPgsql(): mixed
    {
        $host = getenv('HSP_TEST_PGSQL_HOST')     ?: '127.0.0.1';
        $port = getenv('HSP_TEST_PGSQL_PORT')     ?: '5432';
        $user = getenv('HSP_TEST_PGSQL_USER')     ?: 'postgres';
        $pass = getenv('HSP_TEST_PGSQL_PASSWORD') ?: 'postgres';
        $db   = getenv('HSP_TEST_PGSQL_DATABASE') ?: 'postgres';

        $conn = @pg_connect(
            "host={$host} port={$port} user={$user} password={$pass} dbname={$db}",
            PGSQL_CONNECT_FORCE_NEW,
        );

        if ($conn === false) {
            self::markTestSkipped("PostgreSQL not available at {$host}:{$port}.");
        }

        return $conn;
    }
}

/** Counts statements, so "no N+1" is measured rather than asserted by inspection. */
final class SelectionCountingConnection implements DatabaseConnectionInterface
{
    public int $queries = 0;

    public function __construct(private readonly DatabaseConnectionInterface $inner)
    {
    }

    /**
     * @param  list<mixed> $params
     * @return list<array<string,mixed>>
     */
    public function query(string $sql, array $params = []): array
    {
        $this->queries++;

        return $this->inner->query($sql, $params);
    }

    /** @param list<mixed> $params */
    public function execute(string $sql, array $params = []): int
    {
        $this->queries++;

        return $this->inner->execute($sql, $params);
    }

    public function beginTransaction(): void
    {
        $this->inner->beginTransaction();
    }

    public function commit(): void
    {
        $this->inner->commit();
    }

    public function rollback(): void
    {
        $this->inner->rollback();
    }
}

/** Event double carrying an explicit aggregate type, so one class serves every aggregate here. */
final class VariationSelectionEvent implements EventInterface
{
    public function __construct(
        private readonly string $eventType,
        private readonly string $aggregateType,
        private readonly string $aggregateId,
        private readonly int $version,
        private readonly string $eventKey,
    ) {
    }

    public function getId(): string
    {
        return sprintf(
            '%s-0000-7000-8000-%012d',
            substr(md5($this->eventKey), 0, 8),
            crc32($this->eventKey) % 999999,
        );
    }

    public function getEventType(): string { return $this->eventType; }
    public function getEventVersion(): int { return 1; }
    public function getAggregateType(): string { return $this->aggregateType; }
    public function getAggregateId(): string { return $this->aggregateId; }
    public function getAggregateVersion(): int { return $this->version; }
    /** @return array<string,mixed> */
    public function getPayload(): array { return []; }
    public function getChecksum(): string { return str_repeat('e', 64); }
    public function getSourceUpdatedAt(): \DateTimeImmutable { return new \DateTimeImmutable('2026-06-01T00:00:00Z'); }
    public function getCreatedAt(): \DateTimeImmutable { return new \DateTimeImmutable('2026-06-01T00:00:00Z'); }
    public function getCorrelationId(): string { return '01900000-0000-7000-8000-000000000005'; }
    public function getCausationId(): ?string { return null; }
}
