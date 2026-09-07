<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Queries;

use HSP\Core\Contracts\QueryFilterInterface;

/**
 * The Content domain's delivery filter (DECISION AG AG-5).
 *
 * Was `core/Contracts/FilterSet` — a `final` core class whose docblock claimed modules
 * extend it and four of whose seven fields were content-domain. Core now owns only the
 * pagination envelope (QueryFilterInterface); the domain fields live here, where the module
 * that means them can change them without touching core or any sibling module.
 *
 * Field semantics are unchanged, and the constructor keeps its exact parameter order, so
 * every existing positional and named construction remains valid — the same additive
 * discipline P1B-S3 used when it appended `tagSlug` (DECISION F).
 *
 * Authority: Doc 9 §12 (core owns filtering contracts; modules own implementations);
 * ADR-038 (transport-agnostic — no HTTP or WP_REST_Request types here); OPEN-10 (public
 * status set); DECISION F (cursor envelope).
 */
final class ContentFilterSet implements QueryFilterInterface
{
    /**
     * @param string|null $slug           Exact slug match for single-item lookups
     * @param string|null $status         Status filter; null = apply public-set default
     * @param string|null $categorySlug   Category slug for posts listing (projection-side join)
     * @param \DateTimeImmutable|null $publishedAfter Exclusive lower bound on published_at
     * @param string|null $cursor         Opaque cursor token for pagination continuity
     * @param int|null    $limit          Max rows to return; null = implementation default
     * @param string|null $tagSlug        Tag slug for posts listing (projection-side join — P1B-S3)
     */
    public function __construct(
        public readonly ?string $slug = null,
        public readonly ?string $status = null,
        public readonly ?string $categorySlug = null,
        public readonly ?\DateTimeImmutable $publishedAfter = null,
        public readonly ?string $cursor = null,
        public readonly ?int $limit = null,
        public readonly ?string $tagSlug = null,
    ) {
    }

    public function cursor(): ?string
    {
        return $this->cursor;
    }

    public function limit(): ?int
    {
        return $this->limit;
    }
}
