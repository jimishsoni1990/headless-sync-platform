<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Resources;

use HSP\Core\Contracts\ResourceInterface;

/**
 * Shapes a commerce.taxonomies row into the published term contract.
 *
 * `parent` is published as the SOURCE term id rather than the projection UUID, so a consumer
 * can reconstruct the tree from `source_id`/`parent` without a second lookup, and 0 becomes
 * null because "top level" reads better than a magic zero in JSON.
 *
 * `source_id`/`parent` ARE A TEMPORARILY RETAINED LEGACY CONTRACT DEPENDENCY, NOT AN APPROVED
 * PUBLIC IDENTITY — owned by **FLAG-COMMCATPARENT-1**, not by this resource.
 *
 * They survived FLAG-COMMSOURCEID-1's cleanup for one reason only: the shipped
 * `GET /product-categories?parent={source-term-id}` filter takes that integer and `source_id` is
 * the only published field a consumer can obtain it from, so removing either would strand an API
 * this platform already publishes. Live, "Accessories" carries `parent: 28` and "Clothing"
 * carries `source_id: 28`.
 *
 * That is a statement about a dependency, NOT about correctness. The integer-parent filter has
 * **no recorded architecture authorisation**: the P2-S3 hierarchy preflight established that
 * `product_cat` leaf slugs CANNOT repeat under different parents (`wp_unique_term_slug()` scopes
 * uniqueness per taxonomy) and concluded that a category filter "may safely use a bare slug as
 * its canonical key"; the ratified P2-S3 plan row requires "no WordPress term ID as the required
 * public key". The parameter was introduced in commit `85aa4b5` with no rationale recorded for
 * it. So these two fields are contract debt awaiting a ruling, and must not be cited as a
 * precedent for publishing source identity on any other Commerce resource — the other four
 * public shapes publish none.
 *
 * `id` had no such dependency — it is the cursor tiebreaker, still SELECTed, never serialized.
 *
 * No permalink field: WooCommerce category URLs nest and their base is configurable, so any
 * URL here would be a guess (FLAG-COMMPERMA-1).
 */
final class TermResource implements ResourceInterface
{
    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function toArray(array $row): array
    {
        $parent = (int) ($row['parent_id'] ?? 0);

        return [
            'source_id'   => (int) ($row['source_term_id'] ?? 0),
            'slug'        => (string) ($row['slug'] ?? ''),
            'name'        => (string) ($row['name'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'parent'      => $parent === 0 ? null : $parent,
            'count'       => (int) ($row['term_count'] ?? 0),
        ];
    }

    /**
     * @param array<int, array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    public function toCollection(array $rows, ?string $nextCursor): array
    {
        return [
            'data'        => array_values(array_map(fn (array $row): array => $this->toArray($row), $rows)),
            'next_cursor' => $nextCursor,
        ];
    }
}
