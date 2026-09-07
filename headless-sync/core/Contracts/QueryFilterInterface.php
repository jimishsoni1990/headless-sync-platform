<?php

declare(strict_types=1);

namespace HSP\Core\Contracts;

/**
 * Domain-neutral delivery query filter (DECISION AG AG-5).
 *
 * Core owns only what every list endpoint needs regardless of domain: the cursor-pagination
 * envelope (DECISION F / Doc 9 §13). Everything domain-specific — a content category slug,
 * a product price range, a stock status — belongs to a module-owned DTO implementing this
 * contract.
 *
 * This replaces the concrete `FilterSet`, which was `final` while its own docblock claimed
 * "modules extend or wrap this as needed", and four of whose seven fields were
 * content-domain. Commerce filters had nowhere to live except by appending more fields to
 * a core class — the Rule 5 leak AG-5 removes.
 *
 * Deliberately NOT an untyped `array<string,mixed>` bag: strong typing and explicit
 * validation stay. A query provider handed a filter from another domain must reject it
 * explicitly rather than silently reading the wrong DTO and returning plausible nonsense.
 */
interface QueryFilterInterface
{
    /** Opaque cursor from a previous page, or null for the first page. */
    public function cursor(): ?string;

    /** Page size, or null to let the provider apply its default. */
    public function limit(): ?int;
}
