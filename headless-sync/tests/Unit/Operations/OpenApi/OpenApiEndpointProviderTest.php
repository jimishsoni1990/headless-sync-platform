<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\OpenApi;

use HSP\Core\Contracts\Operations\EndpointAuth;
use HSP\Core\Contracts\Operations\EndpointDescriptor;
use HSP\Core\Contracts\Operations\EndpointProviderInterface;
use HSP\Core\Operations\OpenApi\OpenApiEndpointProvider;
use PHPUnit\Framework\TestCase;

/**
 * The core-owned openapi.json endpoint self-describes (ADR-055 (4)): public, on hsp/v1, complete.
 */
final class OpenApiEndpointProviderTest extends TestCase
{
    public function test_implements_core_contract_and_key(): void
    {
        $provider = new OpenApiEndpointProvider();

        self::assertInstanceOf(EndpointProviderInterface::class, $provider);
        self::assertSame('core.openapi.endpoint', $provider->key());
    }

    public function test_describes_the_public_openapi_json_route(): void
    {
        $endpoints = (new OpenApiEndpointProvider())->endpoints();

        self::assertCount(1, $endpoints);
        $ep = $endpoints[0];
        self::assertInstanceOf(EndpointDescriptor::class, $ep);
        self::assertSame('GET', $ep->method);
        self::assertSame('/openapi.json', $ep->route);
        self::assertSame('hsp/v1', $ep->namespace);
        self::assertSame(EndpointAuth::Public, $ep->auth);
        self::assertSame('core', $ep->moduleOwner);
        self::assertNotNull($ep->responseSchema);
        self::assertNotSame('', $ep->description);
    }
}
