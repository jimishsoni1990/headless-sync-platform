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
}
