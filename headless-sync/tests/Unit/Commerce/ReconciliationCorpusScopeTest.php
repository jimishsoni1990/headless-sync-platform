<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Commerce;

use HSP\Modules\Commerce\Extractors\AttributeExtractor;
use HSP\Modules\Commerce\Extractors\InventoryExtractor;
use HSP\Modules\Commerce\Extractors\ProductExtractor;
use HSP\Modules\Commerce\Extractors\TermExtractor;
use HSP\Modules\Commerce\Extractors\VariationExtractor;
use HSP\Modules\Commerce\InventoryOwner;
use HSP\Modules\Commerce\Reconciliation\WpCommerceReconciliationSource;
use HSP\Modules\Commerce\Transformers\AttributeTransformer;
use HSP\Modules\Commerce\Transformers\InventoryTransformer;
use HSP\Modules\Commerce\Transformers\ProductTransformer;
use HSP\Modules\Commerce\Transformers\TermTransformer;
use HSP\Modules\Commerce\Transformers\VariationTransformer;
use HSP\Modules\Commerce\Validation\AttributeValidator;
use HSP\Modules\Commerce\Validation\InventoryValidator;
use HSP\Modules\Commerce\Validation\ProductValidator;
use HSP\Modules\Commerce\Validation\TermValidator;
use HSP\Modules\Commerce\Validation\VariationValidator;
use HSP\Tests\Support\InMemoryCommerceLoader;
use PHPUnit\Framework\TestCase;

/**
 * The reconciliation corpus is IN-SCOPE ONLY — found by the first-run onboarding test.
 *
 * The corpus previously returned every product regardless of type, on the reasoning that the
 * orphan sweep needs to see an entity that has left scope in order to tombstone it. That reasoning
 * was wrong: `ReconciliationService::findOrphans()` enumerates the PROJECTION table and asks
 * `getSourceState()` about each row, so the leaving-scope direction never consults this corpus at
 * all.
 *
 * Meanwhile `BackfillProgress` derives its EXPECTED counts from exactly this method. So a store
 * containing one `grouped` and one `external` product reported expected=126 against projected=122
 * and sat at 96% forever — convergence was unreachable on any catalogue containing a single
 * unsupported product. AG-13 is explicit: unsupported types "must not count as an expected Phase 2
 * projected Product".
 *
 * The subtle half is PAGING, and it is why the filter lives inside the pager rather than being
 * applied to its output: `ReconciliationService` loops `do { ... } while ($ids !== [])`, so a page
 * that filtered down to empty would be read as "corpus exhausted" and silently truncate the scan.
 */
final class ReconciliationCorpusScopeTest extends TestCase
{
    private InMemoryCommerceLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new InMemoryCommerceLoader();
    }

    private function source(): WpCommerceReconciliationSource
    {
        return new WpCommerceReconciliationSource(
            $this->loader,
            new ProductExtractor(new ProductValidator()),
            new ProductTransformer(),
            new TermExtractor(new TermValidator()),
            new TermTransformer(),
            new AttributeExtractor(new AttributeValidator()),
            new AttributeTransformer(),
            new VariationExtractor(new VariationValidator()),
            new VariationTransformer(),
            new InventoryExtractor(new InventoryValidator()),
            new InventoryTransformer(),
        );
    }

    private function givenProduct(int $id, string $type): void
    {
        $this->loader->products[$id] = [
            'id' => $id, 'sku' => "S{$id}", 'slug' => "p{$id}", 'name' => "P{$id}",
            'description' => '', 'short_description' => '', 'status' => 'publish',
            'product_type' => $type, 'catalog_visibility' => 'visible', 'featured' => false,
            'price' => '1.00', 'regular_price' => '1.00', 'sale_price' => null,
            'featured_media_id' => 0, 'gallery_media_ids' => [],
            'published_at' => '2026-01-01 00:00:00', 'modified_at' => '2026-01-01 00:00:00',
            'meta' => [], 'category_ids' => [], 'attribute_term_ids' => [],
        ];
    }

    // -------------------------------------------------------------------------

    /** THE defect: unsupported types must not appear in the expected corpus (AG-13). */
    public function testTheProductCorpusExcludesUnsupportedTypes(): void
    {
        $this->givenProduct(1, 'simple');
        $this->givenProduct(2, 'grouped');
        $this->givenProduct(3, 'variable');
        $this->givenProduct(4, 'external');

        self::assertSame(
            ['1', '3'],
            $this->source()->listAggregateIds('product', 0, 100),
            'grouped and external must not count toward what backfill expects',
        );
    }

    /**
     * The paging trap: a page filtering down to EMPTY must not be read as "corpus exhausted".
     *
     * With the filter applied to the pager's OUTPUT, asking for 2 ids starting at 0 would return
     * [] here — every product in the first raw page is out of scope — and
     * ReconciliationService's `do/while ($ids !== [])` loop would stop, silently truncating the
     * scan and losing product 5.
     */
    public function testAFullyFilteredPageDoesNotTruncateTheCorpus(): void
    {
        $this->givenProduct(1, 'grouped');
        $this->givenProduct(2, 'external');
        $this->givenProduct(3, 'grouped');
        $this->givenProduct(4, 'external');
        $this->givenProduct(5, 'simple');

        self::assertSame(
            ['5'],
            $this->source()->listAggregateIds('product', 0, 2),
            'the pager must keep reading past a page with nothing in scope',
        );
    }

    /** A corpus with nothing in scope at all terminates rather than looping. */
    public function testAnEntirelyOutOfScopeCorpusTerminates(): void
    {
        $this->givenProduct(1, 'grouped');
        $this->givenProduct(2, 'external');

        self::assertSame([], $this->source()->listAggregateIds('product', 0, 10));
    }

    /** Paging still advances: asking after an id returns only what follows it. */
    public function testPagingStillAdvancesPastTheCursor(): void
    {
        $this->givenProduct(1, 'simple');
        $this->givenProduct(2, 'simple');
        $this->givenProduct(3, 'simple');

        self::assertSame(['1', '2'], $this->source()->listAggregateIds('product', 0, 2));
        self::assertSame(['3'], $this->source()->listAggregateIds('product', 2, 2));
        self::assertSame([], $this->source()->listAggregateIds('product', 3, 2));
    }

    /** Variations follow their parent's scope. */
    public function testTheVariationCorpusExcludesVariationsOfOutOfScopeParents(): void
    {
        $this->givenProduct(10, 'variable');
        $this->givenProduct(20, 'grouped');

        $this->loader->variations[11] = ['id' => 11, 'parent_id' => 10];
        $this->loader->variations[21] = ['id' => 21, 'parent_id' => 20];

        self::assertSame(['11'], $this->source()->listAggregateIds('product_variation', 0, 100));
    }

    /**
     * Inventory counts OWNERS, not candidates (AG-14).
     *
     * A parent-managed variation is not an owner and has no row, so counting it would make
     * convergence unreachable in exactly the way the product filter fixes.
     */
    public function testTheInventoryCorpusCountsOwnersOnly(): void
    {
        $this->givenProduct(10, 'variable');
        $this->loader->variations[11] = ['id' => 11, 'parent_id' => 10];
        $this->loader->variations[12] = ['id' => 12, 'parent_id' => 10];

        // The product and variation 11 own stock; variation 12 inherits it from the parent.
        foreach ([10 => InventoryOwner::TYPE_PRODUCT, 11 => InventoryOwner::TYPE_VARIATION] as $id => $type) {
            $this->loader->inventory[$id] = [
                'owner_type' => $type, 'owner_id' => $id, 'manages_stock' => true,
                'stock_quantity' => 1, 'stock_status' => 'instock',
                'backorders' => 'no', 'low_stock_amount' => null,
            ];
        }

        self::assertSame(
            ['10', '11'],
            $this->source()->listAggregateIds('inventory', 0, 100),
            'variation 12 inherits its parent stock and owns no row',
        );
    }

    /**
     * Filtering the corpus does NOT weaken tombstoning — the property that made this look unsafe.
     *
     * An out-of-scope product reports exists=true, public=FALSE, which is what
     * ReconciliationService's orphan sweep acts on after finding the row in the PROJECTION table.
     */
    public function testAnOutOfScopeProductStillReportsAsNotPublic(): void
    {
        $this->givenProduct(1, 'grouped');

        $state = $this->source()->getSourceState('product', '1');

        self::assertTrue($state->exists, 'it is still a real WordPress product');
        self::assertFalse($state->public, 'but not one Phase 2 publishes — so the orphan sweep tombstones it');
    }

    /** Terms and attributes are unaffected — they have no scope concept. */
    public function testTermAndAttributeCorporaAreUnfiltered(): void
    {
        $this->loader->terms[5] = [
            'term_id' => 5, 'taxonomy' => 'product_cat', 'slug' => 'c',
            'name' => 'C', 'description' => '', 'parent' => 0, 'count' => 0,
        ];
        $this->loader->attributes[7] = [
            'id' => 7, 'slug' => 'pa_colour', 'name' => 'Colour',
            'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => false,
        ];

        self::assertSame(['5'], $this->source()->listAggregateIds('product_category', 0, 10));
        self::assertSame(['7'], $this->source()->listAggregateIds('attribute', 0, 10));
    }
}
