<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Operations;

/**
 * A JSON Schema fragment describing a published request/response shape (ADR-055 (c)).
 *
 * Rule 6: this is the PUBLISHED contract shape (the fields a consumer sees), NOT the internal
 * `content.*` projection or canonical-model schema. The generator embeds the fragment verbatim
 * into the OpenAPI 3.1 document (OpenAPI 3.1 Schema Objects are JSON Schema 2020-12).
 *
 * ADR-038: transport-agnostic — a plain immutable wrapper over an array; no HTTP/framework types.
 *
 * @psalm-immutable
 */
final class SchemaObject
{
    /**
     * @param array<string,mixed> $schema JSON Schema 2020-12 fragment (e.g. an `object` with
     *                                     `properties`), embedded verbatim by the generator.
     */
    public function __construct(
        public readonly array $schema,
    ) {
    }

    /**
     * Build an `object` schema from a `field => JSON-Schema-type` map.
     *
     * A convenience for the common case of a flat published resource whose fields are simple
     * scalars (or `object`/`array` where a nested shape is not further specified at MVP).
     *
     * @param array<string,string> $properties field name → JSON Schema type
     */
    public static function object(array $properties): self
    {
        $props = [];
        foreach ($properties as $name => $type) {
            $props[$name] = ['type' => $type];
        }

        return new self([
            'type'       => 'object',
            'properties' => $props,
        ]);
    }

    /**
     * Wrap an item schema in the DECISION F / Doc 9 §13 cursor-pagination envelope
     * (`{ data: item[], next_cursor: string|null }`) — so paginated list operations describe
     * the envelope, not a bare array (ADR-055 (c)).
     */
    public function asCursorPage(): self
    {
        return new self([
            'type'       => 'object',
            'properties' => [
                'data'        => [
                    'type'  => 'array',
                    'items' => $this->schema,
                ],
                'next_cursor' => [
                    'type'        => ['string', 'null'],
                    'description' => 'Opaque cursor for the next page; null on the last page (Doc 9 §13).',
                ],
            ],
            'required'   => ['data', 'next_cursor'],
        ]);
    }
}
