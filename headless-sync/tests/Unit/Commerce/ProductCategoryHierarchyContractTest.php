<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Commerce;

use HSP\Core\Contracts\CursorPage;
use HSP\Core\Contracts\QueryFilterInterface;
use HSP\Core\Contracts\QueryProviderInterface;
use HSP\Core\Observability\StructuredLogger;
use HSP\Core\Operations\OpenApi\OpenApiGenerator;
use HSP\Core\Rest\DeliveryErrorBoundary;
use HSP\Modules\Commerce\Operations\CommerceEndpointProvider;
use HSP\Modules\Commerce\Queries\ProductFilterSet;
use HSP\Modules\Commerce\Queries\TermFilterSet;
use HSP\Modules\Commerce\Queries\TermQueryProvider;
use HSP\Modules\Commerce\Resources\AttributeResource;
use HSP\Modules\Commerce\Resources\AttributeTermResource;
use HSP\Modules\Commerce\Resources\ProductResource;
use HSP\Modules\Commerce\Resources\TermResource;
use HSP\Modules\Commerce\Resources\VariationResource;
use HSP\Modules\Commerce\Rest\CommerceRestRegistrar;
use HSP\Tests\Unit\Content\Adapters\FakeDbConnection;
use HSP\Tests\Unit\Operations\OpenApi\OpenApiMetaSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * FLAG-COMMCATPARENT-1 — the public Product Category hierarchy contract.
 *
 * A category is addressed by `slug` and points at its parent by `parent_slug`, the same key. No
 * WordPress term id is published and no server-side parent filter exists: the tree is rebuilt
 * from the listing. `parent_slug` is resolved from the projection at read time by one self-join.
 */
final class ProductCategoryHierarchyContractTest extends TestCase
{
    private const VALIDATOR_SCRIPT = __DIR__ . '/../../../tools/openapi-validator/validate-openapi.mjs';

    private const KEYS = ['slug', 'name', 'description', 'parent_slug', 'count'];

    // -------------------------------------------------------------------------
    // Resource
    // -------------------------------------------------------------------------

    public function test_a_top_level_category_publishes_a_null_parent_slug(): void
    {
        $published = (new TermResource())->toArray(self::row('clothing', 0, null));

        self::assertSame(self::KEYS, array_keys($published));
        self::assertNull($published['parent_slug']);
    }

    public function test_a_child_category_publishes_its_parents_slug_and_no_source_identity(): void
    {
        $published = (new TermResource())->toArray(self::row('hoodies', 28, 'clothing'));

        self::assertSame(self::KEYS, array_keys($published));
        self::assertSame('clothing', $published['parent_slug']);

        foreach (['id', 'source_id', 'parent', 'parent_id', 'source_term_id'] as $internal) {
            self::assertArrayNotHasKey($internal, $published);
        }
    }

    /**
     * AG-7: the parent has not projected yet (or was tombstoned), so the join resolved nothing.
     * The child is still published, with a null reference — never an error, never a slug
     * invented from the source id.
     */
    public function test_an_unresolved_parent_publishes_null_and_the_category_survives(): void
    {
        $published = (new TermResource())->toArray(self::row('hoodies', 28, null));

        self::assertSame('hoodies', $published['slug']);
        self::assertNull($published['parent_slug']);
    }

    // -------------------------------------------------------------------------
    // Runtime ⇄ generated OpenAPI
    // -------------------------------------------------------------------------

    public function test_runtime_keys_equal_the_published_schema_keys(): void
    {
        $document = self::document();
        $detail   = self::schema($document, '/hsp/v1/product-categories/{slug}');
        $listing  = self::schema($document, '/hsp/v1/product-categories');

        self::assertSame(self::KEYS, array_keys($detail['properties']));
        self::assertSame(self::KEYS, array_keys($listing['properties']['data']['items']['properties']));
        self::assertSame(['string', 'null'], $detail['properties']['parent_slug']['type']);
    }

    /** Both nullability states, through ajv, against the schema the generator actually publishes. */
    public function test_category_payloads_validate_against_the_generated_schema(): void
    {
        $validator = new OpenApiMetaSchemaValidator(self::VALIDATOR_SCRIPT);

        if (! $validator->nodeAvailable()) {
            if (getenv('HSP_REQUIRE_NODE_GATE') === '1') {
                self::fail('HSP_REQUIRE_NODE_GATE=1 requires the ajv instance gate; node is unavailable.');
            }
            self::markTestSkipped('node unavailable.');
        }

        $resource = new TermResource();
        $document = self::document();
        $topLevel = $resource->toArray(self::row('clothing', 0, null));
        $child    = $resource->toArray(self::row('hoodies', 28, 'clothing'));

        $cases = [
            'detail, top-level' => ['/hsp/v1/product-categories/{slug}', $topLevel],
            'detail, child'     => ['/hsp/v1/product-categories/{slug}', $child],
            'listing'           => [
                '/hsp/v1/product-categories',
                $resource->toCollection([self::row('clothing', 0, null), self::row('hoodies', 28, 'clothing')], 'abc'),
            ],
        ];

        foreach ($cases as $label => [$path, $payload]) {
            $decoded = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            $error   = null;

            self::assertSame(
                OpenApiMetaSchemaValidator::GATE_VALID,
                $validator->instanceStatus(self::schema($document, $path), $decoded, $error),
                "{$label}: " . (string) $error,
            );
        }

        // And the gate is not vacuous: the removed integer shape is refused.
        $error = null;
        self::assertSame(
            OpenApiMetaSchemaValidator::GATE_INVALID,
            $validator->instanceStatus(
                self::schema($document, '/hsp/v1/product-categories/{slug}'),
                ['slug' => 'hoodies', 'name' => 'H', 'description' => '', 'parent_slug' => 28, 'count' => 0],
                $error,
            ),
        );
    }

    // -------------------------------------------------------------------------
    // Registrar — no parent filter; slug addressing and the product filter unchanged
    // -------------------------------------------------------------------------

    /**
     * An old caller's `?parent=28` is not read: the listing receives exactly the filter a request
     * without it would. WordPress ignores the undeclared key, so the response is the full
     * listing — the absence of a filter, not a supported one.
     */
    public function test_the_category_listing_reads_no_parent_filter(): void
    {
        $categories = self::spy();

        $this->registrar($categories)->handleCategoryListing(new \WP_REST_Request(['parent' => '28']));

        self::assertEquals(new TermFilterSet(), $categories->lastFilters);
    }

    public function test_the_product_category_filter_still_selects_by_slug(): void
    {
        $products = self::spy();

        $this->registrar(products: $products)->handleProductListing(new \WP_REST_Request(['category' => 'hoodies']));

        self::assertInstanceOf(ProductFilterSet::class, $products->lastFilters);
        self::assertSame('hoodies', $products->lastFilters->categorySlug);
    }

    // -------------------------------------------------------------------------
    // Query provider — one self-join, no per-row lookup
    // -------------------------------------------------------------------------

    public function test_listing_and_lookup_resolve_parent_slug_in_the_same_single_query(): void
    {
        $db       = new FakeDbConnection();
        $provider = new TermQueryProvider($db, 'product_cat');

        $db->willReturnRows([self::row('clothing', 0, null), self::row('hoodies', 28, 'clothing'), self::row('tshirts', 28, 'clothing')]);
        $provider->list(new TermFilterSet());
        $provider->findBySlug('hoodies');

        self::assertCount(2, $db->log, 'one query per call, whatever the page size');

        foreach ($db->log as $call) {
            $sql = (string) preg_replace('/\s+/', ' ', $call['sql']);

            self::assertStringContainsString('p.slug AS parent_slug', $sql);
            self::assertStringContainsString(
                'LEFT JOIN commerce.taxonomies p ON p.source_term_id = t.parent_id '
                . 'AND p.taxonomy_type = t.taxonomy_type AND p.deleted_at IS NULL',
                $sql,
            );
            self::assertStringContainsString('t.taxonomy_type = $', $sql);
            self::assertStringNotContainsString('parent_id = $', $sql, 'no parent filter parameter');
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** A provider that records the filter it was handed and returns an empty page. */
    private static function spy(): QueryProviderInterface
    {
        return new class implements QueryProviderInterface {
            public ?QueryFilterInterface $lastFilters = null;

            public function list(QueryFilterInterface $filters): CursorPage
            {
                $this->lastFilters = $filters;

                return new CursorPage([], null);
            }

            public function findBySlug(string $slug): ?array
            {
                return null;
            }
        };
    }

    private function registrar(
        ?QueryProviderInterface $categories = null,
        ?QueryProviderInterface $products = null,
    ): CommerceRestRegistrar {
        $empty = self::spy();

        return new CommerceRestRegistrar(
            $products ?? $empty,
            new ProductResource(),
            $categories ?? $empty,
            new TermResource(),
            $empty,
            new AttributeResource(),
            static fn (string $taxonomy): QueryProviderInterface => $empty,
            new AttributeTermResource(),
            $empty,
            new VariationResource(),
            new DeliveryErrorBoundary(new StructuredLogger(static function (string $line): void {
            })),
        );
    }

    /** @return array<string,mixed> the query row shape TermQueryProvider returns */
    private static function row(string $slug, int $parentId, ?string $parentSlug): array
    {
        return [
            'id'             => '01a09ec2-5227-7d72-af33-47c75ec8762e',
            'source_term_id' => 30,
            'taxonomy_type'  => 'product_cat',
            'slug'           => $slug,
            'name'           => ucfirst($slug),
            'description'    => '',
            'parent_id'      => $parentId,
            'term_count'     => 4,
            'parent_slug'    => $parentSlug,
        ];
    }

    /** @return array<string,mixed> */
    private static function document(): array
    {
        return (new OpenApiGenerator())->generate((new CommerceEndpointProvider())->endpoints());
    }

    /**
     * @param array<string,mixed> $document
     * @return array<string,mixed>
     */
    private static function schema(array $document, string $path): array
    {
        return $document['paths'][$path]['get']['responses']['200']['content']['application/json']['schema'];
    }
}
