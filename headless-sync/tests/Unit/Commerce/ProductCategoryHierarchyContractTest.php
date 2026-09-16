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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FLAG-COMMCATPARENT-1 — the public Product Category hierarchy contract.
 *
 * A category is addressed by `slug` and relates to its parent through two INDEPENDENT facts:
 * `has_parent` (does its own projected relationship name a parent?) and `parent_slug` (that
 * parent's slug, when its row is currently projected). No WordPress term id is published and no
 * server-side parent filter exists: the tree is rebuilt from the listing.
 *
 * The lifecycle transitions below are asserted here at the Resource boundary, from the rows the
 * query provider returns; ProductCategoryIntegrationTest proves the same states against the real
 * self-join and real handlers (child before parent, parent later projects, tombstoned parent,
 * parent rename with no child rewrite).
 */
final class ProductCategoryHierarchyContractTest extends TestCase
{
    private const VALIDATOR_SCRIPT = __DIR__ . '/../../../tools/openapi-validator/validate-openapi.mjs';

    private const KEYS = ['slug', 'name', 'description', 'has_parent', 'parent_slug', 'count'];

    // -------------------------------------------------------------------------
    // Resource — the three published states
    // -------------------------------------------------------------------------

    /** @return array<string, array{0:array<string,mixed>, 1:array<string,mixed>}> */
    public static function publishedStates(): array
    {
        return [
            'root'             => [
                self::row('clothing', 0, null),
                ['slug' => 'clothing', 'has_parent' => false, 'parent_slug' => null],
            ],
            'resolved child'   => [
                self::row('hoodies', 28, 'clothing'),
                ['slug' => 'hoodies', 'has_parent' => true, 'parent_slug' => 'clothing'],
            ],
            // Parent not projected yet, or tombstoned: the join found nothing. A child, NOT a root.
            'unresolved child' => [
                self::row('hoodies', 28, null),
                ['slug' => 'hoodies', 'has_parent' => true, 'parent_slug' => null],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $expected
     */
    #[DataProvider('publishedStates')]
    public function test_each_hierarchy_state_publishes_exactly(array $row, array $expected): void
    {
        $published = (new TermResource())->toArray($row);

        self::assertSame(self::KEYS, array_keys($published));
        self::assertSame($expected, array_intersect_key($published, $expected));

        foreach (['id', 'source_id', 'parent', 'parent_id', 'source_term_id'] as $internal) {
            self::assertArrayNotHasKey($internal, $published);
        }
    }

    /**
     * `has_parent` comes from the child's own relationship, never from whether the join resolved.
     * Walked through the provider rows a child sees over its life: arriving before its parent,
     * the parent landing, the parent renamed, the parent tombstoned.
     */
    public function test_has_parent_tracks_the_relationship_and_parent_slug_tracks_resolution(): void
    {
        $resource = new TermResource();
        $shape    = static fn (array $row): array => array_intersect_key(
            $resource->toArray($row),
            ['has_parent' => true, 'parent_slug' => true],
        );

        $before  = $shape(self::row('hoodies', 28, null));
        $landed  = $shape(self::row('hoodies', 28, 'clothing'));
        $renamed = $shape(self::row('hoodies', 28, 'apparel'));
        $deleted = $shape(self::row('hoodies', 28, null));

        self::assertSame(['has_parent' => true, 'parent_slug' => null], $before, 'child before parent');
        self::assertSame(['has_parent' => true, 'parent_slug' => 'clothing'], $landed, 'parent later projects');
        self::assertSame(['has_parent' => true, 'parent_slug' => 'apparel'], $renamed, 'parent renamed');
        self::assertSame(['has_parent' => true, 'parent_slug' => null], $deleted, 'parent tombstoned');

        // And a root can never acquire a parent slug, whatever a row carries.
        self::assertSame(
            ['has_parent' => false, 'parent_slug' => null],
            $shape(self::row('clothing', 0, 'stray')),
        );
    }

    // -------------------------------------------------------------------------
    // Runtime ⇄ generated OpenAPI
    // -------------------------------------------------------------------------

    public function test_runtime_keys_equal_the_published_schema_keys_and_all_are_required(): void
    {
        $document = self::document();

        foreach ([
            self::schema($document, '/hsp/v1/product-categories/{slug}'),
            self::schema($document, '/hsp/v1/product-categories')['properties']['data']['items'],
        ] as $item) {
            self::assertSame(self::KEYS, array_keys($item['properties']));
            self::assertSame(self::KEYS, $item['required']);
            self::assertSame('boolean', $item['properties']['has_parent']['type']);
            self::assertSame(['string', 'null'], $item['properties']['parent_slug']['type']);
        }
    }

    /** All three states, through ajv, against the schema the generator actually publishes. */
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
        $detail   = '/hsp/v1/product-categories/{slug}';
        $rows     = array_column(self::publishedStates(), 0);
        $cases    = ['listing' => ['/hsp/v1/product-categories', $resource->toCollection($rows, 'abc')]];

        foreach (self::publishedStates() as $label => [$row]) {
            $cases["detail, {$label}"] = [$detail, $resource->toArray($row)];
        }

        foreach ($cases as $label => [$path, $payload]) {
            $decoded = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            $error   = null;

            self::assertSame(
                OpenApiMetaSchemaValidator::GATE_VALID,
                $validator->instanceStatus(self::schema($document, $path), $decoded, $error),
                "{$label}: " . (string) $error,
            );
        }

        // And the gate is not vacuous: an integer parent, or a payload without has_parent, is refused.
        $valid = [
            'slug' => 'hoodies', 'name' => 'H', 'description' => '',
            'has_parent' => true, 'parent_slug' => null, 'count' => 0,
        ];

        foreach ([
            'integer parent_slug' => ['parent_slug' => 28] + $valid,
            'missing has_parent'  => array_diff_key($valid, ['has_parent' => true]),
        ] as $label => $invalid) {
            $error = null;
            self::assertSame(
                OpenApiMetaSchemaValidator::GATE_INVALID,
                $validator->instanceStatus(self::schema($document, $detail), $invalid, $error),
                $label,
            );
        }
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
