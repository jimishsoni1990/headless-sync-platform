<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Resources;

use HSP\Core\Contracts\ResourceInterface;

/**
 * Shapes a `pa_*` attribute term from `commerce.taxonomies` into its published contract.
 *
 * WHY THIS IS NOT `TermResource` (FLAG-COMMSOURCEID-1). Product categories and `pa_*` terms share
 * ONE projection table — AG-9 put them there, discriminated by `taxonomy_type`, and that is not
 * changing. A shared storage model does not oblige one identical public shape, and here the two
 * genuinely differ, verified against WooCommerce 11.1.0 rather than assumed:
 *
 *   - `product_cat` is registered `'hierarchical' => true` (`class-wc-post-types.php:106`), so its
 *     terms nest and a parent reference means something.
 *   - `pa_*` taxonomies are registered `'hierarchical' => false` (`:269`). The `true` further down
 *     (`:318`) is inside the `rewrite` array — a permalink option, not the taxonomy's hierarchy.
 *
 * So an attribute term's `parent` is structurally always null: not "usually empty", but incapable
 * of carrying information. And its `source_id` anchors nothing — there is no `?parent=` filter on
 * `/product-attributes/{taxonomy}/terms`, and the product filter selects a term by SLUG
 * (`?attribute=pa_colour&attribute_term=blue`). Both fields were published here only because one
 * Resource class happened to serve two endpoint families.
 *
 * What remains is the whole of what a consumer selects and renders with: `slug` (the public
 * identity, and the value the product filter takes), `name` (the label), `description`, and
 * `count` — the `get_term()` / `wp_term_taxonomy.count` source fact settled by
 * FLAG-COMMTERMCOUNT-1, which is independently contractual and unchanged here.
 */
final class AttributeTermResource implements ResourceInterface
{
    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function toArray(array $row): array
    {
        return [
            'slug'        => (string) ($row['slug'] ?? ''),
            'name'        => (string) ($row['name'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
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
