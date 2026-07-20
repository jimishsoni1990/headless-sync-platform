<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Operations;

/**
 * Immutable descriptor of one delivery API endpoint (Doc 12 §15; ADR-050; ADR-055).
 *
 * Read-only metadata about a published hsp/v1 endpoint (DECISION N/F), supplied by an
 * EndpointProviderInterface so the API Playground (OPSC-S3) can list and describe endpoints
 * WITHOUT hardcoding them (ADR-050/ADR-052), and so the OpenAPI generator (ADR-055) can produce
 * the OpenAPI 3.1 document from the registry alone — never hand-authored, never scan-derived.
 *
 * ADR-038 transport-agnosticism: no WP_REST_Request / HTTP framework types appear here — only
 * plain metadata. Rule 6: consumers describe the published API contract, not internal schemas.
 *
 * The five original fields (method/route/namespace/displayGroup/description) are RETAINED
 * verbatim and first. ADR-055 (c) additively enriches the descriptor with the OpenAPI 3.1
 * Operation metadata (parameters, request/response schema, auth requirement, cursor envelope,
 * deprecation, version, module owner) — no field removed, existing consumers unbroken.
 *
 * Fields:
 *   $method       — HTTP method (MVP: 'GET').
 *   $route        — route path relative to the namespace (e.g. '/posts', '/posts/{slug}').
 *   $namespace    — REST namespace (DECISION N: 'hsp/v1').
 *   $displayGroup — Doc 12 §15 display category (e.g. 'Content'); grouping label only.
 *   $description  — human-readable description of the endpoint.
 *   --- ADR-055 (c) additive enrichment ---
 *   $parameters   — path + query parameters (DECISION F filters + cursor); OpenAPI Parameter Objects.
 *   $responseSchema — published 200 response shape (Rule 6 shape; cursor envelope for list ops).
 *   $requestSchema  — published request-body shape (null for GET — no body).
 *   $auth         — public vs authenticated (Doc 9 §22); drives ADR-055 (d) public-only scoping.
 *   $paginated    — true for cursor-paginated list operations (Doc 9 §13 / DECISION F CursorPage).
 *   $deprecated   — Doc 9 §26 lifecycle → OpenAPI `deprecated: true`.
 *   $version      — the contract version the operation belongs to (Doc 9 §7).
 *   $moduleOwner  — the module that registered the endpoint (Doc 9 §6).
 *
 * @psalm-immutable
 */
final class EndpointDescriptor
{
    /**
     * @param EndpointParameter[] $parameters ADR-055 (c) path + query parameters
     */
    public function __construct(
        public readonly string $method,
        public readonly string $route,
        public readonly string $namespace,
        public readonly string $displayGroup,
        public readonly string $description,
        public readonly array $parameters = [],
        public readonly ?SchemaObject $responseSchema = null,
        public readonly ?SchemaObject $requestSchema = null,
        public readonly EndpointAuth $auth = EndpointAuth::Public,
        public readonly bool $paginated = false,
        public readonly bool $deprecated = false,
        public readonly string $version = 'v1',
        public readonly string $moduleOwner = '',
    ) {}
}
