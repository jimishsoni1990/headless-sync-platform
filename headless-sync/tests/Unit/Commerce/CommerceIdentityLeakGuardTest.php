<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Commerce;

use HSP\Modules\Commerce\Operations\CommerceEndpointProvider;
use HSP\Modules\Commerce\Resources\AttributeResource;
use HSP\Modules\Commerce\Resources\AttributeTermResource;
use HSP\Modules\Commerce\Resources\ProductResource;
use HSP\Modules\Commerce\Resources\TermResource;
use HSP\Modules\Commerce\Resources\VariationResource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FLAG-COMMSOURCEID-1 — the durable guard against internal projection/source identity reaching
 * the public Commerce contract.
 *
 * DECISION F already forbids this in prose: *"Resources expose ONLY contract fields. Internal
 * columns (`id UUID`, `source_post_id`, `source_term_id`, `checksum`, `synced_at`, `created_at`,
 * …) are never serialized into responses."* Commerce published four projection UUIDs and four
 * source keys anyway, for four sessions, because prose does not fail CI. This does.
 *
 * WHY IT IS NOT A REGEX OVER `*_id`. A blanket prohibition on id-shaped names would reject
 * `woo_product_id` and `woo_variation_id` (DECISION AK, the deliberate interoperability
 * contract) and `media.featured_id` and `media.gallery_ids` (Finding 011, the attachment
 * references). Every one of those is published on purpose, and a guard that has to be
 * suppressed to ship intentional work gets suppressed until it means nothing.
 *
 * So the rule is semantic and keyed by PUBLIC ENDPOINT SEMANTIC, not by resource class. That
 * distinction is not pedantry — it is the thing that catches this bug class. `product_cat` and
 * `pa_*` terms live in one projection table and were served by one `TermResource`, so a field
 * product categories genuinely need was published on attribute terms too, where it means
 * nothing. A per-class allow-list would have called that compliant.
 *
 * Adding an intentional identifier is a deliberate edit to ALLOWED below — which is the review
 * moment this flag existed to create.
 *
 * Both halves are asserted, because cleaning one and leaving the other is the failure mode that
 * produced the drift in the first place: the RUNTIME resource output, and the PUBLISHED schema
 * the ADR-055 generator turns into `openapi.json`.
 */
final class CommerceIdentityLeakGuardTest extends TestCase
{
    /**
     * Names that denote INTERNAL projection or source identity. None of these may appear in a
     * Commerce response or response schema unless the resource allow-lists it below.
     *
     * `checksum`, `synced_at`, `created_at` and `deleted_at` ride along because DECISION F names
     * them in the same breath and they are equally never-public — a guard that only knows about
     * the fields that already leaked would not have caught these.
     *
     * `parent` and `parent_slug` are HIERARCHY REFERENCES, governed here for the same reason: a
     * parent reference is identity pointing elsewhere. The integer `parent` a product category
     * used to publish was a WordPress term id (FLAG-COMMCATPARENT-1); `parent_slug` is the
     * domain-safe replacement, and listing it here means it is published only where ALLOWED says
     * so — on product categories, never on the flat `pa_*` terms that share their table.
     *
     * @var list<string>
     */
    private const FORBIDDEN = [
        'id',
        'source_id',
        'source_product_id',
        'source_variation_id',
        'source_parent_id',
        'source_term_id',
        'source_attribute_id',
        'source_post_id',
        'entity_id',
        'owner_id',
        'taxonomy_id',
        'attribute_id',
        'variation_id',
        'product_id',
        'term_id',
        'parent_id',
        'checksum',
        'synced_at',
        'created_at',
        'deleted_at',
        'taxonomy_type',
        'parent',
        'parent_slug',
    ];

    /**
     * The identity fields each Commerce resource is authorised to publish, with the ruling that
     * authorises it. Anything in FORBIDDEN and not here is a leak.
     *
     * KEYED BY PUBLIC ENDPOINT SEMANTIC, NOT BY RESOURCE CLASS. `product_category` and
     * `attribute_term` are two entries even though both read `commerce.taxonomies`, because the
     * question this guard asks is about a published contract, not about storage. If they shared
     * one entry, a product category's need would silently license the same field on `pa_*`
     * terms — which is exactly how the attribute-term exposure arose in the first place: one
     * `TermResource` served two endpoint families.
     *
     * NO ENTRY IS DEBT. Every entry cites a ruling or verified source fact that AUTHORISES the
     * field. The product category's `source_id`/`parent` pair used to sit here as DEBT owned by
     * FLAG-COMMCATPARENT-1; that flag removed both, together with the `?parent={source-term-id}`
     * filter they existed for, so `source_id` and `parent` are now plain leaks on every shape.
     *
     * A product's `source_id` anchored nothing — `woo_product_id` carried the identical integer
     * under a name that means it.
     *
     * @var array<string, array<string, string>>
     */
    private const ALLOWED = [
        'product'         => [
            'woo_product_id'    => 'DECISION AK-1 — Woo cart handoff identity',
            'media.featured_id' => 'Finding 011 — attachment reference',
            'media.gallery_ids' => 'Finding 011 — attachment references',
        ],
        'variation'       => [
            'woo_product_id'    => 'DECISION AK-1 — the PARENT product id a handoff needs',
            'woo_variation_id'  => 'DECISION AK-1 — Woo variation id',
            'media.featured_id' => 'Finding 011 — attachment reference',
        ],
        'product_category' => [
            'parent_slug' => 'P2-S3 source verification §7 — product_cat slugs are unique '
                . 'taxonomy-wide, so the slug is the public category key (FLAG-COMMCATPARENT-1)',
        ],
        // Deliberately EMPTY, and the emptiness is load-bearing: `pa_*` taxonomies are
        // registered 'hierarchical' => false, so a parent reference here can never carry
        // information, and no published operation selects an attribute term by id. The
        // product-category `parent_slug` does NOT extend here just because both read
        // `commerce.taxonomies` — that inheritance is precisely what this guard exists to stop.
        'attribute_term'  => [],
        'attribute'       => [],
    ];

    /**
     * FLAG-COMMCATPARENT-1 closed its debt exception rather than rewording it.
     *
     * A product category may publish its domain-safe parent reference and NOTHING else from the
     * identity vocabulary — no `source_id`, no integer `parent` — and no entry anywhere is debt.
     */
    public function test_no_debt_exception_remains(): void
    {
        self::assertSame(['parent_slug'], array_keys(self::ALLOWED['product_category']));

        foreach (self::ALLOWED as $semantic => $fields) {
            foreach ($fields as $field => $why) {
                self::assertStringNotContainsString('DEBT', $why, "{$semantic}.{$field}");
            }
        }
    }

    // -------------------------------------------------------------------------
    // Runtime resources
    // -------------------------------------------------------------------------

    /** @param array<string,mixed> $published */
    #[DataProvider('resourceShapes')]
    public function test_no_commerce_resource_serializes_internal_identity(
        string $resource,
        array $published,
    ): void {
        foreach (self::leaks($resource, self::flatten($published)) as $field) {
            self::fail(
                "{$resource} serializes internal identity `{$field}` (FLAG-COMMSOURCEID-1). "
                . 'Either it is a leak and must not be published, or it is an intentional public '
                . 'identifier — in which case add it to ALLOWED with the ruling that authorises it.'
            );
        }

        self::assertTrue(true, "{$resource} publishes no internal identity");
    }

    /** The intentional identifiers are still there — this guard must not be passed by deleting them. */
    public function test_the_intentional_public_identifiers_survive(): void
    {
        $product   = (new ProductResource())->toArray(self::productRow('variable'));
        $variation = (new VariationResource())->toArray(self::variationRow());
        $term      = (new TermResource())->toArray(self::termRow());

        self::assertSame(95, $product['woo_product_id']);
        self::assertSame(122, $product['media']['featured_id']);
        self::assertSame([123, 124], $product['media']['gallery_ids']);

        self::assertSame(95, $variation['woo_product_id']);
        self::assertSame(118, $variation['woo_variation_id']);
        self::assertSame(125, $variation['media']['featured_id']);

        // A PRODUCT CATEGORY relates to its parent by the parent's slug — resolvable against a
        // sibling's `slug` with no source id anywhere in the shape.
        self::assertSame('clothing', $term['parent_slug']);
    }

    /**
     * The same projection row, through the attribute-term contract: no hierarchy reference.
     *
     * Asserted from one shared fixture on purpose. `termRow()` carries `source_term_id: 31`,
     * `parent_id: 28` and a resolved `parent_slug`, so this proves the two shapes diverge at the
     * RESOURCE boundary rather than because attribute terms happen to arrive with empty columns —
     * which is exactly the claim, since `pa_*` taxonomies are registered `'hierarchical' => false`
     * and the data could never populate them anyway.
     */
    public function test_an_attribute_term_publishes_no_hierarchy_or_source_identity(): void
    {
        $published = (new AttributeTermResource())->toArray(self::termRow());

        self::assertSame(['slug', 'name', 'description', 'count'], array_keys($published));
        self::assertArrayNotHasKey('source_id', $published);
        self::assertArrayNotHasKey('parent', $published);
        self::assertArrayNotHasKey('parent_slug', $published);

        // What a consumer actually selects and renders with is all still here.
        self::assertSame('accessories', $published['slug']);
        self::assertSame('Accessories', $published['name']);
        self::assertSame(5, $published['count']);
    }

    /** HSP addressing is unchanged — slug, not identity. */
    public function test_slug_addressing_is_untouched(): void
    {
        self::assertSame('hoodie', (new ProductResource())->toArray(self::productRow('variable'))['slug']);
        self::assertSame('accessories', (new TermResource())->toArray(self::termRow())['slug']);
        self::assertSame('pa_color', (new AttributeResource())->toArray(self::attributeRow())['taxonomy']);
    }

    // -------------------------------------------------------------------------
    // Published schemas (ADR-055) — the other half
    // -------------------------------------------------------------------------

    #[DataProvider('schemaRoutes')]
    public function test_no_commerce_schema_declares_internal_identity(string $route, string $resource): void
    {
        foreach (self::leaks($resource, self::schemaFields($route)) as $field) {
            self::fail(
                "The published schema for {$route} declares internal identity `{$field}` "
                . '(FLAG-COMMSOURCEID-1). A schema-only ghost is worse than a runtime leak: it '
                . 'puts a field in every generated consumer type that no response ever carries.'
            );
        }

        self::assertTrue(true, "{$route} declares no internal identity");
    }

    /**
     * Runtime and schema must agree on the identity surface EXACTLY — the drift this flag was
     * raised over is the two halves disagreeing, in either direction.
     *
     * @param array<string,mixed> $published
     */
    #[DataProvider('parityCases')]
    public function test_runtime_and_schema_identity_surfaces_match(string $route, array $published): void
    {
        self::assertSame(
            self::identityFields(array_keys(self::flatten($published))),
            self::identityFields(array_keys(self::schemaFields($route))),
            "{$route}: the identity fields in the response and in its schema must be identical",
        );
    }

    /**
     * Every identity-shaped path in a field list — forbidden ones AND intentional ones — so the
     * parity check catches a removal from one half as loudly as an addition to the other.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    private static function identityFields(array $paths): array
    {
        $vocabulary = array_merge(self::FORBIDDEN, [
            'woo_product_id',
            'woo_variation_id',
            'featured_id',
            'gallery_ids',
        ]);

        $found = array_values(array_filter($paths, static function (string $path) use ($vocabulary): bool {
            $leaf = str_contains($path, '.') ? substr((string) strrchr($path, '.'), 1) : $path;

            return in_array($leaf, $vocabulary, true);
        }));

        sort($found);

        return $found;
    }

    // -------------------------------------------------------------------------
    // Mutation fixture — proof the guard actually fails
    // -------------------------------------------------------------------------

    /**
     * The guard is only worth its runtime if it FAILS when the leak returns. A green assertion
     * suite proves nothing about a rule nobody has tried to break, so this reintroduces both
     * historical leaks — the projection UUID and a generic `source_id` — into a product shape and
     * asserts the detector names them.
     */
    public function test_the_guard_fails_when_an_internal_identity_is_reintroduced(): void
    {
        $regressed = (new ProductResource())->toArray(self::productRow('simple'));

        // Exactly what shipped before FLAG-COMMSOURCEID-1 was closed.
        $regressed['id']        = '01a09ec2-5104-7357-9806-ba7d8d8f9c3c';
        $regressed['source_id'] = 95;

        self::assertSame(['id', 'source_id'], self::leaks('product', self::flatten($regressed)));

        // A nested reintroduction is caught too — media is where a future leak is most likely.
        $nested                              = (new VariationResource())->toArray(self::variationRow());
        $nested['media']['source_parent_id'] = 95;

        self::assertSame(['media.source_parent_id'], self::leaks('variation', self::flatten($nested)));

        // FLAG-COMMCATPARENT-1: the old source-term hierarchy pair is a leak on a category now,
        // and a category's `parent_slug` does not license itself onto an attribute term.
        $category              = (new TermResource())->toArray(self::termRow());
        $category['source_id'] = 31;
        $category['parent']    = 28;

        self::assertSame(['parent', 'source_id'], self::leaks('product_category', self::flatten($category)));

        $term                = (new AttributeTermResource())->toArray(self::termRow());
        $term['parent_slug'] = 'clothing';

        self::assertSame(['parent_slug'], self::leaks('attribute_term', self::flatten($term)));
    }

    /** And it stays green on the shapes that ship, so the two halves above are not vacuous. */
    public function test_the_guard_passes_on_the_shipped_shapes(): void
    {
        foreach (self::resourceShapes() as [$resource, $published]) {
            self::assertSame([], self::leaks($resource, self::flatten($published)), $resource);
        }
    }

    // -------------------------------------------------------------------------
    // Detector
    // -------------------------------------------------------------------------

    /**
     * The forbidden identity fields present in `$fields`, minus the ones this resource is
     * authorised to publish. Dotted paths (`media.featured_id`) are matched both fully and by
     * leaf name, so `media.id` is caught while allow-listing `media.featured_id` stays precise.
     *
     * @param array<string,mixed> $fields flattened `path => value`
     * @return list<string>
     */
    private static function leaks(string $resource, array $fields): array
    {
        $allowed = self::ALLOWED[$resource] ?? [];
        $found   = [];

        foreach (array_keys($fields) as $path) {
            if (isset($allowed[$path])) {
                continue;
            }

            $leaf = str_contains($path, '.') ? substr((string) strrchr($path, '.'), 1) : $path;

            if (in_array($leaf, self::FORBIDDEN, true)) {
                $found[] = $path;
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Flatten a published shape to dotted paths, descending into nested objects but NOT into
     * lists of resolved objects: `media.gallery` holds whole media resources owned by the Content
     * contract, and their internals are not Commerce's to police here.
     *
     * @param array<string,mixed> $shape
     * @return array<string,mixed>
     */
    private static function flatten(array $shape, string $prefix = ''): array
    {
        $flat = [];

        foreach ($shape as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $flat += self::flatten($value, $path);
                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }

    /**
     * The item-schema property names for one route, flattened the same way, read from the
     * descriptor registry that generates `openapi.json` (ADR-055).
     *
     * @return array<string,mixed>
     */
    private static function schemaFields(string $route): array
    {
        foreach ((new CommerceEndpointProvider())->endpoints() as $endpoint) {
            if ($endpoint->route !== $route) {
                continue;
            }

            $schema = $endpoint->responseSchema?->schema ?? [];
            $item   = $schema['properties']['data']['items'] ?? $schema;

            return self::flattenSchema($item['properties'] ?? []);
        }

        self::fail("No Commerce descriptor for route {$route}");
    }

    /**
     * @param array<string,mixed> $properties
     * @return array<string,mixed>
     */
    private static function flattenSchema(array $properties, string $prefix = ''): array
    {
        $flat = [];

        foreach ($properties as $name => $shape) {
            $path        = $prefix === '' ? (string) $name : "{$prefix}.{$name}";
            $flat[$path] = true;

            if (is_array($shape) && isset($shape['properties']) && is_array($shape['properties'])) {
                $flat += self::flattenSchema($shape['properties'], $path);
            }
        }

        return $flat;
    }

    // -------------------------------------------------------------------------
    // Providers and fixtures
    // -------------------------------------------------------------------------

    /** @return array<string, array{0:string, 1:array<string,mixed>}> */
    public static function resourceShapes(): array
    {
        return [
            'product (simple)'   => ['product', (new ProductResource())->toArray(self::productRow('simple'))],
            'product (variable)' => ['product', (new ProductResource())->toArray(self::productRow('variable'))],
            'variation'          => ['variation', (new VariationResource())->toArray(self::variationRow())],
            'product category'   => ['product_category', (new TermResource())->toArray(self::termRow())],
            // The SAME projection row through the OTHER resource — so if the two shapes ever
            // collapse back into one class, this case starts failing on `parent_slug`.
            'attribute term'     => ['attribute_term', (new AttributeTermResource())->toArray(self::termRow())],
            'attribute'          => ['attribute', (new AttributeResource())->toArray(self::attributeRow())],
        ];
    }

    /** Every Commerce route family, so a new one cannot be added past the guard. @return array<string, array{0:string,1:string}> */
    public static function schemaRoutes(): array
    {
        return [
            '/products'                              => ['/products', 'product'],
            '/products/{slug}'                       => ['/products/{slug}', 'product'],
            '/products/{slug}/variations'            => ['/products/{slug}/variations', 'variation'],
            '/product-categories'                    => ['/product-categories', 'product_category'],
            '/product-categories/{slug}'             => ['/product-categories/{slug}', 'product_category'],
            '/product-attributes'                    => ['/product-attributes', 'attribute'],
            '/product-attributes/{taxonomy}'         => ['/product-attributes/{taxonomy}', 'attribute'],
            '/product-attributes/{taxonomy}/terms'   => ['/product-attributes/{taxonomy}/terms', 'attribute_term'],
        ];
    }

    /** @return array<string, array{0:string, 1:array<string,mixed>}> */
    public static function parityCases(): array
    {
        return [
            'product'          => ['/products/{slug}', (new ProductResource())->toArray(self::productRow('variable'))],
            'variation'        => ['/products/{slug}/variations', (new VariationResource())->toArray(self::variationRow())],
            'product category' => ['/product-categories/{slug}', (new TermResource())->toArray(self::termRow())],
            'attribute term'   => [
                '/product-attributes/{taxonomy}/terms',
                (new AttributeTermResource())->toArray(self::termRow()),
            ],
            'attribute'        => ['/product-attributes/{taxonomy}', (new AttributeResource())->toArray(self::attributeRow())],
        ];
    }

    /**
     * Values mirror the live catalogue the flag was audited against, so a failure message names
     * numbers a reader can go and look at.
     *
     * @return array<string,mixed>
     */
    private static function productRow(string $type): array
    {
        return [
            'id'                            => '01a09ec2-4cda-7a2b-829c-8adf2be90bfd',
            'source_product_id'             => 95,
            'sku'                           => 'woo-hoodie',
            'slug'                          => 'hoodie',
            'name'                          => 'Hoodie',
            'product_type'                  => $type,
            'featured_media_id'             => 122,
            'gallery_media_ids'             => '[123,124]',
            'variation_selection_supported' => true,
            'checksum'                      => str_repeat('a', 64),
            'meta_jsonb'                    => '{}',
        ];
    }

    /** @return array<string,mixed> */
    private static function variationRow(): array
    {
        return [
            'id'                  => '01a09ec2-567a-7821-8b7e-d494411d97c6',
            'source_variation_id' => 118,
            'source_parent_id'    => 95,
            'sku'                 => 'woo-hoodie-blue-logo',
            'name'                => 'Hoodie - Blue',
            'status'              => 'publish',
            'attributes'          => '{"pa_color":"blue"}',
            'featured_media_id'   => 125,
            'menu_order'          => 0,
        ];
    }

    /** @return array<string,mixed> */
    private static function termRow(): array
    {
        return [
            'id'             => '01a09ec2-5227-7d72-af33-47c75ec8762e',
            'source_term_id' => 31,
            'taxonomy_type'  => 'product_cat',
            'slug'           => 'accessories',
            'name'           => 'Accessories',
            'description'    => '',
            'parent_id'      => 28,
            'term_count'     => 5,
            // Resolved by TermQueryProvider's self-join from parent_id 28 (live: "clothing").
            'parent_slug'    => 'clothing',
        ];
    }

    /** @return array<string,mixed> */
    private static function attributeRow(): array
    {
        return [
            'id'                  => '01a09ec2-52d8-7ff6-aa1d-058c695bd6ec',
            'source_attribute_id' => 1,
            'slug'                => 'pa_color',
            'name'                => 'Color',
            'type'                => 'select',
            'order_by'            => 'menu_order',
            'has_archives'        => false,
        ];
    }
}
