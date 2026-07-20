<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Operations;

/**
 * One path or query parameter of a delivery API endpoint (OpenAPI 3.1 Parameter Object;
 * ADR-055 (c)).
 *
 * Carries the DECISION F filters (`slug`/`status`/`published_after`/`category`) and the
 * cursor/pagination parameters as plain metadata so the generator can emit an OpenAPI
 * Parameter Object without inspecting WordPress route args (ADR-055 (a)).
 *
 * ADR-038: transport-agnostic — no WP_REST_Request / HTTP types; plain scalars only.
 *
 * Fields:
 *   $name        — parameter name (e.g. 'slug', 'status', 'cursor', 'per_page').
 *   $in          — location: 'path' or 'query' (OpenAPI `in`).
 *   $type        — JSON Schema scalar type ('string' | 'integer' | 'boolean' | 'number').
 *   $required    — whether the parameter is required (path params are always required).
 *   $description — human-readable description.
 *
 * @psalm-immutable
 */
final class EndpointParameter
{
    public const IN_PATH  = 'path';
    public const IN_QUERY = 'query';

    public function __construct(
        public readonly string $name,
        public readonly string $in,
        public readonly string $type,
        public readonly bool $required,
        public readonly string $description = '',
    ) {
    }

    /** Convenience factory for a required path parameter (always `required: true`). */
    public static function path(string $name, string $type = 'string', string $description = ''): self
    {
        return new self($name, self::IN_PATH, $type, true, $description);
    }

    /** Convenience factory for an optional query parameter. */
    public static function query(
        string $name,
        string $type = 'string',
        string $description = '',
        bool $required = false
    ): self {
        return new self($name, self::IN_QUERY, $type, $required, $description);
    }
}
