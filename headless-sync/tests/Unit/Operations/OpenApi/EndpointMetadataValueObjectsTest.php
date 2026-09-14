<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\OpenApi;

use HSP\Core\Contracts\Operations\EndpointAuth;
use HSP\Core\Contracts\Operations\EndpointDescriptor;
use HSP\Core\Contracts\Operations\EndpointParameter;
use HSP\Core\Contracts\Operations\SchemaObject;
use PHPUnit\Framework\TestCase;

/**
 * The ADR-055 (c) additive enrichment value objects. Proves the enrichment is ADDITIVE — the
 * original five-argument EndpointDescriptor construction still works with sensible defaults.
 */
final class EndpointMetadataValueObjectsTest extends TestCase
{
    public function test_endpoint_descriptor_five_arg_construction_still_works_additively(): void
    {
        // The pre-ADR-055 call site (e.g. FakeEndpointProvider) — must remain valid.
        $ep = new EndpointDescriptor('GET', '/posts', 'hsp/v1', 'Content', 'List posts');

        self::assertSame('GET', $ep->method);
        self::assertSame([], $ep->parameters);
        self::assertNull($ep->responseSchema);
        self::assertNull($ep->requestSchema);
        self::assertSame(EndpointAuth::Public, $ep->auth);
        self::assertFalse($ep->paginated);
        self::assertFalse($ep->deprecated);
        self::assertSame('v1', $ep->version);
        self::assertSame('', $ep->moduleOwner);
    }

    public function test_endpoint_auth_public_flag(): void
    {
        self::assertTrue(EndpointAuth::Public->isPublic());
        self::assertFalse(EndpointAuth::Authenticated->isPublic());
    }

    public function test_parameter_factories(): void
    {
        $path = EndpointParameter::path('slug', 'string', 'Slug.');
        self::assertSame(EndpointParameter::IN_PATH, $path->in);
        self::assertTrue($path->required);

        $query = EndpointParameter::query('status', 'string', 'Status.');
        self::assertSame(EndpointParameter::IN_QUERY, $query->in);
        self::assertFalse($query->required);
    }

    public function test_schema_object_builds_object_from_type_map(): void
    {
        $schema = SchemaObject::object(['slug' => 'string', 'count' => 'integer'])->schema;

        self::assertSame('object', $schema['type']);
        self::assertSame('string', $schema['properties']['slug']['type']);
        self::assertSame('integer', $schema['properties']['count']['type']);
    }

    public function test_schema_object_cursor_envelope_wraps_the_item(): void
    {
        $envelope = SchemaObject::object(['slug' => 'string'])->asCursorPage()->schema;

        self::assertSame('object', $envelope['type']);
        self::assertSame('array', $envelope['properties']['data']['type']);
        self::assertSame('object', $envelope['properties']['data']['items']['type']);
        self::assertSame(['string', 'null'], $envelope['properties']['next_cursor']['type']);
        self::assertSame(['data', 'next_cursor'], $envelope['required']);
    }

    /**
     * A property value that is an ARRAY is a JSON Schema fragment and must be embedded verbatim
     * — that is what lets a module publish a nested object, a nullable object, an array of
     * objects or an open map instead of a bare `type: object` (ADR-055 (c)).
     */
    public function test_schema_object_embeds_a_nested_fragment_verbatim(): void
    {
        $fragment = [
            'type'       => ['object', 'null'],
            'properties' => ['url' => ['type' => 'string']],
            'required'   => ['url'],
        ];

        $schema = SchemaObject::object([
            'slug'           => 'string',
            'featured_media' => $fragment,
        ])->schema;

        self::assertSame($fragment, $schema['properties']['featured_media']);
        // The flat shorthand is untouched — existing descriptors keep working unchanged.
        self::assertSame(['type' => 'string'], $schema['properties']['slug']);
    }

    public function test_cursor_envelope_preserves_a_nested_item_fragment(): void
    {
        $envelope = SchemaObject::object([
            'tags' => [
                'type'  => 'array',
                'items' => ['type' => 'object', 'properties' => ['slug' => ['type' => 'string']]],
            ],
        ])->asCursorPage()->schema;

        $tags = $envelope['properties']['data']['items']['properties']['tags'];

        self::assertSame('array', $tags['type']);
        self::assertArrayHasKey('slug', $tags['items']['properties']);
    }
}
