<?php

declare(strict_types=1);

namespace HSP\Core\Contracts;

/**
 * Contract for domain query providers that read from delivery projections.
 *
 * Authority: Doc 9 §8 — APIs query delivery projections via Query Providers; endpoints
 * must not query projection tables directly. Doc 9 §10 — Query Providers encapsulate
 * projection queries; benefits: reuse, testability, isolation, consistency.
 * Doc 9 §12/§13 — hybrid filtering + cursor pagination.
 * ADR-038: transport-agnostic. No HTTP, WP_REST_Request, or framework types appear here.
 * ADR-040: consumers depend on API contracts; no WordPress reads on the consumer path.
 *
 * Implementations live in modules (e.g. HSP\Modules\Content\Queries\).
 * Core owns this contract; modules own implementations.
 */
interface QueryProviderInterface
{
    /**
     * Return a paginated list of projection rows matching $filters.
     *
     * Implementations MUST:
     *   - Filter out soft-deleted rows (deleted_at IS NOT NULL).
     *   - Apply the public-set default (status = 'publish') when $filters->status is null.
     *   - Use cursor pagination with a deterministic (primary_sort, id) tiebreaker.
     *   - Never query WordPress or the WordPress database.
     *
     * @return CursorPage  Raw projection rows + opaque next-cursor (null = last page)
     */
    public function list(FilterSet $filters): CursorPage;

    /**
     * Return a single projection row by slug, or null if absent or soft-deleted.
     *
     * Implementations MUST:
     *   - Return null for soft-deleted rows (deleted_at IS NOT NULL).
     *   - Never query WordPress or the WordPress database.
     *
     * @return array<string,mixed>|null
     */
    public function findBySlug(string $slug): ?array;
}
