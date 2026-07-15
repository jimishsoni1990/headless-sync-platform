<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Operations;

use HSP\Core\Contracts\Operations\EndpointDescriptor;
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

    public function test_describes_the_six_hsp_v1_content_endpoints(): void
    {
        $endpoints = (new ContentEndpointProvider())->endpoints();

        self::assertCount(6, $endpoints);
        self::assertContainsOnlyInstancesOf(EndpointDescriptor::class, $endpoints);

        foreach ($endpoints as $ep) {
            self::assertSame('GET', $ep->method);
            self::assertSame('hsp/v1', $ep->namespace);
            self::assertSame('Content', $ep->displayGroup);
            self::assertNotSame('', $ep->description);
        }

        $routes = array_map(static fn (EndpointDescriptor $e) => $e->route, $endpoints);
        self::assertEqualsCanonicalizing(
            ['/pages', '/pages/{slug}', '/posts', '/posts/{slug}', '/categories', '/categories/{slug}'],
            $routes,
        );
    }
}
