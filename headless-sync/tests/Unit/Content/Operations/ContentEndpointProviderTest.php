<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Operations;

use HSP\Core\Contracts\Operations\EndpointAuth;
use HSP\Core\Contracts\Operations\EndpointDescriptor;
use HSP\Core\Contracts\Operations\EndpointParameter;
use HSP\Core\Contracts\Operations\EndpointProviderInterface;
use HSP\Modules\Content\Operations\ContentEndpointProvider;
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
                '/pages', '/pages/{slug}',
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

        foreach (['/pages/{slug}', '/posts/{slug}', '/categories/{slug}'] as $route) {
            $ep = $byRoute[$route];
            self::assertFalse($ep->paginated);
            self::assertCount(1, $ep->parameters);
            self::assertSame('slug', $ep->parameters[0]->name);
            self::assertSame(EndpointParameter::IN_PATH, $ep->parameters[0]->in);
            self::assertTrue($ep->parameters[0]->required);
        }
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
