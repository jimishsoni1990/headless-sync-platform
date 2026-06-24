<?php

declare(strict_types=1);

namespace HSP\Core\Contracts;

/**
 * Immutable value object carrying validated filter parameters for a single query.
 *
 * Authority: Doc 9 §12 — core owns filtering contracts; modules own filter implementations.
 * ADR-038: transport-agnostic. No HTTP, WP_REST_Request, or framework types appear here.
 *
 * Modules extend or wrap this as needed. The base class provides the common filters
 * exercised by the six Phase 1A read endpoints:
 *   - slug       (pages/{slug}, posts/{slug}, categories/{slug})
 *   - status     (constrained to the public set per OPEN-10; null = default public set)
 *   - category   (posts listing — category slug; resolved projection-side, never WP term_id)
 *   - publishedAfter (posts and pages listing)
 *   - cursor     (opaque pagination token)
 *   - limit      (page size; null = implementation default)
 *
 * Consumers of this class are Query Providers only. REST route registration is responsible
 * for building a FilterSet from the sanitized request parameters (WP boundary).
 */
final class FilterSet
{
    /**
     * @param string|null $slug           Exact slug match for single-item lookups
     * @param string|null $status         Status filter; null = apply public-set default
     * @param string|null $categorySlug   Category slug for posts listing (projection-side join)
     * @param \DateTimeImmutable|null $publishedAfter Exclusive lower bound on published_at
     * @param string|null $cursor         Opaque cursor token for pagination continuity
     * @param int|null    $limit          Max rows to return; null = implementation default
     */
    public function __construct(
        public readonly ?string $slug           = null,
        public readonly ?string $status         = null,
        public readonly ?string $categorySlug   = null,
        public readonly ?\DateTimeImmutable $publishedAfter = null,
        public readonly ?string $cursor         = null,
        public readonly ?int    $limit          = null,
    ) {}
}
