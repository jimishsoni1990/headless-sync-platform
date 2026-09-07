<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Commerce;

use HSP\Core\Contracts\CursorPage;
use HSP\Core\Contracts\QueryFilterInterface;
use HSP\Core\Contracts\QueryProviderInterface;
use HSP\Modules\Commerce\Queries\AttributeQueryProvider;
use HSP\Modules\Commerce\Queries\ProductFilterSet;
use HSP\Modules\Commerce\Queries\ProductQueryProvider;
use HSP\Modules\Commerce\Queries\TermFilterSet;
use HSP\Modules\Commerce\Resources\AttributeResource;
use HSP\Modules\Commerce\Resources\TermResource;
use HSP\Modules\Commerce\Rest\CommerceRestRegistrar;
use HSP\Tests\Unit\Content\Adapters\FakeDbConnection;
use PHPUnit\Framework\TestCase;

/**
 * P2-S4 — the attribute delivery surface, and the guard that keeps it from reaching sideways.
 *
 * The attribute routes take a TAXONOMY from the URL. That is new: every route before them
 * either had no taxonomy or carried a module-owned literal, so the discriminator could not be
 * influenced from outside. Here it can, and `commerce.taxonomies` is shared with product
 * categories — so `/product-attributes/product_cat/terms` is a request to serve one aggregate's
 * rows through another aggregate's route. The predicate alone would happily do it.
 *
 * That is the DECISION AA shared-table leak arriving through the front door instead of through
 * a forgotten WHERE clause, and it is what most of this file is about.
 */
final class AttributeDeliveryTest extends TestCase
{
    private FakeDbConnection $db;

    protected function setUp(): void
    {
        $this->db = new FakeDbConnection();
    }

    private function registrar(SpyTermQueryFactory $factory = new SpyTermQueryFactory()): CommerceRestRegistrar
    {
        return new CommerceRestRegistrar(
            new ProductQueryProvider($this->db),
            new \HSP\Modules\Commerce\Resources\ProductResource(),
            new \HSP\Modules\Commerce\Queries\TermQueryProvider($this->db, 'product_cat'),
            new TermResource(),
            new AttributeQueryProvider($this->db),
            new AttributeResource(),
            $factory(...),
        );
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
    // The taxonomy-scope guard
    // -------------------------------------------------------------------------

    public function testTheTermsRouteServesAPaTaxonomy(): void
    {
        $factory = new SpyTermQueryFactory();

        $this->registrar($factory)->handleAttributeTermListing(new StubRestRequest(['taxonomy' => 'pa_colour']));

        self::assertSame(['pa_colour'], $factory->taxonomies);
    }

    /**
     * A non-attribute taxonomy is a 404, not a query.
     *
     * Note what is asserted: the provider is never even BUILT. Returning empty rows would look
     * like a pass while still having run a category query behind an attribute URL.
     */
    public function testTheTermsRouteRefusesANonAttributeTaxonomy(): void
    {
        $factory = new SpyTermQueryFactory();

        $result = $this->registrar($factory)->handleAttributeTermListing(
            new StubRestRequest(['taxonomy' => 'product_cat'])
        );

        self::assertSame([], $factory->taxonomies, 'a category query must not run behind an attribute route');
        self::assertNotNull($result);
        self::assertInstanceOf(\WP_Error::class, $result);
    }

    public function testTheTermsRouteRefusesTheBarePrefix(): void
    {
        $factory = new SpyTermQueryFactory();

        $this->registrar($factory)->handleAttributeTermListing(new StubRestRequest(['taxonomy' => 'pa_']));

        self::assertSame([], $factory->taxonomies);
    }

    // -------------------------------------------------------------------------
    // The product attribute filter
    // -------------------------------------------------------------------------

    public function testTheProductFilterScopesBothTaxonomyAndTerm(): void
    {
        (new ProductQueryProvider($this->db))->list(new ProductFilterSet(
            attributeTaxonomy: 'pa_colour',
            attributeTermSlug: 'blue',
        ));

        $sql = $this->lastSql();

        self::assertStringContainsString('commerce.entity_taxonomies', $sql);
        self::assertStringContainsString('t.taxonomy_type = $', $sql);
        self::assertContains('pa_colour', $this->lastParams());
        self::assertContains('blue', $this->lastParams());
    }

    /**
     * A term slug with no taxonomy applies NO filter.
     *
     * WordPress guarantees slug uniqueness only within a taxonomy, so an unscoped `blue` could
     * match a pa_colour term and a pa_finish term at once — a listing that silently answers a
     * different question than the one asked.
     */
    public function testAHalfSpecifiedAttributeFilterIsNotApplied(): void
    {
        (new ProductQueryProvider($this->db))->list(new ProductFilterSet(attributeTermSlug: 'blue'));

        self::assertNotContains('blue', $this->lastParams());
    }

    public function testTheRequestDropsAnAttributeFilterNamingANonAttributeTaxonomy(): void
    {
        $this->registrar()->handleProductListing(new StubRestRequest([
            'attribute'      => 'product_cat',
            'attribute_term' => 'shoes',
        ]));

        // The term parameter survives into the filter, but with no taxonomy to pair it with the
        // provider applies neither — so no category slug reaches the query.
        self::assertNotContains('shoes', $this->lastParams());
    }

    // -------------------------------------------------------------------------
    // Attribute reads
    // -------------------------------------------------------------------------

    public function testAnAttributeIsLookedUpByItsFullTaxonomyName(): void
    {
        (new AttributeQueryProvider($this->db))->findBySlug('pa_colour');

        self::assertStringContainsString('commerce.attributes', $this->lastSql());
        self::assertSame(['pa_colour'], $this->lastParams());
    }

    public function testAttributeReadsExcludeTombstonedRows(): void
    {
        (new AttributeQueryProvider($this->db))->list(new TermFilterSet());

        self::assertStringContainsString('deleted_at IS NULL', $this->lastSql());
    }

    public function testTheAttributeListingIsBounded(): void
    {
        (new AttributeQueryProvider($this->db))->list(new TermFilterSet(limit: 5000));

        // MAX_LIMIT, not the requested 5000 — an unbounded page is a bounded-cycle hazard as
        // much as a delivery one.
        self::assertContains(201, $this->lastParams());
    }

    public function testTheAttributeProviderRejectsAnotherDomainsFilter(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new AttributeQueryProvider($this->db))->list(new ProductFilterSet());
    }

    // -------------------------------------------------------------------------
    // Resource shape
    // -------------------------------------------------------------------------

    public function testTheResourcePublishesTheFullTaxonomyName(): void
    {
        $shaped = (new AttributeResource())->toArray([
            'id'                  => '0190-uuid',
            'source_attribute_id' => 3,
            'slug'                => 'pa_colour',
            'name'                => 'Colour',
            'type'                => 'select',
            'order_by'            => 'menu_order',
            'has_archives'        => 'f',
        ]);

        self::assertSame('pa_colour', $shaped['taxonomy']);
        self::assertSame(3, $shaped['source_id']);
        self::assertFalse($shaped['has_archives']);
    }

    /** PostgreSQL hands booleans back as 't'/'f' over the text protocol, not as PHP bools. */
    public function testPostgresBooleanTextIsDecoded(): void
    {
        $shaped = (new AttributeResource())->toArray(['has_archives' => 't']);

        self::assertTrue($shaped['has_archives']);
    }

    public function testTheCollectionCarriesTheCursor(): void
    {
        $collection = (new AttributeResource())->toCollection([['slug' => 'pa_size']], 'next-cursor');

        self::assertCount(1, $collection['data']);
        self::assertSame('next-cursor', $collection['meta']['next_cursor']);
    }
}

/** Records which taxonomy the registrar asked for, and never touches a database. */
final class SpyTermQueryFactory
{
    /** @var list<string> */
    public array $taxonomies = [];

    public function __invoke(string $taxonomy): QueryProviderInterface
    {
        $this->taxonomies[] = $taxonomy;

        return new class implements QueryProviderInterface {
            /** @return CursorPage<array<string,mixed>> */
            public function list(QueryFilterInterface $filters): CursorPage
            {
                return new CursorPage([], null);
            }

            /** @return array<string,mixed>|null */
            public function findBySlug(string $slug): ?array
            {
                return null;
            }
        };
    }
}

/** Minimal stand-in for WP_REST_Request — only get_param() is reached. */
final class StubRestRequest
{
    /** @param array<string,mixed> $params */
    public function __construct(private readonly array $params)
    {
    }

    public function get_param(string $key): mixed
    {
        return $this->params[$key] ?? null;
    }
}
