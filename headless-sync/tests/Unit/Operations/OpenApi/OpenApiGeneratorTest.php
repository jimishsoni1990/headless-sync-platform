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
