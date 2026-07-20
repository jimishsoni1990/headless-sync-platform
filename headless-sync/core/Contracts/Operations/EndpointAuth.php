<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Operations;

/**
 * Auth requirement of a delivery API endpoint (Doc 9 §22; ADR-055 (c)/(d)).
 *
 * Drives the OpenAPI generator's public-only scoping (ADR-055 (d), v1.27): only PUBLIC
 * endpoints appear in the served document; endpoints requiring authentication/capabilities
 * are excluded — the filter reads THIS field, never route inspection (ADR-055 (a)).
 *
 * ADR-038: transport-agnostic — a plain enum, no HTTP/framework types.
 */
enum EndpointAuth: string
{
    /** Unauthenticated consumer-facing endpoint (Rule 6). Appears in the served OpenAPI document. */
    case Public = 'public';

    /** Requires authentication/capabilities (Doc 9 §22). EXCLUDED from the served document (ADR-055 (d)). */
    case Authenticated = 'authenticated';

    /** True when this endpoint belongs in the public-only OpenAPI document (ADR-055 (d)). */
    public function isPublic(): bool
    {
        return $this === self::Public;
    }
}
