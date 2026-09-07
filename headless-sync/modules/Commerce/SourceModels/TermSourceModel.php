<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\SourceModels;

/**
 * Normalized, immutable snapshot of one Commerce taxonomy term.
 *
 * Taxonomy-GENERIC rather than category-specific, deliberately. Product categories
 * (`product_cat`) and global attribute terms (`pa_*`) differ only in their `taxonomyType`, and
 * P1B-S3 established that generalising the spine beats duplicating six sibling classes per
 * taxonomy. P2-S4 reuses this model unchanged.
 */
final class TermSourceModel
{
    /**
     * @param int    $termId       wp_terms.term_id — globally unique across taxonomies, which
     *                             is what makes an unscoped lookup by source id safe
     * @param string $taxonomyType WordPress taxonomy: 'product_cat', 'pa_colour', …
     * @param string $slug
     * @param string $name
     * @param string $description
     * @param int    $parentId     Parent TERM id, 0 for top level. Soft reference (AG-7)
     * @param int    $count        Number of objects carrying this term
     */
    public function __construct(
        public readonly int $termId,
        public readonly string $taxonomyType,
        public readonly string $slug,
        public readonly string $name,
        public readonly string $description,
        public readonly int $parentId,
        public readonly int $count,
    ) {
    }
}
