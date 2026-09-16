<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Queries;

use HSP\Core\Contracts\QueryFilterInterface;

/**
 * Filter for Commerce taxonomy-term listings (DECISION AG AG-5).
 *
 * Separate from ProductFilterSet rather than shared: a term listing and a product listing have
 * almost nothing in common beyond pagination, and merging them would recreate in miniature the
 * problem AG-5 solved — one filter class accumulating fields for everything that queries.
 *
 * Pagination only. There is deliberately no parent filter: the shipped `parentId` keyed a public
 * `?parent=` on WordPress term ids with no ruling behind it, and was Removed by
 * FLAG-COMMCATPARENT-1. The tree is rebuilt consumer-side from each category's `has_parent` + `parent_slug`,
 * which is what P2-S3 projected the hierarchy for.
 */
final class TermFilterSet implements QueryFilterInterface
{
    public function __construct(
        public readonly ?string $cursor = null,
        public readonly ?int $limit = null,
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
