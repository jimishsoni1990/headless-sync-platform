<?php

declare(strict_types=1);

namespace HSP\Core\Operations\OpenApi;

use HSP\Core\Operations\Services\OperationsService;

/**
 * Serves the generated OpenAPI 3.1 document for `GET /hsp/v1/openapi.json` (ADR-055 (d)/(e)).
 *
 * The one place the openapi.json request is handled. It reads the aggregated endpoint registry
 * via OperationsService::endpointDescriptors() (the SAME registry the API Playground uses — one
 * source of truth, ADR-055 (b)) and runs the OpenApiGenerator. The generator applies public-only
 * scoping and produces the document from the registry alone (ADR-055 (a)/(d)).
 *
 * PUBLIC + STATELESS (ADR-055 (e)): no capability check, no nonce, no PostgreSQL read, no
 * persistence — the response is computed on demand from the in-process registrations. It is NOT
 * an ADR-054 processing cycle; it is a synchronous public REST read.
 *
 * ADR-038: WordPress REST types are confined to this boundary class (mirrors ContentRestRegistrar).
 * Constructor injection only (ADR-012 / Rule 7).
 */
final class OpenApiRestController
{
    public function __construct(
        private readonly OperationsService $operations,
        private readonly OpenApiGenerator $generator,
    ) {
    }

    /**
     * Handle `GET /hsp/v1/openapi.json`.
     *
     * @return \WP_REST_Response the generated OpenAPI 3.1 document
     */
    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        // $request is unused: the document is derived from the registry, not the request — the
        // endpoint is stateless and takes no parameters (ADR-055 (e)).
        unset($request);

        $document = $this->generator->generate($this->operations->endpointDescriptors());

        return rest_ensure_response($document);
    }
}
