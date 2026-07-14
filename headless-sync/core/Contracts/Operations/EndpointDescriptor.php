<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Operations;

/**
 * Immutable descriptor of one delivery API endpoint (Doc 12 §15; ADR-050).
 *
 * Read-only metadata about a published hsp/v1 endpoint (DECISION N/F), supplied by an
 * EndpointProviderInterface so the API Playground (OPSC-S3) can list and describe
 * endpoints WITHOUT hardcoding them (ADR-050/ADR-052). OPSC-S1 defines the descriptor and
 * provider contract only; the Playground that executes live GETs is OPSC-S3.
 *
 * ADR-038 transport-agnosticism: no WP_REST_Request / HTTP framework types appear here —
 * only plain metadata. Rule 6: consumers describe the published API contract, not internal
 * schemas.
 *
 * Fields:
 *   $method       — HTTP method (MVP: 'GET').
 *   $route        — route path relative to the namespace (e.g. '/posts', '/posts/{slug}').
 *   $namespace    — REST namespace (DECISION N: 'hsp/v1').
 *   $displayGroup — Doc 12 §15 display category (e.g. 'Content'); grouping label only.
 *   $description  — human-readable description of the endpoint.
 *
 * @psalm-immutable
 */
final class EndpointDescriptor
{
    public function __construct(
        public readonly string $method,
        public readonly string $route,
        public readonly string $namespace,
        public readonly string $displayGroup,
        public readonly string $description,
    ) {}
}
