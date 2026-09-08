<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Commerce;

use HSP\Modules\Commerce\Queries\ProductFilterSet;
use HSP\Modules\Commerce\Queries\ProductQueryProvider;
use HSP\Modules\Commerce\Resources\ProductResource;
use HSP\Tests\Unit\Content\Adapters\FakeDbConnection;
use PHPUnit\Framework\TestCase;

/**
 * P2-S2 — the product delivery surface.
 *
 * Two things are asserted against the generated SQL rather than against rows, because they
 * are properties of the QUERY and would otherwise only surface on a live store:
 *
 *   - Catalog visibility scoping (Requirement B). Verified against WooCommerce 11.1.0: a
 *     published product carrying `exclude-from-catalog` resolves to visibility 'search' or
 *     'hidden'. A listing that filtered on post status alone would publish products the store
 *     owner deliberately hid — and would look completely correct in any test that only used
 *     visible products.
 *
 *   - Price comparison as NUMERIC. Compared as text, '9' > '10', so a max_price filter would
 *     quietly return the wrong set.
 */
final class ProductDeliveryTest extends TestCase
{
    private FakeDbConnection $db;
    private ProductQueryProvider $provider;

    protected function setUp(): void
    {
        $this->db       = new FakeDbConnection();
        $this->provider = new ProductQueryProvider($this->db);
    }

    private function lastSql(): string
    {
        $queries = array_values(array_filter(
            $this->db->log,
            static fn (array $entry): bool => $entry['method'] === 'query',
        ));

        self::assertNotSame([], $queries, 'expected a query to have been issued');

        return (string) end($queries)['sql'];
    }

    /** @return list<mixed> */
    private function lastParams(): array
    {
        $queries = array_values(array_filter(
            $this->db->log,
            static fn (array $entry): bool => $entry['method'] === 'query',
        ));

        return (array) end($queries)['params'];
    }

    // -------------------------------------------------------------------------
    // Catalog visibility (Requirement B)
    // -------------------------------------------------------------------------

    public function testTheListingIsScopedToCatalogVisibleProducts(): void
    {
        $this->provider->list(new ProductFilterSet());

        self::assertStringContainsString('catalog_visibility IN', $this->lastSql());
        self::assertContains('visible', $this->lastParams());
        self::assertContains('catalog', $this->lastParams());
    }

    /**
     * 'search' and 'hidden' both carry exclude-from-catalog, so neither may appear in a
     * catalog listing.
     */
    public function testHiddenAndSearchOnlyProductsAreNotIncludedInTheListing(): void
    {
        $this->provider->list(new ProductFilterSet());

        self::assertNotContains('hidden', $this->lastParams());
        self::assertNotContains('search', $this->lastParams());
    }

    /**
     * WooCommerce keeps a catalog-hidden product reachable at its direct address, and
     * Requirement B says single-product addressing follows that rather than copying listing
     * rules.
     */
    public function testTheSingleProductLookupDoesNotApplyCatalogVisibility(): void
    {
        $this->provider->findBySlug('hidden-widget');

        $sql = $this->lastSql();

        // `catalog_visibility` is still SELECTED — it is a published field — so the assertion
        // has to be about the predicate, not about the column appearing anywhere in the SQL.
        self::assertStringNotContainsString(
            'catalog_visibility IN',
            $sql,
            'the direct address must reach a catalog-hidden product, matching WooCommerce',
        );
        self::assertStringContainsString('deleted_at IS NULL', $sql);
    }

    public function testSoftDeletedProductsAreExcludedEverywhere(): void
    {
        $this->provider->list(new ProductFilterSet());
        self::assertStringContainsString('deleted_at IS NULL', $this->lastSql());

        $this->provider->findBySlug('x');
        self::assertStringContainsString('deleted_at IS NULL', $this->lastSql());
    }

    // -------------------------------------------------------------------------
    // Filters
    // -------------------------------------------------------------------------

    public function testPricesAreComparedAsNumericNotText(): void
    {
        $this->provider->list(new ProductFilterSet(minPrice: '5', maxPrice: '10'));

        $sql = $this->lastSql();

        // Without the cast, '9' > '10' lexically and the filter silently returns wrong rows.
        self::assertStringContainsString('price >= $', $sql);
        self::assertStringContainsString('::numeric', $sql);
        self::assertStringContainsString('price <= $', $sql);
    }

    public function testTypeSkuAndFeaturedFiltersAreApplied(): void
    {
        $this->provider->list(new ProductFilterSet(
            sku: 'W-1',
            productType: 'variable',
            featured: true,
        ));

        $sql = $this->lastSql();

        self::assertStringContainsString('sku = $', $sql);
        self::assertStringContainsString('product_type = $', $sql);
        self::assertStringContainsString('featured = $', $sql);
        self::assertContains('W-1', $this->lastParams());
        self::assertContains('variable', $this->lastParams());
    }

    /** The tiebreaker is what makes paging correct when products share a timestamp. */
    public function testTheListingSortsByPublishedAtWithAnIdTiebreaker(): void
    {
        $this->provider->list(new ProductFilterSet());

        self::assertStringContainsString('ORDER BY p.published_at DESC, p.id DESC', $this->lastSql());
    }

    public function testTheListingFetchesOneExtraRowToDetectAFurtherPage(): void
    {
        $this->provider->list(new ProductFilterSet(limit: 5));

        self::assertContains(6, $this->lastParams(), 'limit + 1 avoids a second COUNT query');
    }

    public function testTheLimitIsCapped(): void
    {
        $this->provider->list(new ProductFilterSet(limit: 10_000));

        self::assertContains(101, $this->lastParams(), 'MAX_LIMIT 100, fetched as 101');
    }

    // -------------------------------------------------------------------------
    // AG-5
    // -------------------------------------------------------------------------

    public function testAForeignDomainFilterIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/requires a .*ProductFilterSet/');

        $this->provider->list(new \HSP\Modules\Content\Queries\ContentFilterSet());
    }

    // -------------------------------------------------------------------------
    // Resource shape
    // -------------------------------------------------------------------------

    public function testTheResourcePublishesPricesAsStrings(): void
    {
        $out = (new ProductResource())->toArray([
            'id'                => 'uuid-1',
            'source_product_id' => 42,
            'slug'              => 'w',
            'name'              => 'W',
            'price'             => '19.99',
            'regular_price'     => '24.99',
            'sale_price'        => null,
            'featured'          => 't',
            'gallery_media_ids' => '[8,9]',
            'meta_jsonb'        => '{"colour":"blue"}',
        ]);

        // A JSON number would hand consumers a binary float for a decimal quantity — the
        // route by which 19.99 becomes 19.989999999999998 in a cart total.
        self::assertSame('19.99', $out['prices']['price']);
        self::assertIsString($out['prices']['price']);
        self::assertNull($out['prices']['sale_price']);

        // PostgreSQL returns booleans as 't'/'f' over the text protocol.
        self::assertTrue($out['featured']);

        self::assertSame([8, 9], $out['media']['gallery_ids']);
        self::assertSame(['colour' => 'blue'], $out['meta']);
    }

    /** AG-10: references only. content.media owns attachment state. */
    public function testTheResourcePublishesMediaAsReferencesNotExpandedObjects(): void
    {
        $out = (new ProductResource())->toArray([
            'id' => 'u', 'source_product_id' => 1, 'slug' => 's', 'name' => 'n',
            'featured_media_id' => 7, 'gallery_media_ids' => '[]',
        ]);

        self::assertSame(7, $out['media']['featured_id']);
        self::assertArrayNotHasKey('url', $out['media']);
    }

    /**
     * No permalink field: the WooCommerce permalink base is configurable, so any URL here
     * would be a guess that goes stale on a settings change (FLAG-COMMPERMA-1).
     */
    public function testTheResourcePublishesNoPermalink(): void
    {
        $out = (new ProductResource())->toArray([
            'id' => 'u', 'source_product_id' => 1, 'slug' => 's', 'name' => 'n',
        ]);

        self::assertArrayNotHasKey('permalink', $out);
        self::assertArrayNotHasKey('url', $out);
        self::assertArrayNotHasKey('path', $out);
    }

    public function testTheCollectionCarriesTheCursorEnvelope(): void
    {
        $out = (new ProductResource())->toCollection([], 'next-token');

        self::assertSame([], $out['data']);
        self::assertSame('next-token', $out['meta']['next_cursor']);
    }
}
