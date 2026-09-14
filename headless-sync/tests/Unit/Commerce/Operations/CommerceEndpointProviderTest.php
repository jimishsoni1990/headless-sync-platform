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
    }

    // -------------------------------------------------------------------------
    // Finding 010 — the variation-selection contract, read off the DOCUMENT
    //
    // An open map whose description says nothing is the defect these guard against: the PHP
    // type `object` is true and useless, and a generated client built from it has to be told
    // the semantics by a human who read the module. Each test below asserts a fact a consumer
    // must be able to learn from the contract ALONE.
    // -------------------------------------------------------------------------

    /**
     * KEY semantics: a taxonomy name, and specifically the same one `/product-attributes`
     * publishes — not a label, not WooCommerce's `attribute_`-prefixed meta key, not an id.
     */
    public function test_variation_attribute_keys_are_documented_as_attribute_taxonomy_names(): void
    {
        $description = $this->itemProperties('/products/{slug}/variations')['attributes']['description'];

        self::assertStringContainsString('KEY', $description);
        self::assertStringContainsString('taxonomy name', $description);
        self::assertStringContainsString('pa_', $description);
    }

    /** VALUE semantics: a term SLUG, scoped by its taxonomy — never a name and never an id. */
    public function test_variation_attribute_values_are_documented_as_term_slugs(): void
    {
        $description = $this->itemProperties('/products/{slug}/variations')['attributes']['description'];

        self::assertStringContainsString('TERM SLUG', $description);
        self::assertStringContainsString('never an id', $description);
        self::assertStringContainsString(
            'key is part of the value',
            $description,
            'A slug is unique only within its taxonomy; the contract must say so.',
        );
    }

    /**
     * WILDCARD semantics. The runtime really does emit `""` for "any value of this attribute",
     * so a contract that described the value merely as `string` would be accurate about the
     * type and silent about the only part a matcher depends on.
     */
    public function test_the_wildcard_value_is_documented_as_an_explicit_state(): void
    {
        $description = $this->itemProperties('/products/{slug}/variations')['attributes']['description'];

        self::assertStringContainsString('EMPTY STRING', $description);
        self::assertStringContainsString('WILDCARD', $description);
        self::assertStringContainsString('ANY value', $description);
        self::assertStringContainsString(
            'NOT the same as the attribute being absent',
            $description,
            'A wildcard and a missing dimension are different facts.',
        );
    }

    /**
     * The scope limit is published AND made detectable. Saying "only global attributes are
     * projected" is necessary but not sufficient: a consumer still could not tell whether THIS
     * product was affected. The description must point at the capability flag that answers it.
     */
    public function test_the_global_attribute_scope_limit_points_at_the_capability_flag(): void
    {
        $attributes = $this->itemProperties('/products/{slug}/variations')['attributes']['description'];

        self::assertStringContainsString('variation_selection_supported', $attributes);
        self::assertStringContainsString('must NOT be used to identify a variation', $attributes);

        $endpoint = $this->byRoute()['/products/{slug}/variations']->description;

        self::assertStringContainsString('SCOPE LIMIT', $endpoint);
        self::assertStringContainsString('local/custom', $endpoint);
    }

    // -------------------------------------------------------------------------
    // DECISION AL — the capability contract
    // -------------------------------------------------------------------------

    /**
     * The flag itself. `boolean`, never nullable: delivery resolves an unknown capability to
     * false, so a consumer never receives a third value to reason about.
     */
    public function test_the_variation_selection_capability_is_a_documented_boolean(): void
    {
        foreach (['/products/{slug}', '/products'] as $route) {
            $capability = $this->itemProperties($route)['variation_selection_supported'];

            self::assertSame('boolean', $capability['type'], $route);
            self::assertStringContainsString('TRUE:', $capability['description']);
            self::assertStringContainsString('FALSE:', $capability['description']);
            self::assertStringContainsString('MUST NOT resolve a variation', $capability['description']);
        }
    }

    /**
     * It says what it is NOT. A flag called "supported" invites being read as "this product
     * works", which would strip a perfectly good catalogue product of everything else.
     */
    public function test_the_capability_is_documented_as_selection_only_not_product_support(): void
    {
        $description = $this->itemProperties('/products/{slug}')['variation_selection_supported']['description'];

        self::assertStringContainsString('variation selection ONLY', $description);
        self::assertStringContainsString('remains fully supported for catalog', $description);
    }

    /** Optional, because the runtime omits it on a simple product — so it must not be required. */
    public function test_the_capability_is_not_declared_required(): void
    {
        foreach ([$this->byRoute()['/products/{slug}'], $this->byRoute()['/products']] as $descriptor) {
            self::assertNotNull($descriptor->responseSchema);
            $schema = $descriptor->responseSchema->schema;
            $item   = $descriptor->paginated ? $schema['properties']['data']['items'] : $schema;

            self::assertNotContains(
                'variation_selection_supported',
                $item['required'] ?? [],
                'Absent on simple products, so it cannot be required.',
            );
        }

        // And the runtime really does omit it, which is what makes that the correct declaration.
        $simple = (new ProductResource())->toArray([
            'id'                => 'b2f1c0de-0000-7000-8000-000000000045',
            'source_product_id' => 45,
            'slug'              => 'beanie',
            'product_type'      => 'simple',
        ]);

        self::assertArrayNotHasKey('variation_selection_supported', $simple);
    }

    /** The consumer rule is TWO stages, and the first one is the gate. */
    public function test_the_variations_endpoint_publishes_the_capability_gate_as_stage_one(): void
    {
        $description = $this->byRoute()['/products/{slug}/variations']->description;

        foreach ([
            'STAGE 1',
            'variation_selection_supported',
            'If it is FALSE, STOP',
            'do not resolve a variation from HSP data',
            'STAGE 2',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $description, "Gate fragment: {$fragment}");
        }
    }

    /**
     * `status` and `menu_order` are load-bearing for selection — the candidate filter and the
     * tiebreak order — and both used to be bare types with no description at all.
     */
    public function test_the_fields_selection_depends_on_explain_their_role(): void
    {
        $properties = $this->itemProperties('/products/{slug}/variations');

        self::assertStringContainsString('publish', $properties['status']['description']);
        self::assertStringContainsString(
            'ONLY variations with status "publish"',
            $properties['status']['description'],
        );

        self::assertStringContainsString('tiebreak', $properties['menu_order']['description']);
        self::assertStringContainsString(
            'menu_order ascending, then woo_variation_id ascending',
            $properties['menu_order']['description'],
        );
    }

    /** The matching rule itself, on the endpoint that serves the candidates. */
    public function test_the_variations_endpoint_publishes_the_complete_matching_rule(): void
    {
        $description = $this->byRoute()['/products/{slug}/variations']->description;

        foreach ([
            'status` is "publish"',                 // (1) candidate scope
            'menu_order` ascending',                // (2) ordering
            'empty value is a wildcard',            // (3) matching
            'FIRST matching candidate',             // (4) resolution
            'NO MATCH',                             // (5) no fallback
            'do not fall back',
            'AMBIGUITY IS POSSIBLE',
            'woo_variation_id',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $description, "Rule fragment: {$fragment}");
        }
    }

    /**
     * The product half: product-specific options with labels, described as options rather than
     * as dimensions — the distinction a display-only attribute would otherwise break.
     */
    public function test_product_attributes_publish_labelled_product_specific_options(): void
    {
        $attributes = $this->itemProperties('/products/{slug}')['attributes'];

        self::assertSame('object', $attributes['type']);
        self::assertArrayNotHasKey('properties', $attributes, 'Attribute taxonomies are dynamic.');

        $terms = $attributes['additionalProperties'];
        self::assertSame('array', $terms['type']);
        self::assertSame(['slug', 'name'], array_keys($terms['items']['properties']));
        self::assertSame(['slug', 'name'], $terms['items']['required']);
        self::assertSame('string', $terms['items']['properties']['slug']['type']);
        self::assertSame('string', $terms['items']['properties']['name']['type']);

        self::assertStringContainsString('product-specific', $attributes['description']);
        self::assertStringContainsString(
            'does NOT say which attributes the product varies BY',
            $attributes['description'],
        );
    }

    /** No identifier of any kind is published in a product option — slug and label only. */
    public function test_product_attribute_options_expose_no_identifier(): void
    {
        $terms = $this->itemProperties('/products/{slug}')['attributes']['additionalProperties'];

        foreach (['id', 'source_id', 'term_id', 'source_term_id'] as $forbidden) {
            self::assertArrayNotHasKey(
                $forbidden,
                $terms['items']['properties'],
                "Selection must never require an internal identifier ({$forbidden}).",
            );
        }
    }

    /**
     * The generated-client proof: everything above, re-read from the GENERATED document rather
     * than from the descriptor objects, because that document is all a client generator sees.
     */
    public function test_a_generated_client_can_read_the_selection_semantics_from_the_document(): void
    {
        $document = (new OpenApiGenerator())->generate((new CommerceEndpointProvider())->endpoints());

        $variations = $document['paths']['/hsp/v1/products/{slug}/variations']['get'];
        $item       = $variations['responses']['200']['content']['application/json']['schema']
            ['properties']['data']['items']['properties'];

        // Key, value and wildcard semantics survive generation.
        self::assertStringContainsString('TERM SLUG', $item['attributes']['description']);
        self::assertStringContainsString('WILDCARD', $item['attributes']['description']);
        self::assertSame(['type' => 'string'], $item['attributes']['additionalProperties']);

        // Both stages of the rule reached the operation description.
        self::assertStringContainsString('STAGE 1', $variations['description']);
        self::assertStringContainsString('FIRST matching candidate', $variations['description']);

        // The gate is readable off the product schema, as a plain boolean a client can branch on.
        self::assertSame(
            'boolean',
            $document['paths']['/hsp/v1/products/{slug}']['get']['responses']['200']['content']
                ['application/json']['schema']['properties']['variation_selection_supported']['type'],
        );

        // The product half reached the document with its item shape intact.
        $product = $document['paths']['/hsp/v1/products/{slug}']['get']['responses']['200']
            ['content']['application/json']['schema']['properties'];
        self::assertSame(
            ['slug', 'name'],
            array_keys($product['attributes']['additionalProperties']['items']['properties']),
        );
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
