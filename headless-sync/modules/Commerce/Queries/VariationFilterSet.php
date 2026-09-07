<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Queries;

use HSP\Core\Contracts\QueryFilterInterface;

/**
 * Filter for variation listings (DECISION AG AG-5).
 *
 * `parentSourceId` is the only scoping field and it is REQUIRED in practice: variations are
 * addressed exclusively through their parent product, because WooCommerce gives them no
 * permalink and no independent catalogue presence. An unscoped listing of every variation in
 * the store would be a query nothing in the delivery contract asks for, so the provider refuses
 * one rather than quietly paging the whole table.
 */
final class VariationFilterSet implements QueryFilterInterface
{
    public function __construct(
        public readonly ?int $parentSourceId = null,
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
