<?php

declare(strict_types=1);

namespace HSP\Core\Contracts;

/**
 * Contract for domain resources that serialize projection rows to response shapes.
 *
 * Authority: Doc 9 §11 — Resources are module-owned; responsibilities: serialization,
 * formatting, contract shaping, response consistency. Resources must NOT contain
 * business logic. Doc 9 §6 — core owns the resource contract.
 * ADR-038: transport-agnostic. No HTTP, WP_REST_Request, WP_REST_Response, or any
 * framework/HTTP types appear here. Transport adaptation is the caller's responsibility.
 * ADR-040: response shapes must not leak internal columns (id UUID, source_post_id,
 * checksum, synced_at, *_jsonb internals unless contractually intended).
 *
 * Implementations live in modules (e.g. HSP\Modules\Content\Resources\).
 * Core owns this contract; modules own implementations.
 */
interface ResourceInterface
{
    /**
     * Serialize a single projection row to the API contract shape.
     *
     * @param array<string,mixed> $row  Raw projection row from a Query Provider
     * @return array<string,mixed>      Contract-shaped response array (no internal columns)
     */
    public function toArray(array $row): array;

    /**
     * Serialize a collection of projection rows to a response envelope.
     *
     * The envelope MUST include:
     *   - 'data'       : list of toArray()-serialized items
     *   - 'next_cursor': opaque string or null (last page)
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    public function toCollection(array $rows, ?string $nextCursor): array;
}
