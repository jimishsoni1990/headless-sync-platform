<?php

declare(strict_types=1);

namespace HSP\Core\Operations\OpenApi;

use HSP\Core\Contracts\Operations\EndpointAuth;
use HSP\Core\Contracts\Operations\EndpointDescriptor;
use HSP\Core\Contracts\Operations\EndpointProviderInterface;
use HSP\Core\Contracts\Operations\SchemaObject;

/**
 * Core-owned endpoint metadata for `GET /hsp/v1/openapi.json` itself (ADR-055 (a)/(4)).
 *
 * The drift guard (ADR-055 (f)) covers ALL non-exempted hsp/v1 routes — including the openapi.json
 * route. Core owns that route (it is not a module endpoint), so core owns its descriptor. Being
 * PUBLIC, the openapi.json endpoint self-describes: it appears in its own generated document.
 *
 * Registered with the RefreshCoordinator like any EndpointProviderInterface (explicit
 * registration — ADR-048/052), so OperationsService::endpointDescriptors() aggregates it into the
 * registry the generator consumes. ADR-038: plain metadata only; no HTTP/framework types.
 */
final class OpenApiEndpointProvider implements EndpointProviderInterface
{
    public const KEY = 'core.openapi.endpoint';

    /** Mirrors OpenApiRestRegistrar::NAMESPACE (DECISION N). */
    private const NAMESPACE = 'hsp/v1';

    public function key(): string
    {
        return self::KEY;
    }

    /** @return EndpointDescriptor[] */
    public function endpoints(): array
    {
        return [
            new EndpointDescriptor(
                method: 'GET',
                route: '/openapi.json',
                namespace: self::NAMESPACE,
                displayGroup: 'Meta',
                description: 'OpenAPI 3.1 description of the public delivery API, generated at '
                    . 'request time from the endpoint metadata registry (ADR-055).',
                parameters: [],
                responseSchema: new SchemaObject([
                    'type'        => 'object',
                    'description' => 'An OpenAPI 3.1 document.',
                    'properties'  => [
                        'openapi' => ['type' => 'string'],
                        'info'    => ['type' => 'object'],
                        'paths'   => ['type' => 'object'],
                    ],
                    'required'    => ['openapi', 'info', 'paths'],
                ]),
                requestSchema: null,
                auth: EndpointAuth::Public,
                paginated: false,
                deprecated: false,
                version: 'v1',
                moduleOwner: 'core',
            ),
        ];
    }
}
