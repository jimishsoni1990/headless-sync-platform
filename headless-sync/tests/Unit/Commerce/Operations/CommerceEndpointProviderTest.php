<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Commerce\Operations;

use HSP\Core\Contracts\Operations\EndpointAuth;
use HSP\Core\Contracts\Operations\EndpointDescriptor;
use HSP\Core\Contracts\Operations\EndpointProviderInterface;
use HSP\Core\Operations\OpenApi\OpenApiGenerator;
use HSP\Modules\Commerce\Operations\CommerceEndpointProvider;
use HSP\Modules\Commerce\Resources\ProductResource;
use HSP\Modules\Commerce\Resources\VariationResource;
use HSP\Tests\Unit\Operations\OpenApi\OpenApiMetaSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Commerce endpoint metadata (ADR-055 (c)) — the published nested shapes.
 *
 * The Content module has had descriptor coverage since OAPI-S1; Commerce shipped its descriptors
 * in Phase 2 with none, which is part of why its nested fields (`prices`, `media`, `stock`,
 * `attributes`) reached `openapi.json` as bare `type: object`. These tests assert the INTENDED
 * nested properties — never merely that an object exists — and that the published Resource and
 * the descriptor agree, against explicit row fixtures rather than any reflection over the
 * Resource (ADR-055 (a): the registry describes what modules deliberately registered).
 *
 * The generated Commerce document is also put through the same OpenAPI 3.1 meta-schema gate the
 * drift guard uses, because the ADR-055 (f) guard boots Content + core only and would not catch a
 * malformed Commerce fragment.
 */
final class CommerceEndpointProviderTest extends TestCase
{
    private const VALIDATOR_SCRIPT = __DIR__ . '/../../../../tools/openapi-validator/validate-openapi.mjs';

    public function test_implements_core_contract_and_key(): void
    {
        $provider = new CommerceEndpointProvider();

        self::assertInstanceOf(EndpointProviderInterface::class, $provider);
        self::assertSame('commerce.endpoints', $provider->key());
        self::assertCount(8, $provider->endpoints());
    }

    // -------------------------------------------------------------------------
    // Nested published shapes
    // -------------------------------------------------------------------------

    /** Money is `string|null` on BOTH aggregates — never a JSON number (Requirement C). */
    public function test_prices_publish_three_nullable_decimal_strings_on_product_and_variation(): void
    {
        foreach (['/products/{slug}', '/products/{slug}/variations'] as $route) {
            $prices = $this->itemProperties($route)['prices'];

            self::assertSame('object', $prices['type'], "{$route} prices type");
            self::assertSame(
                ['price', 'regular_price', 'sale_price'],
                array_keys($prices['properties']),
                "{$route} prices properties",
            );
            foreach ($prices['properties'] as $name => $member) {
                self::assertSame(
                    ['string', 'null'],
                    $member['type'],
                    "{$route} prices.{$name} must be a nullable STRING, never a number (Requirement C).",
                );
            }
            self::assertSame(['price', 'regular_price', 'sale_price'], $prices['required']);
        }
    }

    /**
     * Stock is nullable in EVERY member and null means UNKNOWN, not out of stock (AG-8 / AG-14).
     * Publishing that nullability is the whole point — a non-null schema would tell a consumer
     * the distinction does not exist.
     */
    public function test_stock_publishes_every_member_as_nullable_unknown(): void
    {
        $stock = $this->itemProperties('/products/{slug}')['stock'];

        self::assertSame(
            ['status', 'managed', 'quantity', 'backorders'],
            array_keys($stock['properties']),
        );
        self::assertSame(['string', 'null'], $stock['properties']['status']['type']);
        self::assertSame(['boolean', 'null'], $stock['properties']['managed']['type']);
        self::assertSame(['integer', 'null'], $stock['properties']['quantity']['type']);
        self::assertSame(['string', 'null'], $stock['properties']['backorders']['type']);
        self::assertSame(array_keys($stock['properties']), $stock['required']);
        self::assertStringContainsString('NOT the same as out of stock', $stock['description']);
    }

    /** Media stays an ID REFERENCE (AG-10) — but the reference shape itself is now described. */
    public function test_media_publishes_its_id_reference_shape(): void
    {
        $productMedia = $this->itemProperties('/products/{slug}')['media'];

        self::assertSame(['featured_id', 'gallery_ids'], array_keys($productMedia['properties']));
        self::assertSame('integer', $productMedia['properties']['featured_id']['type']);
        self::assertSame('array', $productMedia['properties']['gallery_ids']['type']);
        self::assertSame('integer', $productMedia['properties']['gallery_ids']['items']['type']);

        // A variation carries one image and no gallery — a different shape, described separately.
        $variationMedia = $this->itemProperties('/products/{slug}/variations')['media'];
        self::assertSame(['featured_id'], array_keys($variationMedia['properties']));
    }

    /**
     * Variation attributes are classification B — an OPEN map, because attribute taxonomies are
     * store-defined. The KEY set stays open; the VALUE type is platform-owned and described.
     */
    public function test_variation_attributes_stay_an_open_map_with_a_string_value_type(): void
    {
        $attributes = $this->itemProperties('/products/{slug}/variations')['attributes'];

        self::assertSame('object', $attributes['type']);
        self::assertArrayNotHasKey('properties', $attributes, 'Attribute taxonomies are dynamic.');
        self::assertSame(['type' => 'string'], $attributes['additionalProperties']);
        self::assertStringContainsString('EMPTY value', $attributes['description']);
    }

    /** `meta` is deliberately opaque and must not be frozen into a closed property list. */
    public function test_product_meta_stays_a_deliberately_open_map(): void
    {
        $meta = $this->itemProperties('/products/{slug}')['meta'];

        self::assertSame('object', $meta['type']);
        self::assertTrue($meta['additionalProperties']);
        self::assertArrayNotHasKey('properties', $meta);
    }

    // -------------------------------------------------------------------------
    // Runtime ⇄ descriptor agreement (explicit fixtures; no reflection)
    // -------------------------------------------------------------------------

    public function test_product_resource_nested_output_matches_the_descriptor(): void
    {
        $props     = $this->itemProperties('/products/{slug}');
        $published = (new ProductResource())->toArray([
            'id'                => 'b2f1c0de-0000-7000-8000-000000000001',
            'source_product_id' => 42,
            'slug'              => 'hat',
            'name'              => 'Hat',
            'price'             => '19.99',
            'regular_price'     => '24.99',
            'sale_price'        => null,
            'featured_media_id' => 7,
            'gallery_media_ids' => '[8,9]',
            'stock_status'      => 'instock',
            'manages_stock'     => 't',
            'stock_quantity'    => 5,
            'backorders'        => 'no',
        ]);

        foreach (['prices', 'media', 'stock'] as $field) {
            self::assertSame(
                array_keys($props[$field]['properties']),
                array_keys($published[$field]),
                "Product {$field} runtime keys must match the published descriptor exactly.",
            );
        }
        // The decimal really is published as a string, as the schema promises.
        self::assertSame('19.99', $published['prices']['price']);
        self::assertSame([8, 9], $published['media']['gallery_ids']);
    }

    /** The unknown-inventory arm: every stock member null, exactly as the schema allows. */
    public function test_product_resource_publishes_unknown_stock_as_nulls(): void
    {
        $published = (new ProductResource())->toArray([
            'id'                => 'b2f1c0de-0000-7000-8000-000000000002',
            'source_product_id' => 43,
            'slug'              => 'scarf',
        ]);

        self::assertSame(
            ['status' => null, 'managed' => null, 'quantity' => null, 'backorders' => null],
            $published['stock'],
        );

        $stock = $this->itemProperties('/products/{slug}')['stock'];
        foreach ($stock['properties'] as $name => $member) {
            self::assertContains('null', $member['type'], "stock.{$name} must admit null.");
        }
    }

    public function test_variation_resource_nested_output_matches_the_descriptor(): void
    {
        $props     = $this->itemProperties('/products/{slug}/variations');
        $published = (new VariationResource())->toArray([
            'id'                  => 'b2f1c0de-0000-7000-8000-000000000003',
            'source_variation_id' => 99,
            'source_parent_id'    => 42,
            'price'               => '19.99',
            'regular_price'       => '19.99',
            'sale_price'          => null,
            'attributes'          => '{"pa_colour":"red","pa_size":""}',
            'featured_media_id'   => 7,
        ]);

        foreach (['prices', 'media'] as $field) {
            self::assertSame(
                array_keys($props[$field]['properties']),
                array_keys($published[$field]),
                "Variation {$field} runtime keys must match the published descriptor exactly.",
            );
        }
        // The open map really does carry string values, including the "any value" empty string.
        self::assertSame(['pa_colour' => 'red', 'pa_size' => ''], $published['attributes']);
    }

    // -------------------------------------------------------------------------
    // The generated Commerce document is still valid OpenAPI 3.1
    // -------------------------------------------------------------------------

    public function test_generated_commerce_document_validates_against_openapi_3_1(): void
    {
        $document  = (new OpenApiGenerator())->generate((new CommerceEndpointProvider())->endpoints());
        $validator = new OpenApiMetaSchemaValidator(self::VALIDATOR_SCRIPT);

        self::assertSame([], $validator->structuralViolations($document));

        $error  = null;
        $status = $validator->gateStatus($document, $error);

        if ($status === OpenApiMetaSchemaValidator::GATE_SKIPPED) {
            if (getenv('HSP_REQUIRE_NODE_GATE') === '1') {
                self::fail('HSP_REQUIRE_NODE_GATE=1 requires the node ajv gate: ' . (string) $error);
            }

            self::markTestSkipped('OpenAPI 3.1 meta-schema gate SKIPPED — node unavailable ('
                . (string) $error . '). Set HSP_REQUIRE_NODE_GATE=1 (CI) to make this a failure.');
        }

        self::assertSame(OpenApiMetaSchemaValidator::GATE_VALID, $status, (string) $error);
    }

    public function test_all_commerce_endpoints_are_public_and_v1_commerce_owned(): void
    {
        foreach ((new CommerceEndpointProvider())->endpoints() as $endpoint) {
            self::assertSame(EndpointAuth::Public, $endpoint->auth, $endpoint->route);
            self::assertSame('v1', $endpoint->version, $endpoint->route);
            self::assertSame('commerce', $endpoint->moduleOwner, $endpoint->route);
            self::assertSame('hsp/v1', $endpoint->namespace, $endpoint->route);
        }
    }

    /**
     * The published ITEM properties for a route, unwrapping the cursor envelope for listings.
     *
     * @return array<string,mixed>
     */
    private function itemProperties(string $route): array
    {
        $descriptor = $this->byRoute()[$route];
        self::assertNotNull($descriptor->responseSchema, "{$route} must publish a response schema.");

        $schema = $descriptor->responseSchema->schema;
        /** @var array<string,mixed> $properties */
        $properties = $descriptor->paginated
            ? $schema['properties']['data']['items']['properties']
            : $schema['properties'];

        return $properties;
    }

    /** @return array<string,EndpointDescriptor> route → descriptor */
    private function byRoute(): array
    {
        $out = [];
        foreach ((new CommerceEndpointProvider())->endpoints() as $endpoint) {
            $out[$endpoint->route] = $endpoint;
        }

        return $out;
    }
}
