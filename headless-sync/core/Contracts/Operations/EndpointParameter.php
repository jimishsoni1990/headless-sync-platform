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
 *   --- FLAG-RESTARGDRIFT-1 additive enrichment ---
 *   $enum / $minimum / $maximum / $pattern / $format / $default
 *                — the JSON Schema constraints of the parameter, MACHINE-READABLE.
 *
 * WHY THE CONSTRAINTS HAD TO BECOME FIELDS. Until FLAG-RESTARGDRIFT-1 a parameter could only
 * publish its `type`, so `per_page`'s real contract — 1..100 — existed as English inside
 * `$description` ("Page size (1–100)") and as an INERT pair of schema keywords in the WordPress
 * route args. A generated client could not read it, and nothing could compare the two
 * declarations, so runtime and contract drifted silently for as long as the endpoint existed.
 * A constraint a consumer cannot read is not a published contract; a constraint no test can
 * compare is not a guarded one.
 *
 * Each field is OPTIONAL and null/empty means NOT DECLARED — never "no constraint applies by
 * default". The generator omits an undeclared keyword entirely rather than emitting a neutral
 * value, because `minimum: 0` and "no minimum" are different contracts.
 *
 * Rule 5 / ADR-055 (a): this is the GENERIC metadata shape only. Which values a parameter
 * carries is the owning module's decision — no Content or Commerce semantics appear here.
 *
 * @psalm-immutable
 */
final class EndpointParameter
{
    public const IN_PATH  = 'path';
    public const IN_QUERY = 'query';

    /**
     * @param list<string>|null $enum    Finite allowed value set, or null when unconstrained.
     * @param int|float|null    $minimum Inclusive lower bound (numeric parameters).
     * @param int|float|null    $maximum Inclusive upper bound (numeric parameters).
     * @param string|null       $pattern Anchored regular expression (string parameters).
     * @param string|null       $format  JSON Schema `format` (e.g. 'date-time').
     * @param mixed             $default Declared default; null means no default is published.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $in,
        public readonly string $type,
        public readonly bool $required,
        public readonly string $description = '',
        public readonly ?array $enum = null,
        public readonly int|float|null $minimum = null,
        public readonly int|float|null $maximum = null,
        public readonly ?string $pattern = null,
        public readonly ?string $format = null,
        public readonly mixed $default = null,
    ) {
    }

    /**
     * Convenience factory for a required path parameter (always `required: true`).
     *
     * `$pattern` publishes the route's own structural grammar where it materially narrows the
     * addressing contract — a slug route accepts `[a-z0-9_-]+`, not any string, and a consumer
     * generating URLs needs to know that. Enforcement stays with WordPress route matching, so
     * publishing it creates no new 400: a non-matching URL never reaches the operation at all.
     */
    public static function path(
        string $name,
        string $type = 'string',
        string $description = '',
        ?string $pattern = null
    ): self {
        return new self($name, self::IN_PATH, $type, true, $description, pattern: $pattern);
    }

    /**
     * Convenience factory for an optional query parameter.
     *
     * @param list<string>|null $enum
     */
    public static function query(
        string $name,
        string $type = 'string',
        string $description = '',
        bool $required = false,
        ?array $enum = null,
        int|float|null $minimum = null,
        int|float|null $maximum = null,
        ?string $pattern = null,
        ?string $format = null,
        mixed $default = null
    ): self {
        return new self(
            $name,
            self::IN_QUERY,
            $type,
            $required,
            $description,
            $enum,
            $minimum,
            $maximum,
            $pattern,
            $format,
            $default
        );
    }

    /**
     * The declared JSON Schema constraints, as an OpenAPI 3.1 Schema Object fragment.
     *
     * One place builds this map, so the generator and the ADR-055 parameter drift guard cannot
     * disagree about which keywords a parameter declares. Undeclared keywords are ABSENT.
     *
     * @return array<string,mixed>
     */
    public function schemaConstraints(): array
    {
        $constraints = [];

        if ($this->enum !== null && $this->enum !== []) {
            $constraints['enum'] = $this->enum;
        }
        if ($this->minimum !== null) {
            $constraints['minimum'] = $this->minimum;
        }
        if ($this->maximum !== null) {
            $constraints['maximum'] = $this->maximum;
        }
        if ($this->pattern !== null) {
            $constraints['pattern'] = $this->pattern;
        }
        if ($this->format !== null) {
            $constraints['format'] = $this->format;
        }
        if ($this->default !== null) {
            $constraints['default'] = $this->default;
        }

        return $constraints;
    }
}
