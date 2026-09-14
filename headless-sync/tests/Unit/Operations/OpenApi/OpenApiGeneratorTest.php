<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\OpenApi;

use HSP\Core\Contracts\Operations\EndpointAuth;
use HSP\Core\Contracts\Operations\EndpointDescriptor;
use HSP\Core\Contracts\Operations\EndpointParameter;
use HSP\Core\Contracts\Operations\SchemaObject;
use HSP\Core\Operations\OpenApi\OpenApiGenerator;
use PHPUnit\Framework\TestCase;

/**
 * OpenApiGenerator unit tests (ADR-055 (a)/(c)/(d)/(e)).
 *
 * The generator is a pure array transformer over EndpointDescriptor[] — no WordPress, no PG, no
 * persistence. These tests assert the OpenAPI 3.1 shape, public-only scoping, the cursor-pagination
 * envelope, deprecation, parameters, and the versioned path key.
 */
final class OpenApiGeneratorTest extends TestCase
{
    public function test_emits_openapi_3_1_document_skeleton(): void
    {
        $doc = (new OpenApiGenerator())->generate([]);

        self::assertSame('3.1.0', $doc['openapi']);
        self::assertArrayHasKey('info', $doc);
        self::assertArrayHasKey('title', $doc['info']);
        self::assertArrayHasKey('version', $doc['info']);
        self::assertArrayHasKey('paths', $doc);
        self::assertSame([], $doc['paths']);
    }

    public function test_path_key_is_namespace_prefixed_and_versioned(): void
    {
        $doc = (new OpenApiGenerator())->generate([$this->publicGet('/posts')]);

        self::assertArrayHasKey('/hsp/v1/posts', $doc['paths']);
        self::assertArrayHasKey('get', $doc['paths']['/hsp/v1/posts']);
    }

    public function test_excludes_non_public_endpoints_from_document(): void
    {
        $descriptors = [
            $this->publicGet('/posts'),
            new EndpointDescriptor(
                method: 'GET',
                route: '/secret',
                namespace: 'hsp/v1',
                displayGroup: 'Admin',
                description: 'Authenticated-only route.',
                auth: EndpointAuth::Authenticated,
            ),
        ];

        $doc = (new OpenApiGenerator())->generate($descriptors);

        self::assertArrayHasKey('/hsp/v1/posts', $doc['paths']);
        self::assertArrayNotHasKey('/hsp/v1/secret', $doc['paths']);
    }

    public function test_cursor_paginated_list_describes_the_data_next_cursor_envelope(): void
    {
        $item = SchemaObject::object(['slug' => 'string', 'title' => 'string']);

        $descriptor = new EndpointDescriptor(
            method: 'GET',
            route: '/posts',
            namespace: 'hsp/v1',
            displayGroup: 'Content',
            description: 'List posts.',
            parameters: [EndpointParameter::query('cursor', 'string', 'Cursor.')],
            responseSchema: $item->asCursorPage(),
            paginated: true,
        );

        $doc    = (new OpenApiGenerator())->generate([$descriptor]);
        $schema = $doc['paths']['/hsp/v1/posts']['get']['responses']['200']['content']['application/json']['schema'];

        self::assertSame('object', $schema['type']);
        self::assertArrayHasKey('data', $schema['properties']);
        self::assertSame('array', $schema['properties']['data']['type']);
        self::assertArrayHasKey('next_cursor', $schema['properties']);
        self::assertContains('next_cursor', $schema['required']);
        // The item shape is nested under data.items (the envelope wraps the item schema).
        self::assertArrayHasKey('slug', $schema['properties']['data']['items']['properties']);
    }

    public function test_parameters_are_emitted_as_openapi_parameter_objects(): void
    {
        $descriptor = new EndpointDescriptor(
            method: 'GET',
            route: '/posts/{slug}',
            namespace: 'hsp/v1',
            displayGroup: 'Content',
            description: 'Single post.',
            parameters: [EndpointParameter::path('slug', 'string', 'Post slug.')],
        );

        $doc    = (new OpenApiGenerator())->generate([$descriptor]);
        $params = $doc['paths']['/hsp/v1/posts/{slug}']['get']['parameters'];

        self::assertCount(1, $params);
        self::assertSame('slug', $params[0]['name']);
        self::assertSame('path', $params[0]['in']);
        self::assertTrue($params[0]['required']);
        self::assertSame('string', $params[0]['schema']['type']);
    }

    public function test_deprecated_flag_surfaces_openapi_deprecated(): void
    {
        $live = $this->publicGet('/posts');
        $dead = new EndpointDescriptor(
            method: 'GET',
            route: '/old',
            namespace: 'hsp/v1',
            displayGroup: 'Content',
            description: 'Deprecated route.',
            deprecated: true,
        );

        $doc = (new OpenApiGenerator())->generate([$live, $dead]);

        self::assertArrayNotHasKey('deprecated', $doc['paths']['/hsp/v1/posts']['get']);
        self::assertTrue($doc['paths']['/hsp/v1/old']['get']['deprecated']);
    }

    public function test_module_owner_and_version_surface_as_extensions(): void
    {
        $descriptor = new EndpointDescriptor(
            method: 'GET',
            route: '/posts',
            namespace: 'hsp/v1',
            displayGroup: 'Content',
            description: 'List posts.',
            version: 'v1',
            moduleOwner: 'content',
        );

        $op = (new OpenApiGenerator())->generate([$descriptor])['paths']['/hsp/v1/posts']['get'];

        self::assertSame('content', $op['x-hsp-module']);
        self::assertSame('v1', $op['x-hsp-version']);
    }

    // -------------------------------------------------------------------------
    // Nested schema fidelity (ADR-055 (c)) — the generator must carry a nested fragment
    // through VERBATIM. A field that arrives as a bare `type: object` on the far side leaves a
    // generated consumer type unable to read data the contract deliberately publishes.
    // -------------------------------------------------------------------------

    public function test_nested_object_properties_and_required_survive_generation(): void
    {
        $item = SchemaObject::object([
            'slug'           => 'string',
            'featured_media' => [
                'type'       => ['object', 'null'],
                'properties' => [
                    'url'   => ['type' => 'string'],
                    'width' => ['type' => 'integer'],
                ],
                'required'   => ['url', 'width'],
            ],
        ]);

        $schema = $this->responseSchemaFor($item);
        $nested = $schema['properties']['featured_media'];

        // NULLABLE nested object: OpenAPI 3.1 / JSON Schema 2020-12 type union, not `nullable: true`.
        self::assertSame(['object', 'null'], $nested['type']);
        self::assertSame('string', $nested['properties']['url']['type']);
        self::assertSame('integer', $nested['properties']['width']['type']);
        self::assertSame(['url', 'width'], $nested['required']);
        // The flat-scalar shorthand still works alongside a fragment.
        self::assertSame('string', $schema['properties']['slug']['type']);
    }

    public function test_array_of_nested_objects_survives_generation(): void
    {
        $item = SchemaObject::object([
            'tags' => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'slug' => ['type' => 'string'],
                        'name' => ['type' => 'string'],
                    ],
                    'required'   => ['slug', 'name'],
                ],
            ],
        ]);

        $tags = $this->responseSchemaFor($item)['properties']['tags'];

        self::assertSame('array', $tags['type']);
        self::assertSame('object', $tags['items']['type']);
        self::assertArrayHasKey('slug', $tags['items']['properties']);
        self::assertSame(['slug', 'name'], $tags['items']['required']);
    }

    public function test_open_map_additional_properties_survives_generation(): void
    {
        $item = SchemaObject::object([
            // Open key set, KNOWN value shape — the sizes/attributes case.
            'sizes' => [
                'type'                 => 'object',
                'additionalProperties' => [
                    'type'       => 'object',
                    'properties' => ['url' => ['type' => 'string']],
                    'required'   => ['url'],
                ],
            ],
            // Deliberately opaque map — stays opaque.
            'meta'  => ['type' => 'object', 'additionalProperties' => true],
        ]);

        $props = $this->responseSchemaFor($item)['properties'];

        self::assertSame('object', $props['sizes']['additionalProperties']['type']);
        self::assertSame('string', $props['sizes']['additionalProperties']['properties']['url']['type']);
        self::assertTrue($props['meta']['additionalProperties']);
        self::assertArrayNotHasKey('properties', $props['meta'], 'An opaque map must stay opaque.');
    }

    public function test_nested_schemas_survive_the_cursor_envelope_wrapping(): void
    {
        $item = SchemaObject::object([
            'prices' => [
                'type'       => 'object',
                'properties' => ['price' => ['type' => ['string', 'null']]],
                'required'   => ['price'],
            ],
        ]);

        $descriptor = new EndpointDescriptor(
            method: 'GET',
            route: '/products',
            namespace: 'hsp/v1',
            displayGroup: 'Commerce',
            description: 'List products.',
            responseSchema: $item->asCursorPage(),
            paginated: true,
        );

        $schema = (new OpenApiGenerator())->generate([$descriptor])
            ['paths']['/hsp/v1/products']['get']['responses']['200']['content']['application/json']['schema'];
        $prices = $schema['properties']['data']['items']['properties']['prices'];

        self::assertSame(['string', 'null'], $prices['properties']['price']['type']);
        self::assertSame(['price'], $prices['required']);
    }

    /**
     * Generate a single-resource GET and return its 200 response schema.
     *
     * @return array<string,mixed>
     */
    private function responseSchemaFor(SchemaObject $item): array
    {
        $descriptor = new EndpointDescriptor(
            method: 'GET',
            route: '/posts/{slug}',
            namespace: 'hsp/v1',
            displayGroup: 'Content',
            description: 'Fetch a post.',
            responseSchema: $item,
        );

        /** @var array<string,mixed> $schema */
        $schema = (new OpenApiGenerator())->generate([$descriptor])
            ['paths']['/hsp/v1/posts/{slug}']['get']['responses']['200']['content']['application/json']['schema'];

        return $schema;
    }

    private function publicGet(string $route): EndpointDescriptor
    {
        return new EndpointDescriptor(
            method: 'GET',
            route: $route,
            namespace: 'hsp/v1',
            displayGroup: 'Content',
            description: 'A public GET endpoint.',
        );
    }
}
