<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Operations;

use HSP\Core\Contracts\Operations\EndpointAuth;
use HSP\Core\Contracts\Operations\EndpointDescriptor;
use HSP\Core\Contracts\Operations\EndpointParameter;
use HSP\Core\Contracts\Operations\EndpointProviderInterface;
use HSP\Modules\Content\Operations\ContentEndpointProvider;
use HSP\Modules\Content\Resources\MediaResource;
use HSP\Modules\Content\Resources\PostResource;
use PHPUnit\Framework\TestCase;

final class ContentEndpointProviderTest extends TestCase
{
    public function test_implements_core_contract_and_key(): void
    {
        $provider = new ContentEndpointProvider();

        self::assertInstanceOf(EndpointProviderInterface::class, $provider);
        self::assertSame('content.endpoints', $provider->key());
    }

    public function test_describes_the_ten_hsp_v1_content_endpoints(): void
    {
        $endpoints = (new ContentEndpointProvider())->endpoints();

        self::assertCount(10, $endpoints);
        self::assertContainsOnlyInstancesOf(EndpointDescriptor::class, $endpoints);

        foreach ($endpoints as $ep) {
            self::assertSame('GET', $ep->method);
            self::assertSame('hsp/v1', $ep->namespace);
            self::assertSame('Content', $ep->displayGroup);
            self::assertNotSame('', $ep->description);
        }

        $routes = array_map(static fn (EndpointDescriptor $e) => $e->route, $endpoints);
        self::assertEqualsCanonicalizing(
            [
                '/pages', '/pages/{path}',
                '/posts', '/posts/{slug}',
                '/categories', '/categories/{slug}',
                '/media', '/media/{slug}',
                '/tags', '/tags/{slug}',
            ],
            $routes,
        );
    }

    public function test_all_content_endpoints_are_public_and_v1_content_owned(): void
    {
        foreach ((new ContentEndpointProvider())->endpoints() as $ep) {
            self::assertSame(EndpointAuth::Public, $ep->auth, "{$ep->route} must be public (ADR-055 (d))");
            self::assertSame('v1', $ep->version);
            self::assertSame('content', $ep->moduleOwner);
            self::assertFalse($ep->deprecated);
        }
    }

    public function test_listing_endpoints_are_paginated_with_cursor_envelope_response(): void
    {
        $byRoute = $this->byRoute();

        foreach (['/pages', '/posts', '/categories'] as $route) {
            $ep = $byRoute[$route];
            self::assertTrue($ep->paginated, "{$route} is a cursor-paginated listing");
            self::assertNotNull($ep->responseSchema);
            self::assertArrayHasKey('data', $ep->responseSchema->schema['properties']);
            self::assertArrayHasKey('next_cursor', $ep->responseSchema->schema['properties']);

            // Cursor + per_page params are present on every listing (Doc 9 §13).
            $names = array_map(static fn (EndpointParameter $p) => $p->name, $ep->parameters);
            self::assertContains('cursor', $names);
            self::assertContains('per_page', $names);
        }
    }

    public function test_posts_listing_carries_the_decision_f_filters(): void
    {
        $names = array_map(
            static fn (EndpointParameter $p) => $p->name,
            $this->byRoute()['/posts']->parameters,
        );

        self::assertContains('status', $names);
        self::assertContains('category', $names);
        self::assertContains('published_after', $names);
    }

    public function test_single_endpoints_take_a_required_slug_path_param(): void
    {
        $byRoute = $this->byRoute();

        foreach (['/posts/{slug}', '/categories/{slug}', '/media/{slug}', '/tags/{slug}'] as $route) {
            $ep = $byRoute[$route];
            self::assertFalse($ep->paginated);
            self::assertCount(1, $ep->parameters);
            self::assertSame('slug', $ep->parameters[0]->name);
            self::assertSame(EndpointParameter::IN_PATH, $ep->parameters[0]->in);
            self::assertTrue($ep->parameters[0]->required);
        }
    }

    /**
     * Pages are hierarchical, so their published path parameter is a PATH, not a slug
     * (DECISION AD). The flat resources above keep `slug` — the capability is explicit, not
     * spread across every endpoint.
     */
    public function test_the_page_single_endpoint_takes_a_required_hierarchical_path_param(): void
    {
        $ep = $this->byRoute()['/pages/{path}'];

        self::assertFalse($ep->paginated);
        self::assertCount(1, $ep->parameters);
        self::assertSame('path', $ep->parameters[0]->name);
        self::assertSame(EndpointParameter::IN_PATH, $ep->parameters[0]->in);
        self::assertTrue($ep->parameters[0]->required);
        self::assertStringContainsString('about/team', $ep->parameters[0]->description);
    }

    public function test_response_shapes_expose_only_published_fields_not_internal_columns(): void
    {
        $post = $this->byRoute()['/posts/{slug}'];
        self::assertNotNull($post->responseSchema);
        $props = $post->responseSchema->schema['properties'];

        // Rule 6: published fields present…
        self::assertArrayHasKey('slug', $props);
        self::assertArrayHasKey('title', $props);
        // …internal projection columns ABSENT (ADR-040).
        self::assertArrayNotHasKey('checksum', $props);
        self::assertArrayNotHasKey('source_post_id', $props);
        self::assertArrayNotHasKey('id', $props);
    }


    // -------------------------------------------------------------------------
    // Nested published shapes (ADR-055 (c))
    //
    // These assert the INTENDED nested properties, not merely that `type: object` exists —
    // a bare object is exactly the defect: it generates an unusable consumer type for data the
    // contract deliberately publishes.
    // -------------------------------------------------------------------------

    public function test_featured_media_publishes_its_nested_shape_and_is_nullable(): void
    {
        foreach (['/posts/{slug}', '/pages/{path}'] as $route) {
            $descriptor = $this->byRoute()[$route];
            self::assertNotNull($descriptor->responseSchema);

            $media = $descriptor->responseSchema->schema['properties']['featured_media'];

            // Nullable per ADR-013's soft reference — OpenAPI 3.1 type union, not `nullable: true`.
            self::assertSame(['object', 'null'], $media['type'], "{$route} featured_media nullability");
            self::assertSame(
                ['slug', 'url', 'alt_text', 'mime_type', 'width', 'height', 'sizes'],
                array_keys($media['properties']),
                "{$route} featured_media properties",
            );
            self::assertSame('integer', $media['properties']['width']['type']);
            self::assertSame($media['required'], array_keys($media['properties']));
            // The nested `sizes` map is itself described, not left opaque two levels down.
            self::assertSame(
                'string',
                $media['properties']['sizes']['additionalProperties']['properties']['url']['type'],
            );
        }
    }

    public function test_post_tags_publish_an_array_of_slug_name_objects(): void
    {
        $descriptor = $this->byRoute()['/posts/{slug}'];
        self::assertNotNull($descriptor->responseSchema);

        $tags = $descriptor->responseSchema->schema['properties']['tags'];

        self::assertSame('array', $tags['type']);
        self::assertSame('object', $tags['items']['type']);
        self::assertSame(['slug', 'name'], array_keys($tags['items']['properties']));
        self::assertSame(['slug', 'name'], $tags['items']['required']);
    }

    public function test_media_sizes_is_an_open_map_with_a_described_value_shape(): void
    {
        $descriptor = $this->byRoute()['/media/{slug}'];
        self::assertNotNull($descriptor->responseSchema);

        $sizes = $descriptor->responseSchema->schema['properties']['sizes'];

        // Keys are site-registered size names — OPEN. Values are platform-owned — DESCRIBED.
        self::assertSame('object', $sizes['type']);
        self::assertArrayNotHasKey('properties', $sizes, 'The size-name key set must stay open.');
        self::assertSame(
            ['url', 'width', 'height', 'mime_type'],
            array_keys($sizes['additionalProperties']['properties']),
        );
        self::assertSame('integer', $sizes['additionalProperties']['properties']['width']['type']);
    }

    /**
     * `meta` is classification B — INTENTIONALLY opaque. The key set is the site's, so it must
     * stay an open map and must NOT be frozen into a closed property list.
     */
    public function test_meta_stays_a_deliberately_open_map(): void
    {
        foreach (['/posts/{slug}', '/pages/{path}', '/media/{slug}'] as $route) {
            $descriptor = $this->byRoute()[$route];
            self::assertNotNull($descriptor->responseSchema);

            $meta = $descriptor->responseSchema->schema['properties']['meta'];

            self::assertSame('object', $meta['type'], "{$route} meta type");
            self::assertTrue($meta['additionalProperties'], "{$route} meta stays open");
            self::assertArrayNotHasKey('properties', $meta, "{$route} meta must not be closed");
            self::assertNotSame('', $meta['description'], "{$route} meta opacity must be explained");
        }
    }

    // -------------------------------------------------------------------------
    // Runtime ⇄ descriptor agreement
    //
    // The published Resource and the descriptor must describe the SAME nested contract. Asserted
    // against explicit row fixtures — no reflection, no runtime schema derivation (ADR-055 (a)).
    // -------------------------------------------------------------------------

    public function test_post_resource_nested_output_matches_the_descriptor(): void
    {
        $descriptor = $this->byRoute()['/posts/{slug}'];
        self::assertNotNull($descriptor->responseSchema);
        $props = $descriptor->responseSchema->schema['properties'];

        $published = (new PostResource())->toArray([
            'slug'           => 'hello',
            'title'          => 'Hello',
            'content'        => '<p>Hi</p>',
            'status'         => 'publish',
            'fm_slug'        => 'shot',
            'fm_url'         => 'https://example.test/wp-content/uploads/shot.jpg',
            'fm_alt_text'    => 'A shot',
            'fm_mime_type'   => 'image/jpeg',
            'fm_width'       => 1200,
            'fm_height'      => 800,
            'fm_sizes_jsonb' => '{"thumbnail":{"url":"https://example.test/wp-content/uploads/shot-150x150.jpg",'
                . '"width":150,"height":150,"mime_type":"image/jpeg"}}',
            'tags_json'      => '[{"slug":"php","name":"PHP"}]',
        ]);

        self::assertIsArray($published['featured_media']);
        self::assertSame(
            array_keys($props['featured_media']['properties']),
            array_keys($published['featured_media']),
            'featured_media runtime keys must match the published descriptor exactly.',
        );
        self::assertSame(
            array_keys($props['tags']['items']['properties']),
            array_keys($published['tags'][0]),
            'A published tag must match the descriptor item shape exactly.',
        );
        self::assertSame(
            array_keys($props['featured_media']['properties']['sizes']['additionalProperties']['properties']),
            array_keys($published['featured_media']['sizes']['thumbnail']),
            'A size variant must match the descriptor value shape exactly.',
        );
    }

    /** The nullable arm of the same contract: no featured image projects as JSON null. */
    public function test_post_resource_publishes_null_featured_media_as_the_descriptor_allows(): void
    {
        $published = (new PostResource())->toArray([
            'slug'   => 'hello',
            'title'  => 'Hello',
            'content' => '',
            'status' => 'publish',
        ]);

        self::assertNull($published['featured_media']);
        self::assertSame([], $published['tags'], 'An untagged post publishes an empty array, never null.');

        $descriptor = $this->byRoute()['/posts/{slug}'];
        self::assertNotNull($descriptor->responseSchema);
        self::assertContains(
            'null',
            $descriptor->responseSchema->schema['properties']['featured_media']['type'],
        );
    }

    public function test_media_resource_sizes_output_matches_the_descriptor(): void
    {
        $descriptor = $this->byRoute()['/media/{slug}'];
        self::assertNotNull($descriptor->responseSchema);
        $valueShape = $descriptor->responseSchema->schema['properties']['sizes']['additionalProperties'];

        $published = (new MediaResource())->toArray([
            'slug'        => 'shot',
            'title'       => 'Shot',
            'mime_type'   => 'image/jpeg',
            'url'         => 'https://example.test/wp-content/uploads/shot.jpg',
            'sizes_jsonb' => '{"medium":{"url":"https://example.test/wp-content/uploads/shot-300x200.jpg",'
                . '"width":300,"height":200,"mime_type":"image/jpeg"}}',
        ]);

        self::assertSame(
            array_keys($valueShape['properties']),
            array_keys($published['sizes']['medium']),
        );
    }

    /** @return array<string,EndpointDescriptor> route → descriptor */
    private function byRoute(): array
    {
        $out = [];
        foreach ((new ContentEndpointProvider())->endpoints() as $ep) {
            $out[$ep->route] = $ep;
        }

        return $out;
    }
}
