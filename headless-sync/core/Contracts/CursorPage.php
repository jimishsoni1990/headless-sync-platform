<?php

declare(strict_types=1);

namespace HSP\Core\Contracts;

/**
 * Immutable value object returned by QueryProviderInterface::list().
 *
 * Authority: Doc 9 §13 — cursor pagination; no offset.
 * ADR-038: transport-agnostic. No HTTP, WP_REST_Request, or framework types appear here.
 *
 * Cursor encoding:
 *   The cursor is an opaque base64url token. Internally it encodes the sort
 *   key(s) and id of the last row returned, allowing the next page to be fetched
 *   deterministically even when multiple rows share the same primary sort value.
 *
 *   Encoding format (JSON before base64url):
 *     { "s": "<primary-sort-value>", "id": "<uuid>" }
 *
 *   "s" carries the serialized primary sort column value (ISO-8601 string for
 *   timestamps; plain string for name/slug). Together with "id" the pair forms a
 *   deterministic tiebreaker that proves no skipped or duplicated rows across
 *   page boundaries even when rows share the primary sort value.
 *
 *   The cursor is opaque to callers; its internal structure is a private contract
 *   of the Query Providers that produce and consume it.
 *
 * @template TRow of array<string,mixed>
 */
final class CursorPage
{
    /**
     * @param array<int,array<string,mixed>> $rows       Raw projection rows for this page
     * @param string|null                    $nextCursor Opaque cursor for the next page; null = last page
     */
    public function __construct(
        public readonly array   $rows,
        public readonly ?string $nextCursor,
    ) {}

    /** True when there are no further pages after this one. */
    public function isLastPage(): bool
    {
        return $this->nextCursor === null;
    }
}
