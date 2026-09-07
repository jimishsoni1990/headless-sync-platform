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
 * `parentId` is offered because the tree is reconstructed consumer-side from the projected
 * parent, so fetching one level at a time is a real access pattern. Note that no index covers
 * it yet: migration 0003 deliberately does not create (taxonomy_type, parent_id) because no
 * shipped endpoint drives it, and an index nothing reads is paid for on every write. It ships
 * with the first endpoint that walks the tree.
 */
final class TermFilterSet implements QueryFilterInterface
{
    public function __construct(
        public readonly ?int $parentId = null,
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
